<?php

namespace App\Console\Commands;

use App\Database\ProtectedDatabaseException;
use App\Services\Restore\DatabaseRestoreService;
use Illuminate\Console\Command;
use RuntimeException;

class DatabaseRestoreCommand extends Command
{
    protected $signature = 'db:restore
        {--backup= : ZIP backup filename}
        {--target= : Explicit allow-listed database (never inferred)}
        {--confirmation=RESTORE : Exact confirmation phrase}
        {--force : Ignored — never bypasses database safety}';

    protected $description = 'Restore SQL from a backup ZIP into an explicit allow-listed database (DB-only, never files).';

    public function handle(DatabaseRestoreService $restore): int
    {
        $this->warn('RESTORE DRILL / RECOVERY — DB-ONLY. Never targets gestion. Never restores application files.');

        $backup = (string) $this->option('backup');
        $target = $this->option('target');
        $target = is_string($target) ? $target : null;
        $confirmation = (string) $this->option('confirmation');
        $force = (bool) $this->option('force');

        if ($backup === '') {
            $this->error('DATABASE SAFETY BLOCK: --backup is required.');

            return self::FAILURE;
        }

        try {
            $report = $restore->restore($backup, $target, $confirmation, $force);
        } catch (ProtectedDatabaseException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('DB-only restore completed.');
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        return self::SUCCESS;
    }
}
