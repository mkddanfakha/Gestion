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
    private const MTIME_SKEW_SECONDS = 5;

    /**
     * Snapshot ZIP archives present before a backup operation (filename => mtime/size).
     *
     * @return array<string, array{mtime: int, size: int}>
     */
    public function snapshotExistingArchives(): array
    {
        try {
            $directory = BackupPathGuard::backupDirectoryAbsolutePath();
        } catch (RuntimeException) {
            return [];
        }

        $snapshot = [];

        try {
            $iterator = new \DirectoryIterator($directory);
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isDot() || ! $fileInfo->isFile()) {
                    continue;
                }
                if (strtolower($fileInfo->getExtension()) !== 'zip') {
                    continue;
                }

                $snapshot[$fileInfo->getFilename()] = [
                    'mtime' => (int) $fileInfo->getMTime(),
                    'size' => (int) $fileInfo->getSize(),
                ];
            }
        } catch (\Throwable) {
            return [];
        }

        return $snapshot;
    }

    /**
     * Find the archive produced by the current operation and attach a manifest.
     *
     * @param  array<string, array{mtime: int, size: int}>  $baselineBeforeRun
     * @return array<string, mixed>|null null when no new/changed archive matches
     *
     * @throws BackupArchiveResolutionException when more than one archive matches
     */
    public function attachArchiveProducedByOperation(
        int $notBeforeTimestamp,
        array $baselineBeforeRun,
        bool $onlyDb,
        string $source,
        ?int $userId = null,
    ): ?array {
        $resolved = $this->resolveArchiveProducedByOperation($notBeforeTimestamp, $baselineBeforeRun);
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
     * @deprecated Prefer attachArchiveProducedByOperation() with a pre-run snapshot.
     *
     * @return array<string, mixed>|null
     */
    public function attachNewestArchiveSince(
        int $notBeforeTimestamp,
        bool $onlyDb,
        string $source,
        ?int $userId = null,
    ): ?array {
        return $this->attachArchiveProducedByOperation(
            $notBeforeTimestamp,
            [],
            $onlyDb,
            $source,
            $userId,
        );
    }

    /**
     * Resolve exactly one archive created or modified by the operation since $notBeforeTimestamp.
     *
     * @param  array<string, array{mtime: int, size: int}>  $baselineBeforeRun
     * @return array{absolute: string, filename: string}|null
     *
     * @throws BackupArchiveResolutionException
     */
    public function resolveArchiveProducedByOperation(
        int $notBeforeTimestamp,
        array $baselineBeforeRun,
    ): ?array {
        $candidates = $this->collectOperationArchiveCandidates($notBeforeTimestamp, $baselineBeforeRun);

        if ($candidates === []) {
            return null;
        }

        if (count($candidates) > 1) {
            throw new BackupArchiveResolutionException(
                'DATABASE SAFETY: multiple backup archives match the current operation; refusing to attach a manifest to the wrong file.',
                BackupArchiveResolutionException::AMBIGUOUS,
            );
        }

        return $candidates[0];
    }

    /**
     * @deprecated Prefer resolveArchiveProducedByOperation() with a pre-run snapshot.
     *
     * @return array{absolute: string, filename: string}|null
     */
    public function resolveNewestArchiveSince(int $notBeforeTimestamp): ?array
    {
        try {
            return $this->resolveArchiveProducedByOperation(
                $notBeforeTimestamp,
                [],
            );
        } catch (BackupArchiveResolutionException) {
            return null;
        }
    }

    /**
     * @param  array<string, array{mtime: int, size: int}>  $baselineBeforeRun
     * @return list<array{absolute: string, filename: string}>
     */
    private function collectOperationArchiveCandidates(
        int $notBeforeTimestamp,
        array $baselineBeforeRun,
    ): array {
        try {
            $directory = BackupPathGuard::backupDirectoryAbsolutePath();
        } catch (RuntimeException) {
            return [];
        }

        $threshold = $notBeforeTimestamp - self::MTIME_SKEW_SECONDS;
        $candidates = [];

        try {
            $iterator = new \DirectoryIterator($directory);
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isDot() || ! $fileInfo->isFile()) {
                    continue;
                }
                if (strtolower($fileInfo->getExtension()) !== 'zip') {
                    continue;
                }

                $filename = $fileInfo->getFilename();
                $mtime = (int) $fileInfo->getMTime();
                $size = (int) $fileInfo->getSize();
                $baselineEntry = $baselineBeforeRun[$filename] ?? null;

                if ($baselineEntry !== null
                    && $baselineEntry['mtime'] === $mtime
                    && $baselineEntry['size'] === $size) {
                    continue;
                }

                if ($mtime < $threshold) {
                    continue;
                }

                $candidates[] = [
                    'absolute' => $fileInfo->getPathname(),
                    'filename' => $filename,
                ];
            }
        } catch (\Throwable) {
            return [];
        }

        return $candidates;
    }

    /**
     * Validate archive then write meta/{zip}.json (local source of truth).
     *
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
        $zip = new \ZipArchive;
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
