<?php

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use App\Services\StockLockOrdering;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function stockLockOrderStore(): Store
{
    Company::getInstance();

    return Company::getInstance()->defaultStore()->firstOrFail();
}

function stockLockOrderProduct(int $stock = 10): Product
{
    $store = stockLockOrderStore();
    $category = Category::create(['name' => 'Lock '.uniqid()]);

    $product = Product::create([
        'name' => 'Produit lock '.uniqid(),
        'sku' => 'LK'.random_int(1000, 9999),
        'price' => 1000,
        'stock_quantity' => $stock,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    ProductStock::query()->updateOrCreate(
        ['product_id' => $product->id, 'store_id' => $store->id],
        ['quantity' => $stock],
    );

    return $product;
}

/**
 * Stratégie retenue (Phase 4G-1) : intercalée par product_id ASC —
 * Product 1 → ProductStock 1 → Product 2 → ProductStock 2 → …
 * Alignée avec SaleStockService (Product puis ProductStock par produit).
 */
test('stock lock ordering acquires product before product stock per ascending product id', function () {
    $store = stockLockOrderStore();
    $productA = stockLockOrderProduct();
    $productB = stockLockOrderProduct();
    $productC = stockLockOrderProduct();

    $sortedIds = collect([$productA->id, $productB->id, $productC->id])->sort()->values()->all();

    $ordering = new class extends StockLockOrdering {
        /** @var list<int> */
        public array $recordedProductIds = [];

        public function lockProductAndStock(int $productId, int $storeId): array
        {
            $this->recordedProductIds[] = $productId;

            return parent::lockProductAndStock($productId, $storeId);
        }
    };

    DB::transaction(function () use ($ordering, $productC, $productA, $productB, $store): void {
        $ordering->lockManyProductsAndStocks(
            [$productC->id, $productA->id, $productB->id],
            $store->id,
        );
    });

    expect($ordering->recordedProductIds)->toBe($sortedIds);
});

test('inventory application service uses stock lock ordering dependency', function () {
    $reflection = new ReflectionClass(App\Services\InventoryApplicationService::class);
    $constructor = $reflection->getConstructor();
    $parameters = collect($constructor->getParameters())->pluck('name')->all();

    expect($parameters)->toContain('stockLockOrdering');
});
