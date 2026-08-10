<?php

namespace App\Modules\NotificationCenter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface AudienceResolverInterface
{
    /**
     * Résout les IDs utilisateurs cibles pour un type de notification.
     *
     * @return list<int>
     */
    public function resolveUserIds(string $type, ?int $ownerUserId = null): array;

    public function canReceiveType(Authenticatable $user, string $capability): bool;
}
