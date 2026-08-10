<?php

namespace App\Modules\NotificationCenter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Enrichit les alertes groupées avec les détails des entités concernées (produits, ventes…).
 * Implémenté par chaque application MKD-Pro.
 */
interface GroupedPreviewProviderInterface
{
    /**
     * @param  list<int|string>  $entityIds
     * @return list<array<string, mixed>>
     */
    public function buildPreviews(string $type, array $entityIds, ?Authenticatable $user = null): array;
}
