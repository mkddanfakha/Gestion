<?php

use App\Enums\InventoryScopeType;
use App\Enums\InventorySessionStatus;
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
use App\Services\InventorySessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function inventoryReviewPermissions(array $actions = ['view', 'create', 'count', 'submit', 'review', 'validate']): User
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

function inventoryReviewStore(): Store
{
    return Company::getInstance()->defaultStore()->firstOrFail();
}

function inventoryReviewProduct(array $overrides = [], int $stock = 10): Product
{
    $category = Category::create(['name' => 'Review '.uniqid()]);
    $store = inventoryReviewStore();

    $product = Product::create(array_merge([
        'name' => 'Produit review '.uniqid(),
        'sku' => 'RV'.random_int(1000, 9999),
        'price' => 1000,
        'stock_quantity' => $stock,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ], $overrides));

    ProductStock::query()->firstOrCreate(
        ['product_id' => $product->id, 'store_id' => $store->id],
        ['quantity' => $stock],
    );

    return $product;
}

function inventoryReviewStartedSession(User $user, array $products = []): InventorySession
{
    if ($products === []) {
        $products = [inventoryReviewProduct()];
    }

    $service = app(InventorySessionService::class);
    $session = $service->create([
        'name' => 'Session review '.uniqid(),
        'scope_type' => InventoryScopeType::Complete,
    ], $user);

    return $service->start($session, $user);
}

function inventoryReviewCountAll(InventorySession $session, User $user, ?int $quantity = null): void
{
    $service = app(InventorySessionService::class);
    $session->load('items');

    foreach ($session->items as $item) {
        $service->countItem(
            $session,
            $item,
            $quantity ?? $item->stock_snapshot,
            $user,
        );
    }
}

test('submit with all products counted transitions to review', function () {
    $user = inventoryReviewPermissions();
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);
    inventoryReviewCountAll($session, $user);

    $session = $service->submit($session, $user);

    expect($session->status)->toBe(InventorySessionStatus::Review);
});

test('submit with uncounted product is rejected', function () {
    $user = inventoryReviewPermissions();
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);

    expect(fn () => $service->submit($session, $user))
        ->toThrow(ValidationException::class);
});

test('null quantity is treated as uncounted on submit', function () {
    $user = inventoryReviewPermissions();
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);
    $item = $session->items()->first();

    $service->countItem($session, $item, 0, $user);

    expect($item->fresh()->quantity_counted)->toBe(0)
        ->and($item->fresh()->isCounted())->toBeTrue();
});

test('zero quantity is treated as counted on submit', function () {
    $user = inventoryReviewPermissions();
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);
    inventoryReviewCountAll($session, $user, 0);

    $session = $service->submit($session, $user);

    expect($session->status)->toBe(InventorySessionStatus::Review);
});

test('submit creates activity log', function () {
    $user = inventoryReviewPermissions();
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);
    inventoryReviewCountAll($session, $user);
    $session = $service->submit($session, $user);

    expect(ActivityLog::query()
        ->where('subject_id', $session->id)
        ->where('description', 'like', '%terminé le comptage%')
        ->exists())->toBeTrue();
});

test('submit does not modify product stock', function () {
    $user = inventoryReviewPermissions();
    $product = inventoryReviewProduct([], 12);
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);
    inventoryReviewCountAll($session, $user, 3);
    $service->submit($session, $user);

    expect($product->fresh()->stock_quantity)->toBe(12)
        ->and((int) ProductStock::query()->where('product_id', $product->id)->value('quantity'))->toBe(12);
});

test('submit does not create stock movements', function () {
    $user = inventoryReviewPermissions();
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);
    inventoryReviewCountAll($session, $user);
    $before = StockMovement::query()->count();
    $service->submit($session, $user);

    expect(StockMovement::query()->count())->toBe($before);
});

test('review summary exposes variance counts', function () {
    $user = inventoryReviewPermissions();
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);

    foreach ($session->items as $index => $item) {
        $service->countItem($session, $item, $item->stock_snapshot + ($index === 0 ? -2 : 0), $user);
    }

    $session = $service->submit($session, $user);
    $summary = $service->getSummary($session);

    expect($summary['negative_variances'])->toBeGreaterThanOrEqual(1)
        ->and($summary['total_variance'])->toBeLessThan(0);
});

test('positive variance difference is correct', function () {
    $user = inventoryReviewPermissions();
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);
    $item = $session->items()->first();
    $service->countItem($session, $item, $item->stock_snapshot + 3, $user);

    expect($item->fresh()->differenceFromSnapshot())->toBe(3);
});

test('negative variance difference is correct', function () {
    $user = inventoryReviewPermissions();
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);
    $item = $session->items()->first();
    $service->countItem($session, $item, $item->stock_snapshot - 2, $user);

    expect($item->fresh()->differenceFromSnapshot())->toBe(-2);
});

test('zero variance difference is correct', function () {
    $user = inventoryReviewPermissions();
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);
    $item = $session->items()->first();
    $service->countItem($session, $item, $item->stock_snapshot, $user);

    expect($item->fresh()->differenceFromSnapshot())->toBe(0);
});

