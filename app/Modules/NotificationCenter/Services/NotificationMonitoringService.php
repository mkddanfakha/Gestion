<?php

namespace App\Modules\NotificationCenter\Services;

use App\Modules\NotificationCenter\Models\Notification;
use App\Modules\NotificationCenter\Models\NotificationGlobalSetting;
use Illuminate\Support\Facades\DB;

class NotificationMonitoringService
{
    public function __construct(
        protected NotificationSettingsService $settings,
        protected NotificationHealthService $health,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $stats = $this->settings->getMaintenanceStats();
        $global = NotificationGlobalSetting::query()->first();
        $monitoring = $global?->monitoring_meta ?? [];
        $realtime = $global?->realtime_meta ?? [];

        return [
            'stats' => $stats,
            'metrics' => [
                'avg_processing_ms' => $monitoring['avg_processing_ms'] ?? null,
                'avg_send_duration_ms' => $monitoring['avg_send_duration_ms'] ?? null,
                'last_notification_sent_at' => $monitoring['last_notification_sent_at'] ?? null,
                'last_error_at' => $monitoring['last_error_at'] ?? null,
                'last_error' => $monitoring['last_error'] ?? null,
                'last_archive_at' => $monitoring['last_archive_at'] ?? null,
                'last_cleanup_at' => $monitoring['last_cleanup_at'] ?? null,
            ],
            'realtime' => [
                'pusher_configured' => ! empty(config('broadcasting.connections.pusher.key')),
                'last_event_at' => $realtime['last_event_at'] ?? null,
                'last_event_type' => $realtime['last_event_type'] ?? null,
            ],
            'health' => $this->health->check(),
            'recent_critical' => Notification::query()
                ->where('priority', 'critical')
                ->where('status', 'active')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(['id', 'user_id', 'type', 'created_at'])
                ->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function performanceSnapshot(): array
    {
        $table = (new Notification)->getTable();

        return [
            'table_rows' => Notification::query()->count(),
            'table_size_mb' => $this->estimateTableSizeMb($table),
        ];
    }

    protected function estimateTableSizeMb(string $table): ?float
    {
        try {
            $driver = DB::getDriverName();

            if ($driver === 'mysql') {
                $database = DB::getDatabaseName();
                $result = DB::selectOne(
                    'SELECT (data_length + index_length) / 1024 / 1024 AS size_mb
                     FROM information_schema.tables
                     WHERE table_schema = ? AND table_name = ?',
                    [$database, $table]
                );

                return $result?->size_mb ? round((float) $result->size_mb, 2) : null;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
