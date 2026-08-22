<?php

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use Illuminate\Database\UniqueConstraintViolationException;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createTestCategory(): Category
{
    return Category::create([
        'name' => 'Catégorie test '.uniqid(),
        'color' => '#3B82F6',
    ]);
}

function createTestProduct(array $overrides = []): Product
{
    $category = $overrides['category_id'] ?? createTestCategory()->id;

    return Product::create(array_merge([
        'name' => 'Produit test '.uniqid(),
        'sku' => 'TS'.strtoupper(substr(uniqid(), -4)),
        'price' => 1000,
        'cost_price' => 500,
        'stock_quantity' => 12,
        'min_stock_level' => 2,
        'unit' => 'pièce',
        'category_id' => $category,
        'is_active' => true,
    ], $overrides));
}

test('a company can have a store', function () {
    $company = Company::create([
        'name' => 'Entreprise A',
        'email' => 'a@example.test',
    ]);

    $store = Store::ensureDefaultForCompany($company);

    expect($store)->toBeInstanceOf(Store::class)
        ->and($store->company_id)->toBe($company->id)
        ->and($store->code)->toBe(Store::CODE_MAIN)
        ->and($store->is_default)->toBeTrue();
});

test('main code is unique per company', function () {
    $companyA = Company::create(['name' => 'Entreprise A', 'email' => 'a@example.test']);
    $companyB = Company::create(['name' => 'Entreprise B', 'email' => 'b@example.test']);

    $storeA = Store::ensureDefaultForCompany($companyA);
    $storeB = Store::ensureDefaultForCompany($companyB);

    expect($storeA->code)->toBe(Store::CODE_MAIN)
        ->and($storeB->code)->toBe(Store::CODE_MAIN)
        ->and($storeA->company_id)->not->toBe($storeB->company_id);
});

test('each company automatically receives a main store on creation', function () {
    $company = Company::create([
        'name' => 'Nouvelle entreprise',
        'email' => 'new@example.test',
    ]);

    $store = Store::query()
        ->where('company_id', $company->id)
        ->where('code', Store::CODE_MAIN)
        ->first();

    expect($store)->not->toBeNull()
        ->and($store->is_default)->toBeTrue();
});

test('a company cannot have two default stores simultaneously', function () {
    $company = Company::create(['name' => 'Entreprise default', 'email' => 'd@example.test']);

    $mainStore = Store::ensureDefaultForCompany($company);

    $secondaryStore = Store::create([
        'company_id' => $company->id,
        'name' => 'Annexe',
        'code' => 'ANNEXE',
        'is_default' => true,
        'is_active' => true,
    ]);

    $mainStore->refresh();

    expect($mainStore->is_default)->toBeFalse()
        ->and($secondaryStore->fresh()->is_default)->toBeTrue();
});

test('product stock belongs to product and store', function () {
    $company = Company::create(['name' => 'Entreprise stock', 'email' => 'stock@example.test']);
    $store = Store::ensureDefaultForCompany($company);
    $product = createTestProduct(['stock_quantity' => 8]);

    $stock = ProductStock::create([
        'product_id' => $product->id,
        'store_id' => $store->id,
        'quantity' => 8,
    ]);

    expect($stock->product->is($product))->toBeTrue()
        ->and($stock->store->is($store))->toBeTrue()
        ->and($product->productStocks)->toHaveCount(1)
        ->and($store->productStocks)->toHaveCount(1);
});

test('the same product can have different quantities per store within one company', function () {
    $company = Company::create(['name' => 'Entreprise multi', 'email' => 'multi@example.test']);
    $storeA = Store::ensureDefaultForCompany($company);

    $storeB = Store::create([
        'company_id' => $company->id,
        'name' => 'Annexe',
        'code' => 'ANNEXE',
        'is_default' => false,
        'is_active' => true,
    ]);

    $product = createTestProduct(['stock_quantity' => 0]);

    ProductStock::create([
        'product_id' => $product->id,
        'store_id' => $storeA->id,
        'quantity' => 10,
    ]);

    ProductStock::create([
        'product_id' => $product->id,
        'store_id' => $storeB->id,
        'quantity' => 25,
    ]);

    expect($product->fresh()->productStocks)->toHaveCount(2)
        ->and($product->stockForStore($storeA->id)?->quantity)->toBe(10)
        ->and($product->stockForStore($storeB->id)?->quantity)->toBe(25);
});

