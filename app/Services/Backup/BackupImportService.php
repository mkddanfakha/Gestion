<?php

namespace App\Services\Backup;

use App\Database\BackupArchiveInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Secure backup ZIP import: quarantine → validate → promote.
 * Does NOT restore.
 */
final class BackupImportService
{
    public const MAX_BYTES = 10 * 1024 * 1024 * 1024; // 10 GiB

    public const QUARANTINE_DIR = 'backup-quarantine';

    /**
     * @return array{filename: string, path: string, size: int, sha256: string|null, inspection: array<string, mixed>}
     */
    public function import(UploadedFile $file): array
    {
        $this->assertUploadBasics($file);

        $quarantineDir = storage_path('app/'.self::QUARANTINE_DIR);
        if (! is_dir($quarantineDir) && ! mkdir($quarantineDir, 0755, true) && ! is_dir($quarantineDir)) {
            throw new RuntimeException('Unable to create quarantine directory.');
        }

        $quarantineName = 'import-'.Str::uuid()->toString().'.zip';
        $quarantinePath = $quarantineDir.DIRECTORY_SEPARATOR.$quarantineName;

        try {
            $file->move($quarantineDir, $quarantineName);

            if (! is_file($quarantinePath)) {
                throw new RuntimeException('Unable to store upload in quarantine.');
            }

            $this->assertZipReadableAndSafe($quarantinePath);

            $inspection = BackupArchiveInspector::inspect($quarantinePath);
            if (! ($inspection['readable'] ?? false)) {
                throw new RuntimeException('The ZIP archive is unreadable or corrupted.');
            }

            $verdict = (string) ($inspection['verdict'] ?? '');
            if (in_array($verdict, ['INVALID_TOO_SMALL', 'INVALID_NO_SQL'], true)) {
                throw new RuntimeException(
                    'The ZIP is not a valid MKD-Pro / Spatie backup (missing database dump).',
                );
            }

            $safeName = $this->resolveImportFileName($file->getClientOriginalName(), $inspection);
            $destination = BackupPathGuard::resolveNewBackupPath($safeName);

            // Promote first, then hash the FINAL stored file (never trust upload-side hash).
            if (! @rename($quarantinePath, $destination)) {
                if (! @copy($quarantinePath, $destination)) {
                    throw new RuntimeException('Unable to promote quarantine archive to backup storage.');
                }
                @unlink($quarantinePath);
            }

            $filename = basename($destination);
            $manifestService = app(BackupManifestService::class);
            $hasNonDbContent = $this->archiveHasNonDbContent($destination);
            $type = BackupMetadataService::typeFromInspection($inspection, $hasNonDbContent);

            // Ignore any client/archive-provided integrity claims; server recalculates.
            $meta = $manifestService->createAndWriteForExistingZip($filename, [
                'type' => $type,
                'source' => BackupMetadataService::SOURCE_IMPORT,
                'user_id' => auth()->id(),
                'status' => BackupMetadataService::STATUS_IMPORTED,
                'original_filename' => $file->getClientOriginalName(),
                'verdict' => $verdict,
                'files_included' => $hasNonDbContent || $type === BackupMetadataService::TYPE_FULL,
                'database_included' => true,
                'imported_at' => now()->toIso8601String(),
            ]);

            $sha256 = $meta['archive']['sha256'] ?? $meta['sha256'] ?? null;
            $size = (int) ($meta['archive']['size_bytes'] ?? $meta['size_bytes'] ?? (filesize($destination) ?: 0));

            BackupAuditService::imported($meta);

            Log::info('backup.import.accepted', [
                'filename' => $filename,
                'sha256' => $sha256,
                'size' => $size,
                'verdict' => $verdict,
                'type' => $type,
                'manifest_version' => $meta['manifest_version'] ?? null,
                'user_id' => auth()->id(),
            ]);

            return [
                'filename' => $filename,
                'path' => BackupPathGuard::relativePathFromAbsolute($destination),
                'size' => $size,
                'sha256' => $sha256,
                'type' => $type,
                'status' => BackupMetadataService::STATUS_IMPORTED,
                'meta' => $meta,
                'inspection' => $inspection,
            ];
        } finally {
            if (isset($quarantinePath) && is_file($quarantinePath)) {
                @unlink($quarantinePath);
            }
        }
    }

    private function assertUploadBasics(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new RuntimeException('Upload failed: '.$file->getErrorMessage());
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension !== 'zip') {
            throw new RuntimeException('The file must be a .zip archive.');
        }

        $size = $file->getSize();
        if ($size === false || $size <= 0) {
            throw new RuntimeException('The uploaded file is empty.');
        }

        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('The file exceeds the maximum allowed size (10 GB).');
        }
    }

    /**
     * @throws RuntimeException
     */
    public function assertZipReadableAndSafe(string $zipPath): void
    {
        if (! is_file($zipPath) || filesize($zipPath) === 0) {
            throw new RuntimeException('The ZIP archive is empty or missing.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new RuntimeException('The ZIP archive is corrupted or invalid.');
        }

        try {
            if ($zip->numFiles < 1) {
                throw new RuntimeException('The ZIP archive contains no entries.');
            }

            $hasSql = false;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                BackupPathGuard::assertSafeZipEntryName($name);

                $stat = $zip->statIndex($i);
                // External attributes: Unix symlink bit in high word (0xA0000000 >> 16 = 0xA000)
                $external = (int) ($stat['external_attr'] ?? 0);
                $mode = ($external >> 16) & 0xFFFF;
                if (($mode & 0xF000) === 0xA000) {
                    throw new RuntimeException('ZIP symbolic links are forbidden.');
                }

                // Zip slip / absolute via getNameIndex already checked; also reject OP_SYS specials.
                if (str_ends_with(strtolower($name), '.sql')) {
                    $hasSql = true;
                }
            }

            if (! $hasSql) {
                throw new RuntimeException('The ZIP archive must contain at least one .sql database dump.');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    private function resolveImportFileName(string $originalName, array $inspection): string
    {
        try {
            return BackupPathGuard::sanitizeBackupFileName($originalName);
        } catch (RuntimeException) {
            $hash = substr((string) ($inspection['sha256'] ?? Str::random(16)), 0, 16);

            return 'imported-'.now()->format('Y-m-d-His').'-'.$hash.'.zip';
        }
    }

    /**
     * Entry-name peek only (no SQL content read).
     */
    private function archiveHasNonDbContent(string $absolutePath): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            return false;
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (! is_string($name) || $name === '') {
                    continue;
                }

                $normalized = str_replace('\\', '/', $name);
                if (str_ends_with($normalized, '/')) {
                    continue;
                }
                if (str_ends_with(strtolower($normalized), '.sql')) {
                    continue;
                }
                if (str_starts_with($normalized, 'db-dumps/')) {
                    continue;
                }

                return true;
            }
        } finally {
            $zip->close();
        }

        return false;
    }
}
