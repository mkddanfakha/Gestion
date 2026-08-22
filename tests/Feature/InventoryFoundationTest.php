<?php

use App\Enums\InventoryScopeType;
use App\Enums\InventorySessionStatus;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventorySession;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function inventoryFoundationCompany(): Company
{
    return Company::getInstance();
}

function inventoryFoundationStore(): Store
{
    return inventoryFoundationCompany()->defaultStore()->firstOrFail();
}

function inventoryFoundationCategory(): Category
{
    return Category::create([
        'name' => 'Inventaire '.uniqid(),
        'slug' => 'inv-'.uniqid(),
    ]);
}

function inventoryFoundationProduct(array $overrides = []): Product
{
    $category = inventoryFoundationCategory();

    return Product::create(array_merge([
        'name' => 'Produit inventaire '.uniqid(),
        'sku' => 'IV'.random_int(1000, 9999),
        'price' => 1000,
        'stock_quantity' => 25,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ], $overrides));
}

function inventoryFoundationSession(array $overrides = []): InventorySession
{
    $company = inventoryFoundationCompany();
    $store = inventoryFoundationStore();
    $user = User::factory()->create();

    return InventorySession::query()->create(array_merge([
        'company_id' => $company->id,
        'store_id' => $store->id,
        'status' => InventorySessionStatus::Draft,
        'scope_type' => InventoryScopeType::Complete,
        'created_by' => $user->id,
    ], $overrides));
}

test('inventory session can be created with default draft status', function () {
    $session = inventoryFoundationSession(['name' => 'Inventaire test']);

    expect($session->fresh())->not->toBeNull()
        ->and($session->status)->toBe(InventorySessionStatus::Draft)
        ->and($session->name)->toBe('Inventaire test');
});

test('inventory session belongs to company', function () {
    $company = inventoryFoundationCompany();
    $session = inventoryFoundationSession();

    expect($session->company)->not->toBeNull()
        ->and($session->company->id)->toBe($company->id)
        ->and($company->inventorySessions()->whereKey($session->id)->exists())->toBeTrue();
});

test('inventory session belongs to store', function () {
    $store = inventoryFoundationStore();
    $session = inventoryFoundationSession();

    expect($session->store)->not->toBeNull()
        ->and($session->store->id)->toBe($store->id)
        ->and($store->inventorySessions()->whereKey($session->id)->exists())->toBeTrue();
});

test('inventory session belongs to creating user', function () {
    $user = User::factory()->create();
    $session = inventoryFoundationSession(['created_by' => $user->id]);

    expect($session->createdBy)->not->toBeNull()
        ->and($session->createdBy->id)->toBe($user->id);
});

test('inventory item can be created for a session and product', function () {
    $session = inventoryFoundationSession();
    $product = inventoryFoundationProduct();

    $item = InventoryItem::query()->create([
        'inventory_session_id' => $session->id,
        'product_id' => $product->id,
        'stock_snapshot' => 25,
    ]);

    expect($item->fresh())->not->toBeNull()
        ->and($item->stock_snapshot)->toBe(25)
        ->and($item->quantity_counted)->toBeNull();
});

test('inventory item belongs to product', function () {
    $session = inventoryFoundationSession();
    $product = inventoryFoundationProduct();

    $item = InventoryItem::query()->create([
        'inventory_session_id' => $session->id,
        'product_id' => $product->id,
        'stock_snapshot' => 10,
    ]);

    expect($item->product->id)->toBe($product->id)
        ->and($product->inventoryItems()->whereKey($item->id)->exists())->toBeTrue();
});

test('inventory session has many items', function () {
    $session = inventoryFoundationSession();
    $productA = inventoryFoundationProduct();
    $productB = inventoryFoundationProduct();

    InventoryItem::query()->create([
        'inventory_session_id' => $session->id,
        'product_id' => $productA->id,
        'stock_snapshot' => 5,
    ]);

    InventoryItem::query()->create([
        'inventory_session_id' => $session->id,
        'product_id' => $productB->id,
        'stock_snapshot' => 8,
    ]);

    expect($session->items()->count())->toBe(2);
});

