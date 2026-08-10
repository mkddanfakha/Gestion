<?php

namespace App\Modules\NotificationCenter\Services;

use App\Modules\NotificationCenter\Models\Notification;
use App\Modules\NotificationCenter\Models\NotificationGlobalSetting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

class NotificationHealthService
{
    public function __construct(
        protected NotificationSettingsService $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function check(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'settings' => $this->checkSettings(),
            'cache' => $this->checkCache(),
            'queue' => $this->checkQueue(),
            'scheduler' => $this->checkScheduler(),
            'pusher' => $this->checkPusher(),
        ];

        $status = 'ok';
        foreach ($checks as $check) {
            if (($check['status'] ?? '') === 'fail') {
                $status = 'fail';
                break;
            }
        }

        if ($status === 'ok') {
            foreach ($checks as $check) {
                if (($check['status'] ?? '') === 'warn') {
                    $status = 'warn';
                    break;
                }
            }
        }

        return [
            'status' => $status,
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkDatabase(): array
    {
        try {
            Notification::query()->limit(1)->exists();

            return ['status' => 'ok', 'message' => 'Connexion base de données opérationnelle'];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkSettings(): array
    {
        try {
            if (! Schema::hasTable('notification_global_settings')) {
                return ['status' => 'fail', 'message' => 'Table notification_global_settings absente'];
            }

            $this->settings->ensureDefaults();

            return ['status' => 'ok', 'message' => 'Paramètres chargés'];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkCache(): array
    {
        try {
            $key = 'notification-center.health.'.uniqid('', true);
            Cache::put($key, 'ok', 10);
            $value = Cache::get($key);
            Cache::forget($key);

            if ($value !== 'ok') {
                return ['status' => 'fail', 'message' => 'Cache non fonctionnel'];
            }

            return ['status' => 'ok', 'message' => 'Cache opérationnel', 'driver' => config('cache.default')];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkQueue(): array
    {
        try {
            $connection = config('notification-center.queue.connection') ?? config('queue.default');
            $size = Queue::connection($connection)->size(config('notification-center.queue.name', 'notifications'));

            return [
                'status' => 'ok',
                'message' => 'Queue accessible',
                'connection' => $connection,
                'pending_jobs' => $size,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'warn', 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkScheduler(): array
    {
        $lastRun = Cache::get('notification-center.scheduler.last_run');

        return [
            'status' => $lastRun ? 'ok' : 'warn',
            'message' => $lastRun ? 'Scheduler actif' : 'Aucune exécution enregistrée',
            'last_run' => $lastRun,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkPusher(): array
    {
        $configured = ! empty(config('broadcasting.connections.pusher.key'));
        $global = NotificationGlobalSetting::query()->first();
        $meta = $global?->realtime_meta ?? [];

        if (! $configured) {
            return [
                'status' => 'warn',
                'message' => 'Pusher non configuré',
                'last_event_at' => $meta['last_event_at'] ?? null,
            ];
        }

        return [
            'status' => 'ok',
            'message' => 'Pusher configuré',
            'driver' => config('notification-center.realtime.driver', 'pusher'),
            'last_event_at' => $meta['last_event_at'] ?? null,
            'last_event_type' => $meta['last_event_type'] ?? null,
        ];
    }

    public static function markSchedulerRun(string $command): void
    {
        Cache::put('notification-center.scheduler.last_run', [
            'command' => $command,
            'at' => now()->toIso8601String(),
        ], now()->addDays(7));
    }
}
