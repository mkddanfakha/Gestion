<?php

namespace App\Console\Commands;

use App\Database\DatabaseSafetyGuard;
use App\Database\ProtectedDatabaseException;
use App\Services\Restore\ControlledRestoreVerificationService;
use Illuminate\Console\Command;

class RestoreVerifyProtocolCommand extends Command
{
    protected $signature = 'restore:verify-protocol
                            {--backup= : Backup ZIP filename in backup storage}
                            {--target=gestion_recovery : Restore target database (allow-list only)}
                            {--json : Output report as JSON}';

    protected $description = 'Run PRE-RESTORE controlled restore protocol checks (read-only; never restores).';

    public function handle(ControlledRestoreVerificationService $verification): int
    {
        $target = (string) $this->option('target');
        $backup = $this->option('backup');
        $backupFileName = is_string($backup) && trim($backup) !== '' ? trim($backup) : null;

        if (DatabaseSafetyGuard::isProtectedDatabase($target)) {
            $this->error('DATABASE SAFETY BLOCK: target '.$target.' is protected. Restore protocol aborted.');

            return self::FAILURE;
        }

        try {
            DatabaseSafetyGuard::assertExplicitRestoreTarget($target);
        } catch (ProtectedDatabaseException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $report = $verification->runPreRestoreProtocol($backupFileName, $target);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return ($report['status'] ?? 'FAIL') === 'FAIL' ? self::FAILURE : self::SUCCESS;
        }

        $this->newLine();
        $this->info('PRE-PROD 12.7.18 — CONTROLLED RESTORE PROTOCOL');
        $this->warn('DRY-RUN ONLY');
        $this->warn('NO RESTORE EXECUTED');
        $this->newLine();
        $this->line('Status : '.($report['status'] ?? 'UNKNOWN'));
        $this->line('Target : '.($report['target'] ?? 'n/a'));
        $this->line('Backup : '.($report['backup'] ?? 'not specified'));
        $this->newLine();

        $this->line('Environment:');
        foreach ($report['environment'] ?? [] as $key => $value) {
            $this->line('  '.$key.' : '.$value);
        }
        $this->newLine();

        $this->line('Checks:');
        foreach ($report['checks'] ?? [] as $check) {
            $this->line(sprintf(
                '  [%s] %s — %s',
                $check['status'] ?? '?',
                $check['check'] ?? 'unknown',
                $check['detail'] ?? '',
            ));
        }
        $this->newLine();

        $this->line('Snapshots:');
        $this->line('  gestion          : '.($report['snapshots']['gestion']['status'] ?? 'n/a'));
        $this->line('  target           : '.($report['snapshots']['target']['status'] ?? 'n/a'));
        $this->newLine();

        $this->line('Post-restore checks: IMPLEMENTED — NOT EXECUTED');
        $this->newLine();
        $this->comment('Human approval required before real restore.');
        $this->comment('No database writes performed.');
        $this->newLine();

        return ($report['status'] ?? 'FAIL') === 'FAIL' ? self::FAILURE : self::SUCCESS;
    }
}
