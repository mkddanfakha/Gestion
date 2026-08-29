<?php

namespace App\Console\Commands;

use App\Database\BackupConcurrencyGuard;
use App\Database\PrivilegedProcessRunner;
use Illuminate\Console\Command;
use RuntimeException;

class RunProductionBackupCommand extends Command
{
    protected $signature = 'backup:production {--only-db : Backup only the database via isolated gestion_backup subprocess}';

    protected $description = 'Run backup:run in an isolated subprocess with gestion_backup credentials (runtime stays gestion_app)';

    public function handle(PrivilegedProcessRunner $runner): int
    {
        $onlyDb = (bool) $this->option('only-db');

        $this->info('Starting production backup via isolated subprocess (gestion_backup, CACHE_STORE=file)...');

        try {
            $result = BackupConcurrencyGuard::runBackup(function () use ($runner, $onlyDb) {
                return $runner->runBackupRun($onlyDb);
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

        return self::SUCCESS;
    }
}
