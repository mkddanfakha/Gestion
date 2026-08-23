<?php

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
use App\Services\InventoryApplicationService;
use App\Services\InventorySessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function inventoryExportPermissions(array $actions = ['view', 'create', 'count', 'submit', 'review', 'validate', 'apply', 'close', 'export']): User
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

function inventoryExportAppliedSession(User $user): InventorySession
{
    Company::getInstance();
    Store::ensureDefaultForCompany(Company::getInstance());

    $category = Category::create(['name' => 'Export '.uniqid()]);
    $store = Company::getInstance()->defaultStore()->firstOrFail();

    $product = Product::create([
        'name' => 'Produit export '.uniqid(),
        'sku' => 'EX'.random_int(1000, 9999),
        'price' => 1000,
        'stock_quantity' => 10,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    ProductStock::query()->updateOrCreate(
        ['product_id' => $product->id, 'store_id' => $store->id],
        ['quantity' => 10],
    );

    $sessionService = app(InventorySessionService::class);
    $applicationService = app(InventoryApplicationService::class);
    $applicationService = app(InventoryApplicationService::class);

    $session = $sessionService->create([
        'name' => 'Export session '.uniqid(),
        'scope_type' => InventoryScopeType::Complete,
    ], $user);
    $session = $sessionService->start($session, $user);
    $session->load('items');

    foreach ($session->items as $item) {
        $sessionService->countItem($session, $item, $item->stock_snapshot, $user);
    }

    $session = $sessionService->submit($session, $user);
    $session = $sessionService->validate($session, $user);
    $result = $applicationService->apply($session, $user);

    return $result['session'];
}

test('inventory export pdf is authorized for closed session', function () {
    $user = inventoryExportPermissions();
    $session = inventoryExportAppliedSession($user);
    $session = app(InventorySessionService::class)->close($session, $user);

    $response = $this->actingAs($user)->get(route('inventory.export.pdf', $session));

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('inventory export excel is authorized for applied session', function () {
    $user = inventoryExportPermissions();
    $session = inventoryExportAppliedSession($user);

    $response = $this->actingAs($user)->get(route('inventory.export.excel', $session));

    $response->assertOk()
        ->assertDownload();
});

test('inventory export is forbidden without export permission', function () {
    $user = inventoryExportPermissions(['view', 'create', 'count', 'submit', 'review', 'validate', 'apply', 'close']);
    $session = inventoryExportAppliedSession($user);

    $this->actingAs($user)->get(route('inventory.export.pdf', $session))->assertForbidden();
    $this->actingAs($user)->get(route('inventory.export.excel', $session))->assertForbidden();
});

test('inventory export pdf rejects active session', function () {
    $user = inventoryExportPermissions();
    $session = app(InventorySessionService::class)->create([
        'name' => 'Draft export',
        'scope_type' => InventoryScopeType::Complete,
    ], $user);

    $this->actingAs($user)->get(route('inventory.export.pdf', $session))->assertStatus(422);
});

test('inventory export pdf idor returns 404 for foreign company', function () {
    $user = inventoryExportPermissions();
    Company::getInstance();
    $otherCompany = Company::create([
        'name' => 'Entreprise export '.uniqid(),
        'email' => uniqid().'@export.test',
    ]);
    $otherStore = Store::ensureDefaultForCompany($otherCompany);
    $foreignSession = InventorySession::query()->create([
        'company_id' => $otherCompany->id,
        'store_id' => $otherStore->id,
        'reference' => 'INV-FOR-PDF',
        'name' => 'Inventaire étranger',
        'status' => InventorySessionStatus::Closed,
        'scope_type' => InventoryScopeType::Complete,
        'created_by' => User::factory()->create()->id,
        'closed_at' => now(),
    ]);

    $this->actingAs($user)->get(route('inventory.export.pdf', $foreignSession))->assertNotFound();
});

test('inventory export excel idor returns 404 for foreign company', function () {
    $user = inventoryExportPermissions();
    Company::getInstance();
    $otherCompany = Company::create([
        'name' => 'Entreprise export excel '.uniqid(),
        'email' => uniqid().'@export-excel.test',
    ]);
    $otherStore = Store::ensureDefaultForCompany($otherCompany);
    $foreignSession = InventorySession::query()->create([
        'company_id' => $otherCompany->id,
        'store_id' => $otherStore->id,
        'reference' => 'INV-FOR-XLS',
        'name' => 'Inventaire étranger excel',
        'status' => InventorySessionStatus::Applied,
        'scope_type' => InventoryScopeType::Complete,
        'created_by' => User::factory()->create()->id,
        'applied_at' => now(),
    ]);

    $this->actingAs($user)->get(route('inventory.export.excel', $foreignSession))->assertNotFound();
});

test('inventory export returns 404 for missing session', function () {
    $user = inventoryExportPermissions();

    $this->actingAs($user)->get('/inventory/999999/export/pdf')->assertNotFound();
    $this->actingAs($user)->get('/inventory/999999/export/excel')->assertNotFound();
});

test('inventory show exposes export permission on history session', function () {
    $user = inventoryExportPermissions();
    $session = inventoryExportAppliedSession($user);

    $response = $this->actingAs($user)->get(route('inventory.show', $session));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('countingSession.permissions.export', true)
            ->where('countingSession.is_history', true));
});
