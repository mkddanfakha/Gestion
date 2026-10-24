<?php

use App\Enums\PayableType;
use App\Enums\PaymentTransactionStatus;
use App\Exceptions\InvalidPaymentTransactionException;
use App\Models\ActivityLog;
use App\Models\PaymentTransaction;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\SupplierPaymentTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function supplierPaymentUser(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function supplierPaymentSupplier(): Supplier
{
    return Supplier::create([
        'name' => 'Fournisseur '.uniqid(),
        'status' => 'active',
    ]);
}

function supplierPaymentOpenInvoice(
    User $user,
    ?Supplier $supplier = null,
    float $total = 100000,
    string $status = SupplierInvoice::STATUS_OPEN,
): SupplierInvoice {
    $supplier ??= supplierPaymentSupplier();

    return SupplierInvoice::create([
        'supplier_id' => $supplier->id,
        'invoice_number' => 'FAC-'.uniqid(),
        'invoice_date' => now()->toDateString(),
        'total_amount' => $total,
        'status' => $status,
        'user_id' => $user->id,
    ]);
}

test('open supplier invoice accepts partial payment and updates aggregates', function () {
    $user = supplierPaymentUser();
    $invoice = supplierPaymentOpenInvoice($user);
    $service = app(SupplierPaymentTransactionService::class);

    $tx = $service->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 30000,
        'payment_method' => 'cash',
    ]);

    $invoice->refresh();

    expect($tx->status)->toBe(PaymentTransactionStatus::Posted)
        ->and($tx->payable_type)->toBe(PayableType::SupplierInvoice)
        ->and((float) $invoice->paid_amount)->toBe(30000.0)
        ->and((float) $invoice->remaining_amount)->toBe(70000.0)
        ->and($invoice->payment_status)->toBe(SupplierInvoice::PAYMENT_STATUS_PARTIAL);
});

test('full payment marks supplier invoice as paid', function () {
    $user = supplierPaymentUser();
    $invoice = supplierPaymentOpenInvoice($user, null, 50000);
    $service = app(SupplierPaymentTransactionService::class);

    $service->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 50000,
        'payment_method' => 'bank_transfer',
    ]);

    $invoice->refresh();

    expect($invoice->payment_status)->toBe(SupplierInvoice::PAYMENT_STATUS_PAID)
        ->and((float) $invoice->paid_amount)->toBe(50000.0)
        ->and((float) $invoice->remaining_amount)->toBe(0.0);
});

test('overpayment on supplier invoice is rejected', function () {
    $user = supplierPaymentUser();
    $invoice = supplierPaymentOpenInvoice($user);
    $service = app(SupplierPaymentTransactionService::class);

    $service->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 100001,
        'payment_method' => 'cash',
    ]);
})->throws(InvalidPaymentTransactionException::class);

test('zero amount payment is rejected', function () {
    $user = supplierPaymentUser();
    $invoice = supplierPaymentOpenInvoice($user);
    $service = app(SupplierPaymentTransactionService::class);

    $service->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 0,
        'payment_method' => 'cash',
    ]);
})->throws(InvalidPaymentTransactionException::class);

test('payment on draft supplier invoice is rejected', function () {
    $user = supplierPaymentUser();
    $invoice = supplierPaymentOpenInvoice($user, null, 100000, SupplierInvoice::STATUS_DRAFT);
    $service = app(SupplierPaymentTransactionService::class);

    $service->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 1000,
        'payment_method' => 'cash',
    ]);
})->throws(InvalidPaymentTransactionException::class, 'ouvertes');

test('payment on cancelled supplier invoice is rejected', function () {
    $user = supplierPaymentUser();
    $invoice = supplierPaymentOpenInvoice($user, null, 100000, SupplierInvoice::STATUS_CANCELLED);
    $service = app(SupplierPaymentTransactionService::class);

    $service->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 1000,
        'payment_method' => 'cash',
    ]);
})->throws(InvalidPaymentTransactionException::class, 'annulée');

