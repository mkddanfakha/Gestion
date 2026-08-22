<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\ProductStockNotFoundException;
use App\Models\Company;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Store;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Orchestration stock pour les ventes via StockService (magasin MAIN).
 *
 * Cycle de vie :
 * - création / conversion devis : applySaleCreation() → mouvements sale
 * - modification : applyNetSoldDelta() par produit (sale ou sale_cancel selon delta)
 * - suppression : applySaleCancellation() → mouvements sale_cancel
 *
 * products.stock_quantity est resynchronisé explicitement après chaque mouvement (miroir legacy).
 */
class SaleStockService
{
    public function __construct(
        protected StockService $stockService,
    ) {}

    public function resolveMainStore(): Store
    {
        $company = Company::getInstance();
        $store = $company->defaultStore;

        if (! $store) {
            throw new InvalidArgumentException(
                'Aucun magasin principal configuré pour l\'entreprise. Impossible d\'appliquer la vente.',
            );
        }

        if (! $store->is_active) {
            throw new InvalidArgumentException('Le magasin principal est inactif.');
        }

        return $store;
    }

    public function getAvailableStock(int $productId): int
    {
        return $this->stockService->getStock($productId, $this->resolveMainStore());
    }

    /**
     * @param  array<int, int>  $requestedQuantities  product_id => quantité totale demandée
     * @param  array<int, int>  $restoredQuantities  product_id => quantité déjà vendue (restituée lors d'une modification)
     * @return array<int, string>  product_id => message d'erreur
     */
    public function validateStockAvailability(
        array $requestedQuantities,
        array $restoredQuantities = [],
    ): array {
        $errors = [];

        foreach ($requestedQuantities as $productId => $requestedQty) {
            try {
                $available = $this->getAvailableStock((int) $productId);
                $restored = $restoredQuantities[$productId] ?? 0;
                $effectiveAvailable = $available + $restored;

                if ($effectiveAvailable < $requestedQty) {
                    $errors[$productId] = config('notifications.contextual_messages.insufficient_stock');
                }
            } catch (ProductStockNotFoundException) {
                $errors[$productId] = config('notifications.contextual_messages.insufficient_stock');
            }
        }

        return $errors;
    }

    /**
     * @param  array<int, int>  $productQuantities  product_id => quantité vendue
     */
    public function applySaleCreation(Sale $sale, array $productQuantities): void
    {
        ksort($productQuantities);

        foreach ($productQuantities as $productId => $quantity) {
            if ($quantity > 0) {
                $this->decreaseForSale($sale, (int) $productId, (int) $quantity);
            }
        }
    }

    /**
     * Delta net de quantité vendue : newSoldQty - oldSoldQty.
     * Positif → sale ; négatif → sale_cancel ; zéro → aucun mouvement.
     */
    public function applyNetSoldDelta(Sale $sale, int $productId, int $oldSoldQty, int $newSoldQty): void
    {
        $netDelta = $newSoldQty - $oldSoldQty;

        if ($netDelta === 0) {
            return;
        }

        if ($netDelta > 0) {
            $this->decreaseForSale($sale, $productId, $netDelta);
        } else {
            $this->restoreForSale($sale, $productId, abs($netDelta));
        }
    }

    /**
     * @param  array<int, int>  $oldQuantities  product_id => quantité vendue avant modification
     * @param  array<int, int>  $newQuantities  product_id => quantité vendue après modification
     */
    public function applySaleUpdateDeltas(Sale $sale, array $oldQuantities, array $newQuantities): void
    {
        $allProductIds = array_unique(array_merge(array_keys($oldQuantities), array_keys($newQuantities)));
        sort($allProductIds);

        foreach ($allProductIds as $productId) {
            $oldQty = $oldQuantities[$productId] ?? 0;
            $newQty = $newQuantities[$productId] ?? 0;

            $this->applyNetSoldDelta($sale, (int) $productId, $oldQty, $newQty);
        }
    }

    /**
     * @param  array<int, int>  $productQuantities  product_id => quantité à restituer
     */
    public function applySaleCancellation(Sale $sale, array $productQuantities): void
    {
        ksort($productQuantities);

        foreach ($productQuantities as $productId => $quantity) {
            if ($quantity > 0) {
                $this->restoreForSale($sale, (int) $productId, (int) $quantity);
            }
        }
    }

    /**
     * @param  list<array{product_id: int|string, quantity: int|string}>  $items
     * @return array<int, int>
     */
    public static function aggregateQuantitiesByProduct(array $items): array
    {
        $quantities = [];

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $quantities[$productId] = ($quantities[$productId] ?? 0) + (int) $item['quantity'];
        }

        return $quantities;
    }

    /**
     * @param  iterable<int, object{product_id: int, quantity: int}>  $saleItems
     * @return array<int, int>
     */
    public static function aggregateFromSaleItems(iterable $saleItems): array
    {
        $quantities = [];

        foreach ($saleItems as $item) {
            $productId = (int) $item->product_id;
            $quantities[$productId] = ($quantities[$productId] ?? 0) + (int) $item->quantity;
        }

        return $quantities;
    }

    protected function decreaseForSale(Sale $sale, int $productId, int $quantity): void
    {
        $store = $this->resolveMainStore();
        $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();

        $reason = sprintf(
            'Vente %s (-%d)',
            $sale->sale_number,
            $quantity,
        );

        $this->stockService->decrease(
            $product,
            $store,
            $quantity,
            StockMovementType::Sale,
            user: Auth::user(),
            reason: $reason,
            reference: $sale,
        );

        $this->syncProductMirror($product, $store);
    }

    protected function restoreForSale(Sale $sale, int $productId, int $quantity): void
    {
        $store = $this->resolveMainStore();
        $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();

        $reason = sprintf(
            'Annulation vente %s (+%d)',
            $sale->sale_number,
            $quantity,
        );

        $this->stockService->increase(
            $product,
            $store,
            $quantity,
            StockMovementType::SaleCancel,
            user: Auth::user(),
            reason: $reason,
            reference: $sale,
        );

        $this->syncProductMirror($product, $store);
    }

    protected function syncProductMirror(Product $product, Store $store): void
    {
        $mirroredQuantity = $this->stockService->getStock($product, $store);
        $product->update(['stock_quantity' => $mirroredQuantity]);
    }
}
