<?php

namespace App\Integrations\NotificationCenter;

use App\Models\Product;
use App\Models\Sale;
use App\Modules\NotificationCenter\Contracts\GroupedEntityProviderInterface;
use App\Modules\NotificationCenter\Support\NotificationTypeConfig;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Implémentation métier MKD-Pro Gestion — alertes groupées produits/ventes.
 * Remplacer ce fichier dans chaque projet MKD-Pro (Hôtel, CRM…).
 */
class GestionGroupedEntityProvider implements GroupedEntityProviderInterface
{
    public function getEntityIdsForType(string $type, ?Authenticatable $user = null): array
    {
        return match ($type) {
            'stock_out' => Product::query()
                ->where('stock_quantity', '<=', 0)
                ->where('is_active', true)
                ->pluck('id')
                ->all(),

            'low_stock' => Product::query()
                ->whereRaw('stock_quantity > 0 AND stock_quantity <= min_stock_level')
                ->where('is_active', true)
                ->pluck('id')
                ->all(),

            'product_expired' => Product::query()
                ->whereNotNull('expiration_date')
                ->where('is_active', true)
                ->orderByDesc('expiration_date')
                ->get()
                ->filter(fn (Product $product) => $product->isExpired())
                ->pluck('id')
                ->all(),

            'product_expiring' => Product::query()
                ->whereNotNull('expiration_date')
                ->where('is_active', true)
                ->orderBy('expiration_date')
                ->get()
                ->filter(fn (Product $product) => $product->isExpiringSoon() && ! $product->isExpired())
                ->pluck('id')
                ->all(),

            'invoice_due' => Sale::visibleTo($user instanceof \App\Models\User ? $user : null)
                ->whereNotNull('due_date')
                ->whereDate('due_date', now()->toDateString())
                ->where('payment_status', '!=', 'paid')
                ->pluck('id')
                ->all(),

            default => [],
        };
    }

    public function getLegacyActiveEntityIds(string $legacyType, ?Authenticatable $user = null): array
    {
        $type = NotificationTypeConfig::fromLegacy($legacyType) ?? $legacyType;

        if (! in_array($type, ['stock_out', 'low_stock', 'product_expired', 'product_expiring', 'invoice_due'], true)) {
            return [];
        }

        if ($legacyType === 'low_stock') {
            return Product::query()
                ->whereRaw('stock_quantity <= min_stock_level')
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();
        }

        return $this->getEntityIdsForType($type, $user);
    }
}
