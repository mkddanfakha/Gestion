<?php

use App\Enums\PayableType;
use App\Enums\PaymentTransactionOrigin;
use App\Enums\PaymentTransactionStatus;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Treasury\TreasuryMovement;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

require_once __DIR__.'/SalePaymentTestHelpers.php';

function paymentTimezoneAdminWithTreasury(): User
{
    (new PermissionSeeder())->run();

    $admin = User::factory()->create(['role' => 'admin']);
    $treasuryId = Permission::query()->where('name', 'treasury.view')->value('id');
    if ($treasuryId !== null) {
        $admin->permissions()->syncWithoutDetaching([$treasuryId]);
    }

    return $admin;
}

function paymentTimezoneSale(User $user, Product $product): Sale
{
    test()->actingAs($user)->post(route('sales.store'), saleStorePayload($product))->assertRedirect();

    $sale = Sale::query()->latest('id')->first();
    expect($sale)->not->toBeNull();

    return $sale;
}

test('paid_at sans offset est enregistré comme horloge UTC', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();
    $sale = paymentTimezoneSale($admin, $product);

    $this->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 10000,
        'payment_method' => 'cash',
        'paid_at' => '2025-10-18T22:01',
    ])->assertRedirect();

    $transaction = PaymentTransaction::query()->first();
    expect($transaction)->not->toBeNull()
        ->and($transaction->paid_at->copy()->utc()->format('Y-m-d H:i'))->toBe('2025-10-18 22:01');
});

test('sale show et trésorerie exposent le même instant ISO pour un règlement', function () {
    $admin = paymentTimezoneAdminWithTreasury();

    $product = createSaleTestProduct();
    $sale = paymentTimezoneSale($admin, $product);

    $paidAtUtc = Carbon::parse('2025-10-18 22:01:00', 'UTC');

    PaymentTransaction::query()->create([
        'payable_type' => PayableType::Sale,
        'payable_id' => $sale->id,
        'amount' => 15000,
        'paid_at' => $paidAtUtc,
        'payment_method' => 'cash',
        'origin' => PaymentTransactionOrigin::Recorded,
        'user_id' => $admin->id,
        'status' => PaymentTransactionStatus::Posted,
    ]);

    $expectedIso = PaymentTransaction::query()->first()->paid_at->toIso8601String();

    $this->actingAs($admin)
        ->get(route('sales.show', $sale->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('payment_transactions', 1)
            ->where('payment_transactions.0.paid_at', $expectedIso));

    $this->actingAs($admin)
        ->get(route('treasury.index', [
            'date_from' => '2025-10-18',
            'date_to' => '2025-10-18',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('movements.data', 1)
            ->where('movements.data.0.occurred_at', $expectedIso));
});

test('filtre trésorerie inclut un règlement à 22:01 UTC le jour calendaire UTC', function () {
    $admin = paymentTimezoneAdminWithTreasury();

    $product = createSaleTestProduct();
    $sale = paymentTimezoneSale($admin, $product);

    PaymentTransaction::query()->create([
        'payable_type' => PayableType::Sale,
        'payable_id' => $sale->id,
        'amount' => 5000,
        'paid_at' => Carbon::parse('2025-10-18 22:01:00', 'UTC'),
        'payment_method' => 'cash',
        'origin' => PaymentTransactionOrigin::Recorded,
        'user_id' => $admin->id,
        'status' => PaymentTransactionStatus::Posted,
    ]);

    $this->actingAs($admin)
        ->get(route('treasury.index', [
            'date_from' => '2025-10-18',
            'date_to' => '2025-10-18',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('movements.data.0.source_type', TreasuryMovement::SOURCE_SALE_PAYMENT));
});
