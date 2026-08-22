<?php

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\StockService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function stockTestCategory(): Category
{
    return Category::create([
        'name' => 'Catégorie stock '.uniqid(),
        'color' => '#3B82F6',
    ]);
}

function stockTestCompany(array $overrides = []): Company
{
    return Company::create(array_merge([
        'name' => 'Entreprise stock '.uniqid(),
        'email' => uniqid().'@example.test',
    ], $overrides));
}

function stockTestProduct(int $stockQuantity = 10, ?Company $company = null): array
{
    $company ??= stockTestCompany();
    $store = Store::ensureDefaultForCompany($company);
    $product = Product::create([
        'name' => 'Produit stock '.uniqid(),
        'sku' => 'ST'.strtoupper(substr(uniqid(), -4)),
        'price' => 1000,
        'cost_price' => 500,
        'stock_quantity' => $stockQuantity,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => stockTestCategory()->id,
        'is_active' => true,
    ]);

    $productStock = ProductStock::create([
        'product_id' => $product->id,
        'store_id' => $store->id,
        'quantity' => $stockQuantity,
    ]);

    return compact('company', 'store', 'product', 'productStock');
}

beforeEach(function () {
    $this->stockService = app(StockService::class);
});

test('increase adds quantity to product stock', function () {
    ['store' => $store, 'product' => $product, 'productStock' => $productStock] = stockTestProduct(10);

    $this->stockService->increase(
        $product,
        $store,
        5,
        StockMovementType::Purchase,
    );

    expect($productStock->fresh()->quantity)->toBe(15);
});

test('decrease subtracts quantity from product stock', function () {
    ['store' => $store, 'product' => $product, 'productStock' => $productStock] = stockTestProduct(10);

    $this->stockService->decrease(
        $product,
        $store,
        3,
        StockMovementType::Sale,
    );

    expect($productStock->fresh()->quantity)->toBe(7);
});

test('adjust creates an immutable stock movement journal entry', function () {
    ['store' => $store, 'product' => $product] = stockTestProduct(10);

    $movement = $this->stockService->decrease(
        $product,
        $store,
        3,
        StockMovementType::Sale,
        reason: 'Vente test',
    );

    expect($movement->quantity)->toBe(-3)
        ->and($movement->quantity_before)->toBe(10)
        ->and($movement->quantity_after)->toBe(7)
        ->and($movement->type)->toBe(StockMovementType::Sale)
        ->and($movement->reason)->toBe('Vente test');
});

test('insufficient stock throws and leaves product stock unchanged with no movement', function () {
    ['store' => $store, 'product' => $product, 'productStock' => $productStock] = stockTestProduct(5);

    expect(fn () => $this->stockService->decrease(
        $product,
        $store,
        6,
        StockMovementType::Sale,
    ))->toThrow(InsufficientStockException::class, 'Stock insuffisant');

    expect($productStock->fresh()->quantity)->toBe(5)
        ->and(StockMovement::query()->count())->toBe(0);
});

test('insufficient stock exception exposes diagnostic fields', function () {
    ['store' => $store, 'product' => $product] = stockTestProduct(5);

    try {
        $this->stockService->decrease($product, $store, 6, StockMovementType::Sale);
        expect(false)->toBeTrue('Expected InsufficientStockException');
    } catch (InsufficientStockException $exception) {
        expect($exception->productId)->toBe($product->id)
            ->and($exception->storeId)->toBe($store->id)
            ->and($exception->requestedQuantity)->toBe(6)
            ->and($exception->availableQuantity)->toBe(5);
    }
});

test('transaction rolls back when movement creation fails', function () {
    ['store' => $store, 'product' => $product, 'productStock' => $productStock] = stockTestProduct(10);

    $dispatcher = StockMovement::getEventDispatcher();
    StockMovement::creating(function (): void {
        throw new \RuntimeException('Simulated movement failure');
    });

    try {
        expect(fn () => $this->stockService->increase(
            $product,
            $store,
            5,
            StockMovementType::Purchase,
        ))->toThrow(\RuntimeException::class);

        expect($productStock->fresh()->quantity)->toBe(10)
            ->and(StockMovement::query()->count())->toBe(0);
    } finally {
        StockMovement::setEventDispatcher($dispatcher);
    }
});

test('stock service rejects store from another company', function () {
    $companyA = stockTestCompany(['name' => 'Entreprise A']);
    stockTestCompany(['name' => 'Entreprise B']);

    $storeA = Store::ensureDefaultForCompany($companyA);
    $storeB = Store::query()->where('company_id', '!=', $companyA->id)->firstOrFail();

    ['product' => $product] = stockTestProduct(10, $companyA);

    expect(fn () => $this->stockService->increase(
        $product,
        $storeB,
        1,
        StockMovementType::ManualAdjustment,
    ))->toThrow(InvalidArgumentException::class);
});

