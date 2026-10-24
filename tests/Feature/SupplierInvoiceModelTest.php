<?php

use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function createSupplierForInvoice(string $name = 'Fournisseur Test'): Supplier
{
    return Supplier::create([
        'name' => $name,
        'status' => 'active',
    ]);
}

function createSupplierInvoice(User $user, Supplier $supplier, array $overrides = []): SupplierInvoice
{
    return SupplierInvoice::create(array_merge([
        'supplier_id' => $supplier->id,
        'invoice_number' => 'FAC-'.uniqid(),
        'invoice_date' => now()->toDateString(),
        'total_amount' => 125000.50,
        'status' => SupplierInvoice::STATUS_OPEN,
        'user_id' => $user->id,
    ], $overrides));
}

test('supplier invoice initializes payment aggregates on create', function () {
    $user = User::factory()->create();
    $supplier = createSupplierForInvoice();

    $invoice = createSupplierInvoice($user, $supplier, [
        'invoice_number' => 'INV-001',
        'total_amount' => 50000,
    ]);

    expect((float) $invoice->paid_amount)->toBe(0.0)
        ->and((float) $invoice->remaining_amount)->toBe(50000.0)
        ->and($invoice->payment_status)->toBe(SupplierInvoice::PAYMENT_STATUS_PENDING);
});

test('paid_amount and payment_status are not mass assignable from create payload', function () {
    $user = User::factory()->create();
    $supplier = createSupplierForInvoice();

    $invoice = SupplierInvoice::create([
        'supplier_id' => $supplier->id,
        'invoice_number' => 'INV-002',
        'invoice_date' => now()->toDateString(),
        'total_amount' => 10000,
        'paid_amount' => 9999,
        'remaining_amount' => 1,
        'payment_status' => SupplierInvoice::PAYMENT_STATUS_PAID,
        'status' => SupplierInvoice::STATUS_OPEN,
        'user_id' => $user->id,
    ]);

    expect((float) $invoice->paid_amount)->toBe(0.0)
        ->and((float) $invoice->remaining_amount)->toBe(10000.0)
        ->and($invoice->payment_status)->toBe(SupplierInvoice::PAYMENT_STATUS_PENDING);
});

test('invoice_number is unique per supplier not globally', function () {
    $user = User::factory()->create();
    $supplierA = createSupplierForInvoice('A');
    $supplierB = createSupplierForInvoice('B');

    createSupplierInvoice($user, $supplierA, ['invoice_number' => 'SHARED-NUM']);
    createSupplierInvoice($user, $supplierB, ['invoice_number' => 'SHARED-NUM']);

    expect(SupplierInvoice::query()->where('invoice_number', 'SHARED-NUM')->count())->toBe(2);
});

test('duplicate invoice_number for same supplier is rejected', function () {
    $user = User::factory()->create();
    $supplier = createSupplierForInvoice();

    createSupplierInvoice($user, $supplier, ['invoice_number' => 'DUP-001']);

    expect(fn () => createSupplierInvoice($user, $supplier, ['invoice_number' => 'DUP-001']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

test('supplier invoice belongs to supplier and optional purchase links', function () {
    $user = User::factory()->create();
    $supplier = createSupplierForInvoice();

    $invoice = createSupplierInvoice($user, $supplier, [
        'invoice_number' => 'INV-LINK',
        'purchase_order_id' => null,
        'delivery_note_id' => null,
    ]);

    expect($invoice->supplier->id)->toBe($supplier->id)
        ->and($supplier->fresh()->supplierInvoices)->toHaveCount(1)
        ->and($invoice->purchaseOrder)->toBeNull()
        ->and($invoice->deliveryNote)->toBeNull()
        ->and($invoice->user->id)->toBe($user->id);
});

test('supplier invoice payment transactions relation is scoped to supplier_invoice payable type', function () {
    $user = User::factory()->create();
    $supplier = createSupplierForInvoice();
    $invoice = createSupplierInvoice($user, $supplier, ['invoice_number' => 'INV-PT']);

    expect($invoice->paymentTransactions()->toSql())
        ->toContain('"payable_type" = ?');
});

test('sqlite tests use in memory database', function () {
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    expect(DB::connection()->getDatabaseName())->toBe(':memory:');
});