test('validate transitions review to validated', function () {
    $user = inventoryReviewPermissions();
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);
    inventoryReviewCountAll($session, $user);
    $session = $service->submit($session, $user);
    $session = $service->validate($session, $user);

    expect($session->status)->toBe(InventorySessionStatus::Validated);
});

test('validate from counting is rejected', function () {
    $user = inventoryReviewPermissions();
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);

    expect(fn () => $service->validate($session, $user))
        ->toThrow(ValidationException::class);
});

test('validate with null counted item is rejected', function () {
    $user = inventoryReviewPermissions();
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);
    $session->update(['status' => InventorySessionStatus::Review]);

    expect(fn () => $service->validate($session, $user))
        ->toThrow(ValidationException::class);
});

test('validate creates activity log', function () {
    $user = inventoryReviewPermissions();
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);
    inventoryReviewCountAll($session, $user);
    $session = $service->submit($session, $user);
    $session = $service->validate($session, $user);

    expect(ActivityLog::query()
        ->where('subject_id', $session->id)
        ->where('action', ActivityLog::ACTION_VALIDATE)
        ->exists())->toBeTrue();
});

test('validate does not modify stock quantities', function () {
    $user = inventoryReviewPermissions();
    $product = inventoryReviewProduct([], 15);
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);
    inventoryReviewCountAll($session, $user, 4);
    $session = $service->submit($session, $user);
    $service->validate($session, $user);

    expect($product->fresh()->stock_quantity)->toBe(15);
});

test('validate does not create stock movements', function () {
    $user = inventoryReviewPermissions();
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);
    inventoryReviewCountAll($session, $user);
    $session = $service->submit($session, $user);
    $before = StockMovement::query()->count();
    $service->validate($session, $user);

    expect(StockMovement::query()->count())->toBe($before);
});

test('submit route requires inventory submit permission', function () {
    $manager = inventoryReviewPermissions();
    $viewer = User::factory()->create(['role' => User::ROLE_GESTIONNAIRE]);
    Permission::firstOrCreate(
        ['resource' => 'inventory', 'action' => 'view'],
        ['name' => 'inventory.view', 'description' => 'view'],
    );
    $viewer->permissions()->sync(Permission::where('resource', 'inventory')->where('action', 'view')->pluck('id'));

    $session = inventoryReviewStartedSession($manager);
    inventoryReviewCountAll($session, $manager);

    $this->actingAs($viewer)->postJson(route('inventory.submit', ['session' => $session->id]))
        ->assertForbidden();
});

test('validate route requires inventory validate permission', function () {
    $manager = inventoryReviewPermissions(['view', 'count', 'submit']);
    $session = inventoryReviewStartedSession($manager);
    inventoryReviewCountAll($session, $manager);
    $session = app(InventorySessionService::class)->submit($session, $manager);

    $this->actingAs($manager)->postJson(route('inventory.validate', ['session' => $session->id]))
        ->assertForbidden();
});

test('submit route rejects session from another company', function () {
    $manager = inventoryReviewPermissions();
    Company::getInstance();
    $otherCompany = Company::create(['name' => 'Autre review', 'email' => 'review-other@example.test']);
    $otherStore = Store::ensureDefaultForCompany($otherCompany);

    $otherSession = InventorySession::query()->create([
        'company_id' => $otherCompany->id,
        'store_id' => $otherStore->id,
        'reference' => 'INV999996',
        'status' => InventorySessionStatus::Counting,
        'scope_type' => InventoryScopeType::Complete,
        'created_by' => $manager->id,
    ]);

    $this->actingAs($manager)->postJson(route('inventory.submit', ['session' => $otherSession->id]))
        ->assertNotFound();
});

test('double submit is rejected cleanly', function () {
    $user = inventoryReviewPermissions();
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);
    inventoryReviewCountAll($session, $user);
    $service->submit($session, $user);

    expect(fn () => $service->submit($session->fresh(), $user))
        ->toThrow(ValidationException::class);
});

test('double validate is rejected cleanly', function () {
    $user = inventoryReviewPermissions();
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);
    inventoryReviewCountAll($session, $user);
    $session = $service->submit($session, $user);
    $service->validate($session, $user);

    expect(fn () => $service->validate($session->fresh(), $user))
        ->toThrow(ValidationException::class);
});

test('reopen transitions review back to counting', function () {
    $user = inventoryReviewPermissions();
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);
    inventoryReviewCountAll($session, $user);
    $session = $service->submit($session, $user);
    $session = $service->reopen($session, $user);

    expect($session->status)->toBe(InventorySessionStatus::Counting);
});

test('session detail payload includes progress and summary', function () {
    $user = inventoryReviewPermissions();
    $service = app(InventorySessionService::class);
    $session = inventoryReviewStartedSession($user);
    inventoryReviewCountAll($session, $user, 0);

    $payload = $service->formatSessionDetailPayload($session, $user);

    expect($payload['progress']['counted'])->toBe($payload['progress']['total'])
        ->and($payload['summary']['total_units'])->toBe(0)
        ->and($payload['permissions']['submit'])->toBeTrue();
});
