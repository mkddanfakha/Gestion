<?php

namespace App\Console\Commands;

use App\Database\BackupArchiveInspector;
use Illuminate\Console\Command;

class BackupVerifyCommand extends Command
{
    protected $signature = 'backup:verify {path : Absolute path to a backup ZIP} {--json : Output as JSON}';

    protected $description = 'Inspect a backup ZIP (checksum, SQL, métier inserts). Never imports SQL.';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $report = BackupArchiveInspector::inspect($path);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return ($report['readable'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->info('BACKUP VERIFY (read-only)');
        $this->newLine();
        $this->line('Path: '.$path);
        $this->line('Readable: '.(($report['readable'] ?? false) ? 'yes' : 'no'));
        $this->line('SHA-256: '.($report['sha256'] ?? 'n/a'));
        $this->line('Size: '.($report['size_bytes'] ?? 'n/a'));
        $this->line('Verdict: '.($report['verdict'] ?? ($report['error'] ?? 'UNKNOWN')));
        if (isset($report['sql'])) {
            $this->line('SQL present: '.(($report['sql']['present'] ?? false) ? 'yes' : 'no'));
            $this->line('CREATE TABLE count: '.($report['sql']['create_table_count'] ?? 0));
            $this->line('Business INSERT blocks: '.($report['sql']['business_inserts'] ?? 0));
        }
        $this->newLine();
        $this->comment('No import, no restore, no database changes.');
        $this->newLine();

        return ($report['readable'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
