<?php

use App\Auth\RolePresets;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

test('utilisateur avec products.view accede a la liste produits via Controller checkPermission', function () {
    $user = User::factory()->create([
        'role' => User::ROLE_USER,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->permissions()->sync(
        Permission::query()->where('name', 'products.view')->pluck('id'),
    );

    $this->actingAs($user)
        ->get(route('products.index'))
        ->assertOk();
});

test('utilisateur sans products.view recoit 403 sur la liste produits', function () {
    $user = User::factory()->create([
        'role' => User::ROLE_USER,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->permissions()->sync(
        Permission::query()->where('name', 'sales.view')->pluck('id'),
    );

    $this->actingAs($user)
        ->get(route('products.index'))
        ->assertForbidden();
});

test('admin bypass centralise sur route protegee par checkPermission', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('products.index'))
        ->assertOk();
});

test('legacy products.edit autorise products.update via AuthorizationService sur edit produit', function () {
    $user = User::factory()->create([
        'role' => User::ROLE_USER,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->permissions()->sync(
        Permission::query()->whereIn('name', ['products.view', 'products.edit'])->pluck('id'),
    );

    $category = \App\Models\Category::query()->create([
        'name' => 'Cat N1',
        'slug' => 'cat-n1-'.uniqid(),
    ]);

    $product = \App\Models\Product::query()->create([
        'name' => 'Produit N1 Legacy Edit',
        'sku' => 'N1-LEGACY-'.uniqid(),
        'price' => 10,
        'cost_price' => 5,
        'stock_quantity' => 0,
        'min_stock_level' => 0,
        'unit' => 'u',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('products.edit', ['product' => $product->id]))
        ->assertOk();
});

test('rbac actif ne depend pas de la classe middleware CheckPermission', function () {
    expect(file_exists(app_path('Http/Middleware/CheckPermission.php')))->toBeFalse();

    $vendeur = User::factory()->create([
        'role' => User::ROLE_VENDEUR,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $vendeur->permissions()->sync(RolePresets::permissionIds(User::ROLE_VENDEUR));

    $this->actingAs($vendeur)
        ->get(route('sales.index'))
        ->assertOk();
});
