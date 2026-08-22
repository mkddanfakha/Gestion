<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Support\Collection;

/**
 * Verrouillage déterministe Product → ProductStock (product_id ASC).
 *
 * Aligné avec SaleStockService et PurchaseOrderDeliveryService pour réduire
 * le risque de deadlock lors d'opérations stock concurrentes.
 */
class StockLockOrdering
{
    /**
     * Verrouille Product puis ProductStock pour un product_id donné.
     *
     * @return array{product: Product, productStock: ProductStock}
     */
    public function lockProductAndStock(int $productId, int $storeId): array
    {
        $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();

        $productStock = ProductStock::query()
            ->where('product_id', $productId)
            ->where('store_id', $storeId)
            ->lockForUpdate()
            ->firstOrFail();

        return [
            'product' => $product,
            'productStock' => $productStock,
        ];
    }

    /**
     * Verrouille plusieurs produits dans l'ordre product_id ASC (Product puis ProductStock par id).
     *
     * @param  list<int>  $productIds
     * @return array{
     *     products: Collection<int, Product>,
     *     productStocks: Collection<int, ProductStock>
     * }
     */
    public function lockManyProductsAndStocks(array $productIds, int $storeId): array
    {
        $sortedIds = array_values(array_unique($productIds));
        sort($sortedIds, SORT_NUMERIC);

        $products = collect();
        $productStocks = collect();

        foreach ($sortedIds as $productId) {
            $locked = $this->lockProductAndStock((int) $productId, $storeId);
            $products->put((int) $productId, $locked['product']);
            $productStocks->put((int) $productId, $locked['productStock']);
        }

        return [
            'products' => $products,
            'productStocks' => $productStocks,
        ];
    }
}
