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

function inventoryWorkflowPermissions(?array $actions = null): User
{
    $actions ??= ['view', 'create', 'count', 'submit', 'review', 'validate', 'apply', 'cancel', 'close'];

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

function inventoryWorkflowCompany(): Company
{
    return Company::getInstance();
}

function inventoryWorkflowStore(): Store
{
    return inventoryWorkflowCompany()->defaultStore()->firstOrFail();
}

function inventoryWorkflowCategory(): Category
{
    return Category::create([
        'name' => 'Cat workflow '.uniqid(),
    ]);
}

function inventoryWorkflowProduct(array $overrides = [], ?int $mainStock = 10): Product
{
    $category = inventoryWorkflowCategory();
    $store = inventoryWorkflowStore();

    $product = Product::create(array_merge([
        'name' => 'Produit workflow '.uniqid(),
        'sku' => 'WF'.random_int(1000, 9999),
        'price' => 1000,
        'stock_quantity' => $mainStock,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ], $overrides));

    ProductStock::query()->firstOrCreate(
        ['product_id' => $product->id, 'store_id' => $store->id],
        ['quantity' => $mainStock],
    );

    return $product;
}

function inventoryWorkflowCreateSession(User $user, array $overrides = []): InventorySession
{
    $service = app(InventorySessionService::class);

    return $service->create(array_merge([
        'name' => 'Session test '.uniqid(),
        'scope_type' => InventoryScopeType::Complete,
    ], $overrides), $user);
}

function inventoryWorkflowCountAll(InventorySession $session, User $user, ?int $quantity = null): void
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

test('creating inventory session sets draft status', function () {
    $user = inventoryWorkflowPermissions();
    $session = inventoryWorkflowCreateSession($user);

    expect($session->status)->toBe(InventorySessionStatus::Draft)
        ->and($session->reference)->not->toBeNull()
        ->and($session->created_by)->toBe($user->id);
});

test('creating inventory session with complete scope', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();

    $session = inventoryWorkflowCreateSession($user, [
        'scope_type' => InventoryScopeType::Complete,
    ]);

    expect($session->scope_type)->toBe(InventoryScopeType::Complete);
});

test('creating inventory session with category scope', function () {
    $category = inventoryWorkflowCategory();
    inventoryWorkflowProduct(['category_id' => $category->id]);
    $user = inventoryWorkflowPermissions();

    $session = inventoryWorkflowCreateSession($user, [
        'scope_type' => InventoryScopeType::Category,
        'scope_value' => ['category_id' => $category->id],
    ]);

    expect($session->scope_type)->toBe(InventoryScopeType::Category)
        ->and($session->scope_value)->toBe(['category_id' => $category->id]);
});

test('creating inventory session with stock positive scope', function () {
    inventoryWorkflowProduct([], 5);
    inventoryWorkflowProduct([], 0);
    $user = inventoryWorkflowPermissions();

    $session = inventoryWorkflowCreateSession($user, [
        'scope_type' => InventoryScopeType::StockPositive,
    ]);

    expect($session->scope_type)->toBe(InventoryScopeType::StockPositive);
});

test('start transitions session to counting and creates snapshot items', function () {
    $product = inventoryWorkflowProduct([], 14);
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = inventoryWorkflowCreateSession($user);
    $session = $service->start($session, $user);

    expect($session->status)->toBe(InventorySessionStatus::Counting)
        ->and($session->items)->toHaveCount(1);

    $item = $session->items->first();
    expect($item->product_id)->toBe($product->id)
        ->and($item->stock_snapshot)->toBe(14)
        ->and($item->quantity_counted)->toBeNull();
});

test('snapshot reflects product stock quantity not legacy mirror only', function () {
    $product = inventoryWorkflowProduct(['stock_quantity' => 99], 14);
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);
    $item = $session->items->firstWhere('product_id', $product->id);

    expect($item->stock_snapshot)->toBe(14);
});

test('snapshot is immutable after start', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);
    $item = $session->items->first();

    expect(fn () => $item->update(['stock_snapshot' => 999]))
        ->toThrow(\InvalidArgumentException::class);
});

test('items start with nullable quantity counted', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);

    expect($session->items->every(fn (InventoryItem $item) => $item->quantity_counted === null))->toBeTrue();
});

test('count item stores positive quantity', function () {
    inventoryWorkflowProduct([], 8);
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);
    $item = $session->items->first();

    $updated = $service->countItem($session, $item, 6, $user);

    expect($updated->quantity_counted)->toBe(6)
        ->and($updated->counted_by)->toBe($user->id)
        ->and($updated->counted_at)->not->toBeNull();
});

test('count item accepts zero quantity', function () {
    inventoryWorkflowProduct([], 8);
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);
    $item = $session->items->first();

    $updated = $service->countItem($session, $item, 0, $user);

    expect($updated->quantity_counted)->toBe(0)
        ->and($updated->isCounted())->toBeTrue();
});

