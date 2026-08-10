<?php

namespace App\Modules\NotificationCenter\Jobs;

use App\Modules\NotificationCenter\Contracts\RealtimeProviderInterface;
use App\Modules\NotificationCenter\Services\NotificationSettingsService;
use App\Modules\NotificationCenter\Support\NotificationLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
        public int $userId,
    ) {
        $connection = config('notification-center.queue.connection');
        if ($connection) {
            $this->onConnection($connection);
        }

        $queue = config('notification-center.queue.name', 'notifications');
        $this->onQueue($queue);
    }

    public function handle(
        RealtimeProviderInterface $realtimeProvider,
        NotificationSettingsService $settings,
    ): void {
        if (! $settings->isRealtimeEnabled()) {
            return;
        }

        $startedAt = microtime(true);

        try {
            $realtimeProvider->broadcast($this->payload, $this->userId);

            $settings->touchRealtimeMeta([
                'last_event_at' => now()->toIso8601String(),
                'last_event_type' => $this->payload['type'] ?? null,
            ]);

            $settings->recordMonitoringMetric([
                'last_notification_sent_at' => now()->toIso8601String(),
                'last_send_duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (Throwable $e) {
            NotificationLogger::queueError('Échec envoi notification temps réel', [
                'user_id' => $this->userId,
                'type' => $this->payload['type'] ?? null,
            ], $e);

            $settings->recordMonitoringMetric([
                'last_error_at' => now()->toIso8601String(),
                'last_error' => $e->getMessage(),
                'last_error_context' => 'queue_send',
            ]);

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        NotificationLogger::queueError('Job SendNotificationJob définitivement échoué', [
            'user_id' => $this->userId,
        ], $exception);
    }
}
