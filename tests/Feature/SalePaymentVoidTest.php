<?php

use App\Enums\PaymentTransactionStatus;
use App\Models\ActivityLog;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/SalePaymentTest.php'; // helpers createSaleTestProduct, saleStorePayload

function userWithPaymentVoidPermissions(array $extra = []): User
{
    (new PermissionSeeder())->run();

    $names = array_merge([
        'sales.view',
        'sales.create',
        'sales.payments.view',
        'sales.payments.record',
        'sales.payments.void',
    ], $extra);

    $user = User::factory()->create(['role' => 'vendeur']);
    $user->permissions()->sync(
        Permission::query()->whereIn('name', $names)->pluck('id'),
    );

    return $user;
}

function recordSalePayment(User $user, Sale $sale, float $amount = 30000): PaymentTransaction
{
    test()->actingAs($user)->post(route('sales.payments.store', $sale), [
        'amount' => $amount,
        'payment_method' => 'cash',
    ])->assertRedirect(route('sales.show', $sale->id));

    return PaymentTransaction::query()->latest('id')->firstOrFail();
}

test('void endpoint voids payment and recalculates sale balances', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();
    $sale = createAlignedPendingSaleForVoid($user, $product);

    $payment = recordSalePayment($user, $sale, 40000);

    $sale->refresh();
    expect((float) $sale->down_payment_amount)->toBe(40000.0)
        ->and($sale->payment_status)->toBe('partial');

    $this->actingAs($user)->post(route('sales.payments.void', $payment), [
        'void_reason' => 'Erreur de saisie caisse',
    ])->assertRedirect(route('sales.show', $sale->id));

    $payment->refresh();
    $sale->refresh();

    expect($payment->status)->toBe(PaymentTransactionStatus::Voided)
        ->and($payment->void_reason)->toBe('Erreur de saisie caisse')
        ->and($payment->voided_by_user_id)->toBe($user->id)
        ->and((float) $sale->down_payment_amount)->toBe(0.0)
        ->and($sale->payment_status)->toBe('pending')
        ->and((float) $sale->remaining_amount)->toBe(100000.0);

    expect(ActivityLog::query()->where('action', ActivityLog::ACTION_CANCEL)->exists())->toBeTrue();
});

test('void endpoint rejects double void', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();
    $sale = createAlignedPendingSaleForVoid($user, $product);
    $payment = recordSalePayment($user, $sale);

    $this->actingAs($user)->post(route('sales.payments.void', $payment), [
        'void_reason' => 'Première annulation',
    ])->assertRedirect();

    $this->actingAs($user)->from(route('sales.show', $sale->id))
        ->post(route('sales.payments.void', $payment->fresh()), [
            'void_reason' => 'Deuxième tentative',
        ])
        ->assertRedirect(route('sales.show', $sale->id))
        ->assertSessionHasErrors('void_reason');
});

test('void endpoint requires sales payments void permission', function () {
    (new PermissionSeeder())->run();

    $user = User::factory()->create(['role' => 'vendeur']);
    $user->permissions()->sync(
        Permission::query()->whereIn('name', [
            'sales.view',
            'sales.payments.view',
            'sales.payments.record',
        ])->pluck('id'),
    );

    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();
    $sale = createAlignedPendingSaleForVoid($admin, $product);
    $payment = recordSalePayment($admin, $sale);

    $this->actingAs($user)->post(route('sales.payments.void', $payment), [
        'void_reason' => 'Tentative non autorisée',
    ])->assertForbidden();
});

test('void endpoint requires void reason', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();
    $sale = createAlignedPendingSaleForVoid($user, $product);
    $payment = recordSalePayment($user, $sale);

    $this->actingAs($user)->from(route('sales.show', $sale->id))
        ->post(route('sales.payments.void', $payment), [
            'void_reason' => '',
        ])
        ->assertRedirect(route('sales.show', $sale->id))
        ->assertSessionHasErrors('void_reason');
});

test('sale show exposes voided transaction status and void permission flag', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();
    $sale = createAlignedPendingSaleForVoid($user, $product);
    $payment = recordSalePayment($user, $sale, 25000);

    $this->actingAs($user)->post(route('sales.payments.void', $payment), [
        'void_reason' => 'Test UI void',
    ])->assertRedirect();

    $this->actingAs($user)
        ->get(route('sales.show', $sale))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can_void_payment', true)
            ->has('payment_transactions', 1)
            ->where('payment_transactions.0.status', 'voided')
            ->where('payment_transactions.0.void_reason', 'Test UI void')
        );
});

function createAlignedPendingSaleForVoid(User $user, $product): Sale
{
    $creator = $user->role === 'admin' ? $user : User::factory()->create(['role' => 'admin']);

    test()->actingAs($creator)->post(route('sales.store'), saleStorePayload($product))->assertRedirect();

    return Sale::query()->latest('id')->firstOrFail();
}