test('misaligned paid aggregate blocks payment recording', function () {
    $user = supplierPaymentUser();
    $invoice = supplierPaymentOpenInvoice($user);
    SupplierInvoice::query()->whereKey($invoice->id)->update(['paid_amount' => 5000]);
    $invoice->refresh();

    $service = app(SupplierPaymentTransactionService::class);

    $service->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 1000,
        'payment_method' => 'cash',
    ]);
})->throws(InvalidPaymentTransactionException::class, 'montant payé affiché');

test('voided supplier payments are excluded from paid total', function () {
    $user = supplierPaymentUser();
    $invoice = supplierPaymentOpenInvoice($user);
    $service = app(SupplierPaymentTransactionService::class);

    $tx = $service->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 40000,
        'payment_method' => 'cash',
    ]);

    $service->voidTransaction($tx, $user, 'Erreur de saisie');

    $invoice->refresh();

    expect((float) $invoice->paid_amount)->toBe(0.0)
        ->and($invoice->payment_status)->toBe(SupplierInvoice::PAYMENT_STATUS_PENDING)
        ->and($tx->fresh()->status)->toBe(PaymentTransactionStatus::Voided)
        ->and($tx->fresh()->void_reason)->toBe('Erreur de saisie')
        ->and(PaymentTransaction::query()->count())->toBe(1);
});

test('void on already voided supplier payment is rejected', function () {
    $user = supplierPaymentUser();
    $invoice = supplierPaymentOpenInvoice($user);
    $service = app(SupplierPaymentTransactionService::class);

    $tx = $service->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 10000,
        'payment_method' => 'cash',
    ]);

    $service->voidTransaction($tx, $user, 'Première annulation');

    $service->voidTransaction($tx->fresh(), $user, 'Deuxième annulation');
})->throws(InvalidPaymentTransactionException::class, 'déjà annulé');

test('idempotency key prevents duplicate supplier payment registration', function () {
    $user = supplierPaymentUser();
    $invoice = supplierPaymentOpenInvoice($user);
    $service = app(SupplierPaymentTransactionService::class);

    $first = $service->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 10000,
        'payment_method' => 'cash',
        'idempotency_key' => 'supplier-inv-key-1',
    ]);

    $second = $service->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 10000,
        'payment_method' => 'cash',
        'idempotency_key' => 'supplier-inv-key-1',
    ]);

    expect($second->id)->toBe($first->id)
        ->and(PaymentTransaction::query()->count())->toBe(1);

    $invoice->refresh();

    expect((float) $invoice->paid_amount)->toBe(10000.0);
});

test('failed supplier payment transaction leaves no partial row or aggregate change', function () {
    $user = supplierPaymentUser();
    $invoice = supplierPaymentOpenInvoice($user);
    $service = app(SupplierPaymentTransactionService::class);

    try {
        DB::transaction(function () use ($service, $invoice, $user) {
            $service->recordForSupplierInvoice($invoice->id, $user, [
                'amount' => 10000,
                'payment_method' => 'cash',
            ]);

            throw new RuntimeException('Simulated failure');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(PaymentTransaction::query()->count())->toBe(0);

    $invoice->refresh();

    expect((float) $invoice->paid_amount)->toBe(0.0)
        ->and((float) $invoice->remaining_amount)->toBe(100000.0);
});

test('supplier payment recording writes activity log entry', function () {
    $user = supplierPaymentUser();
    $invoice = supplierPaymentOpenInvoice($user);
    $service = app(SupplierPaymentTransactionService::class);

    $tx = $service->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 15000,
        'payment_method' => 'wave',
        'reference' => 'REF-42',
    ]);

    $log = ActivityLog::query()
        ->where('action', ActivityLog::ACTION_PAYMENT)
        ->where('new_values->payment_transaction_id', $tx->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->new_values['supplier_invoice_id'] ?? null)->toBe($invoice->id)
        ->and($log->new_values['invoice_number'] ?? null)->toBe($invoice->invoice_number);
});

test('consecutive supplier payments cannot exceed invoice total', function () {
    $user = supplierPaymentUser();
    $invoice = supplierPaymentOpenInvoice($user);
    $service = app(SupplierPaymentTransactionService::class);

    $service->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 60000,
        'payment_method' => 'cash',
    ]);

    $service->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 50000,
        'payment_method' => 'cash',
    ]);
})->throws(InvalidPaymentTransactionException::class);

