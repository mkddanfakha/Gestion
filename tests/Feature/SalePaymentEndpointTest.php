<?php

use App\Enums\PayableType;
use App\Enums\PaymentTransactionOrigin;
use App\Enums\PaymentTransactionStatus;
use App\Models\Category;
use App\Models\Company;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedSalePaymentPermissions(): void
{
    (new PermissionSeeder())->run();
}

function vendeurWithPaymentPermissions(): User
{
    seedSalePaymentPermissions();

    $vendeur = User::factory()->create(['role' => 'vendeur']);

    $names = [
        'sales.view',
        'sales.create',
        'sales.update',
        'sales.payments.view',
        'sales.payments.record',
    ];

    $ids = Permission::query()->whereIn('name', $names)->pluck('id');
    $vendeur->permissions()->sync($ids);

    return $vendeur;
}

function createAlignedPendingSale(User $user, Product $product): Sale
{
    $admin = $user->role === 'admin' ? $user : User::factory()->create(['role' => 'admin']);

    test()->actingAs($admin)->post(route('sales.store'), saleStorePayload($product))->assertRedirect();

    $sale = Sale::query()->latest('id')->first();

    expect($sale)->not->toBeNull();

    return $sale;
}

test('partial payment via dedicated endpoint updates sale aggregates', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();

    $sale = createAlignedPendingSale($admin, $product);

    $stockBefore = ProductStock::query()->sum('quantity');

    $this->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 30000,
        'payment_method' => 'cash',
    ])->assertRedirect(route('sales.show', $sale->id));

    $sale->refresh();

    expect((float) $sale->down_payment_amount)->toBe(30000.0)
        ->and($sale->payment_status)->toBe('partial')
        ->and(ProductStock::query()->sum('quantity'))->toBe($stockBefore);
});

test('exact balance payment via endpoint marks sale paid', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();
    $sale = createAlignedPendingSale($admin, $product);

    $this->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 100000,
        'payment_method' => 'wave',
    ])->assertRedirect();

    $sale->refresh();

    expect($sale->payment_status)->toBe('paid')
        ->and((float) $sale->remaining_amount)->toBe(0.0);
});

test('endpoint rejects overpayment', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();
    $sale = createAlignedPendingSale($admin, $product);

    $this->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 100001,
        'payment_method' => 'cash',
    ])->assertSessionHasErrors(['amount']);
});

test('endpoint rejects zero amount', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();
    $sale = createAlignedPendingSale($admin, $product);

    $this->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 0,
        'payment_method' => 'cash',
    ])->assertSessionHasErrors(['amount']);
});

test('endpoint rejects cancelled sale', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();
    $sale = createAlignedPendingSale($admin, $product);
    $sale->update(['status' => 'cancelled']);

    $this->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 1000,
        'payment_method' => 'cash',
    ])->assertSessionHasErrors(['amount']);
});

test('user without record permission cannot collect payment', function () {
    seedSalePaymentPermissions();
    $product = createSaleTestProduct();
    $admin = User::factory()->create(['role' => 'admin']);
    $sale = createAlignedPendingSale($admin, $product);

    $viewer = User::factory()->create(['role' => 'vendeur']);
    $viewer->permissions()->sync(
        Permission::query()->where('name', 'sales.view')->pluck('id'),
    );

    $this->actingAs($viewer)->post(route('sales.payments.store', $sale), [
        'amount' => 1000,
        'payment_method' => 'cash',
    ])->assertForbidden();
});

test('vendeur cannot collect on another users sale', function () {
    $product = createSaleTestProduct();
    $owner = vendeurWithPaymentPermissions();
    $other = vendeurWithPaymentPermissions();

    test()->actingAs($owner)->post(route('sales.store'), saleStorePayload($product))->assertRedirect();
    $sale = Sale::query()->latest('id')->first();

    $this->actingAs($other)->post(route('sales.payments.store', $sale), [
        'amount' => 1000,
        'payment_method' => 'cash',
    ])->assertForbidden();
});

test('sale update ignores forged down payment change', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();

    test()->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'down_payment_amount' => 30000,
        'payment_method' => 'cash',
    ]))->assertRedirect();

    $sale = Sale::query()->latest('id')->first();

    $this->actingAs($admin)->put(route('sales.update', $sale->id), saleStorePayload($product, [
        'down_payment_amount' => 100000,
        'payment_method' => 'cash',
    ]))->assertRedirect();

    $sale->refresh();

    expect((float) $sale->down_payment_amount)->toBe(30000.0)
        ->and($sale->payment_status)->toBe('partial');
});

