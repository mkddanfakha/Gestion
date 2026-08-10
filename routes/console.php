<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sauvegardes automatiques quotidiennes à 2h du matin
Schedule::command('backup:run')->daily()->at('02:00');

// Nettoyage automatique des anciennes sauvegardes quotidiennement à 3h du matin
Schedule::command('backup:clean')->daily()->at('03:00');

// Vérification de la santé des sauvegardes quotidiennement à 4h du matin
Schedule::command('backup:monitor')->daily()->at('04:00');

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
