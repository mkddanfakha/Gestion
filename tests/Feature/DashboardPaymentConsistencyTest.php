<?php

use App\Enums\PayableType;
use App\Enums\PaymentTransactionStatus;
use App\Models\PaymentTransaction;
use App\Models\Sale;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\SalePaymentTransactionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

require_once __DIR__.'/SalePaymentTestHelpers.php';

function dashboardAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function dashboardCustomPeriod(string $from, string $to): array
{
    $service = app(DashboardService::class);
    $request = Request::create('/dashboard', 'GET', [
        'period' => DashboardService::PERIOD_CUSTOM,
        'date_from' => $from,
        'date_to' => $to,
    ]);

    return $service->resolvePeriod($request);
}

function dashboardRevenue(User $user, array $period): float
{
    $build = app(DashboardService::class)->build($user, $period);
    $kpi = collect($build['kpis'])->firstWhere('key', 'revenue');

    return (float) ($kpi['value'] ?? 0);
}

function dashboardPaymentMethods(User $user, array $period): array
{
    $build = app(DashboardService::class)->build($user, $period);

    return $build['payment_methods'];
}

/**
 * Somme des encaissements réels (ledger posted) sur paid_at, ventes visibles.
 */
function ledgerCollectedInPeriod(User $user, \DateTimeInterface $start, \DateTimeInterface $end): float
{
    $saleIds = Sale::query()->visibleTo($user)->pluck('id');

    return (float) PaymentTransaction::query()
        ->where('payable_type', PayableType::Sale)
        ->where('status', PaymentTransactionStatus::Posted)
        ->whereIn('payable_id', $saleIds)
        ->whereBetween('paid_at', [$start, $end])
        ->sum('amount');
}

function ledgerByMethodInPeriod(User $user, \DateTimeInterface $start, \DateTimeInterface $end): array
{
    $saleIds = Sale::query()->visibleTo($user)->pluck('id');

    return PaymentTransaction::query()
        ->where('payable_type', PayableType::Sale)
        ->where('status', PaymentTransactionStatus::Posted)
        ->whereIn('payable_id', $saleIds)
        ->whereBetween('paid_at', [$start, $end])
        ->selectRaw('payment_method, SUM(amount) as total')
        ->groupBy('payment_method')
        ->pluck('total', 'payment_method')
        ->map(fn ($v) => (float) $v)
        ->all();
}

test('dashboard revenue matches ledger for sale paid in several installments', function () {
    Carbon::setTestNow('2026-03-01 10:00:00');
    $admin = dashboardAdmin();
    $product = createSaleTestProduct(100000);
    test()->actingAs($admin)->post(route('sales.store'), saleStorePayload($product))->assertRedirect();
    $sale = Sale::query()->latest('id')->first();

    Carbon::setTestNow('2026-03-05 12:00:00');
    test()->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 30000,
        'payment_method' => 'cash',
        'paid_at' => '2026-03-05T12:00',
    ])->assertRedirect();

    Carbon::setTestNow('2026-03-10 15:00:00');
    test()->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 70000,
        'payment_method' => 'wave',
        'paid_at' => '2026-03-10T15:00',
    ])->assertRedirect();

    $period = dashboardCustomPeriod('2026-03-01', '2026-03-31');
    $ledger = ledgerCollectedInPeriod($admin, $period['start'], $period['end']);

    expect($ledger)->toBe(100000.0)
        ->and(dashboardRevenue($admin, $period))->toBe($ledger);

    Carbon::setTestNow();
});

