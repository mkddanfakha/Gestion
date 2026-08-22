<?php

use App\Enums\InventoryScopeType;
use App\Enums\InventorySessionStatus;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventorySession;
use App\Models\Permission;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function inventoryListPermissions(): User
{
    Permission::firstOrCreate(
        ['resource' => 'inventory', 'action' => 'view'],
        [
            'name' => Permission::generateName('inventory', 'view'),
            'description' => 'inventory.view',
        ],
    );

    $user = User::factory()->create(['role' => User::ROLE_GESTIONNAIRE]);
    $user->permissions()->sync(
        Permission::query()->where('resource', 'inventory')->pluck('id'),
    );

    return $user;
}

function inventoryListCategory(string $name = 'Riz'): Category
{
    return Category::create([
        'name' => $name,
        'slug' => strtolower($name).'-'.uniqid(),
    ]);
}

function inventoryListSession(array $overrides = []): InventorySession
{
    $company = Company::getInstance();
    $store = $company->defaultStore()->firstOrFail();
    $user = User::factory()->create();

    return InventorySession::query()->create(array_merge([
        'company_id' => $company->id,
        'store_id' => $store->id,
        'reference' => 'INV'.random_int(100000, 999999),
        'name' => 'Inventaire '.uniqid(),
        'description' => null,
        'status' => InventorySessionStatus::Draft,
        'scope_type' => InventoryScopeType::Complete,
        'created_by' => $user->id,
        'created_at' => now(),
    ], $overrides));
}

test('inventory index search matches reference', function () {
    $user = inventoryListPermissions();
    $session = inventoryListSession(['reference' => 'INV2608003', 'name' => 'Autre nom']);
    inventoryListSession(['reference' => 'INV9999999', 'name' => 'Sans match']);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'search' => 'INV2608003',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->has('sessions.data', 1)
            ->where('sessions.data.0.id', $session->id)
            ->where('filters.search', 'INV2608003'));
});

test('inventory index search matches name', function () {
    $user = inventoryListPermissions();
    $session = inventoryListSession(['name' => 'Inventaire août magasin']);
    inventoryListSession(['name' => 'Contrôle trimestriel']);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'search' => 'août',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->has('sessions.data', 1)
            ->where('sessions.data.0.id', $session->id));
});

test('inventory index search matches description', function () {
    $user = inventoryListPermissions();
    $session = inventoryListSession(['description' => 'description stock riz']);
    inventoryListSession(['description' => 'Contrôle huile']);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'search' => 'stock riz',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->has('sessions.data', 1)
            ->where('sessions.data.0.id', $session->id));
});

test('inventory index search matches store name', function () {
    $user = inventoryListPermissions();
    $company = Company::getInstance();
    $store = Store::query()->create([
        'company_id' => $company->id,
        'name' => 'Entrepôt Nord '.uniqid(),
        'code' => 'TEST'.random_int(100, 999),
        'is_default' => false,
        'is_active' => true,
    ]);
    $session = inventoryListSession(['store_id' => $store->id, 'name' => 'Inventaire magasin']);
    inventoryListSession(['name' => 'Autre inventaire']);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'search' => $store->name,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->has('sessions.data', 1)
            ->where('sessions.data.0.id', $session->id));
});

test('inventory index filters by status', function () {
    $user = inventoryListPermissions();
    $session = inventoryListSession(['status' => InventorySessionStatus::Counting]);
    inventoryListSession(['status' => InventorySessionStatus::Draft]);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'status' => InventorySessionStatus::Counting->value,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->has('sessions.data', 1)
            ->where('sessions.data.0.id', $session->id)
            ->where('filters.status', InventorySessionStatus::Counting->value));
});

test('inventory index filters by scope type', function () {
    $user = inventoryListPermissions();
    $session = inventoryListSession(['scope_type' => InventoryScopeType::StockPositive]);
    inventoryListSession(['scope_type' => InventoryScopeType::Complete]);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'scope_type' => InventoryScopeType::StockPositive->value,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->has('sessions.data', 1)
            ->where('sessions.data.0.id', $session->id));
});

test('inventory index filters by category id in scope value', function () {
    $user = inventoryListPermissions();
    $category = inventoryListCategory('Riz');
    $otherCategory = inventoryListCategory('Huile');

    $session = inventoryListSession([
        'scope_type' => InventoryScopeType::Category,
        'scope_value' => ['category_id' => $category->id],
        'name' => 'Inventaire riz',
    ]);
    inventoryListSession([
        'scope_type' => InventoryScopeType::Category,
        'scope_value' => ['category_id' => $otherCategory->id],
        'name' => 'Inventaire huile',
    ]);
    inventoryListSession([
        'scope_type' => InventoryScopeType::Complete,
        'scope_value' => null,
        'name' => 'Inventaire complet',
    ]);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'category_id' => $category->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->has('sessions.data', 1)
            ->where('sessions.data.0.id', $session->id));
});

