<?php

use App\Enums\PayableType;
use App\Enums\PaymentTransactionOrigin;
use App\Enums\PaymentTransactionStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\SalePaymentTransactionService;
use App\Services\SupplierPaymentTransactionService;
use App\Services\Treasury\TreasuryMovement;
use App\Services\Treasury\TreasuryReadFilter;
use App\Services\Treasury\TreasuryReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function treasuryUser(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function treasurySale(User $user, float $total): Sale
{
    $sale = Sale::query()->create([
        'sale_number' => 'FA'.uniqid(),
        'customer_id' => Customer::factory()->create()->id,
        'user_id' => $user->id,
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

function treasuryFilter(Carbon $from, Carbon $to): TreasuryReadFilter
{
    return new TreasuryReadFilter(from: $from, to: $to);
}

function treasuryOpenSupplierInvoice(User $user, float $total = 50000): SupplierInvoice
{
    $supplier = Supplier::create([
        'name' => 'Trésorerie Fournisseur',
        'status' => 'active',
    ]);

    return SupplierInvoice::create([
        'supplier_id' => $supplier->id,
        'invoice_number' => 'TREAS-'.uniqid(),
        'invoice_date' => now()->toDateString(),
        'total_amount' => $total,
        'status' => SupplierInvoice::STATUS_OPEN,
        'user_id' => $user->id,
    ]);
}

test('treasury includes posted sale payments as positive inflows', function () {
    $user = treasuryUser();
    $sale = treasurySale($user, 100000);
    $service = app(SalePaymentTransactionService::class);
    $service->recordForSale($sale->id, $user, [
        'amount' => 40000,
        'payment_method' => 'cash',
        'paid_at' => '2026-03-10 14:00:00',
    ]);

    $treasury = app(TreasuryReadService::class);
    $filter = treasuryFilter(
        Carbon::parse('2026-03-01'),
        Carbon::parse('2026-03-31 23:59:59'),
    );

    $totals = $treasury->periodTotals($filter);

    expect($totals->totalInflows)->toBe(40000.0)
        ->and($totals->totalOutflows)->toBe(0.0)
        ->and($totals->net)->toBe(40000.0);
});

test('treasury excludes voided payments from totals', function () {
    $user = treasuryUser();
    $sale = treasurySale($user, 100000);
    $paymentService = app(SalePaymentTransactionService::class);
    $payment = $paymentService->recordForSale($sale->id, $user, [
        'amount' => 50000,
        'payment_method' => 'cash',
        'paid_at' => '2026-04-05 10:00:00',
    ]);

    $paymentService->voidTransaction($payment, $user, 'Correction caisse');

    $treasury = app(TreasuryReadService::class);
    $filter = treasuryFilter(
        Carbon::parse('2026-04-01'),
        Carbon::parse('2026-04-30 23:59:59'),
    );

    expect($treasury->periodTotals($filter)->totalInflows)->toBe(0.0)
        ->and(PaymentTransaction::query()->where('status', PaymentTransactionStatus::Voided)->count())->toBe(1);
});

test('treasury includes expenses as negative outflows on expense_date', function () {
    $user = treasuryUser();

    Expense::query()->create([
        'expense_number' => Expense::generateExpenseNumber(),
        'title' => 'Fournitures bureau',
        'amount' => 12500,
        'category' => 'fournitures',
        'payment_method' => 'cash',
        'expense_date' => '2026-05-12',
        'user_id' => $user->id,
    ]);

    $treasury = app(TreasuryReadService::class);
    $filter = treasuryFilter(
        Carbon::parse('2026-05-01'),
        Carbon::parse('2026-05-31'),
    );

    $totals = $treasury->periodTotals($filter);
    $movements = $treasury->movements($filter);

    expect($totals->totalOutflows)->toBe(12500.0)
        ->and($totals->net)->toBe(-12500.0)
        ->and($movements)->toHaveCount(1)
        ->and($movements[0]->sourceType)->toBe(TreasuryMovement::SOURCE_EXPENSE)
        ->and($movements[0]->amount)->toBe(-12500.0);
});

test('treasury net combines inflows and outflows for the period', function () {
    $user = treasuryUser();
    $sale = treasurySale($user, 80000);
    app(SalePaymentTransactionService::class)->recordForSale($sale->id, $user, [
        'amount' => 60000,
        'payment_method' => 'wave',
        'paid_at' => '2026-06-15 09:30:00',
    ]);

    Expense::query()->create([
        'expense_number' => Expense::generateExpenseNumber(),
        'title' => 'Transport',
        'amount' => 10000,
        'category' => 'transport',
        'payment_method' => 'cash',
        'expense_date' => '2026-06-20',
        'user_id' => $user->id,
    ]);

    $treasury = app(TreasuryReadService::class);
    $filter = treasuryFilter(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30 23:59:59'),
    );

    expect($treasury->periodTotals($filter)->net)->toBe(50000.0);
});

test('treasury aggregates totals by normalized payment method', function () {
    $user = treasuryUser();
    $sale = treasurySale($user, 50000);
    app(SalePaymentTransactionService::class)->recordForSale($sale->id, $user, [
        'amount' => 20000,
        'payment_method' => 'card',
        'paid_at' => '2026-07-01 12:00:00',
    ]);

    Expense::query()->create([
        'expense_number' => Expense::generateExpenseNumber(),
        'title' => 'Abonnement',
        'amount' => 5000,
        'category' => 'autres',
        'payment_method' => 'credit_card',
        'expense_date' => '2026-07-02',
        'user_id' => $user->id,
    ]);

    $treasury = app(TreasuryReadService::class);
    $filter = treasuryFilter(
        Carbon::parse('2026-07-01'),
        Carbon::parse('2026-07-31'),
    );

    $byMethod = $treasury->totalsByPaymentMethod($filter);

    expect($byMethod['card']['inflows'])->toBe(20000.0)
        ->and($byMethod['card']['outflows'])->toBe(5000.0)
        ->and($byMethod['card']['net'])->toBe(15000.0)
        ->and($byMethod['card']['known'])->toBeTrue();
});

test('treasury flags historical backfill origin on payment movements', function () {
    $user = treasuryUser();
    $sale = treasurySale($user, 30000);

    PaymentTransaction::query()->create([
        'payable_type' => PayableType::Sale,
        'payable_id' => $sale->id,
        'amount' => 30000,
        'paid_at' => '2026-08-01 08:00:00',
        'payment_method' => 'cash',
        'origin' => PaymentTransactionOrigin::HistoricalBackfill,
        'user_id' => $user->id,
        'status' => PaymentTransactionStatus::Posted,
        'note' => 'Reprise historique',
        'idempotency_key' => 'historical-backfill:sale:'.$sale->id,
    ]);

    $treasury = app(TreasuryReadService::class);
    $movements = $treasury->movements(treasuryFilter(
        Carbon::parse('2026-08-01'),
        Carbon::parse('2026-08-31'),
    ));

    expect($movements)->toHaveCount(1)
        ->and($movements[0]->isHistoricalBackfill)->toBeTrue()
        ->and($movements[0]->paymentOrigin)->toBe('historical_backfill');
});

test('treasury empty period returns zero totals and no movements', function () {
    $user = treasuryUser();
    $sale = treasurySale($user, 10000);
    app(SalePaymentTransactionService::class)->recordForSale($sale->id, $user, [
        'amount' => 10000,
        'payment_method' => 'cash',
        'paid_at' => '2026-01-10 10:00:00',
    ]);

    $treasury = app(TreasuryReadService::class);
    $filter = treasuryFilter(
        Carbon::parse('2026-02-01'),
        Carbon::parse('2026-02-28'),
    );

    expect($treasury->movements($filter))->toBe([])
        ->and($treasury->periodTotals($filter)->net)->toBe(0.0);
});

test('treasury inflows use posted payments not full sale total', function () {
    $user = treasuryUser();
    $sale = treasurySale($user, 100000);
    app(SalePaymentTransactionService::class)->recordForSale($sale->id, $user, [
        'amount' => 25000,
        'payment_method' => 'orange_money',
        'paid_at' => '2026-09-01 16:00:00',
    ]);

    $treasury = app(TreasuryReadService::class);
    $filter = treasuryFilter(
        Carbon::parse('2026-09-01'),
        Carbon::parse('2026-09-30 23:59:59'),
    );

    expect($treasury->periodTotals($filter)->totalInflows)->toBe(25000.0)
        ->and($treasury->periodTotals($filter)->totalInflows)->not->toBe(100000.0);
});

test('treasury includes posted supplier invoice payments as negative outflows', function () {
    $user = treasuryUser();
    $invoice = treasuryOpenSupplierInvoice($user, 80000);

    app(SupplierPaymentTransactionService::class)->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 35000,
        'payment_method' => 'bank_transfer',
        'paid_at' => '2026-12-10 15:00:00',
    ]);

    $treasury = app(TreasuryReadService::class);
    $filter = treasuryFilter(
        Carbon::parse('2026-12-01'),
        Carbon::parse('2026-12-31 23:59:59'),
    );

    $movements = $treasury->movements($filter);
    $totals = $treasury->periodTotals($filter);

    expect($totals->totalOutflows)->toBe(35000.0)
        ->and($totals->totalInflows)->toBe(0.0)
        ->and($totals->net)->toBe(-35000.0)
        ->and($movements)->toHaveCount(1)
        ->and($movements[0]->sourceType)->toBe(TreasuryMovement::SOURCE_SUPPLIER_INVOICE_PAYMENT)
        ->and($movements[0]->amount)->toBe(-35000.0)
        ->and($movements[0]->supplierInvoiceId)->toBe($invoice->id)
        ->and($movements[0]->supplierName)->toBe('Trésorerie Fournisseur');
});

test('treasury excludes voided supplier invoice payments', function () {
    $user = treasuryUser();
    $invoice = treasuryOpenSupplierInvoice($user);
    $service = app(SupplierPaymentTransactionService::class);

    $payment = $service->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 20000,
        'payment_method' => 'cash',
        'paid_at' => '2026-12-05 10:00:00',
    ]);

    $service->voidTransaction($payment, $user, 'Annulation test trésorerie');

    $treasury = app(TreasuryReadService::class);
    $filter = treasuryFilter(
        Carbon::parse('2026-12-01'),
        Carbon::parse('2026-12-31 23:59:59'),
    );

    expect($treasury->periodTotals($filter)->totalOutflows)->toBe(0.0)
        ->and($treasury->movements($filter))->toBe([]);
});

