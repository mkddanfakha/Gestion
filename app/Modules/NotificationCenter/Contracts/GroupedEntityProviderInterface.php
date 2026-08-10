<?php

namespace App\Modules\NotificationCenter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Fournit les IDs d'entités pour les alertes groupées.
 * Implémenté par chaque application MKD-Pro (Gestion, Hôtel, CRM…).
 */
interface GroupedEntityProviderInterface
{
    /**
     * @return list<int|string>
     */
    public function getEntityIdsForType(string $type, ?Authenticatable $user = null): array;

    /**
     * @return list<int|string>
     */
    public function getLegacyActiveEntityIds(string $legacyType, ?Authenticatable $user = null): array;
}