test('dashboard revenue matches ledger for partially paid sale', function () {
    Carbon::setTestNow('2026-04-01 09:00:00');
    $admin = dashboardAdmin();
    $product = createSaleTestProduct(100000);
    test()->actingAs($admin)->post(route('sales.store'), saleStorePayload($product))->assertRedirect();
    $sale = Sale::query()->latest('id')->first();

    test()->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 40000,
        'payment_method' => 'cash',
        'paid_at' => '2026-04-01T09:30',
    ])->assertRedirect();

    $period = dashboardCustomPeriod('2026-04-01', '2026-04-30');
    $ledger = ledgerCollectedInPeriod($admin, $period['start'], $period['end']);

    expect($ledger)->toBe(40000.0)
        ->and(dashboardRevenue($admin, $period))->toBe($ledger);

    Carbon::setTestNow();
});

test('voided payment is excluded from dashboard revenue', function () {
    (new PermissionSeeder())->run();
    Carbon::setTestNow('2026-05-01 08:00:00');
    $admin = dashboardAdmin();
    $product = createSaleTestProduct(100000);
    test()->actingAs($admin)->post(route('sales.store'), saleStorePayload($product))->assertRedirect();
    $sale = Sale::query()->latest('id')->first();

    test()->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 50000,
        'payment_method' => 'cash',
        'paid_at' => '2026-05-01T10:00',
    ])->assertRedirect();

    $payment = PaymentTransaction::query()->first();
    test()->actingAs($admin)->post(route('sales.payments.void', $payment), [
        'void_reason' => 'Erreur de saisie test',
    ])->assertRedirect();

    $period = dashboardCustomPeriod('2026-05-01', '2026-05-31');
    $ledger = ledgerCollectedInPeriod($admin, $period['start'], $period['end']);

    expect($ledger)->toBe(0.0)
        ->and(dashboardRevenue($admin, $period))->toBe(0.0);

    Carbon::setTestNow();
});

test('dashboard payment methods chart follows ledger per method', function () {
    Carbon::setTestNow('2026-06-01 08:00:00');
    $admin = dashboardAdmin();
    $product = createSaleTestProduct(100000);
    test()->actingAs($admin)->post(route('sales.store'), saleStorePayload($product))->assertRedirect();
    $sale = Sale::query()->latest('id')->first();

    test()->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 25000,
        'payment_method' => 'cash',
        'paid_at' => '2026-06-01T09:00',
    ])->assertRedirect();

    test()->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 75000,
        'payment_method' => 'orange_money',
        'paid_at' => '2026-06-02T11:00',
    ])->assertRedirect();

    $period = dashboardCustomPeriod('2026-06-01', '2026-06-30');
    $ledgerByMethod = ledgerByMethodInPeriod($admin, $period['start'], $period['end']);
    $chart = collect(dashboardPaymentMethods($admin, $period))->keyBy('method');

    expect($ledgerByMethod)->toMatchArray([
        'cash' => 25000.0,
        'orange_money' => 75000.0,
    ])
        ->and((float) $chart['cash']['amount'])->toBe(25000.0)
        ->and((float) $chart['orange_money']['amount'])->toBe(75000.0);

    Carbon::setTestNow();
});

test('dashboard revenue uses payment date not sale creation date for period filter', function () {
    Carbon::setTestNow('2026-02-10 10:00:00');
    $admin = dashboardAdmin();
    $product = createSaleTestProduct(80000);
    test()->actingAs($admin)->post(route('sales.store'), saleStorePayload($product))->assertRedirect();
    $sale = Sale::query()->latest('id')->first();

    Carbon::setTestNow('2026-03-15 14:00:00');
    test()->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 80000,
        'payment_method' => 'wave',
        'paid_at' => '2026-03-15T14:00',
    ])->assertRedirect();

    $feb = dashboardCustomPeriod('2026-02-01', '2026-02-28');
    $mar = dashboardCustomPeriod('2026-03-01', '2026-03-31');

    expect(dashboardRevenue($admin, $feb))->toBe(0.0)
        ->and(dashboardRevenue($admin, $mar))->toBe(80000.0)
        ->and(ledgerCollectedInPeriod($admin, $mar['start'], $mar['end']))->toBe(80000.0);

    Carbon::setTestNow();
});
