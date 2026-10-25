<?php

use App\Enums\PayableType;
use App\Models\ActivityLog;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedSupplierInvoiceHttpPermissions(): void
{
    (new PermissionSeeder)->run();
}

function supplierInvoiceHttpUser(array $permissionNames): User
{
    seedSupplierInvoiceHttpPermissions();

    $user = User::factory()->create(['role' => 'gestionnaire']);
    $ids = Permission::query()->whereIn('name', $permissionNames)->pluck('id');
    $user->permissions()->sync($ids);

    return $user;
}

function supplierInvoiceHttpSupplier(): Supplier
{
    return Supplier::create([
        'name' => 'Fournisseur HTTP '.uniqid(),
        'status' => 'active',
    ]);
}

function validSupplierInvoiceStorePayload(Supplier $supplier, array $overrides = []): array
{
    return array_merge([
        'supplier_id' => $supplier->id,
        'invoice_number' => 'HTTP-'.uniqid(),
        'invoice_date' => now()->toDateString(),
        'total_amount' => 75000,
    ], $overrides);
}

function createOpenSupplierInvoiceViaHttp(User $user, Supplier $supplier, float $total = 75000): SupplierInvoice
{
    $response = test()->actingAs($user)->postJson(
        route('supplier-invoices.store'),
        validSupplierInvoiceStorePayload($supplier, ['total_amount' => $total]),
    );

    $response->assertCreated();

    $invoice = SupplierInvoice::query()->latest('id')->firstOrFail();

    test()->actingAs($user)->postJson(route('supplier-invoices.open', $invoice))->assertOk();

    return $invoice->fresh();
}

test('user without supplier invoice view permission cannot list invoices', function () {
    $user = User::factory()->create(['role' => 'gestionnaire']);

    $this->actingAs($user)->getJson(route('supplier-invoices.index'))->assertForbidden();
    $this->actingAs($user)->get(route('supplier-invoices.index'))->assertForbidden();
});

test('authorized user can access supplier invoice inertia pages', function () {
    $user = supplierInvoiceHttpUser([
        'supplier-invoices.view',
        'supplier-invoices.create',
        'supplier-invoices.open',
    ]);
    $supplier = supplierInvoiceHttpSupplier();

    $this->actingAs($user)->get(route('supplier-invoices.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('SupplierInvoices/Index')
            ->has('supplierInvoices.data')
            ->has('filters'));

    $this->actingAs($user)->get(route('supplier-invoices.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('SupplierInvoices/Create')
            ->has('suppliers')
            ->has('attachmentConfig'));

    $this->actingAs($user)->post(route('supplier-invoices.store'), validSupplierInvoiceStorePayload($supplier))
        ->assertRedirect();

    $invoice = SupplierInvoice::query()->firstOrFail();

    $this->actingAs($user)->get(route('supplier-invoices.show', $invoice))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('SupplierInvoices/Show')
            ->has('supplierInvoice')
            ->has('paymentSummary')
            ->where('canOpenInvoice', true)
            ->where('canRecordPayment', false)
            ->where('canViewPaymentHistory', false)
            ->has('paymentTransactions', 0));

    $this->actingAs($user)->post(route('supplier-invoices.open', $invoice))
        ->assertRedirect(route('supplier-invoices.show', $invoice));

    $this->actingAs($user)->get(route('supplier-invoices.show', $invoice->fresh()))
        ->assertInertia(fn ($page) => $page
            ->where('canOpenInvoice', false)
            ->where('canRecordPayment', false)
            ->where('supplierInvoice.status', SupplierInvoice::STATUS_OPEN));
});

test('show exposes payment ui flags and history according to permissions', function () {
    $recorder = supplierInvoiceHttpUser([
        'supplier-invoices.view',
        'supplier-invoices.create',
        'supplier-invoices.open',
        'supplier-invoices.payments.view',
        'supplier-invoices.payments.record',
    ]);
    $supplier = supplierInvoiceHttpSupplier();
    $invoice = createOpenSupplierInvoiceViaHttp($recorder, $supplier, 40000);

    $this->actingAs($recorder)->get(route('supplier-invoices.show', $invoice))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canRecordPayment', true)
            ->where('canViewPaymentHistory', true)
            ->where('canVoidPayment', false)
            ->has('paymentTransactions', 0));

    $this->actingAs($recorder)->post(route('supplier-invoices.payments.store', $invoice), [
        'amount' => 10000,
        'payment_method' => 'cash',
    ])->assertRedirect(route('supplier-invoices.show', $invoice));

    $invoice->refresh();

    expect((float) $invoice->paid_amount)->toBe(10000.0)
        ->and((float) $invoice->remaining_amount)->toBe(30000.0);

    $this->actingAs($recorder)->get(route('supplier-invoices.show', $invoice))
        ->assertInertia(fn ($page) => $page
            ->where('canRecordPayment', true)
            ->where('paymentSummary.total_paid', 10000)
            ->where('paymentSummary.remaining_balance', 30000)
            ->has('paymentTransactions', 1)
            ->where('paymentTransactions.0.status', 'posted'));

    $this->actingAs($recorder)->post(route('supplier-invoices.payments.store', $invoice), [
        'amount' => 30000,
        'payment_method' => 'wave',
    ])->assertRedirect();

    $paid = $invoice->fresh();

    $this->actingAs($recorder)->get(route('supplier-invoices.show', $paid))
        ->assertInertia(fn ($page) => $page
            ->where('canRecordPayment', false)
            ->where('paymentSummary.remaining_balance', 0)
            ->where('paymentSummary.payment_status', SupplierInvoice::PAYMENT_STATUS_PAID));

    $viewer = supplierInvoiceHttpUser([
        'supplier-invoices.view',
        'supplier-invoices.payments.view',
    ]);

    $this->actingAs($viewer)->get(route('supplier-invoices.show', $paid))
        ->assertInertia(fn ($page) => $page
            ->where('canRecordPayment', false)
            ->where('canViewPaymentHistory', true)
            ->has('paymentTransactions', 2));
});

