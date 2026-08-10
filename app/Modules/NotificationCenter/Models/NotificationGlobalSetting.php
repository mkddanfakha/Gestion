<?php

namespace App\Modules\NotificationCenter\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationGlobalSetting extends Model
{
    protected $fillable = [
        'enabled',
        'realtime_enabled',
        'browser_enabled',
        'sound_enabled',
        'toasts_enabled',
        'badge_enabled',
        'grouping_enabled',
        'auto_mark_read_on_open',
        'default_sound',
        'maintenance_cleanup_days',
        'realtime_meta',
        'monitoring_meta',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'realtime_enabled' => 'boolean',
            'browser_enabled' => 'boolean',
            'sound_enabled' => 'boolean',
            'toasts_enabled' => 'boolean',
            'badge_enabled' => 'boolean',
            'grouping_enabled' => 'boolean',
            'auto_mark_read_on_open' => 'boolean',
            'realtime_meta' => 'array',
            'monitoring_meta' => 'array',
        ];
    }
}
