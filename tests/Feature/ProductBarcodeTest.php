<?php

use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductBarcodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedProductSearchPermissions(): void
{
    foreach (['view', 'create', 'edit', 'update', 'delete'] as $action) {
        Permission::firstOrCreate(
            [
                'resource' => 'products',
                'action' => $action,
            ],
            [
                'name' => Permission::generateName('products', $action),
                'description' => "products.{$action}",
            ],
        );
    }

    foreach (['create', 'edit'] as $action) {
        Permission::firstOrCreate(
            [
                'resource' => 'sales',
                'action' => $action,
            ],
            [
                'name' => Permission::generateName('sales', $action),
                'description' => "sales.{$action}",
            ],
        );
    }

    foreach (['create', 'edit'] as $action) {
        Permission::firstOrCreate(
            [
                'resource' => 'purchase-orders',
                'action' => $action,
            ],
            [
                'name' => Permission::generateName('purchase-orders', $action),
                'description' => "purchase-orders.{$action}",
            ],
        );
    }
}

function createBarcodeTestProduct(string $barcode, bool $isActive = true): Product
{
    $category = Category::create([
        'name' => 'Barcode Test',
        'slug' => 'barcode-test-'.uniqid(),
    ]);

    return Product::create([
        'name' => 'Produit scannable',
        'sku' => 'SKU-'.uniqid(),
        'barcode' => $barcode,
        'price' => 1500,
        'cost_price' => 900,
        'stock_quantity' => 25,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => $isActive,
    ]);
}

test('product barcode service normalizes whitespace and control characters', function () {
    $service = app(ProductBarcodeService::class);

    expect($service->normalize(" 0123456789012 \r\n"))->toBe('0123456789012')
        ->and($service->normalize("0123456789012\r"))->toBe('0123456789012')
        ->and($service->normalize("0123456789012\n"))->toBe('0123456789012');
});

test('product barcode service preserves leading zeros', function () {
    $service = app(ProductBarcodeService::class);

    expect($service->normalize('0123456789012'))->toBe('0123456789012');
});

test('product barcode lookup returns active product by exact barcode', function () {
    seedProductSearchPermissions();

    $admin = User::factory()->create(['role' => 'admin']);
    $product = createBarcodeTestProduct('0123456789012');

    $response = $this->actingAs($admin)->getJson(route('products.barcode', [
        'barcode' => '0123456789012',
    ]));

    $response->assertOk()
        ->assertJsonFragment([
            'id' => $product->id,
            'name' => 'Produit scannable',
            'barcode' => '0123456789012',
            'stock_quantity' => 25,
        ]);
});

test('product barcode lookup returns not found for unknown barcode', function () {
    seedProductSearchPermissions();

    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->getJson(route('products.barcode', [
        'barcode' => '9999999999999',
    ]));

    $response->assertNotFound()
        ->assertJsonFragment([
            'message' => 'Produit introuvable.',
            'barcode' => '9999999999999',
        ]);
});

test('product barcode service returns null for empty barcode', function () {
    $service = app(ProductBarcodeService::class);

    expect($service->findByBarcode('   '))->toBeNull()
        ->and($service->normalize('   '))->toBe('');
});

test('product barcode lookup rejects invalid route barcode characters', function () {
    seedProductSearchPermissions();

    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->getJson('/products/barcode/ABC%20DEF');

    $response->assertNotFound();
});

test('product barcode lookup ignores inactive products', function () {
    seedProductSearchPermissions();

    $admin = User::factory()->create(['role' => 'admin']);
    createBarcodeTestProduct('0123456789012', false);

    $response = $this->actingAs($admin)->getJson(route('products.barcode', [
        'barcode' => '0123456789012',
    ]));

    $response->assertNotFound();
});

test('product barcode lookup requires authentication', function () {
    $response = $this->getJson(route('products.barcode', [
        'barcode' => '0123456789012',
    ]));

    $response->assertUnauthorized();
});

test('product barcode lookup is available to users with purchase order create permission', function () {
    seedProductSearchPermissions();

    $user = User::factory()->create(['role' => 'gestionnaire']);
    $permission = Permission::where('name', Permission::generateName('purchase-orders', 'create'))->first();
    $user->permissions()->attach($permission);

    $product = createBarcodeTestProduct('0123456789012');

    $response = $this->actingAs($user)->getJson(route('products.barcode', [
        'barcode' => '0123456789012',
    ]));

    $response->assertOk()
        ->assertJsonFragment([
            'id' => $product->id,
        ]);
});

test('product barcode lookup is forbidden without product search permissions', function () {
    seedProductSearchPermissions();

    $user = User::factory()->create(['role' => 'gestionnaire']);

    $response = $this->actingAs($user)->getJson(route('products.barcode', [
        'barcode' => '0123456789012',
    ]));

    $response->assertForbidden();
});

test('duplicate barcodes return the first matching active product', function () {
    seedProductSearchPermissions();

    $admin = User::factory()->create(['role' => 'admin']);
    $first = createBarcodeTestProduct('0123456789012');
    createBarcodeTestProduct('0123456789012');

    $response = $this->actingAs($admin)->getJson(route('products.barcode', [
        'barcode' => '0123456789012',
    ]));

    $response->assertOk()
        ->assertJsonFragment([
            'id' => $first->id,
        ]);
});
