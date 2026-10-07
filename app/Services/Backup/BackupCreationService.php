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

        $requiresWorker = config('queue.default') !== 'sync';

        BackupCreationProgress::put($jobId, [
            'status' => 'queued',
            'percentage' => 5,
            'message' => $requiresWorker
                ? 'Création de sauvegarde en file d\'attente… Un worker queue doit traiter la demande (php artisan queue:work).'
                : 'Création de sauvegarde en file d\'attente…',
            'requires_worker' => $requiresWorker,
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
        return app(BackupManifestAttachmentService::class)->attachNewestArchiveSince(
            $notBeforeTimestamp,
            $onlyDb,
            BackupMetadataService::SOURCE_MANUAL,
            $userId,
        );
    }
}
