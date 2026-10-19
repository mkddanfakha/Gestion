<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Sale;
use App\Models\User;
use App\Services\UserActivity\UserActivityQueryService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../SalePaymentTestHelpers.php';

function userActivityViewer(): User
{
    (new PermissionSeeder())->run();

    $admin = User::factory()->create(['role' => 'admin']);
    $admin->permissions()->sync(
        Permission::query()->whereIn('name', [
            'user-activities.view',
            'sales.view',
            'sales.payments.record',
            'sales.payments.void',
        ])->pluck('id'),
    );

    return $admin;
}

test('successful sale payment creates one activity log entry with ledger metadata', function () {
    $admin = userActivityViewer();
    $customer = Customer::factory()->create();
    $product = createSaleTestProduct(100000);

    test()->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'customer_id' => $customer->id,
    ]))->assertRedirect();

    $sale = Sale::query()->latest('id')->firstOrFail();

    test()->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 40000,
        'payment_method' => 'wave',
        'reference' => 'REF-WAVE-01',
        'idempotency_key' => 'idem-payment-activity-1',
    ])->assertRedirect();

    $payment = PaymentTransaction::query()->latest('id')->firstOrFail();

    $logs = ActivityLog::query()
        ->where('action', ActivityLog::ACTION_PAYMENT)
        ->where('new_values->payment_transaction_id', $payment->id)
        ->get();

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->user_id)->toBe($admin->id)
        ->and($logs->first()->description)->toContain('40 000,00 FCFA')
        ->and($logs->first()->description)->toContain('Wave')
        ->and($logs->first()->description)->toContain($sale->sale_number)
        ->and($logs->first()->new_values['reference'])->toBe('REF-WAVE-01')
        ->and($logs->first()->new_values['currency'])->toBe('FCFA');
});

test('idempotent payment retry does not duplicate activity log', function () {
    $admin = userActivityViewer();
    $product = createSaleTestProduct(100000);

    test()->actingAs($admin)->post(route('sales.store'), saleStorePayload($product))->assertRedirect();
    $sale = Sale::query()->latest('id')->firstOrFail();

    $payload = [
        'amount' => 25000,
        'payment_method' => 'cash',
        'idempotency_key' => 'idem-payment-activity-dup',
    ];

    test()->actingAs($admin)->post(route('sales.payments.store', $sale), $payload)->assertRedirect();
    test()->actingAs($admin)->post(route('sales.payments.store', $sale), $payload)->assertRedirect();

    expect(ActivityLog::query()->where('action', ActivityLog::ACTION_PAYMENT)->count())->toBe(1);
});

test('failed payment does not create payment activity log', function () {
    $admin = userActivityViewer();
    $product = createSaleTestProduct(100000);

    test()->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'down_payment_amount' => 100000,
        'payment_method' => 'cash',
    ]))->assertRedirect();

    $sale = Sale::query()->latest('id')->firstOrFail();
    $before = ActivityLog::query()->where('action', ActivityLog::ACTION_PAYMENT)->count();

    test()->actingAs($admin)->from(route('sales.show', $sale))
        ->post(route('sales.payments.store', $sale), [
            'amount' => 50000,
            'payment_method' => 'cash',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('amount');

    expect(ActivityLog::query()->where('action', ActivityLog::ACTION_PAYMENT)->count())->toBe($before);
});

test('user activities index lists recorded payments and void events', function () {
    $admin = userActivityViewer();
    $customer = Customer::factory()->create();
    $product = createSaleTestProduct(100000);

    test()->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'customer_id' => $customer->id,
    ]))->assertRedirect();

    $sale = Sale::query()->latest('id')->firstOrFail();

    test()->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 30000,
        'payment_method' => 'orange_money',
    ])->assertRedirect();

    $payment = PaymentTransaction::query()->latest('id')->firstOrFail();

    test()->actingAs($admin)->post(route('sales.payments.void', $payment), [
        'void_reason' => 'Erreur de saisie test activité',
    ])->assertRedirect();

    $response = test()->actingAs($admin)->get(route('user-activities.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('UserActivities/Index')
            ->where('stats.sale_payments_count', 1)
            ->where('stats.sale_payment_voids_count', 1)
        );

    $activities = $response->original->getData()['page']['props']['activities']['data'] ?? [];
    $types = collect($activities)->pluck('activity_type')->all();

    expect($types)->toContain(UserActivityQueryService::TYPE_SALE_PAYMENT)
        ->and($types)->toContain(UserActivityQueryService::TYPE_SALE_PAYMENT_VOID);
});

test('payment void still creates cancel activity log entry', function () {
    $admin = userActivityViewer();
    $product = createSaleTestProduct(50000);

    test()->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'down_payment_amount' => 50000,
        'payment_method' => 'cash',
    ]))->assertRedirect();

    $payment = PaymentTransaction::query()->latest('id')->firstOrFail();

    test()->actingAs($admin)->post(route('sales.payments.void', $payment), [
        'void_reason' => 'Test annulation traçabilité',
    ])->assertRedirect();

    expect(ActivityLog::query()
        ->where('action', ActivityLog::ACTION_CANCEL)
        ->where('module', 'Paiement')
        ->where('new_values->payment_transaction_id', $payment->id)
        ->count())->toBe(1);
});
