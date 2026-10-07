<?php

namespace App\Jobs;

use App\Services\Backup\BackupAuditService;
use App\Services\Backup\BackupCreationProgress;
use App\Services\Backup\BackupCreationService;
use App\Services\Backup\BackupJobOutputSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

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

    /** Single attempt — backup:production is not idempotent for duplicate ZIP creation. */
    public int $tries = 1;

    public bool $onlyDb;

    public int $userId;

    public string $jobId;

    public function __construct(bool $onlyDb = false, ?int $userId = null, ?string $jobId = null)
    {
        $this->onlyDb = $onlyDb;
        $this->userId = $userId ?? (int) (auth()->id() ?? 0);
        $this->jobId = $jobId ?: (string) Str::uuid();
    }

    public function handle(
        BackupCreationService $creation,
        BackupJobOutputSanitizer $outputSanitizer,
    ): void {
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

            $params = [
                '--defer-manifest' => true,
            ];
            if ($this->onlyDb) {
                $params['--only-db'] = true;
            }

            $exitCode = Artisan::call('backup:production', $params);
            $sanitizedOutput = $outputSanitizer->sanitize(trim(Artisan::output()));

            if ($exitCode !== 0) {
                Log::error('backup.job.create.failed', [
                    'job_id' => $this->jobId,
                    'exit_code' => $exitCode,
                    'output' => mb_substr($sanitizedOutput, 0, 4000),
                    'user_id' => $this->userId,
                ]);

                $this->markFailed(
                    'La sauvegarde n\'a pas pu être créée (moteur backup:production).',
                    'subprocess_exit_'.$exitCode,
                    $sanitizedOutput,
                );

                throw new RuntimeException('backup:production failed with exit code '.$exitCode);
            }

            BackupCreationProgress::put($this->jobId, [
                'status' => 'running',
                'percentage' => 85,
                'message' => 'Finalisation des métadonnées…',
                'user_id' => $this->userId,
                'only_db' => $this->onlyDb,
            ]);

            try {
                $meta = $creation->attachManualMetadata(
                    $this->onlyDb,
                    $this->userId > 0 ? $this->userId : null,
                    $startedAt,
                );
            } catch (\Throwable $e) {
                Log::error('backup.job.create.manifest_failed', [
                    'job_id' => $this->jobId,
                    'message' => $e->getMessage(),
                    'user_id' => $this->userId,
                ]);

                $this->markFailed(
                    'La sauvegarde a été produite mais le manifeste n\'a pas pu être créé.',
                    'manifest_failed',
                    null,
                );

                throw $e;
            }

            if ($meta === null) {
                Log::error('backup.job.create.no_archive', [
                    'job_id' => $this->jobId,
                    'user_id' => $this->userId,
                ]);

                $this->markFailed(
                    'Aucune archive de sauvegarde détectée après l\'exécution.',
                    'archive_missing',
                    $sanitizedOutput !== '' ? $sanitizedOutput : null,
                );

                throw new RuntimeException('backup:production succeeded but no archive was found.');
            }

            BackupAuditService::created($meta);

            BackupCreationProgress::put($this->jobId, [
                'status' => 'completed',
                'percentage' => 100,
                'message' => $this->onlyDb
                    ? 'Sauvegarde de la base de données créée avec succès.'
                    : 'Sauvegarde (base de données + fichiers) créée avec succès.',
                'user_id' => $this->userId,
                'only_db' => $this->onlyDb,
                'filename' => $meta['filename'] ?? null,
                'sha256' => $meta['archive']['sha256'] ?? $meta['sha256'] ?? null,
            ]);

            Log::info('backup.job.create.success', [
                'job_id' => $this->jobId,
                'only_db' => $this->onlyDb,
                'user_id' => $this->userId,
                'filename' => $meta['filename'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $progress = BackupCreationProgress::get($this->jobId);
            if ($progress === null || ($progress['status'] ?? '') !== 'failed') {
                $this->markFailed(
                    'La sauvegarde n\'a pas pu être créée. Veuillez réessayer.',
                    'unexpected',
                    null,
                );
            }

            Log::error('backup.job.create.exception', [
                'job_id' => $this->jobId,
                'message' => $e->getMessage(),
                'user_id' => $this->userId,
            ]);

            throw $e;
        }
    }

    private function markFailed(string $userMessage, string $reasonCode, ?string $logHint): void
    {
        BackupCreationProgress::put($this->jobId, [
            'status' => 'failed',
            'percentage' => 0,
            'message' => $userMessage,
            'failure_reason' => $reasonCode,
            'log_hint' => $logHint !== null && $logHint !== ''
                ? mb_substr($logHint, 0, 500)
                : null,
            'user_id' => $this->userId,
            'only_db' => $this->onlyDb,
        ]);
    }
}
