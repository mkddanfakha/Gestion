<?php

namespace App\Enums;

enum NotificationAudience: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Seller = 'seller';
    case Custom = 'custom';

    public function label(): string
    {
        return config("notifications.audiences.{$this->value}.label", $this->value);
    }

    /**
     * Rôle utilisateur Laravel associé à cette audience.
     */
    public function userRole(): ?string
    {
        return config("notifications.audiences.{$this->value}.role");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
