<?php

namespace App\Modules\NotificationCenter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'toasts_enabled',
        'sound_enabled',
        'browser_enabled',
        'critical_only',
        'hide_resolved',
        'sound_profile',
        'toast_position',
        'toast_durations',
        'sound_volume',
        'sound_profiles',
        'auto_mark_read_on_open',
        'grouping_enabled',
    ];

    protected function casts(): array
    {
        return [
            'toasts_enabled' => 'boolean',
            'sound_enabled' => 'boolean',
            'browser_enabled' => 'boolean',
            'critical_only' => 'boolean',
            'hide_resolved' => 'boolean',
            'auto_mark_read_on_open' => 'boolean',
            'grouping_enabled' => 'boolean',
            'toast_durations' => 'array',
            'sound_profiles' => 'array',
            'sound_volume' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        $model = config('notification-center.user_model');

        return $this->belongsTo($model);
    }
}
