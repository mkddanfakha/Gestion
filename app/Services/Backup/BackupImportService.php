<?php

namespace App\Services\Backup;

use App\Database\BackupArchiveInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Secure backup ZIP import: quarantine → validate → promote → manifest.
 * Does NOT restore and does NOT upload to R2.
 */
final class BackupImportService
{
    public const MAX_BYTES = 10 * 1024 * 1024 * 1024; // 10 GiB

    public const QUARANTINE_DIR = 'backup-quarantine';

    /**
     * @return array{
     *   filename: string,
     *   path: string,
     *   size: int,
     *   sha256: string|null,
     *   type: string,
     *   status: string,
     *   meta: array<string, mixed>,
     *   inspection: array<string, mixed>
     * }
     */
    public function import(UploadedFile $file): array
    {
        $startedAt = microtime(true);
        $originalClientName = (string) $file->getClientOriginalName();

        $this->assertUploadBasics($file);

        $quarantineDir = storage_path('app/'.self::QUARANTINE_DIR);
        if (! is_dir($quarantineDir) && ! mkdir($quarantineDir, 0755, true) && ! is_dir($quarantineDir)) {
            $this->reject(
                BackupImportRejectedException::QUARANTINE_FAILED,
                'Unable to create quarantine directory.',
            );
        }

        $quarantineName = 'import-'.Str::uuid()->toString().'.zip';
        $quarantinePath = $quarantineDir.DIRECTORY_SEPARATOR.$quarantineName;
        $destination = null;
        $filename = null;

        try {
            try {
                $file->move($quarantineDir, $quarantineName);
            } catch (Throwable $e) {
                $this->reject(
                    BackupImportRejectedException::QUARANTINE_FAILED,
                    'Unable to store upload in quarantine.',
                );
            }

            if (! is_file($quarantinePath)) {
                $this->reject(
                    BackupImportRejectedException::QUARANTINE_FAILED,
                    'Unable to store upload in quarantine.',
                );
            }

            $this->assertZipReadableAndSafe($quarantinePath);

            $inspection = BackupArchiveInspector::inspect($quarantinePath);
            if (! ($inspection['readable'] ?? false)) {
                $this->reject(
                    BackupImportRejectedException::INVALID_ARCHIVE,
                    'The ZIP archive is unreadable or corrupted.',
                );
            }

            $verdict = (string) ($inspection['verdict'] ?? '');
            if ($verdict === 'INVALID_TOO_SMALL') {
                $this->reject(
                    BackupImportRejectedException::INVALID_TOO_SMALL,
                    'The ZIP archive is too small to be a valid backup.',
                );
            }
            if ($verdict === 'INVALID_NO_SQL') {
                $this->reject(
                    BackupImportRejectedException::INVALID_NO_SQL,
                    'The ZIP archive must contain at least one .sql database dump.',
                );
            }

            $safeName = $this->resolveImportFileName($originalClientName, $inspection);

            try {
                $destination = BackupPathGuard::resolveNewBackupPath($safeName);
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), 'already exists')) {
                    $this->reject(
                        BackupImportRejectedException::DUPLICATE_BACKUP,
                        'A backup with this filename already exists.',
                    );
                }

