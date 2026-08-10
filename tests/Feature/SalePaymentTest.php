<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createSaleTestProduct(float $price = 100000, int $stock = 100): Product
{
    $category = Category::create([
        'name' => 'Test',
        'slug' => 'test-'.uniqid(),
    ]);

    return Product::create([
        'name' => 'Produit test',
        'sku' => 'SKU-'.uniqid(),
        'price' => $price,
        'stock_quantity' => $stock,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);
}

function saleStorePayload(Product $product, array $overrides = []): array
{
    return array_merge([
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 0,
        'items' => [
            [
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => (float) $product->price,
            ],
        ],
    ], $overrides);
}

test('creating sale without payment stores pending status', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();

    $response = $this->actingAs($admin)->post(route('sales.store'), saleStorePayload($product));

    $response->assertRedirect();

    $sale = Sale::query()->latest('id')->first();

    expect($sale)->not->toBeNull()
        ->and((float) $sale->total_amount)->toBe(100000.0)
        ->and((float) $sale->down_payment_amount)->toBe(0.0)
        ->and((float) $sale->remaining_amount)->toBe(100000.0)
        ->and($sale->payment_status)->toBe('pending');
});

test('creating sale with partial payment stores partial status', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();

    $this->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'down_payment_amount' => 30000,
        'payment_method' => 'cash',
    ]))->assertRedirect();

    $sale = Sale::query()->latest('id')->first();

    expect($sale->payment_status)->toBe('partial')
        ->and((float) $sale->down_payment_amount)->toBe(30000.0)
        ->and((float) $sale->remaining_amount)->toBe(70000.0);
});

test('creating sale with full payment stores paid status', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();

    $this->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'down_payment_amount' => 100000,
        'payment_method' => 'wave',
    ]))->assertRedirect();

    $sale = Sale::query()->latest('id')->first();

    expect($sale->payment_status)->toBe('paid')
        ->and((float) $sale->down_payment_amount)->toBe(100000.0)
        ->and((float) $sale->remaining_amount)->toBe(0.0)
        ->and($sale->payment_method)->toBe('wave');
});

test('creating sale with payment above total is capped as paid', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();

    $this->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'down_payment_amount' => 150000,
        'payment_method' => 'cash',
    ]))->assertRedirect();

    $sale = Sale::query()->latest('id')->first();

    expect($sale->payment_status)->toBe('paid')
        ->and((float) $sale->down_payment_amount)->toBe(100000.0)
        ->and((float) $sale->remaining_amount)->toBe(0.0);
});

test('creating sale with payment logs audit payment entry', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();

    $this->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'down_payment_amount' => 100000,
        'payment_method' => 'wave',
    ]))->assertRedirect();

    expect(ActivityLog::query()->where('action', ActivityLog::ACTION_PAYMENT)->count())->toBe(1);
});

test('creating sale without payment does not log payment audit entry', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();

    $this->actingAs($admin)->post(route('sales.store'), saleStorePayload($product))->assertRedirect();

    expect(ActivityLog::query()->where('action', ActivityLog::ACTION_PAYMENT)->count())->toBe(0);
});

test('payment status uses total after discount', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct(120000);

    $this->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'discount_amount' => 20000,
        'down_payment_amount' => 100000,
        'payment_method' => 'wave',
    ]))->assertRedirect();

    $sale = Sale::query()->latest('id')->first();

    expect((float) $sale->total_amount)->toBe(100000.0)
        ->and($sale->payment_status)->toBe('paid')
        ->and((float) $sale->remaining_amount)->toBe(0.0);
});

test('backend ignores forged payment status on create', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();

    $payload = saleStorePayload($product, [
        'payment_status' => 'paid',
        'down_payment_amount' => 0,
    ]);

    $this->actingAs($admin)->post(route('sales.store'), $payload)->assertRedirect();

    $sale = Sale::query()->latest('id')->first();

    expect($sale->payment_status)->toBe('pending');
});

test('fully paid sale preserves due date when provided', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();
    $dueDate = now()->addDays(15)->format('Y-m-d');

    $this->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'down_payment_amount' => 100000,
        'due_date' => $dueDate,
        'payment_method' => 'wave',
    ]))->assertRedirect();

    $sale = Sale::query()->latest('id')->first();

    expect($sale->payment_status)->toBe('paid')
        ->and($sale->due_date?->format('Y-m-d'))->toBe($dueDate);
});

test('creating sale without payment does not require payment method', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();

    $this->actingAs($admin)->post(route('sales.store'), saleStorePayload($product))->assertRedirect();

    $sale = Sale::query()->latest('id')->first();

    expect($sale->payment_status)->toBe('pending')
        ->and($sale->payment_method)->toBeNull();
});

test('creating sale with partial payment requires payment method', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();

    $payload = saleStorePayload($product, [
        'down_payment_amount' => 30000,
    ]);

    $this->actingAs($admin)->post(route('sales.store'), $payload)
        ->assertSessionHasErrors(['payment_method']);
});

test('updating sale payment amount recalculates status automatically', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();

    $this->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'down_payment_amount' => 50000,
        'payment_method' => 'wave',
    ]))->assertRedirect();

    $sale = Sale::query()->latest('id')->first();

    expect($sale->payment_status)->toBe('partial');

    $this->actingAs($admin)->put(route('sales.update', $sale->id), saleStorePayload($product, [
        'down_payment_amount' => 100000,
        'payment_method' => 'wave',
    ]))->assertRedirect();

    $sale->refresh();

    expect($sale->payment_status)->toBe('paid')
        ->and((float) $sale->remaining_amount)->toBe(0.0);
});

test('backend ignores forged payment status on update', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();

    $this->actingAs($admin)->post(route('sales.store'), saleStorePayload($product))->assertRedirect();

    $sale = Sale::query()->latest('id')->first();

    $this->actingAs($admin)->put(route('sales.update', $sale->id), array_merge(
        saleStorePayload($product),
        ['payment_status' => 'paid'],
    ))->assertRedirect();

    $sale->refresh();

    expect($sale->payment_status)->toBe('pending')
        ->and((float) $sale->down_payment_amount)->toBe(0.0);
});
