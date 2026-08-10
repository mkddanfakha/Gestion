<?php

namespace App\Modules\NotificationCenter\Jobs;

use App\Modules\NotificationCenter\Services\NotificationSettingsService;
use App\Modules\NotificationCenter\Support\NotificationLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ArchiveNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $mode,
        public ?int $days = null,
    ) {
        $connection = config('notification-center.queue.connection');
        if ($connection) {
            $this->onConnection($connection);
        }

        $this->onQueue(config('notification-center.queue.name', 'notifications'));
    }

    public function handle(NotificationSettingsService $settings): void
    {
        try {
            $count = match ($this->mode) {
                'resolved' => $settings->archiveResolvedNotifications(
                    $this->days ?? (int) config('notification-center.scheduler.archive_resolved_after_days', 30)
                ),
                'old_active' => $settings->archiveOldNotifications(
                    $this->days ?? 30
                ),
                default => 0,
            };

            NotificationLogger::info('Archivage notifications terminé', [
                'mode' => $this->mode,
                'count' => $count,
            ]);

            $settings->recordMonitoringMetric([
                'last_archive_at' => now()->toIso8601String(),
                'last_archive_count' => $count,
            ]);
        } catch (Throwable $e) {
            NotificationLogger::queueError('Échec archivage notifications', [
                'mode' => $this->mode,
            ], $e);

            throw $e;
        }
    }
}
