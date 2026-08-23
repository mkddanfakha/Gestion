<?php

use App\Auth\AuthorizationService;
use App\Auth\PermissionCatalog;
use App\Auth\RolePresets;
use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->authorization = app(AuthorizationService::class);
});

/**
 * @return array<string, string> legacy => canonical
 */
function legacyEditUpdatePairs(): array
{
    return [
        'products.edit' => 'products.update',
        'categories.edit' => 'categories.update',
        'customers.edit' => 'customers.update',
        'sales.edit' => 'sales.update',
        'quotes.edit' => 'quotes.update',
        'expenses.edit' => 'expenses.update',
        'suppliers.edit' => 'suppliers.update',
        'purchase-orders.edit' => 'purchase-orders.update',
        'delivery-notes.edit' => 'delivery-notes.update',
        'company.edit' => 'company.update',
    ];
}

function userWithPermissionNames(array $names): User
{
    $user = User::factory()->create(['role' => User::ROLE_USER]);
    $user->permissions()->sync(
        Permission::query()->whereIn('name', $names)->pluck('id'),
    );

    return $user->fresh();
}

test('PermissionCatalog marque chaque permission edit comme legacy', function () {
    foreach (legacyEditUpdatePairs() as $legacy => $canonical) {
        expect(PermissionCatalog::exists($legacy))->toBeTrue();
        expect(PermissionCatalog::isLegacy($legacy))->toBeTrue();
        expect(PermissionCatalog::canonicalName($legacy))->toBe($canonical);
        expect(PermissionCatalog::canonicalName($canonical))->toBe($canonical);
        expect(PermissionCatalog::isLegacy($canonical))->toBeFalse();
    }
});

test('utilisateur avec update peut acceder via allows update', function () {
    foreach (legacyEditUpdatePairs() as $legacy => $canonical) {
        $user = userWithPermissionNames([$canonical]);
        expect($this->authorization->allows($user, $canonical))->toBeTrue();
    }
});

test('utilisateur avec update peut acceder via allows edit legacy', function () {
    foreach (legacyEditUpdatePairs() as $legacy => $canonical) {
        $user = userWithPermissionNames([$canonical]);
        expect($this->authorization->allows($user, $legacy))->toBeTrue();
    }
});

test('utilisateur avec edit legacy peut acceder via allows update', function () {
    foreach (legacyEditUpdatePairs() as $legacy => $canonical) {
        $user = userWithPermissionNames([$legacy]);
        expect($this->authorization->allows($user, $canonical))->toBeTrue();
    }
});

test('utilisateur sans edit ni update est refuse', function () {
    foreach (legacyEditUpdatePairs() as $legacy => $canonical) {
        $user = userWithPermissionNames(['dashboard.view']);
        expect($this->authorization->allows($user, $canonical))->toBeFalse();
        expect($this->authorization->allows($user, $legacy))->toBeFalse();
    }
});

test('admin bypass pour edit et update', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

    foreach (legacyEditUpdatePairs() as $legacy => $canonical) {
        expect($this->authorization->allows($admin, $canonical))->toBeTrue();
        expect($this->authorization->allows($admin, $legacy))->toBeTrue();
    }
});

test('RolePresets n utilise que des permissions update canoniques', function () {
    foreach ([User::ROLE_VENDEUR, User::ROLE_GESTIONNAIRE] as $role) {
        foreach (RolePresets::permissionNames($role) as $name) {
            expect(str_ends_with($name, '.edit'))->toBeFalse();
        }
    }
});

function createTestProduct(): \App\Models\Product
{
    $category = \App\Models\Category::create([
        'name' => 'Cat test',
        'slug' => 'cat-'.uniqid(),
    ]);

    return \App\Models\Product::create([
        'name' => 'Test',
        'sku' => 'TST-'.uniqid(),
        'price' => 100,
        'cost_price' => 80,
        'stock_quantity' => 1,
        'min_stock_level' => 0,
        'unit' => 'u',
        'category_id' => $category->id,
        'is_active' => true,
    ]);
}

test('Controller checkPermission accepte update pour formulaire edit produit', function () {
    $user = userWithPermissionNames(['products.update']);
    $product = createTestProduct();

    $this->actingAs($user)
        ->get(route('products.edit', ['product' => $product->id]))
        ->assertOk();
});

test('Controller checkPermission accepte edit legacy pour formulaire edit produit', function () {
    $user = userWithPermissionNames(['products.edit']);
    $product = createTestProduct();

    $this->actingAs($user)
        ->get(route('products.edit', ['product' => $product->id]))
        ->assertOk();
});

test('Controller checkPermission refuse edit produit sans permission', function () {
    $user = userWithPermissionNames(['products.view']);
    $product = createTestProduct();

    $this->actingAs($user)
        ->get(route('products.edit', ['product' => $product->id]))
        ->assertForbidden();
});

test('PermissionSeeder conserve les permissions edit sans modifier user_permissions', function () {
    $user = userWithPermissionNames(['products.edit']);
    $pivotBefore = $user->permissions()->pluck('permissions.id')->sort()->values()->all();

    $this->seed(PermissionSeeder::class);
    $this->seed(PermissionSeeder::class);

    $user->refresh();
    $pivotAfter = $user->permissions()->pluck('permissions.id')->sort()->values()->all();

    expect($pivotAfter)->toBe($pivotBefore);
    expect(Permission::query()->where('name', 'products.edit')->exists())->toBeTrue();
});

test('inventory review reste hors du mapping edit update', function () {
    expect(PermissionCatalog::isLegacy(PermissionName::InventoryReview))->toBeTrue();
    expect(PermissionCatalog::canonicalName(PermissionName::InventoryReview))->toBe('inventory.reopen');
    expect(PermissionCatalog::canonical(PermissionName::InventoryReview))->toBe(PermissionName::InventoryReopen);
});