test('voided supplier payment appears in inertia history without counting as active collection', function () {
    seedSupplierInvoiceHttpPermissions();
    $admin = User::factory()->create(['role' => 'admin']);
    $supplier = supplierInvoiceHttpSupplier();
    $invoice = createOpenSupplierInvoiceViaHttp($admin, $supplier, 25000);

    $paymentResponse = $this->actingAs($admin)->postJson(route('supplier-invoices.payments.store', $invoice), [
        'amount' => 8000,
        'payment_method' => 'bank_transfer',
    ]);

    $paymentId = (int) $paymentResponse->json('payment_transaction.id');

    $this->actingAs($admin)->post(route('supplier-invoices.payments.void', $paymentId), [
        'void_reason' => 'Erreur de saisie',
    ])->assertRedirect(route('supplier-invoices.show', $invoice));

    $invoice->refresh();

    expect((float) $invoice->paid_amount)->toBe(0.0)
        ->and((float) $invoice->remaining_amount)->toBe(25000.0);

    $this->actingAs($admin)->get(route('supplier-invoices.show', $invoice))
        ->assertInertia(fn ($page) => $page
            ->where('canRecordPayment', true)
            ->where('paymentSummary.total_paid', 0)
            ->has('paymentTransactions', 1)
            ->where('paymentTransactions.0.status', 'voided')
            ->where('paymentTransactions.0.void_reason', 'Erreur de saisie'));
});

test('user without create permission cannot open create form', function () {
    $user = supplierInvoiceHttpUser(['supplier-invoices.view']);

    $this->actingAs($user)->get(route('supplier-invoices.create'))->assertForbidden();
});

test('store creates supplier invoice in draft and rejects financial aggregates in request', function () {
    $user = supplierInvoiceHttpUser([
        'supplier-invoices.view',
        'supplier-invoices.create',
    ]);
    $supplier = supplierInvoiceHttpSupplier();

    $this->actingAs($user)->postJson(
        route('supplier-invoices.store'),
        validSupplierInvoiceStorePayload($supplier, [
            'paid_amount' => 1000,
            'remaining_amount' => 0,
            'payment_status' => 'paid',
            'status' => 'open',
        ]),
    )->assertUnprocessable()
        ->assertJsonValidationErrors(['paid_amount', 'remaining_amount', 'payment_status', 'status']);

    $this->actingAs($user)->postJson(
        route('supplier-invoices.store'),
        validSupplierInvoiceStorePayload($supplier),
    )->assertCreated();

    $invoice = SupplierInvoice::query()->firstOrFail();

    expect($invoice->status)->toBe(SupplierInvoice::STATUS_DRAFT)
        ->and((float) $invoice->paid_amount)->toBe(0.0)
        ->and((float) $invoice->remaining_amount)->toBe(75000.0)
        ->and($invoice->payment_status)->toBe(SupplierInvoice::PAYMENT_STATUS_PENDING);
});

test('open transitions draft invoice to open', function () {
    $user = supplierInvoiceHttpUser([
        'supplier-invoices.view',
        'supplier-invoices.create',
        'supplier-invoices.open',
    ]);
    $supplier = supplierInvoiceHttpSupplier();

    $this->actingAs($user)->postJson(
        route('supplier-invoices.store'),
        validSupplierInvoiceStorePayload($supplier),
    )->assertCreated();

    $invoice = SupplierInvoice::query()->firstOrFail();

    $this->actingAs($user)->postJson(route('supplier-invoices.open', $invoice))
        ->assertOk();

    expect($invoice->fresh()->status)->toBe(SupplierInvoice::STATUS_OPEN);
});