test('negative counted quantity is rejected', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);
    $item = $session->items->first();

    expect(fn () => $service->countItem($session, $item, -1, $user))
        ->toThrow(ValidationException::class);
});

test('counting product outside session scope is rejected', function () {
    inventoryWorkflowProduct();
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $firstSession = $service->start(inventoryWorkflowCreateSession($user), $user);
    $outsideItem = $firstSession->items->first();
    $service->cancel($firstSession, $user);

    $secondSession = $service->start(
        inventoryWorkflowCreateSession($user, ['name' => 'Session B']),
        $user,
    );

    expect(fn () => $service->countItem($secondSession, $outsideItem, 1, $user))
        ->toThrow(ValidationException::class);
});

test('counting is forbidden outside counting status', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);
    $item = $session->items->first();
    $session = $service->cancel($session, $user);

    expect(fn () => $service->countItem($session, $item, 1, $user))
        ->toThrow(ValidationException::class);
});

test('submit is rejected when products are not counted', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);

    expect(fn () => $service->submit($session, $user))
        ->toThrow(ValidationException::class);
});

test('submit succeeds when all products are counted', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);
    inventoryWorkflowCountAll($session, $user);

    $session = $service->submit($session, $user);

    expect($session->status)->toBe(InventorySessionStatus::Review)
        ->and($session->submitted_by)->toBe($user->id);
});

test('review status is set after submit', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);
    inventoryWorkflowCountAll($session, $user, 0);

    $session = $service->submit($session, $user);

    expect($session->status)->toBe(InventorySessionStatus::Review);
});

test('difference snapshot is computed for audit only', function () {
    inventoryWorkflowProduct([], 10);
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);
    $item = $session->items->first();
    $service->countItem($session, $item, 7, $user);

    expect($item->fresh()->differenceFromSnapshot())->toBe(-3);
});

test('validate transitions session to validated', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);
    inventoryWorkflowCountAll($session, $user);
    $session = $service->submit($session, $user);
    $session = $service->validate($session, $user);

    expect($session->status)->toBe(InventorySessionStatus::Validated)
        ->and($session->validated_by)->toBe($user->id);
});

test('validate is forbidden outside review status', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = inventoryWorkflowCreateSession($user);

    expect(fn () => $service->validate($session, $user))
        ->toThrow(ValidationException::class);
});

test('counting does not modify product stock', function () {
    $product = inventoryWorkflowProduct([], 12);
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);
    $service->countItem($session, $session->items->first(), 3, $user);

    expect($product->fresh()->stock_quantity)->toBe(12)
        ->and((int) ProductStock::query()->where('product_id', $product->id)->value('quantity'))->toBe(12);
});

test('submit does not modify product stock', function () {
    $product = inventoryWorkflowProduct([], 12);
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);
    inventoryWorkflowCountAll($session, $user, 3);
    $service->submit($session, $user);

    expect($product->fresh()->stock_quantity)->toBe(12);
});

test('validate does not modify product stock', function () {
    $product = inventoryWorkflowProduct([], 12);
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);
    inventoryWorkflowCountAll($session, $user, 3);
    $session = $service->submit($session, $user);
    $service->validate($session, $user);

    expect($product->fresh()->stock_quantity)->toBe(12);
});

test('workflow does not create stock movements', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $before = StockMovement::query()->count();

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);
    inventoryWorkflowCountAll($session, $user, 1);
    $session = $service->submit($session, $user);
    $service->validate($session, $user);

    expect(StockMovement::query()->count())->toBe($before);
});

test('cancel transitions active session to cancelled', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = inventoryWorkflowCreateSession($user);
    $session = $service->cancel($session, $user);

    expect($session->status)->toBe(InventorySessionStatus::Cancelled)
        ->and($session->cancelled_by)->toBe($user->id);
});

test('cancel is forbidden after validated workflow would need applied or closed', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = inventoryWorkflowCreateSession($user);
    $session->update(['status' => InventorySessionStatus::Applied]);

    expect(fn () => $service->cancel($session, $user))
        ->toThrow(ValidationException::class);
});

test('close is rejected before applied status', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = inventoryWorkflowCreateSession($user);
    $session->update(['status' => InventorySessionStatus::Validated]);

    expect(fn () => $service->close($session, $user))
        ->toThrow(ValidationException::class);
});

test('session from another company store is rejected at start', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $otherCompany = Company::create(['name' => 'Autre', 'email' => 'other@example.test']);
    $otherStore = Store::ensureDefaultForCompany($otherCompany);

    $session = InventorySession::query()->create([
        'company_id' => inventoryWorkflowCompany()->id,
        'store_id' => $otherStore->id,
        'reference' => 'INV999999',
        'status' => InventorySessionStatus::Draft,
        'scope_type' => InventoryScopeType::Complete,
        'created_by' => $user->id,
    ]);

    expect(fn () => $service->start($session, $user))
        ->toThrow(ValidationException::class);
});