test('treasury supplier payments ignore sale payable type rows', function () {
    $user = treasuryUser();
    $sale = treasurySale($user, 10000);
    $invoice = treasuryOpenSupplierInvoice($user, 10000);

    app(SalePaymentTransactionService::class)->recordForSale($sale->id, $user, [
        'amount' => 10000,
        'payment_method' => 'cash',
        'paid_at' => '2026-12-20 10:00:00',
    ]);

    app(SupplierPaymentTransactionService::class)->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 5000,
        'payment_method' => 'cash',
        'paid_at' => '2026-12-20 11:00:00',
    ]);

    $treasury = app(TreasuryReadService::class);
    $filter = treasuryFilter(
        Carbon::parse('2026-12-01'),
        Carbon::parse('2026-12-31 23:59:59'),
    );

    $movements = $treasury->movements($filter);

    expect($movements)->toHaveCount(2)
        ->and(collect($movements)->where('sourceType', TreasuryMovement::SOURCE_SALE_PAYMENT)->count())->toBe(1)
        ->and(collect($movements)->where('sourceType', TreasuryMovement::SOURCE_SUPPLIER_INVOICE_PAYMENT)->count())->toBe(1)
        ->and($treasury->periodTotals($filter)->net)->toBe(5000.0);
});

test('treasury does not double count supplier payment with expense in same period', function () {
    $user = treasuryUser();
    $invoice = treasuryOpenSupplierInvoice($user, 40000);

    app(SupplierPaymentTransactionService::class)->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 15000,
        'payment_method' => 'cash',
        'paid_at' => '2026-12-18 09:00:00',
    ]);

    Expense::query()->create([
        'expense_number' => Expense::generateExpenseNumber(),
        'title' => 'Charge diverse',
        'amount' => 8000,
        'category' => 'autres',
        'payment_method' => 'cash',
        'expense_date' => '2026-12-19',
        'user_id' => $user->id,
    ]);

    $treasury = app(TreasuryReadService::class);
    $filter = treasuryFilter(
        Carbon::parse('2026-12-01'),
        Carbon::parse('2026-12-31 23:59:59'),
    );

    expect($treasury->periodTotals($filter)->totalOutflows)->toBe(23000.0)
        ->and($treasury->movements($filter))->toHaveCount(2);
});