test('creating sale with initial down payment creates exactly one transaction', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();

    $this->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'down_payment_amount' => 25000,
        'payment_method' => 'card',
    ]))->assertRedirect();

    $sale = Sale::query()->latest('id')->first();

    expect(PaymentTransaction::query()->count())->toBe(1)
        ->and((float) $sale->down_payment_amount)->toBe(25000.0)
        ->and(PaymentTransaction::query()->first()->idempotency_key)->toBe('initial-payment:sale:'.$sale->id);
});

test('idempotency key on endpoint prevents duplicate payment', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();
    $sale = createAlignedPendingSale($admin, $product);

    $payload = [
        'amount' => 10000,
        'payment_method' => 'cash',
        'idempotency_key' => 'browser-key-123',
    ];

    $this->actingAs($admin)->post(route('sales.payments.store', $sale), $payload)->assertRedirect();
    $this->actingAs($admin)->post(route('sales.payments.store', $sale), $payload)->assertRedirect();

    expect(PaymentTransaction::query()->count())->toBe(1);
});

test('unbackfilled historical sale refuses payment without lowering paid amount', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $sale = Sale::query()->create([
        'sale_number' => 'FA'.uniqid(),
        'user_id' => $admin->id,
        'subtotal' => 100000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 40000,
        'remaining_amount' => 60000,
        'payment_status' => 'partial',
        'total_amount' => 100000,
        'status' => 'completed',
        'payment_method' => 'cash',
        'sale_date' => now(),
    ]);

    $this->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 10000,
        'payment_method' => 'cash',
    ])->assertSessionHasErrors(['amount']);

    $sale->refresh();

    expect((float) $sale->down_payment_amount)->toBe(40000.0)
        ->and(PaymentTransaction::query()->count())->toBe(0);
});

test('backfilled historical sale accepts payment with coherent balance', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $sale = Sale::query()->create([
        'sale_number' => 'FA'.uniqid(),
        'user_id' => $admin->id,
        'subtotal' => 100000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 40000,
        'remaining_amount' => 60000,
        'payment_status' => 'partial',
        'total_amount' => 100000,
        'status' => 'completed',
        'payment_method' => 'cash',
        'sale_date' => now(),
    ]);

    PaymentTransaction::query()->create([
        'payable_type' => PayableType::Sale,
        'payable_id' => $sale->id,
        'amount' => 40000,
        'paid_at' => now(),
        'payment_method' => 'cash',
        'origin' => PaymentTransactionOrigin::HistoricalBackfill,
        'user_id' => $admin->id,
        'status' => PaymentTransactionStatus::Posted,
        'idempotency_key' => 'historical-backfill:sale:'.$sale->id,
    ]);

    $this->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 60000,
        'payment_method' => 'wave',
    ])->assertRedirect();

    $sale->refresh();

    expect($sale->payment_status)->toBe('paid')
        ->and(PaymentTransaction::query()->where('status', PaymentTransactionStatus::Posted)->count())->toBe(2);
});

test('inconsistent ledger returns explicit error without partial write', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $sale = Sale::query()->create([
        'sale_number' => 'FA'.uniqid(),
        'user_id' => $admin->id,
        'subtotal' => 100000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 30000,
        'remaining_amount' => 70000,
        'payment_status' => 'partial',
        'total_amount' => 100000,
        'status' => 'completed',
        'payment_method' => 'cash',
        'sale_date' => now(),
    ]);

    PaymentTransaction::query()->create([
        'payable_type' => PayableType::Sale,
        'payable_id' => $sale->id,
        'amount' => 20000,
        'paid_at' => now(),
        'payment_method' => 'cash',
        'origin' => PaymentTransactionOrigin::Recorded,
        'user_id' => $admin->id,
        'status' => PaymentTransactionStatus::Posted,
    ]);

    $this->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 5000,
        'payment_method' => 'cash',
    ])->assertSessionHasErrors(['amount']);

    expect(PaymentTransaction::query()->count())->toBe(1);
});

test('update rejects total below amount already collected', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct(100000);

    test()->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'down_payment_amount' => 100000,
        'payment_method' => 'cash',
    ]))->assertRedirect();

    $sale = Sale::query()->latest('id')->first();

    $this->actingAs($admin)->put(route('sales.update', $sale->id), saleStorePayload($product, [
        'items' => [
            [
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 50000,
            ],
        ],
    ]))->assertSessionHasErrors(['items']);
});
