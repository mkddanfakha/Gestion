<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\ProductStockNotFoundException;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockService
{
    /**
     * Ajuste le stock matérialisé et enregistre un mouvement immuable.
     *
     * @param  int  $quantity  Delta signé (+ entrée, - sortie)
     */
    public function adjust(
        Product|int $product,
        Store|int $store,
        int $quantity,
        StockMovementType $type,
        ?User $user = null,
        ?string $reason = null,
        ?Model $reference = null,
        array $metadata = [],
    ): StockMovement {
        if ($quantity === 0) {
            throw new InvalidArgumentException('Le delta de stock ne peut pas être nul.');
        }

        $productModel = $this->resolveProduct($product);
        $storeModel = $this->resolveStore($store);
        $this->assertCompanyCompatibility($productModel, $storeModel);

        return DB::transaction(function () use (
            $productModel,
            $storeModel,
            $quantity,
            $type,
            $user,
            $reason,
            $reference,
            $metadata,
        ): StockMovement {
            $productStock = ProductStock::query()
                ->where('product_id', $productModel->id)
                ->where('store_id', $storeModel->id)
                ->lockForUpdate()
                ->first();

            if (! $productStock) {
                throw new ProductStockNotFoundException($productModel->id, $storeModel->id);
            }

            $quantityBefore = (int) $productStock->quantity;
            $quantityAfter = $quantityBefore + $quantity;

            if ($quantityAfter < 0) {
                throw new InsufficientStockException(
                    productId: $productModel->id,
                    storeId: $storeModel->id,
                    requestedQuantity: abs($quantity),
                    availableQuantity: $quantityBefore,
                );
            }

            $productStock->update(['quantity' => $quantityAfter]);

            return StockMovement::create([
                'company_id' => $storeModel->company_id,
                'store_id' => $storeModel->id,
                'product_id' => $productModel->id,
                'type' => $type,
                'quantity' => $quantity,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'reason' => $reason,
                'user_id' => ($user ?? Auth::user())?->id,
                'metadata' => $metadata !== [] ? $metadata : null,
            ]);
        });
    }

    public function increase(
        Product|int $product,
        Store|int $store,
        int $quantity,
        StockMovementType $type,
        ?User $user = null,
        ?string $reason = null,
        ?Model $reference = null,
        array $metadata = [],
    ): StockMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('La quantité entrante doit être strictement positive.');
        }

        return $this->adjust($product, $store, $quantity, $type, $user, $reason, $reference, $metadata);
    }

    public function decrease(
        Product|int $product,
        Store|int $store,
        int $quantity,
        StockMovementType $type,
        ?User $user = null,
        ?string $reason = null,
        ?Model $reference = null,
        array $metadata = [],
    ): StockMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('La quantité sortante doit être strictement positive.');
        }

        return $this->adjust($product, $store, -$quantity, $type, $user, $reason, $reference, $metadata);
    }

    public function set(
        Product|int $product,
        Store|int $store,
        int $targetQuantity,
        StockMovementType $type,
        ?User $user = null,
        ?string $reason = null,
        ?Model $reference = null,
        array $metadata = [],
    ): StockMovement {
        if ($targetQuantity < 0) {
            throw new InvalidArgumentException('La quantité cible ne peut pas être négative.');
        }

        $currentQuantity = $this->getStock($product, $store);
        $delta = $targetQuantity - $currentQuantity;

        if ($delta === 0) {
            throw new InvalidArgumentException('La quantité cible est identique au stock actuel.');
        }

        return $this->adjust($product, $store, $delta, $type, $user, $reason, $reference, $metadata);
    }

    public function getStock(Product|int $product, Store|int $store): int
    {
        $productModel = $this->resolveProduct($product);
        $storeModel = $this->resolveStore($store);
        $this->assertCompanyCompatibility($productModel, $storeModel);

        $productStock = ProductStock::query()
            ->where('product_id', $productModel->id)
            ->where('store_id', $storeModel->id)
            ->first();

        if (! $productStock) {
            throw new ProductStockNotFoundException($productModel->id, $storeModel->id);
        }

        return (int) $productStock->quantity;
    }

    protected function resolveProduct(Product|int $product): Product
    {
        if ($product instanceof Product) {
            return $product;
        }

        return Product::query()->findOrFail($product);
    }

    protected function resolveStore(Store|int $store): Store
    {
        if ($store instanceof Store) {
            return $store;
        }

        return Store::query()->findOrFail($store);
    }

    protected function assertCompanyCompatibility(Product $product, Store $store): void
    {
        $primaryCompany = Company::getInstance();

        if ($store->company_id !== $primaryCompany->id) {
            throw new InvalidArgumentException(
                'Ce magasin appartient à une autre entreprise que l\'entreprise primaire de l\'installation.',
            );
        }
    }
}
