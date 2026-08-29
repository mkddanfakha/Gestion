<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// PRE-PROD 10.2 — daily backup chain (Africa/Dakar via config/app.php timezone).
// withoutOverlapping: skip if previous run still held (mutex, minutes).
Schedule::command('backup:production')
    ->dailyAt('02:00')
    ->withoutOverlapping(180);

Schedule::command('backup:clean')
    ->dailyAt('03:00')
    ->withoutOverlapping(120);

Schedule::command('backup:monitor')
    ->dailyAt('04:00')
    ->withoutOverlapping(60);

// Nettoyage automatique des anciennes notifications
if (config('notification-center.scheduler.enabled', true)) {
    Schedule::command('notifications:optimize-tables')
        ->dailyAt(config('notification-center.scheduler.optimize_tables_at', '03:00'));

    Schedule::command('notifications:archive-resolved')
        ->dailyAt(config('notification-center.scheduler.archive_resolved_at', '03:30'));

    Schedule::command('notifications:delete-archived')
        ->monthlyOn(1, config('notification-center.scheduler.delete_archived_at', '04:00'));

    Schedule::command('notifications:cleanup-orphans')
        ->dailyAt(config('notification-center.scheduler.cleanup_orphans_at', '04:30'));

    Schedule::command('notifications:cleanup')
        ->dailyAt(config('notification-center.scheduler.cleanup_expired_at', '05:00'));
}
