<?php

namespace App\Models;

use App\Enums\NotificationAudience;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Modules\NotificationCenter\Models\Notification as ModuleNotification;

class Notification extends ModuleNotification
{
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'priority' => NotificationPriority::class,
            'status' => NotificationStatus::class,
            'audience' => NotificationAudience::class,
            'read_at' => 'datetime',
            'resolved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