test('duplicate product stock for the same store is forbidden', function () {
    $company = Company::create(['name' => 'Entreprise unique', 'email' => 'unique@example.test']);
    $store = Store::ensureDefaultForCompany($company);
    $product = createTestProduct();

    ProductStock::create([
        'product_id' => $product->id,
        'store_id' => $store->id,
        'quantity' => 3,
    ]);

    expect(fn () => ProductStock::create([
        'product_id' => $product->id,
        'store_id' => $store->id,
        'quantity' => 9,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('initialization migration copies products stock quantity into product stocks', function () {
    $company = Company::create(['name' => 'Entreprise init', 'email' => 'init@example.test']);
    $store = Store::ensureDefaultForCompany($company);
    $product = createTestProduct(['stock_quantity' => 47]);

    $created = Store::initializeProductStocksForPrimaryCompany();

    $stock = ProductStock::query()
        ->where('product_id', $product->id)
        ->where('store_id', $store->id)
        ->first();

    expect($created)->toBeGreaterThanOrEqual(1)
        ->and($stock)->not->toBeNull()
        ->and($stock->quantity)->toBe(47);
});

test('product stock initialization is idempotent', function () {
    $company = Company::create(['name' => 'Entreprise idempotent', 'email' => 'idempotent@example.test']);
    Store::ensureDefaultForCompany($company);
    createTestProduct(['stock_quantity' => 15]);
    createTestProduct(['stock_quantity' => 20]);

    $firstRun = Store::initializeProductStocksForPrimaryCompany();
    $secondRun = Store::initializeProductStocksForPrimaryCompany();

    expect($firstRun)->toBe(2)
        ->and($secondRun)->toBe(0)
        ->and(ProductStock::query()->count())->toBe(2);
});

test('existing products receive a product stock in the primary main store', function () {
    $company = Company::create(['name' => 'Entreprise produits', 'email' => 'products@example.test']);
    $store = Store::ensureDefaultForCompany($company);

    $products = collect([
        createTestProduct(['stock_quantity' => 1]),
        createTestProduct(['stock_quantity' => 2]),
        createTestProduct(['stock_quantity' => 3]),
    ]);

    Store::initializeProductStocksForPrimaryCompany();

    foreach ($products as $product) {
        $stock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('store_id', $store->id)
            ->first();

        expect($stock)->not->toBeNull()
            ->and($stock->quantity)->toBe((int) $product->stock_quantity);
    }
});

test('a company without products does not fail initialization', function () {
    Company::create(['name' => 'Entreprise vide', 'email' => 'empty@example.test']);

    expect(fn () => Store::initializeProductStocksForPrimaryCompany())->not->toThrow(Exception::class)
        ->and(ProductStock::query()->count())->toBe(0);
});

test('product stock cannot use a store from another company', function () {
    $companyA = Company::create(['name' => 'Entreprise A', 'email' => 'a@example.test']);
    Company::create(['name' => 'Entreprise B', 'email' => 'b@example.test']);

    $storeA = Store::ensureDefaultForCompany($companyA);
    $storeB = Store::query()->where('code', Store::CODE_MAIN)->where('company_id', '!=', $companyA->id)->firstOrFail();

    $product = createTestProduct(['stock_quantity' => 5]);

    ProductStock::create([
        'product_id' => $product->id,
        'store_id' => $storeA->id,
        'quantity' => 5,
    ]);

    expect(fn () => ProductStock::create([
        'product_id' => $product->id,
        'store_id' => $storeB->id,
        'quantity' => 5,
    ]))->toThrow(InvalidArgumentException::class);
});

test('stock for store helper does not create rows implicitly', function () {
    $company = Company::create(['name' => 'Entreprise helper', 'email' => 'helper@example.test']);
    $store = Store::ensureDefaultForCompany($company);
    $product = createTestProduct();

    expect($product->stockForStore($store->id))->toBeNull()
        ->and(ProductStock::query()->count())->toBe(0);
});

test('ensure default store is idempotent', function () {
    $company = Company::create(['name' => 'Entreprise stable', 'email' => 'stable@example.test']);

    $first = Store::ensureDefaultForCompany($company);
    $second = Store::ensureDefaultForCompany($company);

    expect($first->id)->toBe($second->id)
        ->and(Store::query()->where('company_id', $company->id)->count())->toBe(1);
});
