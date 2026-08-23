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

function inventoryHistoryPermissions(array $actions = ['view']): User
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

function inventoryHistorySession(array $overrides = []): InventorySession
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

test('inventory index list view active returns only active sessions', function () {
    $user = inventoryHistoryPermissions();
    $active = inventoryHistorySession(['status' => InventorySessionStatus::Counting, 'name' => 'Actif']);
    inventoryHistorySession(['status' => InventorySessionStatus::Closed, 'name' => 'Historique']);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'list_view' => 'active',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->has('sessions.data', 1)
            ->where('sessions.data.0.id', $active->id)
            ->where('filters.list_view', 'active'));
});

test('inventory index list view history returns only terminated sessions', function () {
    $user = inventoryHistoryPermissions();
    inventoryHistorySession(['status' => InventorySessionStatus::Counting, 'name' => 'Actif']);
    $closed = inventoryHistorySession(['status' => InventorySessionStatus::Closed, 'name' => 'Historique']);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'list_view' => 'history',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->has('sessions.data', 1)
            ->where('sessions.data.0.id', $closed->id)
            ->where('listStats.history_count', 1));
});

test('inventory index filters by store id within company', function () {
    $user = inventoryHistoryPermissions();
    $company = Company::getInstance();
    $defaultStore = $company->defaultStore()->firstOrFail();
    $otherStore = Store::query()->create([
        'company_id' => $company->id,
        'name' => 'Entrepôt test '.uniqid(),
        'code' => 'TST'.random_int(100, 999),
        'is_default' => false,
        'is_active' => true,
    ]);

    $session = inventoryHistorySession(['store_id' => $otherStore->id]);
    inventoryHistorySession(['store_id' => $defaultStore->id]);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'store_id' => $otherStore->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->has('sessions.data', 1)
            ->where('sessions.data.0.id', $session->id)
            ->where('filters.store_id', (string) $otherStore->id));
});

test('inventory show returns history payload for closed session', function () {
    $user = inventoryHistoryPermissions();
    $session = inventoryHistorySession([
        'status' => InventorySessionStatus::Closed,
        'name' => 'Inventaire clôturé',
        'closed_at' => now(),
        'closed_by' => $user->id,
    ]);

    $response = $this->actingAs($user)->get(route('inventory.show', $session));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->where('countingSession.id', $session->id)
            ->where('countingSession.is_history', true)
            ->has('countingSession.kpi')
            ->has('countingSession.movements'));
});

test('inventory history pagination preserves list view filter', function () {
    $user = inventoryHistoryPermissions();

    for ($index = 0; $index < 21; $index++) {
        inventoryHistorySession([
            'status' => InventorySessionStatus::Closed,
            'name' => "Historique {$index}",
        ]);
    }

    inventoryHistorySession(['status' => InventorySessionStatus::Counting, 'name' => 'Actif seul']);

    $response = $this->actingAs($user)->get(route('inventory.index', [
        'list_view' => 'history',
        'page' => 2,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->where('sessions.current_page', 2)
            ->where('filters.list_view', 'history'));
});

test('inventory index exposes stores for filter select', function () {
    $user = inventoryHistoryPermissions();
    $company = Company::getInstance();

    $response = $this->actingAs($user)->get(route('inventory.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->has('stores', 1)
            ->where('stores.0.id', $company->defaultStore()->firstOrFail()->id));
});
