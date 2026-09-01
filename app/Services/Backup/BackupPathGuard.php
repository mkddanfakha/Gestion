<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Resolves backup archive paths safely (no path traversal).
 */
final class BackupPathGuard
{
    /**
     * Normalize a user-supplied backup filename to a single basename ending in .zip.
     *
     * @throws RuntimeException
     */
    public static function sanitizeBackupFileName(string $backupName): string
    {
        $decoded = rawurldecode($backupName);
        $decoded = str_replace(["\0", '\\'], ['', '/'], $decoded);

        if ($decoded === '' || str_contains($decoded, "\0")) {
            throw new RuntimeException('Invalid backup name.');
        }

        // Reject absolute / UNC / drive paths before basename.
        if (preg_match('#^(?:[a-zA-Z]:)?[\\\\/]#', $decoded) === 1
            || str_starts_with($decoded, '\\\\')
            || str_contains($decoded, '://')) {
            throw new RuntimeException('Absolute backup paths are forbidden.');
        }

        if (str_contains($decoded, '..')) {
            throw new RuntimeException('Path traversal in backup name is forbidden.');
        }

        $base = basename(str_replace('\\', '/', $decoded));

        if ($base === '' || $base === '.' || $base === '..') {
            throw new RuntimeException('Invalid backup name.');
        }

        if (! preg_match('/^[A-Za-z0-9._-]+\.zip$/i', $base)) {
            throw new RuntimeException('Backup name must be a simple .zip filename.');
        }

        return $base;
    }

    public static function backupDiskName(): string
    {
        return config('backup.backup.destination.disks')[0] ?? 'local';
    }

    public static function backupFolderName(): string
    {
        return (string) config('backup.backup.name', 'laravel-backup');
    }

    /**
     * Absolute directory where Spatie stores ZIP archives for this app.
     *
     * @throws RuntimeException
     */
    public static function backupDirectoryAbsolutePath(?string $disk = null): string
    {
        $disk ??= self::backupDiskName();
        $folder = self::backupFolderName();
        $relative = trim(str_replace('\\', '/', $folder), '/');

        $absolute = Storage::disk($disk)->path($relative);
        $absolute = self::normalizeSeparators($absolute);

        if (! is_dir($absolute)) {
            if (! mkdir($absolute, 0755, true) && ! is_dir($absolute)) {
                throw new RuntimeException('Backup directory is not available.');
            }
        }

        $real = realpath($absolute);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('Backup directory cannot be resolved.');
        }

        return self::normalizeSeparators($real);
    }

    /**
     * Resolve a user-supplied backup name to an absolute file path inside the backup dir.
     *
     * @throws RuntimeException
     */
    public static function resolveExistingBackupPath(string $backupName, ?string $disk = null): string
    {
        $safeName = self::sanitizeBackupFileName($backupName);
        $directory = self::backupDirectoryAbsolutePath($disk);
        $candidate = self::normalizeSeparators($directory.DIRECTORY_SEPARATOR.$safeName);

        if (! is_file($candidate)) {
            throw new RuntimeException('Backup not found.');
        }

        $realFile = realpath($candidate);
        if ($realFile === false || ! is_file($realFile)) {
            throw new RuntimeException('Backup not found.');
        }

        $realFile = self::normalizeSeparators($realFile);
        if (! self::isPathInsideDirectory($realFile, $directory)) {
            throw new RuntimeException('Backup path escapes the authorized directory.');
        }

        return $realFile;
    }

    /**
     * Build a destination path for a new archive (must not already exist unless $allowOverwrite).
     *
     * @throws RuntimeException
     */
    public static function resolveNewBackupPath(string $backupName, ?string $disk = null, bool $allowOverwrite = false): string
    {
        $safeName = self::sanitizeBackupFileName($backupName);
        $directory = self::backupDirectoryAbsolutePath($disk);
        $candidate = self::normalizeSeparators($directory.DIRECTORY_SEPARATOR.$safeName);

        if (! $allowOverwrite && is_file($candidate)) {
            throw new RuntimeException('A backup with this name already exists.');
        }

        // Parent must stay inside backup dir (candidate may not exist yet — check dirname).
        $parent = dirname($candidate);
        $realParent = realpath($parent);
        if ($realParent === false) {
            throw new RuntimeException('Backup directory cannot be resolved.');
        }
        $realParent = self::normalizeSeparators($realParent);
        if (! self::isPathInsideDirectory($realParent, $directory) && $realParent !== $directory) {
            throw new RuntimeException('Backup path escapes the authorized directory.');
        }

        return $candidate;
    }

    public static function relativePathFromAbsolute(string $absolutePath, ?string $disk = null): string
    {
        $disk ??= self::backupDiskName();
        $root = self::normalizeSeparators(rtrim(Storage::disk($disk)->path(''), DIRECTORY_SEPARATOR));
        $absolutePath = self::normalizeSeparators($absolutePath);

        if (! str_starts_with($absolutePath, $root.DIRECTORY_SEPARATOR) && $absolutePath !== $root) {
            throw new RuntimeException('Path is outside the backup disk root.');
        }

        $relative = ltrim(substr($absolutePath, strlen($root)), DIRECTORY_SEPARATOR);

        return str_replace('\\', '/', $relative);
    }

    public static function isPathInsideDirectory(string $path, string $directory): bool
    {
        $path = self::normalizeSeparators($path);
        $directory = rtrim(self::normalizeSeparators($directory), DIRECTORY_SEPARATOR);

        return $path === $directory
            || str_starts_with($path, $directory.DIRECTORY_SEPARATOR);
    }

    public static function normalizeSeparators(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    /**
     * Reject dangerous ZIP entry names (traversal, absolute, Windows drives).
     */
    public static function assertSafeZipEntryName(string $entryName): void
    {
        $name = str_replace('\\', '/', $entryName);

        if ($name === '' || str_contains($name, "\0")) {
            throw new RuntimeException('ZIP entry name is invalid.');
        }

        if (str_starts_with($name, '/') || str_starts_with($name, '\\')) {
            throw new RuntimeException('ZIP absolute paths are forbidden.');
        }

        if (preg_match('#^[a-zA-Z]:#', $name) === 1) {
            throw new RuntimeException('ZIP drive paths are forbidden.');
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '..') {
                throw new RuntimeException('ZIP path traversal is forbidden.');
            }
        }
    }
}
