<?php

use App\Auth\RolePresets;
use App\Enums\InventoryScopeType;
use App\Enums\InventorySessionStatus;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventorySession;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\User;
use App\Services\InventorySessionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function inventoryReopenUserWithNames(array $permissionNames, string $role = User::ROLE_USER): User
{
    $user = User::factory()->create(['role' => $role]);
    $user->permissions()->sync(
        Permission::query()->whereIn('name', $permissionNames)->pluck('id'),
    );

    return $user->fresh();
}

function inventoryReopenSessionInReview(User $actor): InventorySession
{
    Company::getInstance();
    $store = Company::getInstance()->defaultStore()->firstOrFail();
    $category = Category::create(['name' => 'Reopen '.uniqid()]);

    $product = Product::create([
        'name' => 'Produit reopen '.uniqid(),
        'sku' => 'RO'.random_int(1000, 9999),
        'price' => 1000,
        'stock_quantity' => 10,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    ProductStock::query()->firstOrCreate(
        ['product_id' => $product->id, 'store_id' => $store->id],
        ['quantity' => 10],
    );

    $service = app(InventorySessionService::class);
    $session = $service->create([
        'name' => 'Session reopen '.uniqid(),
        'scope_type' => InventoryScopeType::Complete,
    ], $actor);
    $session = $service->start($session, $actor);

    foreach ($session->fresh('items')->items as $item) {
        $service->countItem($session, $item, $item->stock_snapshot, $actor);
    }

    return $service->submit($session, $actor);
}

test('user avec inventory.reopen peut reouvrir', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $session = inventoryReopenSessionInReview($admin);
    $user = inventoryReopenUserWithNames(['inventory.view', 'inventory.reopen']);

    $this->actingAs($user)
        ->post(route('inventory.reopen', $session))
        ->assertRedirect();

    expect($session->fresh()->status)->toBe(InventorySessionStatus::Counting);
});

test('user avec inventory.review uniquement peut encore reouvrir', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $session = inventoryReopenSessionInReview($admin);
    $user = inventoryReopenUserWithNames(['inventory.view', 'inventory.review']);

    $this->actingAs($user)
        ->post(route('inventory.reopen', $session))
        ->assertRedirect();

    expect($session->fresh()->status)->toBe(InventorySessionStatus::Counting);
});

test('user sans review ni reopen recoit 403', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $session = inventoryReopenSessionInReview($admin);
    $user = inventoryReopenUserWithNames(['inventory.view', 'inventory.count']);

    $this->actingAs($user)
        ->post(route('inventory.reopen', $session))
        ->assertForbidden();

    expect($session->fresh()->status)->toBe(InventorySessionStatus::Review);
});

test('gestionnaire preset peut reouvrir', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $session = inventoryReopenSessionInReview($admin);

    $gestionnaire = User::factory()->create(['role' => User::ROLE_GESTIONNAIRE]);
    $gestionnaire->permissions()->sync(RolePresets::permissionIds(User::ROLE_GESTIONNAIRE));

    $this->actingAs($gestionnaire->fresh())
        ->post(route('inventory.reopen', $session))
        ->assertRedirect();

    expect($session->fresh()->status)->toBe(InventorySessionStatus::Counting);
});

test('vendeur ne peut pas reouvrir', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $session = inventoryReopenSessionInReview($admin);

    $vendeur = User::factory()->create(['role' => User::ROLE_VENDEUR]);
    $vendeur->permissions()->sync(RolePresets::permissionIds(User::ROLE_VENDEUR));

    $this->actingAs($vendeur->fresh())
        ->post(route('inventory.reopen', $session))
        ->assertForbidden();
});

test('admin peut reouvrir via bypass', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $session = inventoryReopenSessionInReview($admin);

    $this->actingAs($admin)
        ->post(route('inventory.reopen', $session))
        ->assertRedirect();

    expect($session->fresh()->status)->toBe(InventorySessionStatus::Counting);
});

test('reopen sur session autre company retourne 404', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    inventoryReopenSessionInReview($admin);

    $otherCompany = Company::create(['name' => 'Autre reopen', 'email' => 'reopen-other@example.test']);
    $otherStore = Store::ensureDefaultForCompany($otherCompany);
    $foreignSession = InventorySession::query()->create([
        'company_id' => $otherCompany->id,
        'store_id' => $otherStore->id,
        'reference' => 'INV-RO-'.random_int(100000, 999999),
        'status' => InventorySessionStatus::Review,
        'scope_type' => InventoryScopeType::Complete,
        'created_by' => $admin->id,
    ]);

    $user = inventoryReopenUserWithNames(['inventory.view', 'inventory.reopen']);

    $this->actingAs($user)
        ->post(route('inventory.reopen', $foreignSession))
        ->assertNotFound();
});

test('payload session expose permissions.reopen pour utilisateur legacy review', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $session = inventoryReopenSessionInReview($admin);
    $user = inventoryReopenUserWithNames(['inventory.view', 'inventory.review']);

    $payload = app(InventorySessionService::class)->formatSessionDetailPayload($session, $user);

    expect($payload['permissions']['reopen'])->toBeTrue()
        ->and($payload['permissions']['review'])->toBeTrue();
});

test('seeder cree inventory.reopen sans supprimer inventory.review ni pivots', function () {
    $user = inventoryReopenUserWithNames(['inventory.review']);
    $before = $user->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all();

    $this->seed(PermissionSeeder::class);
    $this->seed(PermissionSeeder::class);

    $user->refresh();

    expect(Permission::query()->where('name', 'inventory.reopen')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'inventory.review')->exists())->toBeTrue()
        ->and($user->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all())->toBe($before);
});
