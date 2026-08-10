<?php

namespace App\Modules\NotificationCenter\Console;

use App\Modules\NotificationCenter\Jobs\CleanupNotificationJob;
use App\Modules\NotificationCenter\Services\NotificationHealthService;
use App\Modules\NotificationCenter\Services\NotificationSettingsService;
use Illuminate\Console\Command;

class CleanupOrphanNotificationsCommand extends Command
{
    protected $signature = 'notifications:cleanup-orphans {--sync : Exécuter sans file d\'attente}';

    protected $description = 'Supprime les notifications orphelines (utilisateur inexistant)';

    public function handle(NotificationSettingsService $settings): int
    {
        if ($this->option('sync') || ! config('notification-center.scheduler.use_queue_for_scheduled_tasks', true)) {
            $count = $settings->cleanupOrphanNotifications();
            $this->info("{$count} notification(s) orpheline(s) supprimée(s).");
        } else {
            CleanupNotificationJob::dispatch('orphans');
            $this->info('Nettoyage orphelins planifié via la file d\'attente.');
        }

        NotificationHealthService::markSchedulerRun('notifications:cleanup-orphans');

        return self::SUCCESS;
    }
}