test('sqlite supplier payment tests use in memory database', function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    expect(DB::connection()->getDatabaseName())->toBe(':memory:');
});

test('open invoice with posted payments cannot be cancelled until payments are voided', function () {
    $user = supplierPaymentUser();
    $invoice = supplierPaymentOpenInvoice($user);
    $service = app(SupplierPaymentTransactionService::class);

    $service->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 25000,
        'payment_method' => 'cash',
    ]);

    $invoice->refresh();

    expect(fn () => $invoice->update(['status' => SupplierInvoice::STATUS_CANCELLED]))
        ->toThrow(InvalidPaymentTransactionException::class, 'règlements actifs');

    expect($invoice->fresh()->status)->toBe(SupplierInvoice::STATUS_OPEN);
});

test('invoice without posted payments can be cancelled', function () {
    $user = supplierPaymentUser();
    $invoice = supplierPaymentOpenInvoice($user);

    $invoice->update(['status' => SupplierInvoice::STATUS_CANCELLED]);

    expect($invoice->fresh()->status)->toBe(SupplierInvoice::STATUS_CANCELLED);
});

test('invoice can be cancelled after all posted payments are voided', function () {
    $user = supplierPaymentUser();
    $invoice = supplierPaymentOpenInvoice($user);
    $service = app(SupplierPaymentTransactionService::class);

    $tx = $service->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 15000,
        'payment_method' => 'cash',
    ]);

    $service->voidTransaction($tx, $user, 'Correction');

    $invoice->refresh()->update(['status' => SupplierInvoice::STATUS_CANCELLED]);

    expect($invoice->fresh()->status)->toBe(SupplierInvoice::STATUS_CANCELLED)
        ->and((float) $invoice->paid_amount)->toBe(0.0);
});

test('supplier payment void activity log helper stores audit fields for future controller', function () {
    $user = supplierPaymentUser();
    $invoice = supplierPaymentOpenInvoice($user);
    $service = app(SupplierPaymentTransactionService::class);

    $tx = $service->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 12000,
        'payment_method' => 'cash',
    ]);

    $voided = $service->voidTransaction($tx, $user, 'Motif audit test');

    ActivityLogger::logSupplierPaymentVoid($invoice->fresh(), $voided);

    $log = ActivityLog::query()
        ->where('action', ActivityLog::ACTION_CANCEL)
        ->where('new_values->payment_transaction_id', $voided->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->new_values['void_reason'] ?? null)->toBe('Motif audit test')
        ->and($log->new_values['voided_by_user_id'] ?? null)->toBe($user->id)
        ->and($log->new_values['supplier_invoice_id'] ?? null)->toBe($invoice->id);
});

test('payment aggregates after void match ledger posted sum', function () {
    $user = supplierPaymentUser();
    $invoice = supplierPaymentOpenInvoice($user, null, 80000);
    $service = app(SupplierPaymentTransactionService::class);

    $tx = $service->recordForSupplierInvoice($invoice->id, $user, [
        'amount' => 50000,
        'payment_method' => 'wave',
    ]);

    $service->voidTransaction($tx, $user, 'Test cohérence');

    $invoice->refresh();
    $posted = $service->sumPostedAmount(PayableType::SupplierInvoice, $invoice->id);

    expect($posted)->toBe(0.0)
        ->and((float) $invoice->paid_amount)->toBe(0.0)
        ->and((float) $invoice->remaining_amount)->toBe(80000.0)
        ->and($invoice->payment_status)->toBe(SupplierInvoice::PAYMENT_STATUS_PENDING);

    $service->assertPaymentLedgerAligned($invoice);
});
