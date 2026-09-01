<?php

namespace App\Jobs;

use App\Services\Backup\BackupAuditService;
use App\Services\Backup\BackupCreationProgress;
use App\Services\Backup\BackupCreationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Queued backup creation for the admin UI.
 *
 * Lock ownership stays exclusively in backup:production.
 * Do NOT wrap Artisan::call with BackupConcurrencyGuard here.
 */
class CreateBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public bool $onlyDb;

    public int $userId;

    public string $jobId;

    public function __construct(bool $onlyDb = false, ?int $userId = null, ?string $jobId = null)
    {
        $this->onlyDb = $onlyDb;
        $this->userId = $userId ?? (int) (auth()->id() ?? 0);
        $this->jobId = $jobId ?: (string) Str::uuid();
    }

    public function handle(BackupCreationService $creation): void
    {
        $startedAt = time();

        BackupCreationProgress::put($this->jobId, [
            'status' => 'running',
            'percentage' => 15,
            'message' => $this->onlyDb
                ? 'Sauvegarde de la base de données en cours…'
                : 'Sauvegarde (base + fichiers) en cours…',
            'user_id' => $this->userId,
            'only_db' => $this->onlyDb,
            'started_at' => now()->toIso8601String(),
        ]);

        try {
            Log::info('backup.job.create.start', [
                'job_id' => $this->jobId,
                'only_db' => $this->onlyDb,
                'user_id' => $this->userId,
            ]);

            $exitCode = $this->onlyDb
                ? Artisan::call('backup:production', ['--only-db' => true])
                : Artisan::call('backup:production');

            if ($exitCode !== 0) {
                $preview = substr(Artisan::output(), 0, 500);
                Log::error('backup.job.create.failed', [
                    'job_id' => $this->jobId,
                    'exit_code' => $exitCode,
                    'output_preview' => $preview,
                    'user_id' => $this->userId,
                ]);

                throw new \RuntimeException('backup:production failed with exit code '.$exitCode);
            }

            BackupCreationProgress::put($this->jobId, [
                'status' => 'running',
                'percentage' => 85,
                'message' => 'Finalisation des métadonnées…',
                'user_id' => $this->userId,
                'only_db' => $this->onlyDb,
            ]);

            $meta = $creation->attachManualMetadata($this->onlyDb, $this->userId > 0 ? $this->userId : null, $startedAt);

            if (is_array($meta)) {
                BackupAuditService::created($meta);
            } else {
                BackupAuditService::created([
                    'filename' => null,
                    'type' => $this->onlyDb ? 'DATABASE' : 'FULL',
                    'status' => 'valid',
                    'source' => 'manual',
                    'user_id' => $this->userId > 0 ? $this->userId : null,
                ]);
            }

            BackupCreationProgress::put($this->jobId, [
                'status' => 'completed',
                'percentage' => 100,
                'message' => $this->onlyDb
                    ? 'Sauvegarde de la base de données créée avec succès.'
                    : 'Sauvegarde (base de données + fichiers) créée avec succès.',
                'user_id' => $this->userId,
                'only_db' => $this->onlyDb,
                'filename' => $meta['filename'] ?? null,
                'sha256' => $meta['sha256'] ?? null,
            ]);

            Log::info('backup.job.create.success', [
                'job_id' => $this->jobId,
                'only_db' => $this->onlyDb,
                'user_id' => $this->userId,
                'filename' => $meta['filename'] ?? null,
            ]);
        } catch (\Throwable $e) {
            BackupCreationProgress::put($this->jobId, [
                'status' => 'failed',
                'percentage' => 0,
                'message' => 'La sauvegarde n\'a pas pu être créée. Veuillez réessayer.',
                'user_id' => $this->userId,
                'only_db' => $this->onlyDb,
            ]);

            Log::error('backup.job.create.exception', [
                'job_id' => $this->jobId,
                'message' => $e->getMessage(),
                'user_id' => $this->userId,
            ]);

            throw $e;
        }
    }
}
