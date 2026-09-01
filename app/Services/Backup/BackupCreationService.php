<?php

namespace App\Services\Backup;

use App\Database\BackupConcurrencyGuard;
use App\Jobs\CreateBackupJob;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Orchestrates manual backup creation (async job, single lock in backup:production).
 */
final class BackupCreationService
{
    /**
     * @return array{job_id: string, only_db: bool, status: string}
     */
    public function start(bool $onlyDb, User $user): array
    {
        if (BackupConcurrencyGuard::isBackupLocked()) {
            throw new RuntimeException('A backup operation is already in progress.');
        }

        $jobId = (string) Str::uuid();

        BackupCreationProgress::put($jobId, [
            'status' => 'queued',
            'percentage' => 5,
            'message' => 'Création de sauvegarde en file d\'attente…',
            'user_id' => $user->id,
            'only_db' => $onlyDb,
            'started_at' => now()->toIso8601String(),
        ]);

        CreateBackupJob::dispatch($onlyDb, $user->id, $jobId);

        return [
            'job_id' => $jobId,
            'only_db' => $onlyDb,
            'status' => 'queued',
        ];
    }

    /**
     * After a successful backup:production run, attach sidecar meta to the newest archive.
     *
     * @return array<string, mixed>|null
     */
    public function attachManualMetadata(bool $onlyDb, ?int $userId, int $notBeforeTimestamp): ?array
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

        $filename = basename($newest);

        return app(BackupManifestService::class)->createAndWriteForExistingZip($filename, [
            'type' => $onlyDb
                ? BackupMetadataService::TYPE_DATABASE
                : BackupMetadataService::TYPE_FULL,
            'source' => BackupMetadataService::SOURCE_MANUAL,
            'user_id' => $userId,
            'status' => BackupMetadataService::STATUS_VALID,
            'files_included' => ! $onlyDb,
            'database_included' => true,
        ]);
    }
}
