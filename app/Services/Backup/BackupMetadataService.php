<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Sidecar JSON metadata next to Spatie backup ZIPs (format v1).
 *
 * Path: {backupDirectory}/meta/{zipBasename}.json
 * Never stores secrets or credentials.
 */
final class BackupMetadataService
{
    public const FORMAT_VERSION = 1;

    public const APPLICATION = 'MKD-Pro';

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SCHEDULER = 'scheduler';

    public const TYPE_DATABASE = 'DATABASE';

    public const TYPE_FULL = 'FULL';

    public const STATUS_VALID = 'valid';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CORRUPTED = 'corrupted';

    public const STATUS_INCOMPATIBLE = 'incompatible';

    /**
     * Absolute path to the meta directory (created if missing).
     *
     * @throws RuntimeException
     */
    public static function metaDirectoryAbsolutePath(?string $disk = null): string
    {
        $backupDir = BackupPathGuard::backupDirectoryAbsolutePath($disk);
        $metaDir = BackupPathGuard::normalizeSeparators($backupDir.DIRECTORY_SEPARATOR.'meta');

        if (! is_dir($metaDir) && ! mkdir($metaDir, 0755, true) && ! is_dir($metaDir)) {
            throw new RuntimeException('Unable to create backup meta directory.');
        }

        $real = realpath($metaDir);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('Backup meta directory cannot be resolved.');
        }

        $real = BackupPathGuard::normalizeSeparators($real);
        if (! BackupPathGuard::isPathInsideDirectory($real, $backupDir)) {
            throw new RuntimeException('Backup meta directory escapes the authorized directory.');
        }

        return $real;
    }

    /**
     * @throws RuntimeException
     */
    public static function resolveMetaPathForZip(string $zipFileName, ?string $disk = null): string
    {
        $safeZip = BackupPathGuard::sanitizeBackupFileName($zipFileName);
        $metaDir = self::metaDirectoryAbsolutePath($disk);
        $metaName = $safeZip.'.json';
        $candidate = BackupPathGuard::normalizeSeparators($metaDir.DIRECTORY_SEPARATOR.$metaName);

        $parent = dirname($candidate);
        $realParent = realpath($parent);
        if ($realParent === false) {
            throw new RuntimeException('Backup meta directory cannot be resolved.');
        }
        $realParent = BackupPathGuard::normalizeSeparators($realParent);
        $backupDir = BackupPathGuard::backupDirectoryAbsolutePath($disk);
        if (! BackupPathGuard::isPathInsideDirectory($realParent, $backupDir)) {
            throw new RuntimeException('Backup meta path escapes the authorized directory.');
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function writeForZip(string $zipFileName, array $attributes): array
    {
        $attributes['filename'] = self::sanitizeOrKeep($zipFileName, $attributes['filename'] ?? null);

        return app(BackupManifestService::class)->createAndWriteForExistingZip(
            BackupPathGuard::sanitizeBackupFileName($zipFileName),
            $attributes,
        );
    }

    private static function sanitizeOrKeep(string $zipFileName, mixed $existing): string
    {
        if (is_string($existing) && $existing !== '') {
            try {
                return BackupPathGuard::sanitizeBackupFileName($existing);
            } catch (RuntimeException) {
                // fall through
            }
        }

        return BackupPathGuard::sanitizeBackupFileName($zipFileName);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function readForZip(string $zipFileName): ?array
    {
        try {
            $path = self::resolveMetaPathForZip($zipFileName);
        } catch (RuntimeException) {
            return null;
        }

        if (! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($data)) {
            return null;
        }

        return $data;
    }

    public static function deleteForZip(string $zipFileName): void
    {
        try {
            $path = self::resolveMetaPathForZip($zipFileName);
        } catch (RuntimeException) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Map inspector / ZIP peek into DATABASE|FULL.
     *
     * @param  array<string, mixed>  $inspection
     */
    public static function typeFromInspection(array $inspection, bool $hasNonDbContent = false): string
    {
        if ($hasNonDbContent || ! empty($inspection['has_attachments_paths'])) {
            return self::TYPE_FULL;
        }

        return self::TYPE_DATABASE;
    }

    public static function uiTypeFromMetaType(?string $metaType): ?string
    {
        return match (strtoupper((string) $metaType)) {
            self::TYPE_DATABASE => 'database',
            self::TYPE_FULL => 'full',
            default => null,
        };
    }

    public static function uiStatusFromMeta(?array $meta, string $fallbackStatus): string
    {
        if ($meta === null) {
            return $fallbackStatus;
        }

        $status = strtolower((string) ($meta['status'] ?? ''));

        return match ($status) {
            self::STATUS_IMPORTED => 'imported',
            self::STATUS_CORRUPTED, self::STATUS_FAILED, self::STATUS_INCOMPATIBLE => 'invalid',
            self::STATUS_VALID => 'valid',
            default => $fallbackStatus,
        };
    }

    public static function normalizeTypePublic(mixed $type): string
    {
        return self::normalizeType($type);
    }

    public static function normalizeSourcePublic(mixed $source): string
    {
        return self::normalizeSource($source);
    }

    public static function normalizeStatusPublic(mixed $status): string
    {
        return self::normalizeStatus($status);
    }

    private static function normalizeType(mixed $type): string
    {
        $value = strtoupper((string) $type);

        return in_array($value, [self::TYPE_DATABASE, self::TYPE_FULL], true)
            ? $value
            : self::TYPE_DATABASE;
    }

    private static function normalizeSource(mixed $source): string
    {
        $value = strtolower((string) $source);

        return in_array($value, [self::SOURCE_IMPORT, self::SOURCE_MANUAL, self::SOURCE_SCHEDULER], true)
            ? $value
            : self::SOURCE_IMPORT;
    }

    private static function normalizeStatus(mixed $status): string
    {
        $value = strtolower((string) $status);
        $allowed = [
            self::STATUS_VALID,
            self::STATUS_IMPORTED,
            self::STATUS_FAILED,
            self::STATUS_CORRUPTED,
            self::STATUS_INCOMPATIBLE,
        ];

        return in_array($value, $allowed, true) ? $value : self::STATUS_VALID;
    }
}
