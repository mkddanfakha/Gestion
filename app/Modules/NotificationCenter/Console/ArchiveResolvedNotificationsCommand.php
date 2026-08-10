<?php

namespace App\Modules\NotificationCenter\Console;

use App\Modules\NotificationCenter\Jobs\ArchiveNotificationJob;
use App\Modules\NotificationCenter\Services\NotificationHealthService;
use App\Modules\NotificationCenter\Services\NotificationSettingsService;
use Illuminate\Console\Command;

class ArchiveResolvedNotificationsCommand extends Command
{
    protected $signature = 'notifications:archive-resolved {--days=} {--sync : Exécuter sans file d\'attente}';

    protected $description = 'Archive les notifications résolues depuis plus de X jours';

    public function handle(NotificationSettingsService $settings): int
    {
        $days = (int) ($this->option('days') ?: config('notification-center.scheduler.archive_resolved_after_days', 30));

        if ($this->option('sync') || ! config('notification-center.scheduler.use_queue_for_scheduled_tasks', true)) {
            $count = $settings->archiveResolvedNotifications($days);
            $this->info("{$count} notification(s) archivée(s).");
        } else {
            ArchiveNotificationJob::dispatch('resolved', $days);
            $this->info('Archivage planifié via la file d\'attente.');
        }

        NotificationHealthService::markSchedulerRun('notifications:archive-resolved');

        return self::SUCCESS;
    }
}
