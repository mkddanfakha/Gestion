<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductBarcodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedProductFormPermissions(): void
{
    foreach (['view', 'create', 'edit', 'update'] as $action) {
        \App\Models\Permission::firstOrCreate(
            [
                'resource' => 'products',
                'action' => $action,
            ],
            [
                'name' => \App\Models\Permission::generateName('products', $action),
                'description' => "products.{$action}",
            ],
        );
    }
}

function createProductFormCategory(): Category
{
    return Category::create([
        'name' => 'Formulaire Produit',
        'slug' => 'product-form-'.uniqid(),
    ]);
}

test('product barcode availability returns true for unused barcode', function () {
    seedProductFormPermissions();

    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->getJson(route('products.barcode.availability', [
        'barcode' => '6043000070493',
    ]));

    $response->assertOk()
        ->assertJson([
            'available' => true,
            'barcode' => '6043000070493',
        ]);
});

test('product barcode availability returns false when barcode belongs to another product', function () {
    seedProductFormPermissions();

    $admin = User::factory()->create(['role' => 'admin']);
    $category = createProductFormCategory();

    $existing = Product::create([
        'name' => 'Produit existant',
        'sku' => 'AB1234',
        'barcode' => '6043000070493',
        'price' => 1000,
        'cost_price' => 500,
        'stock_quantity' => 10,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->getJson(route('products.barcode.availability', [
        'barcode' => '6043000070493',
    ]));

    $response->assertOk()
        ->assertJson([
            'available' => false,
            'barcode' => '6043000070493',
        ]);

    $sameProductResponse = $this->actingAs($admin)->getJson(route('products.barcode.availability', [
        'barcode' => '6043000070493',
        'exclude' => $existing->id,
    ]));

    $sameProductResponse->assertOk()
        ->assertJson([
            'available' => true,
            'barcode' => '6043000070493',
        ]);
});

test('product store rejects duplicate barcode', function () {
    seedProductFormPermissions();

    $admin = User::factory()->create(['role' => 'admin']);
    $category = createProductFormCategory();

    Product::create([
        'name' => 'Produit existant',
        'sku' => 'AB1234',
        'barcode' => '6925125451107',
        'price' => 1000,
        'cost_price' => 500,
        'stock_quantity' => 10,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->post(route('products.store'), [
        'name' => 'Nouveau produit',
        'sku' => 'CD5678',
        'barcode' => '6925125451107',
        'price' => 1500,
        'cost_price' => 900,
        'stock_quantity' => 5,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $response->assertSessionHasErrors(['barcode']);
});

test('product update allows unchanged barcode for same product', function () {
    seedProductFormPermissions();

    $admin = User::factory()->create(['role' => 'admin']);
    $category = createProductFormCategory();

    $product = Product::create([
        'name' => 'Produit modifiable',
        'sku' => 'EF9012',
        'barcode' => '6043000070493',
        'price' => 1000,
        'cost_price' => 500,
        'stock_quantity' => 10,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->put(route('products.update', $product), [
        'name' => 'Produit modifiable',
        'sku' => 'EF9012',
        'barcode' => '6043000070493',
        'price' => 1200,
        'cost_price' => 500,
        'stock_quantity' => 10,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $response->assertRedirect(route('products.index'))
        ->assertSessionHasNoErrors();

    expect((float) $product->fresh()->price)->toBe(1200.0);
});

test('product update rejects barcode already used by another product', function () {
    seedProductFormPermissions();

    $admin = User::factory()->create(['role' => 'admin']);
    $category = createProductFormCategory();

    Product::create([
        'name' => 'Produit A',
        'sku' => 'GH3456',
        'barcode' => '6043000070493',
        'price' => 1000,
        'cost_price' => 500,
        'stock_quantity' => 10,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $productB = Product::create([
        'name' => 'Produit B',
        'sku' => 'IJ7890',
        'barcode' => '6925125451107',
        'price' => 1000,
        'cost_price' => 500,
        'stock_quantity' => 10,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->put(route('products.update', $productB), [
        'name' => 'Produit B',
        'sku' => 'IJ7890',
        'barcode' => '6043000070493',
        'price' => 1000,
        'cost_price' => 500,
        'stock_quantity' => 10,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $response->assertSessionHasErrors(['barcode']);
});

test('product barcode service reports availability with exclude id', function () {
    $service = app(ProductBarcodeService::class);
    $category = createProductFormCategory();

    $product = Product::create([
        'name' => 'Produit test',
        'sku' => 'KL1111',
        'barcode' => '6043000070493',
        'price' => 1000,
        'cost_price' => 500,
        'stock_quantity' => 10,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    expect($service->isBarcodeAvailable('6043000070493'))->toBeFalse()
        ->and($service->isBarcodeAvailable('6043000070493', $product->id))->toBeTrue()
        ->and($service->isBarcodeAvailable('9999999999999'))->toBeTrue();
});
