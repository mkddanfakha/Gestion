<?php

use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Quote;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\ProductStockInitializationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function seedProductStockPermissions(): void
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

function productStockCategory(): Category
{
    return Category::create([
        'name' => 'Stock Test',
        'slug' => 'stock-test-'.uniqid(),
    ]);
}

function productStockPayload(Category $category, array $overrides = []): array
{
    return array_merge([
        'name' => 'Produit stock '.uniqid(),
        'sku' => 'ST'.random_int(1000, 9999),
        'price' => 1000,
        'stock_quantity' => 0,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ], $overrides);
}

function productStockMainStore(): Store
{
    return Company::getInstance()->defaultStore()->firstOrFail();
}

function createProductViaStore(User $user, array $payload): Product
{
    test()->actingAs($user)->post(route('products.store'), $payload)->assertRedirect();

    return Product::query()->latest('id')->firstOrFail();
}

test('creating product with initial stock creates main product stock and opening balance', function () {
    seedProductStockPermissions();
    $admin = User::factory()->create(['role' => 'admin']);
    $category = productStockCategory();
    $store = productStockMainStore();

    $product = createProductViaStore($admin, productStockPayload($category, [
        'stock_quantity' => 10,
    ]));

    $productStock = ProductStock::query()
        ->where('product_id', $product->id)
        ->where('store_id', $store->id)
        ->first();

    $movement = StockMovement::query()
        ->where('product_id', $product->id)
        ->where('type', StockMovementType::OpeningBalance)
        ->first();

    expect($productStock)->not->toBeNull()
        ->and($productStock->quantity)->toBe(10)
        ->and($product->fresh()->stock_quantity)->toBe(10)
        ->and($movement)->not->toBeNull()
        ->and($movement->quantity)->toBe(10)
        ->and($movement->quantity_before)->toBe(0)
        ->and($movement->quantity_after)->toBe(10)
        ->and($movement->metadata)->toMatchArray([
            'source' => 'product_creation',
            'initial_stock' => true,
        ]);
});

test('creating product with zero stock creates main product stock without opening balance movement', function () {
    seedProductStockPermissions();
    $admin = User::factory()->create(['role' => 'admin']);
    $category = productStockCategory();
    $store = productStockMainStore();

    $product = createProductViaStore($admin, productStockPayload($category, [
        'stock_quantity' => 0,
    ]));

    expect(ProductStock::query()
        ->where('product_id', $product->id)
        ->where('store_id', $store->id)
        ->value('quantity'))->toBe(0)
        ->and(StockMovement::query()->where('product_id', $product->id)->count())->toBe(0);
});

test('creating product without explicit stock defaults main stock to zero', function () {
    seedProductStockPermissions();
    $admin = User::factory()->create(['role' => 'admin']);
    $category = productStockCategory();

    $payload = productStockPayload($category);
    unset($payload['stock_quantity']);

    $this->actingAs($admin)->post(route('products.store'), $payload)
        ->assertSessionHasErrors(['stock_quantity']);
});

