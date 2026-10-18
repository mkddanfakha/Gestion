<?php

use App\Auth\RolePresets;
use App\Enums\PayableType;
use App\Enums\PaymentTransactionOrigin;
use App\Enums\PaymentTransactionStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\SalePaymentTransactionService;
use App\Services\Treasury\AuthorizedTreasuryReadService;
use App\Services\Treasury\TreasuryReadFilter;
use App\Services\Treasury\TreasuryReport;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

function seedTreasuryPermissions(): void
{
    (new PermissionSeeder())->run();
}

function attachTreasuryPermissionNames(User $user, array $names): User
{
    seedTreasuryPermissions();
    $ids = Permission::query()->whereIn('name', $names)->pluck('id')->all();
    $user->permissions()->syncWithoutDetaching($ids);

    return $user;
}

function treasuryAuthSale(User $owner, float $total = 50000): Sale
{
    $sale = Sale::query()->create([
        'sale_number' => 'FA'.uniqid(),
        'customer_id' => Customer::factory()->create()->id,
        'user_id' => $owner->id,
        'subtotal' => $total,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 0,
        'remaining_amount' => $total,
        'payment_status' => 'pending',
        'total_amount' => $total,
        'status' => 'completed',
        'payment_method' => null,
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

function treasuryAuthFilter(string $from, string $to, ?string $method = null): TreasuryReadFilter
{
    return new TreasuryReadFilter(
        from: Carbon::parse($from)->startOfDay(),
        to: Carbon::parse($to)->endOfDay(),
        paymentMethodCanonical: $method,
    );
}

function vendeurWithTreasury(): User
{
    $user = User::factory()->create(['role' => 'vendeur']);

    return attachTreasuryPermissionNames($user, [
        'treasury.view',
        'sales.view',
        'sales.payments.view',
    ]);
}

function gestionnaireWithTreasury(): User
{
    $user = User::factory()->create(['role' => 'gestionnaire']);

    return attachTreasuryPermissionNames($user, [
        'treasury.view',
        'expenses.view',
    ]);
}

test('user without treasury.view is forbidden on treasury index', function () {
    seedTreasuryPermissions();
    $user = User::factory()->create(['role' => 'vendeur']);
    attachTreasuryPermissionNames($user, ['sales.view']);

    $this->actingAs($user)
        ->get(route('treasury.index'))
        ->assertForbidden();
});

test('user with treasury.view can access treasury index', function () {
    $user = vendeurWithTreasury();

    $this->actingAs($user)
        ->get(route('treasury.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Treasury/Index')
            ->has('disclaimer')
            ->where('disclaimer', TreasuryReport::DISCLAIMER)
        );
});

test('vendeur treasury excludes another vendeur sale payments from movements and totals', function () {
    $vendeurA = vendeurWithTreasury();
    $vendeurB = User::factory()->create(['role' => 'vendeur']);

    $saleA = treasuryAuthSale($vendeurA, 30000);
    $saleB = treasuryAuthSale($vendeurB, 90000);

    $payments = app(SalePaymentTransactionService::class);
    $payments->recordForSale($saleA->id, $vendeurA, [
        'amount' => 30000,
        'payment_method' => 'cash',
        'paid_at' => '2026-05-10 10:00:00',
    ]);
    $payments->recordForSale($saleB->id, $vendeurB, [
        'amount' => 90000,
        'payment_method' => 'cash',
        'paid_at' => '2026-05-10 11:00:00',
    ]);

    $filter = treasuryAuthFilter('2026-05-01', '2026-05-31');
    $report = app(AuthorizedTreasuryReadService::class)->reportForUser($vendeurA, $filter);

    expect($report->periodTotals->totalInflows)->toBe(30000.0)
        ->and($report->movements)->toHaveCount(1)
        ->and($report->movements[0]->amount)->toBe(30000.0);

    $this->actingAs($vendeurA)
        ->get(route('treasury.index', [
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('periodTotals.total_inflows', fn ($value) => (float) $value === 30000.0)
            ->where('periodTotals.net', fn ($value) => (float) $value === 30000.0)
            ->has('movements.data', 1)
        );
});

test('gestionnaire treasury excludes another gestionnaire expenses per Expense visibleTo', function () {
    $gestA = gestionnaireWithTreasury();
    $gestB = User::factory()->create(['role' => 'gestionnaire']);

    Expense::query()->create([
        'expense_number' => Expense::generateExpenseNumber(),
        'title' => 'Dépense A',
        'amount' => 12000,
        'category' => 'autres',
        'payment_method' => 'cash',
        'expense_date' => '2026-06-15',
        'user_id' => $gestA->id,
    ]);
    Expense::query()->create([
        'expense_number' => Expense::generateExpenseNumber(),
        'title' => 'Dépense B',
        'amount' => 45000,
        'category' => 'autres',
        'payment_method' => 'cash',
        'expense_date' => '2026-06-15',
        'user_id' => $gestB->id,
    ]);

    $filter = treasuryAuthFilter('2026-06-01', '2026-06-30');
    $report = app(AuthorizedTreasuryReadService::class)->reportForUser($gestA, $filter);

    expect($report->periodTotals->totalOutflows)->toBe(12000.0)
        ->and($report->periodTotals->net)->toBe(-12000.0)
        ->and($report->movements)->toHaveCount(1);
});

test('gestionnaire with treasury does not see sale payments without sales permissions', function () {
    $gest = gestionnaireWithTreasury();
    $admin = User::factory()->create(['role' => 'admin']);

    $sale = treasuryAuthSale($admin, 60000);
    app(SalePaymentTransactionService::class)->recordForSale($sale->id, $admin, [
        'amount' => 60000,
        'payment_method' => 'cash',
        'paid_at' => '2026-07-01 09:00:00',
    ]);

    $filter = treasuryAuthFilter('2026-07-01', '2026-07-31');
    $report = app(AuthorizedTreasuryReadService::class)->reportForUser($gest, $filter);

    expect($report->movements)->toBe([])
        ->and($report->periodTotals->totalInflows)->toBe(0.0);
});

test('authorized treasury filters by payment method consistently on totals', function () {
    $user = vendeurWithTreasury();
    $sale = treasuryAuthSale($user, 80000);
    $payments = app(SalePaymentTransactionService::class);
    $payments->recordForSale($sale->id, $user, [
        'amount' => 20000,
        'payment_method' => 'cash',
        'paid_at' => '2026-08-01 10:00:00',
    ]);
    $payments->recordForSale($sale->id, $user, [
        'amount' => 15000,
        'payment_method' => 'wave',
        'paid_at' => '2026-08-02 10:00:00',
    ]);

    $filter = treasuryAuthFilter('2026-08-01', '2026-08-31', 'cash');
    $report = app(AuthorizedTreasuryReadService::class)->reportForUser($user, $filter);

    expect($report->movements)->toHaveCount(1)
        ->and($report->periodTotals->totalInflows)->toBe(20000.0)
        ->and($report->totalsByPaymentMethod)->toHaveKey('cash')
        ->and($report->totalsByPaymentMethod['cash']['inflows'])->toBe(20000.0)
        ->and($report->totalsByPaymentMethod)->not->toHaveKey('wave');
});

test('authorized treasury identifies historical backfill movements', function () {
    $user = vendeurWithTreasury();
    $sale = treasuryAuthSale($user, 25000);

    \App\Models\PaymentTransaction::query()->create([
        'payable_type' => PayableType::Sale,
        'payable_id' => $sale->id,
        'amount' => 25000,
        'paid_at' => '2026-09-01 08:00:00',
        'payment_method' => 'cash',
        'origin' => PaymentTransactionOrigin::HistoricalBackfill,
        'user_id' => $user->id,
        'status' => PaymentTransactionStatus::Posted,
        'note' => 'Reprise',
        'idempotency_key' => 'historical-backfill:sale:'.$sale->id.':auth-test',
    ]);

    $report = app(AuthorizedTreasuryReadService::class)->reportForUser(
        $user,
        treasuryAuthFilter('2026-09-01', '2026-09-30'),
    );

    expect($report->movements[0]->isHistoricalBackfill)->toBeTrue();

    $this->actingAs($user)
        ->get(route('treasury.index', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('movements.data.0.is_historical_backfill', true)
            ->where('movements.data.0.type_label', 'Reprise historique')
        );
});

test('authorized treasury excludes voided payments', function () {
    $user = vendeurWithTreasury();
    $sale = treasuryAuthSale($user, 100000);
    $paymentService = app(SalePaymentTransactionService::class);
    $payment = $paymentService->recordForSale($sale->id, $user, [
        'amount' => 50000,
        'payment_method' => 'cash',
        'paid_at' => '2026-10-05 10:00:00',
    ]);
    $paymentService->voidTransaction($payment, $user, 'Correction');

    $report = app(AuthorizedTreasuryReadService::class)->reportForUser(
        $user,
        treasuryAuthFilter('2026-10-01', '2026-10-31'),
    );

    expect($report->periodTotals->totalInflows)->toBe(0.0)
        ->and($report->movements)->toBe([]);
});

test('authorized treasury does not double count a single posted payment', function () {
    $user = vendeurWithTreasury();
    $sale = treasuryAuthSale($user, 40000);
    app(SalePaymentTransactionService::class)->recordForSale($sale->id, $user, [
        'amount' => 40000,
        'payment_method' => 'cash',
        'paid_at' => '2026-11-01 12:00:00',
    ]);

    $report = app(AuthorizedTreasuryReadService::class)->reportForUser(
        $user,
        treasuryAuthFilter('2026-11-01', '2026-11-30'),
    );

    expect($report->movements)->toHaveCount(1)
        ->and($report->periodTotals->totalInflows)->toBe(40000.0)
        ->and($report->periodTotals->net)->toBe(40000.0);
});

test('role presets grant treasury.view to vendeur and gestionnaire only among presets', function () {
    expect(RolePresets::permissionNames(User::ROLE_VENDEUR))->toContain('treasury.view')
        ->and(RolePresets::permissionNames(User::ROLE_GESTIONNAIRE))->toContain('treasury.view')
        ->and(RolePresets::permissionNames(User::ROLE_USER))->not->toContain('treasury.view');
});
