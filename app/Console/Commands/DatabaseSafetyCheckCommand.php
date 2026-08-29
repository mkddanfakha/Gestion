<?php

namespace App\Console\Commands;

use App\Database\DatabaseAccountGuard;
use App\Database\DatabaseSafetyGuard;
use Illuminate\Console\Command;

class DatabaseSafetyCheckCommand extends Command
{
    protected $signature = 'db:safety-check {--json : Output as JSON}';

    protected $description = 'Display database safety status (read-only; never migrates or seeds)';

    public function handle(): int
    {
        $status = DatabaseSafetyGuard::status();
        $accounts = DatabaseAccountGuard::status();

        if ($this->option('json')) {
            $this->line(json_encode(array_merge($status, [
                'mysql_boundary' => DatabaseSafetyGuard::mysqlBoundaryStatus(),
                'accounts' => $accounts,
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('DATABASE SAFETY CHECK');
        $this->newLine();
        $this->line('Environment : '.$status['environment']);
        $this->line('Connection  : '.$status['connection']);
        $this->line('Driver      : '.($status['driver'] ?? 'n/a'));
        $this->line('Host        : '.($status['host'] ?? 'n/a'));
        $this->line('Database    : '.$status['database']);
        if (! empty($status['mysql_database'])) {
            $this->line('MySQL DB    : '.$status['mysql_database']);
        }
        $boundary = DatabaseSafetyGuard::mysqlBoundaryStatus();
        $this->line('MySQL user  : '.($boundary['laravel_db_username'] ?? 'n/a'));
        $this->line('Uses root   : '.($boundary['laravel_uses_mysql_root'] ? 'YES (CRITICAL)' : 'NO'));
        $this->line('MySQL LP    : '.$boundary['protected_by_mysql_least_privilege']);
        $this->newLine();
        $this->line('Account roles (policy):');
        $this->line('  runtime   : '.$accounts['runtime_account'].' (created/wired: cutover)');
        $this->line('  backup    : '.$accounts['backup_account'].' (created: '.($accounts['backup_account_created'] ? 'YES' : 'NO').')');
        $this->line('  restore   : '.$accounts['restore_account'].' (created: '.($accounts['restore_account_created'] ? 'YES' : 'NO').')');
        $this->line('  migration : '.$accounts['migration_account'].' (created: '.($accounts['migration_account_created'] ? 'YES' : 'NO').')');
        $this->line('  runtime matches policy : '.($accounts['runtime_matches_policy'] ? 'YES' : 'NO'));
        $this->newLine();
        $this->line('STATUS      : '.$status['status']);
        $this->newLine();
        $this->line('Destructive migrations:');
        $this->line($status['destructive_migrations']);
        $this->newLine();
        $this->line('Database wipe:');
        $this->line($status['database_wipe']);
        $this->newLine();
        $this->line('Application restore / DROP DATABASE:');
        $this->line($status['restore'].' (target: '.($status['restore_target'] ?? 'n/a').')');
        $this->newLine();
        $this->line('Test isolation:');
        $this->line($status['test_isolation']);
        $this->newLine();
        $this->line('Protected databases: '.implode(', ', $status['protected_databases']));
        $this->line('Restore allow-list: '.implode(', ', $status['restore_allowed_databases']));
        $this->newLine();
        $this->comment('No changes performed.');
        $this->newLine();

        return self::SUCCESS;
    }
}
