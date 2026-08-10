<?php

namespace App\Modules\NotificationCenter\Enums;

enum NotificationAudience: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Seller = 'seller';
    case Custom = 'custom';

    public function label(): string
    {
        return config("notification-center.audiences.{$this->value}.label", $this->value);
    }

    public function userRole(): ?string
    {
        return config("notification-center.audiences.{$this->value}.role");
    }
}