test('payment endpoint rejects draft and cancelled invoices', function () {
    $user = supplierInvoiceHttpUser([
        'supplier-invoices.view',
        'supplier-invoices.create',
        'supplier-invoices.open',
        'supplier-invoices.payments.record',
    ]);
    $supplier = supplierInvoiceHttpSupplier();

    $this->actingAs($user)->postJson(
        route('supplier-invoices.store'),
        validSupplierInvoiceStorePayload($supplier),
    );

    $draft = SupplierInvoice::query()->firstOrFail();

    $this->actingAs($user)->postJson(route('supplier-invoices.payments.store', $draft), [
        'amount' => 1000,
        'payment_method' => 'cash',
    ])->assertUnprocessable();

    $open = createOpenSupplierInvoiceViaHttp($user, $supplier);
    $open->update(['status' => SupplierInvoice::STATUS_CANCELLED]);

    $this->actingAs($user)->postJson(route('supplier-invoices.payments.store', $open), [
        'amount' => 1000,
        'payment_method' => 'cash',
    ])->assertUnprocessable();
});

test('payment endpoint rejects overpayment on open invoice', function () {
    $user = supplierInvoiceHttpUser([
        'supplier-invoices.view',
        'supplier-invoices.create',
        'supplier-invoices.open',
        'supplier-invoices.payments.record',
    ]);
    $supplier = supplierInvoiceHttpSupplier();
    $invoice = createOpenSupplierInvoiceViaHttp($user, $supplier, 50000);

    $this->actingAs($user)->postJson(route('supplier-invoices.payments.store', $invoice), [
        'amount' => 50001,
        'payment_method' => 'cash',
    ])->assertUnprocessable();
});

test('payment history endpoint only returns transactions for requested invoice', function () {
    $user = supplierInvoiceHttpUser([
        'supplier-invoices.view',
        'supplier-invoices.create',
        'supplier-invoices.open',
        'supplier-invoices.payments.view',
        'supplier-invoices.payments.record',
    ]);
    $supplier = supplierInvoiceHttpSupplier();

    $invoiceA = createOpenSupplierInvoiceViaHttp($user, $supplier, 40000);
    $invoiceB = createOpenSupplierInvoiceViaHttp($user, $supplier, 60000);

    $this->actingAs($user)->postJson(route('supplier-invoices.payments.store', $invoiceA), [
        'amount' => 10000,
        'payment_method' => 'cash',
    ])->assertCreated();

    $this->actingAs($user)->postJson(route('supplier-invoices.payments.store', $invoiceB), [
        'amount' => 20000,
        'payment_method' => 'wave',
    ])->assertCreated();

    $historyA = $this->actingAs($user)->getJson(route('supplier-invoices.payments.index', $invoiceA))
        ->assertOk()
        ->json('payment_transactions');

    expect($historyA)->toHaveCount(1)
        ->and((float) $historyA[0]['amount'])->toBe(10000.0)
        ->and(PaymentTransaction::query()->where('payable_type', PayableType::SupplierInvoice)->count())->toBe(2);
});

test('void requires reason and logs supplier payment void activity', function () {
    seedSupplierInvoiceHttpPermissions();
    $admin = User::factory()->create(['role' => 'admin']);
    $supplier = supplierInvoiceHttpSupplier();
    $invoice = createOpenSupplierInvoiceViaHttp($admin, $supplier, 30000);

    $paymentResponse = $this->actingAs($admin)->postJson(route('supplier-invoices.payments.store', $invoice), [
        'amount' => 12000,
        'payment_method' => 'bank_transfer',
    ]);

    $paymentResponse->assertCreated();
    $paymentId = (int) $paymentResponse->json('payment_transaction.id');

    $this->actingAs($admin)->postJson(route('supplier-invoices.payments.void', $paymentId), [
        'void_reason' => 'ab',
    ])->assertUnprocessable();

    $this->actingAs($admin)->postJson(route('supplier-invoices.payments.void', $paymentId), [
        'void_reason' => 'Erreur de saisie comptable',
    ])->assertOk();

    expect(PaymentTransaction::query()->find($paymentId)?->status->value)->toBe('voided');

    $recordLog = ActivityLog::query()
        ->where('action', ActivityLog::ACTION_PAYMENT)
        ->where('new_values->payment_transaction_id', $paymentId)
        ->count();

    $voidLog = ActivityLog::query()
        ->where('action', ActivityLog::ACTION_CANCEL)
        ->where('new_values->payment_transaction_id', $paymentId)
        ->first();

    expect($recordLog)->toBe(1)
        ->and($voidLog)->not->toBeNull()
        ->and($voidLog->new_values['void_reason'] ?? null)->toBe('Erreur de saisie comptable');
});

test('user without record permission cannot post supplier payment', function () {
    seedSupplierInvoiceHttpPermissions();
    $supplier = supplierInvoiceHttpSupplier();

    $recorder = supplierInvoiceHttpUser([
        'supplier-invoices.view',
        'supplier-invoices.create',
        'supplier-invoices.open',
        'supplier-invoices.payments.record',
    ]);

    $viewer = supplierInvoiceHttpUser([
        'supplier-invoices.view',
        'supplier-invoices.payments.view',
    ]);

    $invoice = createOpenSupplierInvoiceViaHttp($recorder, $supplier);

    $this->actingAs($viewer)->postJson(route('supplier-invoices.payments.store', $invoice), [
        'amount' => 1000,
        'payment_method' => 'cash',
    ])->assertForbidden();
});

test('sqlite supplier invoice http tests use in memory database', function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
});