                throw $e;
            }

            if (! $this->promoteQuarantineToBackup($quarantinePath, $destination)) {
                $this->reject(
                    BackupImportRejectedException::PROMOTION_FAILED,
                    'Unable to promote quarantine archive to backup storage.',
                );
            }

            // Quarantine file moved — prevent finally block from deleting promoted file.
            $quarantinePath = '';

            $filename = basename($destination);
            $hasNonDbContent = $this->archiveHasNonDbContent($destination);
            $type = BackupMetadataService::typeFromInspection($inspection, $hasNonDbContent);
            $onlyDb = $type === BackupMetadataService::TYPE_DATABASE;

            try {
                $meta = app(BackupManifestAttachmentService::class)->attachManifestForArchive(
                    $destination,
                    $filename,
                    $onlyDb,
                    BackupMetadataService::SOURCE_IMPORT,
                    auth()->id(),
                    [
                        'original_filename' => $originalClientName,
                        'verdict' => $verdict !== '' ? $verdict : null,
                        'imported_at' => now()->toIso8601String(),
                    ],
                );
            } catch (Throwable $e) {
                $this->rollbackPromotedArchive($destination, $filename);
                Log::error('backup.import.manifest_failed', [
                    'filename' => $filename,
                    'message' => $e->getMessage(),
                    'user_id' => auth()->id(),
                ]);

                $this->reject(
                    BackupImportRejectedException::MANIFEST_FAILED,
                    'Unable to create backup manifest after import.',
                );
            }

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
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'original_filename' => $originalClientName,
            ]);

            return [
                'filename' => $filename,
                'path' => BackupPathGuard::relativePathFromAbsolute($destination),
                'size' => $size,
                'sha256' => is_string($sha256) ? $sha256 : null,
                'type' => $type,
                'status' => BackupMetadataService::STATUS_IMPORTED,
                'meta' => $meta,
                'inspection' => $inspection,
            ];
        } catch (BackupImportRejectedException $e) {
            Log::warning('backup.import.rejected', [
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
                'original_filename' => $originalClientName,
                'user_id' => auth()->id(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            throw $e;
        } finally {
            if ($quarantinePath !== '' && is_file($quarantinePath)) {
                if (! @unlink($quarantinePath)) {
                    Log::warning('backup.import.quarantine_cleanup_failed', [
                        'path' => basename($quarantinePath),
                    ]);
                }
            }
        }
    }

    /**
     * @throws BackupImportRejectedException
     */
    private function reject(string $errorCode, string $message): never
    {
        throw new BackupImportRejectedException($errorCode, $message);
    }

    /**
     * @throws BackupImportRejectedException
     */
    private function assertUploadBasics(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            $this->reject(
                BackupImportRejectedException::UPLOAD_FAILED,
                'Upload failed: '.$file->getErrorMessage(),
            );
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension !== 'zip') {
            $this->reject(
                BackupImportRejectedException::INVALID_EXTENSION,
                'The file must be a .zip archive.',
            );
        }

        $size = $file->getSize();
        if ($size === false || $size <= 0) {
            $this->reject(
                BackupImportRejectedException::UPLOAD_EMPTY,
                'The uploaded file is empty.',
            );
        }

        if ($size > self::MAX_BYTES) {
            $this->reject(
                BackupImportRejectedException::UPLOAD_TOO_LARGE,
                'The file exceeds the maximum allowed size (10 GB).',
            );
        }
    }

    /**
     * @throws BackupImportRejectedException
     */
    public function assertZipReadableAndSafe(string $zipPath): void
    {
        if (! is_file($zipPath) || filesize($zipPath) === 0) {
            $this->reject(
                BackupImportRejectedException::INVALID_ZIP,
                'The ZIP archive is empty or missing.',
            );
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            $this->reject(
                BackupImportRejectedException::INVALID_ZIP,
                'The ZIP archive is corrupted or invalid.',
            );
        }

        try {
            if ($zip->numFiles < 1) {
                $this->reject(
                    BackupImportRejectedException::INVALID_ZIP,
                    'The ZIP archive contains no entries.',
                );
            }

            $hasSql = false;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                try {
                    BackupPathGuard::assertSafeZipEntryName($name);
                } catch (RuntimeException) {
                    $this->reject(
                        BackupImportRejectedException::ZIP_SECURITY,
                        'ZIP path traversal or absolute paths are forbidden.',
                    );
                }

                $stat = $zip->statIndex($i);
                $external = (int) ($stat['external_attr'] ?? 0);
                $mode = ($external >> 16) & 0xFFFF;
                if (($mode & 0xF000) === 0xA000) {
                    $this->reject(
                        BackupImportRejectedException::ZIP_SECURITY,
                        'ZIP symbolic links are forbidden.',
                    );
                }

                if (str_ends_with(strtolower($name), '.sql')) {
                    $hasSql = true;
                }
            }

            if (! $hasSql) {
                $this->reject(
                    BackupImportRejectedException::INVALID_NO_SQL,
                    'The ZIP archive must contain at least one .sql database dump.',
                );
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

    private function promoteQuarantineToBackup(string $quarantinePath, string $destination): bool
    {
        if (@rename($quarantinePath, $destination)) {
            return is_file($destination);
        }

        if (@copy($quarantinePath, $destination)) {
            @unlink($quarantinePath);

            return is_file($destination);
        }

        return false;
    }

    private function rollbackPromotedArchive(string $destination, string $filename): void
    {
        if (is_file($destination)) {
            @unlink($destination);
        }

        try {
            BackupMetadataService::deleteForZip($filename);
        } catch (Throwable $e) {
            Log::warning('backup.import.rollback_meta_failed', [
                'filename' => $filename,
                'message' => $e->getMessage(),
            ]);
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
