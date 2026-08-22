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
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function inventoryCreatePermissions(array $actions = ['view', 'create']): User
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

function inventoryCreateCategory(): Category
{
    return Category::create(['name' => 'Cat create '.uniqid()]);
}

function inventoryCreateProduct(Category $category, int $stock = 5): Product
{
    Company::getInstance();
    $store = Company::getInstance()->defaultStore()->firstOrFail();

    $product = Product::create([
        'name' => 'Produit create '.uniqid(),
        'sku' => 'CR'.random_int(1000, 9999),
        'price' => 1000,
        'stock_quantity' => $stock,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    ProductStock::query()->updateOrCreate(
        ['product_id' => $product->id, 'store_id' => $store->id],
        ['quantity' => $stock],
    );

    return $product;
}

test('inventory store route creates complete scope session', function () {
    inventoryCreateProduct(inventoryCreateCategory());
    $user = inventoryCreatePermissions();

    $response = $this->actingAs($user)->post(route('inventory.store'), [
        'name' => 'Inventaire complet HTTP',
        'description' => null,
        'scope_type' => InventoryScopeType::Complete->value,
        'scope_value' => null,
    ]);

    $session = InventorySession::query()->latest('id')->first();

    $response->assertRedirect(route('inventory.show', $session))
        ->assertSessionHas('success');

    expect($session)->not->toBeNull()
        ->and($session->status)->toBe(InventorySessionStatus::Draft)
        ->and($session->scope_type)->toBe(InventoryScopeType::Complete);
});

test('inventory store route creates category scope session', function () {
    $category = inventoryCreateCategory();
    inventoryCreateProduct($category);
    $user = inventoryCreatePermissions();

    $response = $this->actingAs($user)->post(route('inventory.store'), [
        'name' => 'Inventaire catégorie HTTP',
        'description' => 'Test catégorie',
        'scope_type' => InventoryScopeType::Category->value,
        'scope_value' => ['category_id' => $category->id],
    ]);

    $session = InventorySession::query()->latest('id')->first();

    $response->assertRedirect(route('inventory.show', $session))
        ->assertSessionHas('success');

    expect($session)->not->toBeNull()
        ->and($session->scope_type)->toBe(InventoryScopeType::Category)
        ->and($session->scope_value)->toBe(['category_id' => $category->id]);
});

test('inventory store route rejects category scope without category id', function () {
    inventoryCreateProduct(inventoryCreateCategory());
    $user = inventoryCreatePermissions();

    $response = $this->actingAs($user)->post(route('inventory.store'), [
        'name' => 'Inventaire catégorie invalide',
        'scope_type' => InventoryScopeType::Category->value,
        'scope_value' => null,
    ]);

    $response->assertSessionHasErrors('scope_value.category_id');
});

test('inventory store route rejects unknown category id', function () {
    inventoryCreateProduct(inventoryCreateCategory());
    $user = inventoryCreatePermissions();

    $response = $this->actingAs($user)->post(route('inventory.store'), [
        'name' => 'Inventaire catégorie inconnue',
        'scope_type' => InventoryScopeType::Category->value,
        'scope_value' => ['category_id' => 999999],
    ]);

    $response->assertSessionHasErrors('scope_value.category_id');
});

test('inventory store route requires inventory create permission', function () {
    inventoryCreateProduct(inventoryCreateCategory());
    $viewer = User::factory()->create(['role' => User::ROLE_GESTIONNAIRE]);
    Permission::firstOrCreate(
        ['resource' => 'inventory', 'action' => 'view'],
        [
            'name' => Permission::generateName('inventory', 'view'),
            'description' => 'inventory.view',
        ],
    );
    $viewer->permissions()->sync(
        Permission::query()->where('resource', 'inventory')->where('action', 'view')->pluck('id'),
    );

    $response = $this->actingAs($viewer)->post(route('inventory.store'), [
        'name' => 'Sans permission',
        'scope_type' => InventoryScopeType::Complete->value,
        'scope_value' => null,
    ]);

    $response->assertForbidden();
});

test('inventory store route creates stock positive scope session', function () {
    inventoryCreateProduct(inventoryCreateCategory(), 4);
    $user = inventoryCreatePermissions();

    $response = $this->actingAs($user)->post(route('inventory.store'), [
        'name' => 'Inventaire stock positif HTTP',
        'scope_type' => InventoryScopeType::StockPositive->value,
        'scope_value' => null,
    ]);

    $session = InventorySession::query()->latest('id')->first();

    $response->assertRedirect(route('inventory.show', $session));

    expect($session->scope_type)->toBe(InventoryScopeType::StockPositive);
});
