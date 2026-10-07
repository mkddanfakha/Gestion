<?php

namespace App\Console\Commands;

use App\Database\BackupConcurrencyGuard;
use App\Database\PrivilegedProcessRunner;
use App\Services\Backup\BackupManifestAttachmentService;
use App\Services\Backup\BackupMetadataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RunProductionBackupCommand extends Command
{
    protected $signature = 'backup:production
        {--only-db : Backup only the database via isolated gestion_backup subprocess}
        {--only-to-disk= : Restrict this backup run to one backup disk, without changing global BACKUP_DISKS}
        {--defer-manifest : Skip sidecar manifest (manual UI job attaches metadata after success)}';

    protected $description = 'Run backup:run in an isolated subprocess with gestion_backup credentials (runtime stays gestion_app)';

    public function handle(PrivilegedProcessRunner $runner, BackupManifestAttachmentService $manifests): int
    {
        $onlyDb = (bool) $this->option('only-db');
        $onlyToDisk = $this->option('only-to-disk');
        if ($onlyToDisk !== null && ! is_string($onlyToDisk)) {
            $onlyToDisk = null;
        }
        $deferManifest = (bool) $this->option('defer-manifest');
        $startedAt = time();

        $this->info('Starting production backup via isolated subprocess (gestion_backup, CACHE_STORE=file)...');

        try {
            $result = BackupConcurrencyGuard::runBackup(function () use ($runner, $onlyDb, $onlyToDisk) {
                return $runner->runBackupRun($onlyDb, null, $onlyToDisk);
            });
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'already in progress')) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            throw $e;
        }

        if ($result->output !== '') {
            $this->line($result->output);
        }

        if ($result->errorOutput !== '') {
            $this->error($result->errorOutput);
        }

        if (! $result->successful()) {
            $this->error('Production backup subprocess failed (exit code '.$result->exitCode.').');

            return $result->exitCode !== 0 ? $result->exitCode : self::FAILURE;
        }

        $this->info('Production backup subprocess completed successfully.');

        if (! $deferManifest) {
            try {
                $meta = $manifests->attachNewestArchiveSince(
                    $startedAt,
                    $onlyDb,
                    BackupMetadataService::SOURCE_SCHEDULER,
                    null,
                );
                if ($meta === null) {
                    Log::warning('backup.production.manifest.missing_archive', [
                        'only_db' => $onlyDb,
                        'started_at' => $startedAt,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('backup.production.manifest.failed', [
                    'only_db' => $onlyDb,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
