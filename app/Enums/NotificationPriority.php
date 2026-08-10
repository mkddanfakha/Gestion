<?php

namespace App\Enums;

enum NotificationPriority: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Info = 'info';

    public function label(): string
    {
        return config("notifications.priorities.{$this->value}.label", $this->value);
    }

    public function color(): string
    {
        return config("notifications.priorities.{$this->value}.color", 'secondary');
    }

    public function icon(): string
    {
        return config("notifications.priorities.{$this->value}.icon", 'bi-bell');
    }

    public function isCritical(): bool
    {
        return $this === self::Critical;
    }

    public function isWarning(): bool
    {
        return $this === self::Warning;
    }

    public function isInfo(): bool
    {
        return $this === self::Info;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
