<?php

use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedProductIndexPermissions(): void
{
    Permission::firstOrCreate(
        ['resource' => 'products', 'action' => 'view'],
        [
            'name' => Permission::generateName('products', 'view'),
            'description' => 'products.view',
        ],
    );
}

function createProductIndexFixture(string $name, string $sku, ?string $barcode = null): Product
{
    $category = Category::create([
        'name' => 'Index Test',
        'slug' => 'index-test-'.uniqid(),
    ]);

    return Product::create([
        'name' => $name,
        'sku' => $sku,
        'barcode' => $barcode,
        'price' => 1000,
        'cost_price' => 500,
        'stock_quantity' => 10,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);
}

test('products index search matches product name', function () {
    seedProductIndexPermissions();

    $admin = User::factory()->create(['role' => 'admin']);
    $product = createProductIndexFixture('Riz parfumé', 'RI0001', '6043000070493');
    createProductIndexFixture('Haricot rouge', 'HA0002', '6925125451107');

    $response = $this->actingAs($admin)->get(route('products.index', [
        'search' => 'Riz',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Products/Index')
            ->has('products.data', 1)
            ->where('products.data.0.id', $product->id)
            ->where('filters.search', 'Riz'));
});

test('products index search matches sku', function () {
    seedProductIndexPermissions();

    $admin = User::factory()->create(['role' => 'admin']);
    $product = createProductIndexFixture('Riz parfumé', 'RI0001', '6043000070493');

    $response = $this->actingAs($admin)->get(route('products.index', [
        'search' => 'RI0001',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Products/Index')
            ->has('products.data', 1)
            ->where('products.data.0.id', $product->id));
});

test('products index search matches barcode', function () {
    seedProductIndexPermissions();

    $admin = User::factory()->create(['role' => 'admin']);
    $product = createProductIndexFixture('Riz parfumé', 'RI0001', '6043000070493');
    createProductIndexFixture('Haricot rouge', 'HA0002', '6925125451107');

    $response = $this->actingAs($admin)->get(route('products.index', [
        'search' => '6043000070493',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Products/Index')
            ->has('products.data', 1)
            ->where('products.data.0.id', $product->id)
            ->where('filters.search', '6043000070493'));
});

test('products index search returns empty list for unknown barcode', function () {
    seedProductIndexPermissions();

    $admin = User::factory()->create(['role' => 'admin']);
    createProductIndexFixture('Riz parfumé', 'RI0001', '6043000070493');

    $response = $this->actingAs($admin)->get(route('products.index', [
        'search' => '9999999999999',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Products/Index')
            ->has('products.data', 0)
            ->where('filters.search', '9999999999999'));
});
