<?php

namespace App\Enums;

enum NotificationStatus: string
{
    case Active = 'active';
    case Resolved = 'resolved';
    case Archived = 'archived';

    public function label(): string
    {
        return config("notifications.statuses.{$this->value}.label", $this->value);
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function isResolved(): bool
    {
        return $this === self::Resolved;
    }

    public function isArchived(): bool
    {
        return $this === self::Archived;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