test('duplicate product in same inventory session is forbidden', function () {
    $session = inventoryFoundationSession();
    $product = inventoryFoundationProduct();

    InventoryItem::query()->create([
        'inventory_session_id' => $session->id,
        'product_id' => $product->id,
        'stock_snapshot' => 12,
    ]);

    expect(fn () => InventoryItem::query()->create([
        'inventory_session_id' => $session->id,
        'product_id' => $product->id,
        'stock_snapshot' => 12,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('inventory item preserves null quantity counted as not counted', function () {
    $session = inventoryFoundationSession();
    $product = inventoryFoundationProduct();

    $item = InventoryItem::query()->create([
        'inventory_session_id' => $session->id,
        'product_id' => $product->id,
        'stock_snapshot' => 7,
        'quantity_counted' => null,
    ]);

    $item->refresh();

    expect($item->quantity_counted)->toBeNull()
        ->and($item->isCounted())->toBeFalse();
});

test('inventory item accepts zero as counted quantity', function () {
    $session = inventoryFoundationSession();
    $product = inventoryFoundationProduct();

    $item = InventoryItem::query()->create([
        'inventory_session_id' => $session->id,
        'product_id' => $product->id,
        'stock_snapshot' => 7,
        'quantity_counted' => 0,
    ]);

    expect($item->fresh()->quantity_counted)->toBe(0)
        ->and($item->isCounted())->toBeTrue();
});

test('inventory session scope type is cast to enum', function () {
    $session = inventoryFoundationSession([
        'scope_type' => InventoryScopeType::Category,
        'scope_value' => ['category_id' => 42],
    ]);

    expect($session->fresh()->scope_type)->toBe(InventoryScopeType::Category)
        ->and($session->scope_value)->toBe(['category_id' => 42]);
});

test('inventory session status is cast to enum with helpers', function () {
    $session = inventoryFoundationSession(['status' => InventorySessionStatus::Counting]);

    expect($session->fresh()->status)->toBe(InventorySessionStatus::Counting)
        ->and($session->status->isCounting())->toBeTrue()
        ->and($session->status->isActive())->toBeTrue()
        ->and($session->isActive())->toBeTrue()
        ->and(InventorySessionStatus::Applied->isActive())->toBeFalse();
});

test('inventory item stock snapshot is persisted as integer', function () {
    $session = inventoryFoundationSession();
    $product = inventoryFoundationProduct();

    $item = InventoryItem::query()->create([
        'inventory_session_id' => $session->id,
        'product_id' => $product->id,
        'stock_snapshot' => 47,
    ]);

    expect($item->fresh()->stock_snapshot)->toBe(47);
});

test('inventory session active scope filters workflow statuses', function () {
    inventoryFoundationSession(['status' => InventorySessionStatus::Draft]);
    inventoryFoundationSession(['status' => InventorySessionStatus::Counting]);
    inventoryFoundationSession(['status' => InventorySessionStatus::Applied]);

    expect(InventorySession::query()->active()->count())->toBe(2);
});

test('multiple inventory sessions can exist on different stores', function () {
    $companyA = Company::create(['name' => 'Entreprise A', 'email' => 'a@example.test']);
    $companyB = Company::create(['name' => 'Entreprise B', 'email' => 'b@example.test']);

    $storeA = Store::ensureDefaultForCompany($companyA);
    $storeB = Store::ensureDefaultForCompany($companyB);
    $user = User::factory()->create();

    InventorySession::query()->create([
        'company_id' => $companyA->id,
        'store_id' => $storeA->id,
        'status' => InventorySessionStatus::Draft,
        'scope_type' => InventoryScopeType::Complete,
        'created_by' => $user->id,
    ]);

    InventorySession::query()->create([
        'company_id' => $companyB->id,
        'store_id' => $storeB->id,
        'status' => InventorySessionStatus::Draft,
        'scope_type' => InventoryScopeType::Complete,
        'created_by' => $user->id,
    ]);

    expect(InventorySession::query()->count())->toBe(2);
});

test('creating inventory foundation records does not modify product stock', function () {
    $product = inventoryFoundationProduct(['stock_quantity' => 33]);
    $session = inventoryFoundationSession();

    InventoryItem::query()->create([
        'inventory_session_id' => $session->id,
        'product_id' => $product->id,
        'stock_snapshot' => 33,
        'quantity_counted' => 28,
    ]);

    expect($product->fresh()->stock_quantity)->toBe(33);
});
