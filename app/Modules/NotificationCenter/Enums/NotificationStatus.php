<?php

namespace App\Modules\NotificationCenter\Enums;

enum NotificationStatus: string
{
    case Active = 'active';
    case Resolved = 'resolved';
    case Archived = 'archived';

    public function label(): string
    {
        return config("notification-center.statuses.{$this->value}.label", $this->value);
    }
}
