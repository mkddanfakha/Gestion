<?php

use App\Enums\PaymentTransactionOrigin;
use App\Enums\PaymentTransactionStatus;
use App\Exceptions\InvalidPaymentTransactionException;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\SalePaymentTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function paymentTxUser(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function paymentTxCustomer(): Customer
{
    return Customer::factory()->create();
}

function paymentTxSale(User $user, Customer $customer, float $total, float $down = 0, string $status = 'completed'): Sale
{
    $sale = Sale::query()->create([
        'sale_number' => 'FA'.uniqid(),
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'subtotal' => $total,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => $down,
        'remaining_amount' => $total - $down,
        'payment_status' => $down <= 0 ? 'pending' : ($down >= $total ? 'paid' : 'partial'),
        'total_amount' => $total,
        'status' => $status,
        'payment_method' => $down > 0 ? 'cash' : null,
        'sale_date' => now(),
    ]);

    $category = Category::create(['name' => 'Cat', 'slug' => 'cat-'.uniqid()]);
    $product = Product::create([
        'name' => 'P',
        'sku' => 'SKU-'.uniqid(),
        'price' => $total,
        'stock_quantity' => 10,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    SaleItem::query()->create([
        'sale_id' => $sale->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => $total,
        'total_price' => $total,
    ]);

    return $sale;
}

test('sale without prior payment accepts partial payment and updates aggregates', function () {
    $user = paymentTxUser();
    $sale = paymentTxSale($user, paymentTxCustomer(), 100000, 0);
    $service = app(SalePaymentTransactionService::class);

    $tx = $service->recordForSale($sale->id, $user, [
        'amount' => 30000,
        'payment_method' => 'cash',
    ]);

    $sale->refresh();

    expect($tx->status)->toBe(PaymentTransactionStatus::Posted)
        ->and((float) $sale->down_payment_amount)->toBe(30000.0)
        ->and((float) $sale->remaining_amount)->toBe(70000.0)
        ->and($sale->payment_status)->toBe('partial')
        ->and($sale->payment_method)->toBe('cash');
});

test('multiple successive payments accumulate correctly', function () {
    $user = paymentTxUser();
    $sale = paymentTxSale($user, paymentTxCustomer(), 100000, 0);
    $service = app(SalePaymentTransactionService::class);

    $service->recordForSale($sale->id, $user, ['amount' => 20000, 'payment_method' => 'cash']);
    $service->recordForSale($sale->id, $user, ['amount' => 30000, 'payment_method' => 'wave']);

    $sale->refresh();

    expect((float) $sale->down_payment_amount)->toBe(50000.0)
        ->and($sale->payment_status)->toBe('partial')
        ->and($sale->payment_method)->toBe('wave');
});

test('exact balance payment marks sale as paid', function () {
    $user = paymentTxUser();
    $sale = paymentTxSale($user, paymentTxCustomer(), 100000, 0);
    $service = app(SalePaymentTransactionService::class);

    $service->recordForSale($sale->id, $user, ['amount' => 100000, 'payment_method' => 'card']);

    $sale->refresh();

    expect($sale->payment_status)->toBe('paid')
        ->and((float) $sale->remaining_amount)->toBe(0.0);
});

test('overpayment is rejected', function () {
    $user = paymentTxUser();
    $sale = paymentTxSale($user, paymentTxCustomer(), 100000, 0);
    $service = app(SalePaymentTransactionService::class);

    $service->recordForSale($sale->id, $user, ['amount' => 100001, 'payment_method' => 'cash']);
})->throws(InvalidPaymentTransactionException::class);

test('zero or negative amount is rejected', function () {
    $user = paymentTxUser();
    $sale = paymentTxSale($user, paymentTxCustomer(), 100000, 0);
    $service = app(SalePaymentTransactionService::class);

    $service->recordForSale($sale->id, $user, ['amount' => 0, 'payment_method' => 'cash']);
})->throws(InvalidPaymentTransactionException::class);

test('payment on cancelled sale is rejected', function () {
    $user = paymentTxUser();
    $sale = paymentTxSale($user, paymentTxCustomer(), 100000, 0, 'cancelled');
    $service = app(SalePaymentTransactionService::class);

    $service->recordForSale($sale->id, $user, ['amount' => 1000, 'payment_method' => 'cash']);
})->throws(InvalidPaymentTransactionException::class);

test('voided transactions are excluded from paid total', function () {
    $user = paymentTxUser();
    $sale = paymentTxSale($user, paymentTxCustomer(), 100000, 0);
    $service = app(SalePaymentTransactionService::class);

    $tx = $service->recordForSale($sale->id, $user, ['amount' => 40000, 'payment_method' => 'cash']);
    $service->voidTransaction($tx, $user, 'Erreur de saisie');

    $sale->refresh();

    expect((float) $sale->down_payment_amount)->toBe(0.0)
        ->and($sale->payment_status)->toBe('pending')
        ->and($tx->fresh()->status)->toBe(PaymentTransactionStatus::Voided);
});

test('idempotency key prevents duplicate registration', function () {
    $user = paymentTxUser();
    $sale = paymentTxSale($user, paymentTxCustomer(), 100000, 0);
    $service = app(SalePaymentTransactionService::class);

    $first = $service->recordForSale($sale->id, $user, [
        'amount' => 10000,
        'payment_method' => 'cash',
        'idempotency_key' => 'test-key-1',
    ]);

    $second = $service->recordForSale($sale->id, $user, [
        'amount' => 10000,
        'payment_method' => 'cash',
        'idempotency_key' => 'test-key-1',
    ]);

    expect($second->id)->toBe($first->id)
        ->and(PaymentTransaction::query()->count())->toBe(1);
});

test('failed transaction leaves no partial payment row', function () {
    $user = paymentTxUser();
    $sale = paymentTxSale($user, paymentTxCustomer(), 100000, 0);
    $service = app(SalePaymentTransactionService::class);

    try {
        DB::transaction(function () use ($service, $sale, $user) {
            $service->recordForSale($sale->id, $user, [
                'amount' => 10000,
                'payment_method' => 'cash',
            ]);

            throw new RuntimeException('Simulated failure');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(PaymentTransaction::query()->count())->toBe(0);

    $sale->refresh();

    expect((float) $sale->down_payment_amount)->toBe(0.0);
});

test('backfill dry run reports sales to create without writing', function () {
    $user = paymentTxUser();
    paymentTxSale($user, paymentTxCustomer(), 100000, 50000);

    $this->artisan('payments:backfill-sale-down-payments', ['--dry-run' => true])
        ->assertSuccessful();

    expect(PaymentTransaction::query()->count())->toBe(0);
});

test('backfill apply is idempotent on second run', function () {
    $user = paymentTxUser();
    paymentTxSale($user, paymentTxCustomer(), 100000, 50000);

    $this->artisan('payments:backfill-sale-down-payments', ['--apply' => true])
        ->assertSuccessful();

    expect(PaymentTransaction::query()->count())->toBe(1);

    $this->artisan('payments:backfill-sale-down-payments', ['--apply' => true])
        ->assertSuccessful();

    expect(PaymentTransaction::query()->count())->toBe(1);
});

test('backfill detects inconsistent aggregates', function () {
    $user = paymentTxUser();
    $sale = paymentTxSale($user, paymentTxCustomer(), 100000, 50000);
    $sale->update(['remaining_amount' => 99999]);

    $this->artisan('payments:backfill-sale-down-payments', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Anomalies');

    expect(PaymentTransaction::query()->count())->toBe(0);
});

test('unbackfilled legacy down payment blocks further recording', function () {
    $user = paymentTxUser();
    $sale = paymentTxSale($user, paymentTxCustomer(), 100000, 40000);
    $service = app(SalePaymentTransactionService::class);

    $service->recordForSale($sale->id, $user, ['amount' => 10000, 'payment_method' => 'cash']);
})->throws(InvalidPaymentTransactionException::class, 'acompte historique non migré');

test('consecutive payments cannot exceed total when second would overpay', function () {
    $user = paymentTxUser();
    $sale = paymentTxSale($user, paymentTxCustomer(), 100000, 0);
    $service = app(SalePaymentTransactionService::class);

    $service->recordForSale($sale->id, $user, ['amount' => 60000, 'payment_method' => 'cash']);

    $service->recordForSale($sale->id, $user, ['amount' => 50000, 'payment_method' => 'cash']);
})->throws(InvalidPaymentTransactionException::class);