test('concurrent active session on same store is rejected', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    inventoryWorkflowCreateSession($user, ['name' => 'Session A']);

    expect(fn () => inventoryWorkflowCreateSession($user, ['name' => 'Session B']))
        ->toThrow(ValidationException::class);
});

test('inventory references are unique per company', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();

    $first = inventoryWorkflowCreateSession($user, ['name' => 'A']);
    $first->update(['status' => InventorySessionStatus::Cancelled]);

    $second = inventoryWorkflowCreateSession($user, ['name' => 'B']);

    expect($first->reference)->not->toBe($second->reference);
});

test('activity log is created on session creation', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();

    $session = inventoryWorkflowCreateSession($user);

    expect(ActivityLog::query()
        ->where('module', 'Inventaire')
        ->where('action', ActivityLog::ACTION_CREATE)
        ->where('subject_id', $session->id)
        ->exists())->toBeTrue();
});

test('activity log is created on start', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);

    expect(ActivityLog::query()
        ->where('module', 'Inventaire')
        ->where('action', ActivityLog::ACTION_UPDATE)
        ->where('subject_id', $session->id)
        ->where('description', 'like', '%démarré%')
        ->exists())->toBeTrue();
});

test('activity log is created on submit', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);
    inventoryWorkflowCountAll($session, $user);
    $session = $service->submit($session, $user);

    expect(ActivityLog::query()
        ->where('module', 'Inventaire')
        ->where('subject_id', $session->id)
        ->where('description', 'like', '%terminé le comptage%')
        ->exists())->toBeTrue();
});

test('activity log is created on validate', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);
    inventoryWorkflowCountAll($session, $user);
    $session = $service->submit($session, $user);
    $session = $service->validate($session, $user);

    expect(ActivityLog::query()
        ->where('module', 'Inventaire')
        ->where('action', ActivityLog::ACTION_VALIDATE)
        ->where('subject_id', $session->id)
        ->exists())->toBeTrue();
});

test('activity log is created on cancel', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = inventoryWorkflowCreateSession($user);
    $session = $service->cancel($session, $user);

    expect(ActivityLog::query()
        ->where('module', 'Inventaire')
        ->where('action', ActivityLog::ACTION_CANCEL)
        ->where('subject_id', $session->id)
        ->exists())->toBeTrue();
});

test('inventory routes enforce permissions', function () {
    inventoryWorkflowProduct();
    $user = User::factory()->create(['role' => User::ROLE_VENDEUR]);

    $response = $this->actingAs($user)->get(route('inventory.index'));

    $response->assertForbidden();
});

test('count route stores quantity through controller', function () {
    inventoryWorkflowProduct([], 5);
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = $service->start(inventoryWorkflowCreateSession($user), $user);
    $item = $session->items->first();

    $response = $this->actingAs($user)->postJson(route('inventory.items.count', [
        'session' => $session->id,
        'item' => $item->id,
    ]), [
        'quantity_counted' => 2,
    ]);

    $response->assertOk()
        ->assertJsonPath('item.quantity_counted', 2);
});

test('stock positive scope only includes products with positive main stock', function () {
    $positive = inventoryWorkflowProduct([], 4);
    $zero = inventoryWorkflowProduct([], 0);
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = inventoryWorkflowCreateSession($user, [
        'scope_type' => InventoryScopeType::StockPositive,
    ]);

    $session = $service->start($session, $user);
    $productIds = $session->items->pluck('product_id')->all();

    expect($productIds)->toContain($positive->id)
        ->not->toContain($zero->id);
});

test('category scope only includes products from selected category', function () {
    $categoryA = inventoryWorkflowCategory();
    $categoryB = inventoryWorkflowCategory();
    $productA = inventoryWorkflowProduct(['category_id' => $categoryA->id]);
    inventoryWorkflowProduct(['category_id' => $categoryB->id]);
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $session = inventoryWorkflowCreateSession($user, [
        'scope_type' => InventoryScopeType::Category,
        'scope_value' => ['category_id' => $categoryA->id],
    ]);

    $session = $service->start($session, $user);

    expect($session->items)->toHaveCount(1)
        ->and($session->items->first()->product_id)->toBe($productA->id);
});

test('start fails when product stock row is missing', function () {
    $product = inventoryWorkflowProduct([], 0);
    ProductStock::query()->where('product_id', $product->id)->delete();

    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    expect(fn () => $service->start(inventoryWorkflowCreateSession($user), $user))
        ->toThrow(ValidationException::class);
});

test('cancelled session frees store for a new active session', function () {
    inventoryWorkflowProduct();
    $user = inventoryWorkflowPermissions();
    $service = app(InventorySessionService::class);

    $first = inventoryWorkflowCreateSession($user, ['name' => 'A']);
    $service->cancel($first, $user);

    $second = inventoryWorkflowCreateSession($user, ['name' => 'B']);

    expect($second->status)->toBe(InventorySessionStatus::Draft);
});
