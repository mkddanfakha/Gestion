<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Initialisation explicite du stock MAIN lors de la création d'un produit.
 *
 * ProductStock = source de vérité ; products.stock_quantity = miroir legacy MAIN.
 */
class ProductStockInitializationService
{
    /**
     * Crée la ligne ProductStock MAIN et le mouvement opening_balance si stock > 0.
     *
     * @throws InvalidArgumentException si le magasin principal est absent/inactif
     */
    public function initializeMainStock(Product $product, int $initialQuantity, ?User $user = null): ProductStock
    {
        $store = $this->resolveMainStore();
        $initialQuantity = max(0, $initialQuantity);

        if ((int) $product->stock_quantity !== $initialQuantity) {
            $product->update(['stock_quantity' => $initialQuantity]);
        }

        if (ProductStock::query()
            ->where('product_id', $product->id)
            ->where('store_id', $store->id)
            ->exists()) {
            throw new InvalidArgumentException(
                "Une ligne ProductStock MAIN existe déjà pour le produit #{$product->id}.",
            );
        }

        $productStock = ProductStock::query()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => $initialQuantity,
        ]);

        if ($initialQuantity > 0) {
            $this->createOpeningBalanceMovement($product, $store, $initialQuantity, $user);
        }

        return $productStock;
    }

    public function resolveMainStore(): Store
    {
        $company = Company::getInstance();
        $store = $company->defaultStore;

        if (! $store) {
            throw new InvalidArgumentException(
                'Aucun magasin principal configuré pour l\'entreprise. Impossible d\'initialiser le stock produit.',
            );
        }

        if (! $store->is_active) {
            throw new InvalidArgumentException('Le magasin principal est inactif.');
        }

        return $store;
    }

    protected function createOpeningBalanceMovement(
        Product $product,
        Store $store,
        int $quantity,
        ?User $user = null,
    ): StockMovement {
        if (StockMovement::hasOpeningBalance($store->company_id, $store->id, $product->id)) {
            throw new InvalidArgumentException(
                "Un solde d'ouverture existe déjà pour le produit #{$product->id} sur le magasin MAIN.",
            );
        }

        return StockMovement::create([
            'company_id' => $store->company_id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'type' => StockMovementType::OpeningBalance,
            'quantity' => $quantity,
            'quantity_before' => 0,
            'quantity_after' => $quantity,
            'reference_type' => $product->getMorphClass(),
            'reference_id' => $product->id,
            'reason' => 'Stock initial à la création du produit',
            'user_id' => ($user ?? Auth::user())?->id,
            'metadata' => [
                'source' => 'product_creation',
                'initial_stock' => true,
            ],
        ]);
    }
}
