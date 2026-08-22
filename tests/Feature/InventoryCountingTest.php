<?php

use App\Enums\InventoryScopeType;
use App\Enums\InventorySessionStatus;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventorySession;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\InventorySessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function inventoryCountingPermissions(): User
{
    foreach (['view', 'create', 'count', 'submit'] as $action) {
        Permission::firstOrCreate(
            ['resource' => 'inventory', 'action' => $action],
            [
                'name' => Permission::generateName('inventory', $action),
                'description' => "inventory.{$action}",
            ],
        );
    }

    $user = User::factory()->create(['role' => User::ROLE_GESTIONNAIRE]);
    $user->permissions()->sync(
        Permission::query()->where('resource', 'inventory')->pluck('id'),
    );

    return $user;
}

function inventoryCountingStore(): Store
{
    return Company::getInstance()->defaultStore()->firstOrFail();
}

function inventoryCountingProduct(string $barcode, int $stock = 10): Product
{
    $category = Category::create(['name' => 'Count '.uniqid()]);
    $store = inventoryCountingStore();

    $product = Product::create([
        'name' => 'Produit '.$barcode,
        'sku' => 'SK'.random_int(1000, 9999),
        'barcode' => $barcode,
        'price' => 1000,
        'stock_quantity' => $stock,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    ProductStock::query()->firstOrCreate(
        ['product_id' => $product->id, 'store_id' => $store->id],
        ['quantity' => $stock],
    );

    return $product;
}

function inventoryCountingStartedSession(User $user, Product $product): InventorySession
{
    $service = app(InventorySessionService::class);
    $session = $service->create([
        'name' => 'Session scan '.uniqid(),
        'scope_type' => InventoryScopeType::Complete,
    ], $user);

    return $service->start($session, $user);
}

test('scan increments known barcode from null to one', function () {
    $user = inventoryCountingPermissions();
    $product = inventoryCountingProduct('6043000070493');
    $session = inventoryCountingStartedSession($user, $product);
    $service = app(InventorySessionService::class);

    $payload = $service->countItemByBarcode($session, '6043000070493', $user);

    expect($payload['success'])->toBeTrue()
        ->and($payload['item']['quantity_counted'])->toBe(1)
        ->and($payload['product']['id'])->toBe($product->id);
});

test('second scan increments quantity to two', function () {
    $user = inventoryCountingPermissions();
    $product = inventoryCountingProduct('6043000070494');
    $session = inventoryCountingStartedSession($user, $product);
    $service = app(InventorySessionService::class);

    $service->countItemByBarcode($session, '6043000070494', $user);
    $payload = $service->countItemByBarcode($session, '6043000070494', $user);

    expect($payload['item']['quantity_counted'])->toBe(2);
});

test('multiple scans accumulate quantity', function () {
    $user = inventoryCountingPermissions();
    $product = inventoryCountingProduct('6043000070495');
    $session = inventoryCountingStartedSession($user, $product);
    $service = app(InventorySessionService::class);

    foreach (range(1, 5) as $expected) {
        $payload = $service->countItemByBarcode($session, '6043000070495', $user);
        expect($payload['item']['quantity_counted'])->toBe($expected);
    }
});

test('scan rejects product absent from session', function () {
    $user = inventoryCountingPermissions();
    $categoryA = Category::create(['name' => 'Cat A '.uniqid()]);
    $categoryB = Category::create(['name' => 'Cat B '.uniqid()]);
    $store = inventoryCountingStore();

    $inScope = Product::create([
        'name' => 'Produit A',
        'sku' => 'A'.random_int(1000, 9999),
        'barcode' => '1111111111111',
        'price' => 1000,
        'stock_quantity' => 5,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $categoryA->id,
        'is_active' => true,
    ]);
    ProductStock::query()->create([
        'product_id' => $inScope->id,
        'store_id' => $store->id,
        'quantity' => 5,
    ]);

    $outside = Product::create([
        'name' => 'Produit B',
        'sku' => 'B'.random_int(1000, 9999),
        'barcode' => '2222222222222',
        'price' => 1000,
        'stock_quantity' => 5,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $categoryB->id,
        'is_active' => true,
    ]);
    ProductStock::query()->create([
        'product_id' => $outside->id,
        'store_id' => $store->id,
        'quantity' => 5,
    ]);

    $service = app(InventorySessionService::class);
    $session = $service->create([
        'name' => 'Session catégorie',
        'scope_type' => InventoryScopeType::Category,
        'scope_value' => ['category_id' => $categoryA->id],
    ], $user);
    $session = $service->start($session, $user);

    expect(fn () => $service->countItemByBarcode($session, $outside->barcode, $user))
        ->toThrow(ValidationException::class);
});

test('scan rejects unknown barcode', function () {
    $user = inventoryCountingPermissions();
    inventoryCountingProduct('4444444444444');
    $session = inventoryCountingStartedSession($user, inventoryCountingProduct('5555555555555'));
    $service = app(InventorySessionService::class);

    expect(fn () => $service->countItemByBarcode($session, '9999999999999', $user))
        ->toThrow(ValidationException::class);
});

test('scan rejects empty barcode', function () {
    $user = inventoryCountingPermissions();
    $session = inventoryCountingStartedSession($user, inventoryCountingProduct('6666666666666'));
    $service = app(InventorySessionService::class);

    expect(fn () => $service->countItemByBarcode($session, '   ', $user))
        ->toThrow(ValidationException::class);
});

test('scan rejects session not in counting status', function () {
    $user = inventoryCountingPermissions();
    $product = inventoryCountingProduct('7777777777777');
    $service = app(InventorySessionService::class);
    $session = $service->create([
        'name' => 'Draft session',
        'scope_type' => InventoryScopeType::Complete,
    ], $user);

    expect(fn () => $service->countItemByBarcode($session, $product->barcode, $user))
        ->toThrow(ValidationException::class);
});

test('scan rejects session from another company context', function () {
    $user = inventoryCountingPermissions();
    $product = inventoryCountingProduct('8888888888888');
    $otherCompany = Company::create(['name' => 'Autre', 'email' => 'other@example.test']);
    $otherStore = Store::ensureDefaultForCompany($otherCompany);

    $session = InventorySession::query()->create([
        'company_id' => Company::getInstance()->id,
        'store_id' => $otherStore->id,
        'reference' => 'INV999998',
        'status' => InventorySessionStatus::Counting,
        'scope_type' => InventoryScopeType::Complete,
        'created_by' => $user->id,
    ]);

    InventoryItem::query()->create([
        'inventory_session_id' => $session->id,
        'product_id' => $product->id,
        'stock_snapshot' => 10,
    ]);

    $service = app(InventorySessionService::class);

    expect(fn () => $service->countItemByBarcode($session, $product->barcode, $user))
        ->toThrow(ValidationException::class);
});

test('scan route requires inventory count permission', function () {
    $user = User::factory()->create(['role' => User::ROLE_VENDEUR]);
    $manager = inventoryCountingPermissions();
    $product = inventoryCountingProduct('1010101010101');
    $session = inventoryCountingStartedSession($manager, $product);

    $response = $this->actingAs($user)->postJson(route('inventory.scan', ['session' => $session->id]), [
        'barcode' => $product->barcode,
    ]);

    $response->assertForbidden();
});

test('scan from zero counted quantity becomes one', function () {
    $user = inventoryCountingPermissions();
    $product = inventoryCountingProduct('1212121212121');
    $session = inventoryCountingStartedSession($user, $product);
    $service = app(InventorySessionService::class);
    $item = $session->items()->firstWhere('product_id', $product->id);

    $service->countItem($session, $item, 0, $user);
    $payload = $service->countItemByBarcode($session, $product->barcode, $user);

    expect($payload['item']['quantity_counted'])->toBe(1);
});

test('scan does not modify product stock quantities', function () {
    $user = inventoryCountingPermissions();
    $product = inventoryCountingProduct('1313131313131', 10);
    $session = inventoryCountingStartedSession($user, $product);
    $service = app(InventorySessionService::class);

    foreach (range(1, 5) as $_) {
        $service->countItemByBarcode($session, $product->barcode, $user);
    }

    expect($product->fresh()->stock_quantity)->toBe(10)
        ->and((int) ProductStock::query()->where('product_id', $product->id)->value('quantity'))->toBe(10);
});

test('scan does not create stock movements', function () {
    $user = inventoryCountingPermissions();
    $product = inventoryCountingProduct('1414141414141');
    $session = inventoryCountingStartedSession($user, $product);
    $service = app(InventorySessionService::class);
    $before = StockMovement::query()->count();

    $service->countItemByBarcode($session, $product->barcode, $user);
    $service->countItemByBarcode($session, $product->barcode, $user);

    expect(StockMovement::query()->count())->toBe($before);
});

test('scan sets counted at and counted by', function () {
    $user = inventoryCountingPermissions();
    $product = inventoryCountingProduct('1515151515151');
    $session = inventoryCountingStartedSession($user, $product);
    $service = app(InventorySessionService::class);

    $service->countItemByBarcode($session, $product->barcode, $user);
    $item = InventoryItem::query()->firstWhere('product_id', $product->id);

    expect($item->counted_at)->not->toBeNull()
        ->and($item->counted_by)->toBe($user->id);
});

test('scan returns correct snapshot difference', function () {
    $user = inventoryCountingPermissions();
    $product = inventoryCountingProduct('1616161616161', 10);
    $session = inventoryCountingStartedSession($user, $product);
    $service = app(InventorySessionService::class);

    $payload = $service->countItemByBarcode($session, $product->barcode, $user);
    $payload = $service->countItemByBarcode($session, $product->barcode, $user);
    $payload = $service->countItemByBarcode($session, $product->barcode, $user);

    expect($payload['item']['difference_from_snapshot'])->toBe(-7);
});

test('sequential locked scans do not lose increments', function () {
    $user = inventoryCountingPermissions();
    $product = inventoryCountingProduct('1717171717171');
    $session = inventoryCountingStartedSession($user, $product);
    $service = app(InventorySessionService::class);
    $item = $session->items()->firstWhere('product_id', $product->id);

    $service->countItem($session, $item, 5, $user);
    $service->countItemByBarcode($session, $product->barcode, $user);
    $payload = $service->countItemByBarcode($session, $product->barcode, $user);

    expect($payload['item']['quantity_counted'])->toBe(7);
});

test('scan route returns json payload for successful scan', function () {
    $user = inventoryCountingPermissions();
    $product = inventoryCountingProduct('1818181818181');
    $session = inventoryCountingStartedSession($user, $product);

    $response = $this->actingAs($user)->postJson(route('inventory.scan', ['session' => $session->id]), [
        'barcode' => $product->barcode,
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('item.quantity_counted', 1)
        ->assertJsonPath('product.id', $product->id);
});

test('scan route rejects item from another session via service validation', function () {
    $user = inventoryCountingPermissions();
    $categoryA = Category::create(['name' => 'Only A '.uniqid()]);
    $categoryB = Category::create(['name' => 'Only B '.uniqid()]);
    $store = inventoryCountingStore();

    $productA = Product::create([
        'name' => 'Produit session A',
        'sku' => 'PA'.random_int(1000, 9999),
        'barcode' => '1919191919191',
        'price' => 1000,
        'stock_quantity' => 5,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $categoryA->id,
        'is_active' => true,
    ]);
    ProductStock::query()->create(['product_id' => $productA->id, 'store_id' => $store->id, 'quantity' => 5]);

    $productB = Product::create([
        'name' => 'Produit session B',
        'sku' => 'PB'.random_int(1000, 9999),
        'barcode' => '2020202020202',
        'price' => 1000,
        'stock_quantity' => 5,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $categoryB->id,
        'is_active' => true,
    ]);
    ProductStock::query()->create(['product_id' => $productB->id, 'store_id' => $store->id, 'quantity' => 5]);

    $service = app(InventorySessionService::class);
    $sessionB = $service->start($service->create([
        'name' => 'Session B',
        'scope_type' => InventoryScopeType::Category,
        'scope_value' => ['category_id' => $categoryB->id],
    ], $user), $user);

    $response = $this->actingAs($user)->postJson(route('inventory.scan', ['session' => $sessionB->id]), [
        'barcode' => $productA->barcode,
    ]);

    $response->assertUnprocessable();
});

test('inventory scan route is registered', function () {
    expect(route('inventory.scan', ['session' => 1]))->toContain('/inventory/1/scan');
});
