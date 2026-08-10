<?php

namespace App\Modules\NotificationCenter\Enums;

enum NotificationPriority: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Info = 'info';

    public function label(): string
    {
        return config("notification-center.priorities.{$this->value}.label", $this->value);
    }

    public function color(): string
    {
        return config("notification-center.priorities.{$this->value}.color", 'secondary');
    }

    public function icon(): string
    {
        return config("notification-center.priorities.{$this->value}.icon", 'bi-bell');
    }
}