test('treasury supplier payment without matching invoice row still produces one outflow movement', function () {
    $user = treasuryUser();

    PaymentTransaction::query()->create([
        'payable_type' => PayableType::SupplierInvoice,
        'payable_id' => 999_999,
        'amount' => 7777,
        'paid_at' => '2026-12-22 12:00:00',
        'payment_method' => 'cash',
        'origin' => PaymentTransactionOrigin::Recorded,
        'user_id' => $user->id,
        'status' => PaymentTransactionStatus::Posted,
        'idempotency_key' => 'orphan-supplier-invoice-payment:'.uniqid(),
    ]);

    expect(SupplierInvoice::query()->find(999_999))->toBeNull();

    $treasury = app(TreasuryReadService::class);
    $filter = treasuryFilter(
        Carbon::parse('2026-12-01'),
        Carbon::parse('2026-12-31 23:59:59'),
    );

    $movements = $treasury->movements($filter);

    expect($movements)->toHaveCount(1)
        ->and($movements[0]->amount)->toBe(-7777.0)
        ->and($movements[0]->supplierInvoiceId)->toBeNull()
        ->and($movements[0]->label)->toContain('999999');
});

test('treasury supplier payment outside date filter is excluded', function () {
    $user = treasuryUser();
    $invoice = treasuryOpenSupplierInvoice($user);

    app(SupplierPaymentTransactionService::class)->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 12000,
        'payment_method' => 'wave',
        'paid_at' => '2026-01-15 10:00:00',
    ]);

    $treasury = app(TreasuryReadService::class);
    $filter = treasuryFilter(
        Carbon::parse('2026-02-01'),
        Carbon::parse('2026-02-28'),
    );

    expect($treasury->movements($filter))->toBe([])
        ->and($treasury->periodTotals($filter)->totalOutflows)->toBe(0.0);
});

test('treasury filter by payment method canonical limits movements', function () {
    $user = treasuryUser();
    $sale = treasurySale($user, 50000);
    app(SalePaymentTransactionService::class)->recordForSale($sale->id, $user, [
        'amount' => 15000,
        'payment_method' => 'cash',
        'paid_at' => '2026-10-05 11:00:00',
    ]);
    app(SalePaymentTransactionService::class)->recordForSale($sale->id, $user, [
        'amount' => 10000,
        'payment_method' => 'wave',
        'paid_at' => '2026-10-06 11:00:00',
    ]);

    $treasury = app(TreasuryReadService::class);
    $filter = new TreasuryReadFilter(
        from: Carbon::parse('2026-10-01'),
        to: Carbon::parse('2026-10-31 23:59:59'),
        paymentMethodCanonical: 'cash',
    );

    expect($treasury->movements($filter))->toHaveCount(1)
        ->and($treasury->periodTotals($filter)->totalInflows)->toBe(15000.0);
});
