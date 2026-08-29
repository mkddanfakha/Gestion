<?php

namespace App\Console\Commands;

use App\Database\BackupArchiveInspector;
use App\Database\BackupConcurrencyGuard;
use App\Database\BackupOperationalStatus;
use App\Database\DatabaseSafetyGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackupStatusCommand extends Command
{
    protected $signature = 'backup:status {--json : Output as JSON}';

    protected $description = 'Read-only MKD-Pro backup status (never restores or migrates)';

    public function handle(): int
    {
        $safety = DatabaseSafetyGuard::status();
        $ops = BackupOperationalStatus::evaluate();
        $disk = 'local';
        $folder = config('backup.backup.name', 'laravel-backup');
        $files = [];

        try {
            $files = Storage::disk($disk)->files($folder);
        } catch (\Throwable) {
            $files = [];
        }

        $zips = array_values(array_filter(
            $files,
            static fn (string $path): bool => str_ends_with(strtolower($path), '.zip'),
        ));

        $inspections = [];

        foreach ($zips as $relative) {
            $absolute = Storage::disk($disk)->path($relative);
            $info = BackupArchiveInspector::inspect($absolute);
            $inspections[] = [
                'file' => basename($relative),
                'size_bytes' => $info['size_bytes'] ?? null,
                'sha256' => $info['sha256'] ?? null,
                'verdict' => $info['verdict'] ?? 'UNKNOWN',
                'sql_present' => $info['sql']['present'] ?? false,
                'business_inserts' => $info['sql']['business_inserts'] ?? 0,
            ];
        }

        usort($inspections, static function (array $a, array $b): int {
            return strcmp((string) ($b['file'] ?? ''), (string) ($a['file'] ?? ''));
        });

        $latest = $inspections[0] ?? null;

        $payload = [
            'application' => 'MKD-Pro',
            'database' => $safety['mysql_database'] ?? $safety['database'],
            'environment' => $safety['environment'],
            'safety_status' => $safety['status'],
            'restore' => $safety['restore'] ?? null,
            'backup_lock' => BackupConcurrencyGuard::isBackupLocked() ? 'LOCKED' : 'FREE',
            'restore_lock' => BackupConcurrencyGuard::isRestoreLocked() ? 'LOCKED' : 'FREE',
            'lock_store' => BackupConcurrencyGuard::lockStoreName(),
            'status' => $ops['status'],
            'mode' => $ops['mode'],
            'backup_local' => $ops['backup_local'],
            'backup_offsite' => $ops['backup_offsite'],
            'last_backup' => $ops['last_backup'],
            'last_offsite_copy' => $ops['last_offsite_copy'],
            'checksum' => $ops['checksum'],
            'zip' => $ops['zip'],
            'backup_age_hours' => $ops['backup_age_hours'],
            'rpo' => $ops['rpo'],
            'rpo_hours' => $ops['rpo_hours'],
            'offsite_wiring' => $ops['offsite_wiring'],
            'local_disk' => $disk,
            'local_folder' => $folder,
            'archive_count' => count($zips),
            'latest_backup' => $latest,
            'archives' => $inspections,
            'retention' => [
                'keep_all_days' => config('backup.cleanup.default_strategy.keep_all_backups_for_days'),
                'keep_daily_days' => config('backup.cleanup.default_strategy.keep_daily_backups_for_days'),
                'keep_weekly_weeks' => config('backup.cleanup.default_strategy.keep_weekly_backups_for_weeks'),
                'keep_monthly_months' => config('backup.cleanup.default_strategy.keep_monthly_backups_for_months'),
            ],
            'offsite' => $ops['offsite_wiring']['automated_offsite'] ?? 'NOT_CONFIGURED',
            'alerting_mail' => \App\Notifications\Backup\BackupAlertChannels::isMailAlertConfigured()
                ? 'CONFIGURED'
                : 'NOT_CONFIGURED',
            'backup_disks' => $ops['backup_disks'],
            'last_restore_test' => 'NOT_TESTED',
            'schedule' => [
                'backup:run' => 'daily 02:00 withoutOverlapping',
                'backup:clean' => 'daily 03:00 withoutOverlapping',
                'backup:monitor' => 'daily 04:00 withoutOverlapping',
                'timezone' => config('app.timezone'),
            ],
            'operation' => 'STATUS ONLY',
            'no_changes_performed' => true,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('MKD-Pro BACKUP STATUS');
        $this->newLine();
        $this->line('STATUS: '.$payload['status']);
        $this->line('MODE: '.$payload['mode']);
        $this->line('BACKUP_LOCAL: '.$payload['backup_local']);
        $this->line('BACKUP_OFFSITE: '.$payload['backup_offsite']);
        $this->line('RPO: '.$payload['rpo'].' (≤ '.$payload['rpo_hours'].'h)');
        $this->line('CHECKSUM: '.$payload['checksum']);
        $this->line('ZIP: '.$payload['zip']);
        $this->line('Database: '.($payload['database'] ?? 'n/a'));
        $this->line('Safety: '.$payload['safety_status']);
        $this->line('Restore to current MySQL target: '.($payload['restore'] ?? 'n/a'));
        $this->line('Backup lock: '.$payload['backup_lock'].' (store='.$payload['lock_store'].')');
        $this->line('Local archives: '.$payload['archive_count']);
        if ($latest) {
            $this->line('Latest file: '.$latest['file']);
            $this->line('Latest verdict: '.$latest['verdict']);
            $this->line('SHA-256: '.($latest['sha256'] ?? 'n/a'));
            $this->line('Business INSERT blocks: '.$latest['business_inserts']);
        } else {
            $this->warn('No local ZIP backups found.');
        }
        $this->line('Automated offsite: '.$payload['offsite']);
        $this->line('Alerting mail: '.$payload['alerting_mail']);
        $this->line('Last restore test: NOT_TESTED');
        $this->newLine();
        $this->comment('No changes performed.');
        $this->newLine();

        return self::SUCCESS;
    }
}
