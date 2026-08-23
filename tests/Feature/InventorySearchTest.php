<?php

use App\Enums\InventoryScopeType;
use App\Enums\InventorySessionStatus;
use App\Models\Company;
use App\Models\InventorySession;
use App\Models\Permission;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function inventorySearchPermissions(): User
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

function inventorySearchSession(array $overrides = []): InventorySession
{
    $company = Company::getInstance();
    $store = $company->defaultStore()->firstOrFail();

    return InventorySession::query()->create(array_merge([
        'company_id' => $company->id,
        'store_id' => $store->id,
        'reference' => 'INV'.random_int(100000, 999999),
        'name' => 'Inventaire '.uniqid(),
        'description' => null,
        'status' => InventorySessionStatus::Draft,
        'scope_type' => InventoryScopeType::Complete,
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
    ], $overrides));
}

test('inventory search without filter returns paginated sessions', function () {
    $user = inventorySearchPermissions();
    inventorySearchSession(['name' => 'Premier']);
    inventorySearchSession(['name' => 'Second']);

    $response = $this->actingAs($user)->get(route('inventory.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->has('sessions.data', 2)
            ->where('sessions.per_page', 20));
});

test('inventory search by reference is server side', function () {
    $user = inventorySearchPermissions();
    $session = inventorySearchSession(['reference' => 'INV2608999']);
    inventorySearchSession(['reference' => 'INV1111111']);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'search' => 'INV2608999',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sessions.data', 1)
            ->where('sessions.data.0.id', $session->id));
});

test('inventory search by name is server side', function () {
    $user = inventorySearchPermissions();
    $session = inventorySearchSession(['name' => 'Inventaire trimestriel nord']);
    inventorySearchSession(['name' => 'Contrôle hebdo']);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'search' => 'trimestriel',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sessions.data', 1)
            ->where('sessions.data.0.id', $session->id));
});

test('inventory search query params are preserved in filters prop', function () {
    $user = inventorySearchPermissions();

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'search' => 'INV',
        'status' => InventorySessionStatus::Applied->value,
        'list_view' => 'history',
        'page' => 1,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.search', 'INV')
            ->where('filters.status', InventorySessionStatus::Applied->value)
            ->where('filters.list_view', 'history'));
});

test('inventory search remains isolated per company', function () {
    $user = inventorySearchPermissions();
    $visible = inventorySearchSession(['name' => 'Recherche locale', 'reference' => 'INVLOCAL1']);

    $otherCompany = Company::create([
        'name' => 'Entreprise search '.uniqid(),
        'email' => uniqid().'@search.test',
    ]);
    $otherStore = Store::ensureDefaultForCompany($otherCompany);

    InventorySession::query()->create([
        'company_id' => $otherCompany->id,
        'store_id' => $otherStore->id,
        'reference' => 'INVLOCAL1',
        'name' => 'Recherche locale',
        'status' => InventorySessionStatus::Closed,
        'scope_type' => InventoryScopeType::Complete,
        'created_by' => User::factory()->create()->id,
    ]);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'search' => 'Recherche locale',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sessions.data', 1)
            ->where('sessions.data.0.id', $visible->id));
});
