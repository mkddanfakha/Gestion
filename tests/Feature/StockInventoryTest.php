<?php

use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function seedStockInventoryPermissions(): User
{
    foreach (['view', 'update'] as $action) {
        Permission::firstOrCreate(
            ['resource' => 'products', 'action' => $action],
            [
                'name' => Permission::generateName('products', $action),
                'description' => "products.{$action}",
            ],
        );
    }

    $user = User::factory()->create();
    $user->permissions()->sync(
        Permission::where('resource', 'products')->pluck('id'),
    );

    return $user;
}

test('stock inventory count updates product stock by barcode', function () {
    $user = seedStockInventoryPermissions();

    $category = Category::create([
        'name' => 'Inventaire',
        'slug' => 'inventaire-'.uniqid(),
    ]);

    $product = Product::create([
        'name' => 'Produit inventaire',
        'sku' => 'INV-'.uniqid(),
        'barcode' => '6043000070493',
        'price' => 1000,
        'cost_price' => 500,
        'stock_quantity' => 12,
        'min_stock_level' => 2,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)->postJson(route('stock-inventory.count'), [
        'barcode' => '6043000070493',
        'counted_quantity' => 20,
    ]);

    $response->assertOk()
        ->assertJsonPath('counted_quantity', 20)
        ->assertJsonPath('previous_stock', 12)
        ->assertJsonPath('delta', 8);

    expect($product->fresh()->stock_quantity)->toBe(20);
});

test('stock inventory preserves leading zeros in barcode lookup', function () {
    $user = seedStockInventoryPermissions();

    $category = Category::create([
        'name' => 'Leading zero',
        'slug' => 'leading-zero-'.uniqid(),
    ]);

    Product::create([
        'name' => 'Produit zéro initial',
        'sku' => 'LZ-'.uniqid(),
        'barcode' => '030000030493',
        'price' => 500,
        'cost_price' => 200,
        'stock_quantity' => 3,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)->postJson(route('stock-inventory.count'), [
        'barcode' => '030000030493',
        'counted_quantity' => 7,
    ]);

    $response->assertOk()->assertJsonPath('counted_quantity', 7);
});

test('stock inventory returns 404 for unknown barcode', function () {
    $user = seedStockInventoryPermissions();

    $response = $this->actingAs($user)->postJson(route('stock-inventory.count'), [
        'barcode' => '9999999999999',
        'counted_quantity' => 1,
    ]);

    $response->assertNotFound();
});
