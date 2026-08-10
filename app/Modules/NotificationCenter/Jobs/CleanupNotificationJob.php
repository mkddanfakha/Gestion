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

class CleanupNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $mode = 'expired',
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
                'archived' => $settings->deleteArchivedOlderThan(
                    $this->days ?? (int) config('notification-center.scheduler.delete_archived_after_days', 90)
                ),
                'orphans' => $settings->cleanupOrphanNotifications(),
                default => $settings->cleanupOlderThan($this->days),
            };

            NotificationLogger::info('Nettoyage notifications terminé', [
                'mode' => $this->mode,
                'count' => $count,
            ]);

            $settings->recordMonitoringMetric([
                'last_cleanup_at' => now()->toIso8601String(),
                'last_cleanup_count' => $count,
            ]);
        } catch (Throwable $e) {
            NotificationLogger::queueError('Échec nettoyage notifications', [
                'mode' => $this->mode,
            ], $e);

            throw $e;
        }
    }
}
