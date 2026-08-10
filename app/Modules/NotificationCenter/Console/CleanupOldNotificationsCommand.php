<?php

namespace App\Modules\NotificationCenter\Console;

use App\Modules\NotificationCenter\Jobs\CleanupNotificationJob;
use App\Modules\NotificationCenter\Services\NotificationHealthService;
use App\Modules\NotificationCenter\Services\NotificationSettingsService;
use Illuminate\Console\Command;

class CleanupOldNotificationsCommand extends Command
{
    protected $signature = 'notifications:cleanup {--days=} {--sync : Exécuter sans file d\'attente}';

    protected $description = 'Supprime les notifications plus anciennes que X jours (configurable)';

    public function handle(NotificationSettingsService $settings): int
    {
        $days = $this->option('days') ? (int) $this->option('days') : null;

        if ($this->option('sync') || ! config('notification-center.scheduler.use_queue_for_scheduled_tasks', true)) {
            $deleted = $settings->cleanupOlderThan($days);
            $this->info("{$deleted} notification(s) supprimée(s).");
        } else {
            CleanupNotificationJob::dispatch('expired', $days);
            $this->info('Nettoyage planifié via la file d\'attente.');
        }

        NotificationHealthService::markSchedulerRun('notifications:cleanup');

        return self::SUCCESS;
    }
}
