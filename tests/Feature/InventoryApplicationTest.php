<?php

use App\Enums\InventoryScopeType;
use App\Enums\InventorySessionStatus;
use App\Enums\StockMovementType;
use App\Models\ActivityLog;
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
use App\Services\InventoryApplicationService;
use App\Services\InventorySessionService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function inventoryApplicationPermissions(array $actions = ['view', 'create', 'count', 'submit', 'review', 'validate', 'apply', 'close']): User
{
    foreach ($actions as $action) {
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

function inventoryApplicationStore(): Store
{
    Company::getInstance();

    return Company::getInstance()->defaultStore()->firstOrFail();
}

function inventoryApplicationProduct(array $overrides = [], int $stock = 10): Product
{
    $category = Category::create(['name' => 'Apply '.uniqid()]);
    $store = inventoryApplicationStore();

    $product = Product::create(array_merge([
        'name' => 'Produit apply '.uniqid(),
        'sku' => 'AP'.random_int(1000, 9999),
        'price' => 1000,
        'stock_quantity' => $stock,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ], $overrides));

    ProductStock::query()->updateOrCreate(
        ['product_id' => $product->id, 'store_id' => $store->id],
        ['quantity' => $stock],
    );

    return $product;
}

function inventoryApplicationValidatedSession(User $user, array $products = [], ?callable $countCallback = null): InventorySession
{
    if ($products === []) {
        $products = [inventoryApplicationProduct()];
    }

    $sessionService = app(InventorySessionService::class);
    $session = $sessionService->create([
        'name' => 'Session apply '.uniqid(),
        'scope_type' => InventoryScopeType::Complete,
    ], $user);
    $session = $sessionService->start($session, $user);
    $session->load('items');

    foreach ($session->items as $item) {
        $quantity = $countCallback
            ? $countCallback($item)
            : $item->stock_snapshot;

        $sessionService->countItem($session, $item, $quantity, $user);
    }

    $session = $sessionService->submit($session, $user);

    return $sessionService->validate($session, $user);
}

test('apply from validated transitions to applied', function () {
    $user = inventoryApplicationPermissions();
    $service = app(InventoryApplicationService::class);
    $session = inventoryApplicationValidatedSession($user);

    $result = $service->apply($session, $user);

    expect($result['session']->status)->toBe(InventorySessionStatus::Applied)
        ->and($result['session']->applied_by)->toBe($user->id)
        ->and($result['session']->applied_at)->not->toBeNull();
});

test('apply from draft is rejected', function () {
    $user = inventoryApplicationPermissions();
    $sessionService = app(InventorySessionService::class);
    $session = $sessionService->create([
        'name' => 'Draft',
        'scope_type' => InventoryScopeType::Complete,
    ], $user);

    expect(fn () => app(InventoryApplicationService::class)->apply($session, $user))
        ->toThrow(ValidationException::class);
});

test('apply from counting is rejected', function () {
    $user = inventoryApplicationPermissions();
    inventoryApplicationProduct();
    $sessionService = app(InventorySessionService::class);
    $session = $sessionService->start(
        $sessionService->create(['name' => 'Counting', 'scope_type' => InventoryScopeType::Complete], $user),
        $user,
    );

    expect(fn () => app(InventoryApplicationService::class)->apply($session, $user))
        ->toThrow(ValidationException::class);
});

test('apply from review is rejected', function () {
    $user = inventoryApplicationPermissions();
    $sessionService = app(InventorySessionService::class);
    $session = inventoryApplicationValidatedSession($user);
    $session->update(['status' => InventorySessionStatus::Review]);

    expect(fn () => app(InventoryApplicationService::class)->apply($session->fresh(), $user))
        ->toThrow(ValidationException::class);
});

test('double apply is rejected without duplicate movements', function () {
    $user = inventoryApplicationPermissions();
    $service = app(InventoryApplicationService::class);
    $session = inventoryApplicationValidatedSession($user);
    $service->apply($session, $user);
    $movementCount = StockMovement::query()->count();

    expect(fn () => $service->apply($session->fresh(), $user))
        ->toThrow(ValidationException::class)
        ->and(StockMovement::query()->count())->toBe($movementCount);
});

test('apply from closed is rejected', function () {
    $user = inventoryApplicationPermissions();
    $applicationService = app(InventoryApplicationService::class);
    $sessionService = app(InventorySessionService::class);
    $session = inventoryApplicationValidatedSession($user);
    $session = $applicationService->apply($session, $user)['session'];
    $session = $sessionService->close($session, $user);

    expect(fn () => $applicationService->apply($session->fresh(), $user))
        ->toThrow(ValidationException::class);
});

test('positive delta increases stock through stock service', function () {
    $user = inventoryApplicationPermissions();
    $product = inventoryApplicationProduct([], 20);
    $session = inventoryApplicationValidatedSession($user, [$product], fn ($item) => $item->stock_snapshot + 5);
    $beforeMovements = StockMovement::query()->count();

    app(InventoryApplicationService::class)->apply($session, $user);

    expect((int) ProductStock::query()->where('product_id', $product->id)->value('quantity'))->toBe(25)
        ->and($product->fresh()->stock_quantity)->toBe(25)
        ->and(StockMovement::query()->count())->toBe($beforeMovements + 1)
        ->and(StockMovement::query()->latest('id')->first()->quantity)->toBe(5);
});

test('negative delta decreases stock through stock service', function () {
    $user = inventoryApplicationPermissions();
    $product = inventoryApplicationProduct([], 20);
    $session = inventoryApplicationValidatedSession($user, [$product], fn ($item) => $item->stock_snapshot - 3);

    app(InventoryApplicationService::class)->apply($session, $user);

    expect((int) ProductStock::query()->where('product_id', $product->id)->value('quantity'))->toBe(17)
        ->and(StockMovement::query()->latest('id')->first()->quantity)->toBe(-3);
});

test('zero delta creates no stock movement', function () {
    $user = inventoryApplicationPermissions();
    $product = inventoryApplicationProduct([], 20);
    $session = inventoryApplicationValidatedSession($user, [$product]);
    $beforeMovements = StockMovement::query()->count();

    $result = app(InventoryApplicationService::class)->apply($session, $user);

    expect(StockMovement::query()->count())->toBe($beforeMovements)
        ->and($result['summary']['unchanged_items'])->toBe(1)
        ->and($result['summary']['adjusted_items'])->toBe(0);
});

test('apply handles multiple products atomically', function () {
    $user = inventoryApplicationPermissions();
    $productA = inventoryApplicationProduct(['name' => 'A'], 10);
    $productB = inventoryApplicationProduct(['name' => 'B'], 8);
    $session = inventoryApplicationValidatedSession($user, [$productA, $productB], fn ($item) => $item->stock_snapshot + 2);

    app(InventoryApplicationService::class)->apply($session, $user);

    expect((int) ProductStock::query()->where('product_id', $productA->id)->value('quantity'))->toBe(12)
        ->and((int) ProductStock::query()->where('product_id', $productB->id)->value('quantity'))->toBe(10)
        ->and(StockMovement::query()->where('type', StockMovementType::InventoryAdjustment)->count())->toBe(2);
});

test('inventory adjustment movement stores before and after quantities', function () {
    $user = inventoryApplicationPermissions();
    $product = inventoryApplicationProduct([], 15);
    $session = inventoryApplicationValidatedSession($user, [$product], fn ($item) => $item->stock_snapshot + 4);

    app(InventoryApplicationService::class)->apply($session, $user);

    $movement = StockMovement::query()->latest('id')->first();

    expect($movement->quantity_before)->toBe(15)
        ->and($movement->quantity_after)->toBe(19)
        ->and($movement->quantity)->toBe(4);
});

test('inventory adjustment movement references inventory session', function () {
    $user = inventoryApplicationPermissions();
    $session = inventoryApplicationValidatedSession($user, [inventoryApplicationProduct([], 10)], fn ($item) => $item->stock_snapshot + 1);

    $session = app(InventoryApplicationService::class)->apply($session, $user)['session'];
    $movement = StockMovement::query()->latest('id')->first();

    expect($movement->reference_type)->toBe((new InventorySession)->getMorphClass())
        ->and($movement->reference_id)->toBe($session->id);
});

test('inventory adjustment movement stores user id', function () {
    $user = inventoryApplicationPermissions();
    $session = inventoryApplicationValidatedSession($user, [inventoryApplicationProduct([], 10)], fn ($item) => $item->stock_snapshot + 1);

    app(InventoryApplicationService::class)->apply($session, $user);

    expect(StockMovement::query()->latest('id')->first()->user_id)->toBe($user->id);
});

test('inventory adjustment movement stores rich metadata', function () {
    $user = inventoryApplicationPermissions();
    $product = inventoryApplicationProduct([], 12);
    $session = inventoryApplicationValidatedSession($user, [$product], fn ($item) => $item->stock_snapshot - 2);

    app(InventoryApplicationService::class)->apply($session, $user);

    $metadata = StockMovement::query()->latest('id')->first()->metadata;

    expect($metadata)->toMatchArray([
        'source' => 'inventory_application',
        'stock_before_apply' => 12,
        'stock_after_apply' => 10,
        'delta_from_current' => -2,
        'quantity_counted' => 10,
    ]);
});

test('application summary uses delta from current stock not snapshot', function () {
    $user = inventoryApplicationPermissions();
    $product = inventoryApplicationProduct([], 100);
    $store = inventoryApplicationStore();
    $session = inventoryApplicationValidatedSession($user, [$product], fn ($item) => 90);

    app(StockService::class)->decrease(
        $product,
        $store,
        10,
        StockMovementType::Sale,
        user: $user,
        reason: 'Vente pendant inventaire',
    );
    $product->update(['stock_quantity' => 90]);

    $result = app(InventoryApplicationService::class)->apply($session, $user);

    expect($result['summary']['adjusted_items'])->toBe(0)
        ->and($result['summary']['unchanged_items'])->toBe(1)
        ->and(StockMovement::query()->where('type', StockMovementType::InventoryAdjustment)->count())->toBe(0);
});

test('application increases stock when counted exceeds current stock after movements', function () {
    $user = inventoryApplicationPermissions();
    $product = inventoryApplicationProduct([], 100);
    $store = inventoryApplicationStore();
    $session = inventoryApplicationValidatedSession($user, [$product], fn ($item) => 100);

    app(StockService::class)->decrease($product, $store, 10, StockMovementType::Sale, user: $user, reason: 'Vente');
    $product->update(['stock_quantity' => 90]);

    app(InventoryApplicationService::class)->apply($session, $user);

    expect((int) ProductStock::query()->where('product_id', $product->id)->value('quantity'))->toBe(100)
        ->and(StockMovement::query()->where('type', StockMovementType::InventoryAdjustment)->first()->quantity)->toBe(10);
});

test('apply rejects null counted items defensively', function () {
    $user = inventoryApplicationPermissions();
    $session = inventoryApplicationValidatedSession($user);

    InventoryItem::query()
        ->where('inventory_session_id', $session->id)
        ->limit(1)
        ->update(['quantity_counted' => null]);

    expect(InventoryItem::query()->where('inventory_session_id', $session->id)->whereNull('quantity_counted')->count())
        ->toBe(1);

    expect(fn () => app(InventoryApplicationService::class)->apply($session->fresh(), $user))
        ->toThrow(ValidationException::class);
});

test('missing product stock rolls back entire application', function () {
    $user = inventoryApplicationPermissions();
    $productA = inventoryApplicationProduct(['name' => 'Rollback A'], 10);
    $productB = inventoryApplicationProduct(['name' => 'Rollback B'], 10);
    $session = inventoryApplicationValidatedSession($user, [$productA, $productB], fn ($item) => $item->stock_snapshot + 1);
    $store = inventoryApplicationStore();
    $beforeMovements = StockMovement::query()->count();

    ProductStock::query()
        ->where('product_id', $productB->id)
        ->where('store_id', $store->id)
        ->delete();

    expect(fn () => app(InventoryApplicationService::class)->apply($session, $user))
        ->toThrow(ValidationException::class);

    expect((int) ProductStock::query()->where('product_id', $productA->id)->value('quantity'))->toBe(10)
        ->and(StockMovement::query()->count())->toBe($beforeMovements)
        ->and($session->fresh()->status)->toBe(InventorySessionStatus::Validated);
});

test('apply route requires inventory apply permission', function () {
    $manager = inventoryApplicationPermissions();
    $viewer = User::factory()->create(['role' => User::ROLE_GESTIONNAIRE]);
    Permission::firstOrCreate(
        ['resource' => 'inventory', 'action' => 'view'],
        ['name' => 'inventory.view', 'description' => 'view'],
    );
    $viewer->permissions()->sync(Permission::where('resource', 'inventory')->where('action', 'view')->pluck('id'));

    $session = inventoryApplicationValidatedSession($manager);

    $this->actingAs($viewer)->postJson(route('inventory.apply', ['session' => $session->id]))
        ->assertForbidden();
});

test('apply route rejects session from another company', function () {
    $manager = inventoryApplicationPermissions();
    Company::getInstance();
    $otherCompany = Company::create(['name' => 'Autre apply', 'email' => 'apply-other@example.test']);
    $otherStore = Store::ensureDefaultForCompany($otherCompany);

    $otherSession = InventorySession::query()->create([
        'company_id' => $otherCompany->id,
        'store_id' => $otherStore->id,
        'reference' => 'INV999995',
        'status' => InventorySessionStatus::Validated,
        'scope_type' => InventoryScopeType::Complete,
        'created_by' => $manager->id,
    ]);

    $this->actingAs($manager)->postJson(route('inventory.apply', ['session' => $otherSession->id]))
        ->assertNotFound();
});

test('close transitions applied to closed', function () {
    $user = inventoryApplicationPermissions();
    $applicationService = app(InventoryApplicationService::class);
    $sessionService = app(InventorySessionService::class);
    $session = inventoryApplicationValidatedSession($user);
    $session = $applicationService->apply($session, $user)['session'];
    $session = $sessionService->close($session, $user);

    expect($session->status)->toBe(InventorySessionStatus::Closed)
        ->and($session->closed_by)->toBe($user->id);
});

test('close from validated is rejected', function () {
    $user = inventoryApplicationPermissions();
    $session = inventoryApplicationValidatedSession($user);

    expect(fn () => app(InventorySessionService::class)->close($session, $user))
        ->toThrow(ValidationException::class);
});

test('double close is rejected', function () {
    $user = inventoryApplicationPermissions();
    $applicationService = app(InventoryApplicationService::class);
    $sessionService = app(InventorySessionService::class);
    $session = inventoryApplicationValidatedSession($user);
    $session = $applicationService->apply($session, $user)['session'];
    $sessionService->close($session, $user);

    expect(fn () => $sessionService->close($session->fresh(), $user))
        ->toThrow(ValidationException::class);
});

test('close route requires inventory close permission', function () {
    $manager = inventoryApplicationPermissions(['view', 'apply']);
    $session = inventoryApplicationValidatedSession($manager);
    $session = app(InventoryApplicationService::class)->apply($session, $manager)['session'];

    $this->actingAs($manager)->postJson(route('inventory.close', ['session' => $session->id]))
        ->assertForbidden();
});

test('closed session cannot be cancelled', function () {
    $user = inventoryApplicationPermissions();
    $applicationService = app(InventoryApplicationService::class);
    $sessionService = app(InventorySessionService::class);
    $session = inventoryApplicationValidatedSession($user);
    $session = $applicationService->apply($session, $user)['session'];
    $session = $sessionService->close($session, $user);

    expect(fn () => $sessionService->cancel($session->fresh(), $user))
        ->toThrow(ValidationException::class);
});

test('apply creates activity log', function () {
    $user = inventoryApplicationPermissions();
    $session = inventoryApplicationValidatedSession($user);
    $session = app(InventoryApplicationService::class)->apply($session, $user)['session'];

    expect(ActivityLog::query()
        ->where('subject_id', $session->id)
        ->where('description', 'like', '%appliqué%')
        ->exists())->toBeTrue();
});

test('close creates activity log', function () {
    $user = inventoryApplicationPermissions();
    $applicationService = app(InventoryApplicationService::class);
    $sessionService = app(InventorySessionService::class);
    $session = inventoryApplicationValidatedSession($user);
    $session = $applicationService->apply($session, $user)['session'];
    $session = $sessionService->close($session, $user);

    expect(ActivityLog::query()
        ->where('subject_id', $session->id)
        ->where('description', 'like', '%clôturé%')
        ->exists())->toBeTrue();
});

test('legacy stock inventory routes are absent', function () {
    expect(Route::has('stock-inventory.index'))->toBeFalse()
        ->and(Route::has('stock-inventory.count'))->toBeFalse();
});

test('inventory apply route is registered', function () {
    expect(Route::has('inventory.apply'))->toBeTrue();
});

test('application summary counts adjusted and unchanged items', function () {
    $user = inventoryApplicationPermissions();
    $productA = inventoryApplicationProduct([], 10);
    $productB = inventoryApplicationProduct([], 10);
    $session = inventoryApplicationValidatedSession($user, [$productA, $productB], function ($item) use ($productA) {
        return $item->product_id === $productA->id ? 12 : 10;
    });

    $summary = app(InventoryApplicationService::class)->apply($session, $user)['summary'];

    expect($summary['total_items'])->toBe(2)
        ->and($summary['adjusted_items'])->toBe(1)
        ->and($summary['unchanged_items'])->toBe(1)
        ->and($summary['net_adjustment'])->toBe(2);
});

test('items are processed in ascending product id order', function () {
    $user = inventoryApplicationPermissions();
    $productA = inventoryApplicationProduct(['name' => 'Order A'], 5);
    $productB = inventoryApplicationProduct(['name' => 'Order B'], 5);

    if ($productA->id > $productB->id) {
        [$productA, $productB] = [$productB, $productA];
    }

    $session = inventoryApplicationValidatedSession(
        $user,
        [$productB, $productA],
        fn ($item) => $item->stock_snapshot + 1,
    );

    app(InventoryApplicationService::class)->apply($session, $user);

    $productIds = StockMovement::query()
        ->where('type', StockMovementType::InventoryAdjustment)
        ->orderBy('id')
        ->pluck('product_id')
        ->all();

    expect($productIds)->toBe([$productA->id, $productB->id]);
});
