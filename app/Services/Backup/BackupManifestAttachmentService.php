<?php

namespace App\Services\Backup;

use App\Database\BackupArchiveInspector;
use RuntimeException;

/**
 * Attach sidecar manifests after a Spatie backup ZIP exists on disk.
 * Used by manual UI jobs (source=manual) and backup:production CRON (source=scheduler).
 */
final class BackupManifestAttachmentService
{
    /**
     * Find the newest ZIP created at or after $notBeforeTimestamp and attach manifest.
     *
     * @return array<string, mixed>|null null when no matching archive (caller decides severity)
     */
    public function attachNewestArchiveSince(
        int $notBeforeTimestamp,
        bool $onlyDb,
        string $source,
        ?int $userId = null,
    ): ?array {
        $resolved = $this->resolveNewestArchiveSince($notBeforeTimestamp);
        if ($resolved === null) {
            return null;
        }

        return $this->attachManifestForArchive(
            $resolved['absolute'],
            $resolved['filename'],
            $onlyDb,
            $source,
            $userId,
        );
    }

    /**
     * @return array{absolute: string, filename: string}|null
     */
    public function resolveNewestArchiveSince(int $notBeforeTimestamp): ?array
    {
        try {
            $directory = BackupPathGuard::backupDirectoryAbsolutePath();
        } catch (RuntimeException) {
            return null;
        }

        $newest = null;
        $newestMtime = 0;

        try {
            $iterator = new \DirectoryIterator($directory);
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isDot() || ! $fileInfo->isFile()) {
                    continue;
                }
                if (strtolower($fileInfo->getExtension()) !== 'zip') {
                    continue;
                }

                $mtime = (int) $fileInfo->getMTime();
                if ($mtime < $notBeforeTimestamp - 5) {
                    continue;
                }

                if ($mtime >= $newestMtime) {
                    $newestMtime = $mtime;
                    $newest = $fileInfo->getPathname();
                }
            }
        } catch (\Throwable) {
            return null;
        }

        if ($newest === null || ! is_file($newest)) {
            return null;
        }

        return [
            'absolute' => $newest,
            'filename' => basename($newest),
        ];
    }

    /**
     * Validate archive then write meta/{zip}.json (local source of truth).
     *
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $extraAttributes
     * @return array<string, mixed>
     */
    public function attachManifestForArchive(
        string $absolutePath,
        string $filename,
        bool $onlyDb,
        string $source,
        ?int $userId = null,
        array $extraAttributes = [],
    ): array {
        $safeName = BackupPathGuard::sanitizeBackupFileName($filename);

        if (! is_file($absolutePath)) {
            throw new RuntimeException('Backup archive not found after creation.');
        }

        $size = filesize($absolutePath);
        if ($size === false || $size <= 0) {
            throw new RuntimeException('Backup archive is empty.');
        }

        $inspection = BackupArchiveInspector::inspect($absolutePath);
        if (! ($inspection['readable'] ?? false)) {
            throw new RuntimeException('Backup archive is not readable.');
        }

        $verdict = (string) ($inspection['verdict'] ?? '');
        if (in_array($verdict, ['INVALID_TOO_SMALL', 'INVALID_NO_SQL'], true)) {
            throw new RuntimeException('Backup archive failed content validation.');
        }

        $hasNonDbContent = $this->archiveHasNonDbContent($absolutePath);
        $type = $onlyDb
            ? BackupMetadataService::TYPE_DATABASE
            : BackupMetadataService::typeFromInspection($inspection, $hasNonDbContent);

        $status = $source === BackupMetadataService::SOURCE_IMPORT
            ? BackupMetadataService::STATUS_IMPORTED
            : BackupMetadataService::STATUS_VALID;

        return app(BackupManifestService::class)->createAndWriteForExistingZip($safeName, array_merge([
            'type' => $type,
            'source' => $source,
            'user_id' => $userId,
            'status' => $status,
            'files_included' => $type === BackupMetadataService::TYPE_FULL,
            'database_included' => true,
        ], $extraAttributes));
    }

    private function archiveHasNonDbContent(string $absolutePath): bool
    {
        $zip = new \ZipArchive();
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