test('product creation rolls back when main product stock initialization fails', function () {
    seedProductStockPermissions();
    $admin = User::factory()->create(['role' => 'admin']);
    $category = productStockCategory();

    expect(fn () => DB::transaction(function () use ($category, $admin) {
        $product = Product::create([
            'name' => 'Rollback stock',
            'sku' => 'RB'.random_int(1000, 9999),
            'price' => 1000,
            'stock_quantity' => 5,
            'min_stock_level' => 0,
            'unit' => 'pièce',
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        app(ProductStockInitializationService::class)->initializeMainStock($product, 5, $admin);

        throw new RuntimeException('Simulated transaction failure');
    }))->toThrow(RuntimeException::class);

    expect(Product::query()->count())->toBe(0)
        ->and(ProductStock::query()->count())->toBe(0);
});

test('product creation rolls back when opening balance creation fails', function () {
    seedProductStockPermissions();
    $admin = User::factory()->create(['role' => 'admin']);
    $category = productStockCategory();

    $service = Mockery::mock(ProductStockInitializationService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('createOpeningBalanceMovement')
        ->once()
        ->andThrow(new RuntimeException('Simulated opening balance failure'));

    expect(fn () => DB::transaction(function () use ($category, $admin, $service) {
        $product = Product::create([
            'name' => 'Rollback opening',
            'sku' => 'RO'.random_int(1000, 9999),
            'price' => 1000,
            'stock_quantity' => 8,
            'min_stock_level' => 0,
            'unit' => 'pièce',
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        $service->initializeMainStock($product, 8, $admin);
    }))->toThrow(RuntimeException::class);

    expect(Product::query()->count())->toBe(0)
        ->and(ProductStock::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
});

test('updating product fields does not change stock quantities', function () {
    seedProductStockPermissions();
    $admin = User::factory()->create(['role' => 'admin']);
    $category = productStockCategory();
    $product = createProductViaStore($admin, productStockPayload($category, [
        'stock_quantity' => 12,
    ]));

    $this->actingAs($admin)->put(route('products.update', $product), [
        'name' => 'Nom modifié',
        'sku' => $product->sku,
        'price' => 1500,
        'min_stock_level' => 2,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ])->assertRedirect();

    $store = productStockMainStore();

    expect($product->fresh()->name)->toBe('Nom modifié')
        ->and($product->fresh()->stock_quantity)->toBe(12)
        ->and((int) ProductStock::query()
            ->where('product_id', $product->id)
            ->where('store_id', $store->id)
            ->value('quantity'))->toBe(12);
});

test('forged stock quantity on product update is rejected', function () {
    seedProductStockPermissions();
    $admin = User::factory()->create(['role' => 'admin']);
    $category = productStockCategory();
    $product = createProductViaStore($admin, productStockPayload($category, [
        'stock_quantity' => 4,
    ]));

    $this->actingAs($admin)->put(route('products.update', $product), [
        'name' => $product->name,
        'sku' => $product->sku,
        'price' => 1000,
        'stock_quantity' => 999,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ])->assertSessionHasErrors(['stock_quantity']);

    expect($product->fresh()->stock_quantity)->toBe(4);
});

test('initializing main stock twice for the same product is forbidden', function () {
    seedProductStockPermissions();
    $admin = User::factory()->create(['role' => 'admin']);
    $category = productStockCategory();
    $product = createProductViaStore($admin, productStockPayload($category, [
        'stock_quantity' => 3,
    ]));

    $service = app(ProductStockInitializationService::class);

    expect(fn () => $service->initializeMainStock($product, 3, $admin))
        ->toThrow(InvalidArgumentException::class);
});

test('stock check consistency detects missing main product stock', function () {
    Company::getInstance();
    $category = productStockCategory();

    Product::create([
        'name' => 'Sans stock MAIN',
        'sku' => 'NS'.random_int(1000, 9999),
        'price' => 1000,
        'stock_quantity' => 5,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    Artisan::call('stock:check-consistency', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($output['ok'])->toBeFalse()
        ->and(collect($output['issues'])->pluck('code'))->toContain('missing_main_product_stock');
});

test('stock check consistency detects legacy mirror divergence', function () {
    seedProductStockPermissions();
    $admin = User::factory()->create(['role' => 'admin']);
    $category = productStockCategory();
    $product = createProductViaStore($admin, productStockPayload($category, [
        'stock_quantity' => 15,
    ]));

    $product->update(['stock_quantity' => 10]);

    Artisan::call('stock:check-consistency', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($output['ok'])->toBeFalse()
        ->and(collect($output['issues'])->pluck('code'))->toContain('legacy_mirror_divergence');
});

test('product stock rejects store from another company', function () {
    $primaryCompany = Company::getInstance();
    $category = productStockCategory();
    $product = Product::create([
        'name' => 'Isolation',
        'sku' => 'IS'.random_int(1000, 9999),
        'price' => 1000,
        'stock_quantity' => 1,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $otherCompany = Company::create([
        'name' => 'Autre entreprise',
        'tagline' => 'Test',
        'address' => 'Adresse',
        'phone1' => '+225000000000',
        'email' => 'other@example.com',
    ]);

    $foreignStore = Store::query()
        ->where('company_id', $otherCompany->id)
        ->where('code', Store::CODE_MAIN)
        ->firstOrFail();

    ProductStock::query()->create([
        'product_id' => $product->id,
        'store_id' => $primaryCompany->defaultStore->id,
        'quantity' => 1,
    ]);

    expect(fn () => ProductStock::query()->create([
        'product_id' => $product->id,
        'store_id' => $foreignStore->id,
        'quantity' => 1,
    ]))->toThrow(InvalidArgumentException::class);
});

test('creating product with barcode keeps barcode workflow functional', function () {
    seedProductStockPermissions();
    $admin = User::factory()->create(['role' => 'admin']);
    $category = productStockCategory();

    $product = createProductViaStore($admin, productStockPayload($category, [
        'barcode' => '6043000070493',
        'stock_quantity' => 6,
    ]));

    expect($product->fresh()->barcode)->toBe('6043000070493');

    $this->actingAs($admin)->getJson(route('products.barcode', [
        'barcode' => '6043000070493',
    ]))->assertOk()
        ->assertJsonPath('id', $product->id);
});

test('sale after product creation decreases main stock through stock service', function () {
    seedProductStockPermissions();
    $admin = User::factory()->create(['role' => 'admin']);
    $category = productStockCategory();
    $product = createProductViaStore($admin, productStockPayload($category, [
        'stock_quantity' => 10,
        'price' => 10000,
    ]));

    $this->actingAs($admin)->post(route('sales.store'), [
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 0,
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_price' => 10000,
        ]],
    ])->assertRedirect();

    $store = productStockMainStore();

    expect((int) ProductStock::query()
        ->where('product_id', $product->id)
        ->where('store_id', $store->id)
        ->value('quantity'))->toBe(7)
        ->and($product->fresh()->stock_quantity)->toBe(7)
        ->and(StockMovement::query()
            ->where('product_id', $product->id)
            ->where('type', StockMovementType::Sale)
            ->exists())->toBeTrue();
});

test('delivery after product creation increases main stock through stock service', function () {
    seedProductStockPermissions();
    $admin = User::factory()->create(['role' => 'admin']);
    $category = productStockCategory();
    $product = createProductViaStore($admin, productStockPayload($category, [
        'stock_quantity' => 10,
        'price' => 15000,
    ]));

    $supplier = \App\Models\Supplier::create([
        'name' => 'Fournisseur stock test',
        'status' => 'active',
    ]);

    $purchaseOrder = \App\Models\PurchaseOrder::create([
        'po_number' => \App\Models\PurchaseOrder::generatePONumber(),
        'supplier_id' => $supplier->id,
        'user_id' => $admin->id,
        'order_date' => now()->toDateString(),
        'status' => 'confirmed',
        'subtotal' => 60000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 60000,
    ]);

    \App\Models\PurchaseOrderItem::create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'quantity' => 20,
        'unit_price' => 3000,
        'total_price' => 60000,
    ]);

    $deliveryNote = \App\Models\DeliveryNote::create([
        'delivery_number' => \App\Models\DeliveryNote::generateDeliveryNumber(),
        'purchase_order_id' => $purchaseOrder->id,
        'supplier_id' => $supplier->id,
        'user_id' => $admin->id,
        'delivery_date' => now()->toDateString(),
        'status' => 'pending',
        'subtotal' => 15000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 15000,
    ]);

    \App\Models\DeliveryNoteItem::create([
        'delivery_note_id' => $deliveryNote->id,
        'product_id' => $product->id,
        'quantity' => 5,
        'unit_price' => 3000,
        'total_price' => 15000,
    ]);

    app(\App\Services\PurchaseOrderDeliveryService::class)
        ->validateDeliveryNote($deliveryNote->fresh());

    $store = productStockMainStore();

    expect((int) ProductStock::query()
        ->where('product_id', $product->id)
        ->where('store_id', $store->id)
        ->value('quantity'))->toBe(15)
        ->and($product->fresh()->stock_quantity)->toBe(15)
        ->and(StockMovement::query()
            ->where('product_id', $product->id)
            ->where('type', StockMovementType::Purchase)
            ->exists())->toBeTrue();
});

test('insufficient stock sale after product creation leaves stock unchanged', function () {
    seedProductStockPermissions();
    $admin = User::factory()->create(['role' => 'admin']);
    $category = productStockCategory();
    $product = createProductViaStore($admin, productStockPayload($category, [
        'stock_quantity' => 2,
        'price' => 5000,
    ]));

    $this->actingAs($admin)->post(route('sales.store'), [
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 0,
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_price' => 5000,
        ]],
    ])->assertSessionHasErrors(['items.0.quantity']);

    $store = productStockMainStore();

    expect((int) ProductStock::query()
        ->where('product_id', $product->id)
        ->where('store_id', $store->id)
        ->value('quantity'))->toBe(2)
        ->and($product->fresh()->stock_quantity)->toBe(2)
        ->and(StockMovement::query()
            ->where('product_id', $product->id)
            ->where('type', StockMovementType::Sale)
            ->exists())->toBeFalse();
});