test('reference is stored on stock movement', function () {
    ['store' => $store, 'product' => $product] = stockTestProduct(10);
    $user = User::factory()->create();

    $movement = $this->stockService->increase(
        $product,
        $store,
        2,
        StockMovementType::Purchase,
        reference: $user,
    );

    expect($movement->reference_type)->toBe($user->getMorphClass())
        ->and($movement->reference_id)->toBe($user->id);
});

test('user id is stored on stock movement', function () {
    ['store' => $store, 'product' => $product] = stockTestProduct(10);
    $user = User::factory()->create();

    $movement = $this->stockService->increase(
        $product,
        $store,
        1,
        StockMovementType::ManualAdjustment,
        user: $user,
    );

    expect($movement->user_id)->toBe($user->id);
});

test('metadata json is stored on stock movement', function () {
    ['store' => $store, 'product' => $product] = stockTestProduct(10);

    $movement = $this->stockService->increase(
        $product,
        $store,
        1,
        StockMovementType::ManualAdjustment,
        metadata: ['origin' => 'unit-test', 'batch' => 'A1'],
    );

    expect($movement->metadata)->toBe([
        'origin' => 'unit-test',
        'batch' => 'A1',
    ]);
});

test('opening balance movement is created from existing product stock', function () {
    ['store' => $store, 'product' => $product, 'productStock' => $productStock] = stockTestProduct(25);

    $created = StockMovement::createOpeningBalancesFromExistingStocks();

    $movement = StockMovement::query()
        ->where('product_id', $product->id)
        ->where('store_id', $store->id)
        ->where('type', StockMovementType::OpeningBalance->value)
        ->first();

    expect($created)->toBe(1)
        ->and($movement)->not->toBeNull()
        ->and($movement->quantity_before)->toBe(0)
        ->and($movement->quantity)->toBe(25)
        ->and($movement->quantity_after)->toBe(25)
        ->and($movement->metadata)->toMatchArray([
            'source' => 'products.stock_quantity',
            'migration' => 'phase_1_inventory_foundation',
        ])
        ->and($productStock->fresh()->quantity)->toBe(25);
});

test('opening balance creation is idempotent', function () {
    $company = stockTestCompany();
    stockTestProduct(12, $company);
    stockTestProduct(8, $company);

    $firstRun = StockMovement::createOpeningBalancesFromExistingStocks();
    $secondRun = StockMovement::createOpeningBalancesFromExistingStocks();

    expect($firstRun)->toBe(2)
        ->and($secondRun)->toBe(0)
        ->and(StockMovement::query()->where('type', StockMovementType::OpeningBalance->value)->count())->toBe(2);
});

test('sequential decreases enforce strict stock without lost updates', function () {
    ['store' => $store, 'product' => $product, 'productStock' => $productStock] = stockTestProduct(5);

    $this->stockService->decrease($product, $store, 3, StockMovementType::Sale);

    expect($productStock->fresh()->quantity)->toBe(2);

    expect(fn () => $this->stockService->decrease(
        $product,
        $store,
        3,
        StockMovementType::Sale,
    ))->toThrow(InsufficientStockException::class);

    expect($productStock->fresh()->quantity)->toBe(2)
        ->and(StockMovement::query()->count())->toBe(1);
});

test('get stock returns current product stock quantity', function () {
    ['store' => $store, 'product' => $product] = stockTestProduct(14);

    expect($this->stockService->getStock($product, $store))->toBe(14);
});

test('set adjusts product stock to target quantity', function () {
    ['store' => $store, 'product' => $product, 'productStock' => $productStock] = stockTestProduct(10);

    $movement = $this->stockService->set(
        $product,
        $store,
        18,
        StockMovementType::InventoryAdjustment,
    );

    expect($productStock->fresh()->quantity)->toBe(18)
        ->and($movement->quantity)->toBe(8)
        ->and($movement->quantity_before)->toBe(10)
        ->and($movement->quantity_after)->toBe(18);
});

test('stock movement cannot be updated or deleted', function () {
    ['store' => $store, 'product' => $product] = stockTestProduct(10);

    $movement = $this->stockService->increase(
        $product,
        $store,
        1,
        StockMovementType::ManualAdjustment,
    );

    expect($movement->update(['reason' => 'changed']))->toBeFalse()
        ->and($movement->delete())->toBeFalse();
});