test('inventory index filters by created date range', function () {
    $user = inventoryListPermissions();
    $session = inventoryListSession([
        'reference' => 'INV-DATE-AAA',
        'created_at' => '2026-08-15 10:00:00',
    ]);
    inventoryListSession([
        'reference' => 'INV-DATE-BBB',
        'created_at' => '2026-07-01 10:00:00',
    ]);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'search' => 'INV-DATE-AAA',
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->has('sessions.data', 1)
            ->where('sessions.data.0.id', $session->id));
});

test('inventory index combines multiple filters with AND logic', function () {
    $user = inventoryListPermissions();
    $category = inventoryListCategory('Riz');

    $session = inventoryListSession([
        'name' => 'Inventaire riz mensuel',
        'description' => 'Contrôle riz',
        'status' => InventorySessionStatus::Counting,
        'scope_type' => InventoryScopeType::Category,
        'scope_value' => ['category_id' => $category->id],
        'created_at' => '2026-08-10 09:00:00',
    ]);

    inventoryListSession([
        'name' => 'Inventaire riz brouillon',
        'description' => 'Contrôle riz',
        'status' => InventorySessionStatus::Draft,
        'scope_type' => InventoryScopeType::Category,
        'scope_value' => ['category_id' => $category->id],
        'created_at' => '2026-08-10 09:00:00',
    ]);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'search' => 'riz',
        'status' => InventorySessionStatus::Counting->value,
        'scope_type' => InventoryScopeType::Category->value,
        'category_id' => $category->id,
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->has('sessions.data', 1)
            ->where('sessions.data.0.id', $session->id));
});

test('inventory index pagination preserves active filters', function () {
    $user = inventoryListPermissions();

    for ($index = 0; $index < 21; $index++) {
        inventoryListSession([
            'name' => "Inventaire riz {$index}",
            'status' => InventorySessionStatus::Counting,
        ]);
    }

    inventoryListSession([
        'name' => 'Inventaire huile',
        'status' => InventorySessionStatus::Draft,
    ]);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'search' => 'riz',
        'status' => InventorySessionStatus::Counting->value,
        'page' => 2,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->where('sessions.current_page', 2)
            ->where('filters.search', 'riz')
            ->where('filters.status', InventorySessionStatus::Counting->value));
});

test('inventory index keeps company isolation with filters', function () {
    $user = inventoryListPermissions();
    $visible = inventoryListSession(['name' => 'Inventaire visible', 'reference' => 'INV1111111']);

    $otherCompany = Company::create([
        'name' => 'Entreprise B '.uniqid(),
        'email' => uniqid().'@b.test',
    ]);
    $otherStore = Store::ensureDefaultForCompany($otherCompany);

    InventorySession::query()->create([
        'company_id' => $otherCompany->id,
        'store_id' => $otherStore->id,
        'reference' => 'INV2222222',
        'name' => 'Inventaire visible',
        'status' => InventorySessionStatus::Counting,
        'scope_type' => InventoryScopeType::Complete,
        'created_by' => User::factory()->create()->id,
    ]);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'search' => 'visible',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->has('sessions.data', 1)
            ->where('sessions.data.0.id', $visible->id));
});

test('inventory index category filter does not leak sessions from another company', function () {
    $user = inventoryListPermissions();
    $category = inventoryListCategory('Riz partagé');

    inventoryListSession([
        'scope_type' => InventoryScopeType::Category,
        'scope_value' => ['category_id' => $category->id],
        'name' => 'Inventaire local',
    ]);

    $otherCompany = Company::create([
        'name' => 'Entreprise C '.uniqid(),
        'email' => uniqid().'@c.test',
    ]);
    $otherStore = Store::ensureDefaultForCompany($otherCompany);

    InventorySession::query()->create([
        'company_id' => $otherCompany->id,
        'store_id' => $otherStore->id,
        'reference' => 'INV3333333',
        'name' => 'Inventaire distant',
        'status' => InventorySessionStatus::Draft,
        'scope_type' => InventoryScopeType::Category,
        'scope_value' => ['category_id' => $category->id],
        'created_by' => User::factory()->create()->id,
    ]);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'category_id' => $category->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->has('sessions.data', 1)
            ->where('sessions.data.0.name', 'Inventaire local'));
});

test('inventory index returns empty results for unmatched search', function () {
    $user = inventoryListPermissions();
    inventoryListSession(['name' => 'Inventaire existant']);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'search' => 'introuvable-xyz',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->has('sessions.data', 0)
            ->where('hasSessions', true)
            ->where('filters.search', 'introuvable-xyz'));
});

test('inventory show preserves list filters for back navigation', function () {
    $user = inventoryListPermissions();
    $session = inventoryListSession(['name' => 'Inventaire retour']);

    $response = $this->actingAs($user)->get(route('inventory.show', [
        'session' => $session->id,
        'search' => 'retour',
        'status' => InventorySessionStatus::Draft->value,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->where('listFilters.search', 'retour')
            ->where('listFilters.status', InventorySessionStatus::Draft->value));
});
