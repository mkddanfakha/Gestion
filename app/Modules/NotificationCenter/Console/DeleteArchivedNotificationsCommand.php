<?php

namespace App\Modules\NotificationCenter\Console;

use App\Modules\NotificationCenter\Jobs\CleanupNotificationJob;
use App\Modules\NotificationCenter\Services\NotificationHealthService;
use App\Modules\NotificationCenter\Services\NotificationSettingsService;
use Illuminate\Console\Command;

class DeleteArchivedNotificationsCommand extends Command
{
    protected $signature = 'notifications:delete-archived {--days=} {--sync : Exécuter sans file d\'attente}';

    protected $description = 'Supprime définitivement les notifications archivées de plus de X jours';

    public function handle(NotificationSettingsService $settings): int
    {
        $days = (int) ($this->option('days') ?: config('notification-center.scheduler.delete_archived_after_days', 90));

        if ($this->option('sync') || ! config('notification-center.scheduler.use_queue_for_scheduled_tasks', true)) {
            $count = $settings->deleteArchivedOlderThan($days);
            $this->info("{$count} notification(s) supprimée(s).");
        } else {
            CleanupNotificationJob::dispatch('archived', $days);
            $this->info('Suppression planifiée via la file d\'attente.');
        }

        NotificationHealthService::markSchedulerRun('notifications:delete-archived');

        return self::SUCCESS;
    }
}
