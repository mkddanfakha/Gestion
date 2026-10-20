<?php

use App\Models\Customer;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Sale;
use App\Models\User;
use App\Services\CustomerCrmService;
use App\Services\SalePaymentTransactionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/SalePaymentTestHelpers.php';

function receivablesAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function receivablesCreateSaleForCustomer(
    User $user,
    Customer $customer,
    float $totalAmount,
    float $remainingAmount,
    string $paymentStatus = 'pending',
    string $status = 'completed',
): Sale {
    $downPayment = $totalAmount - $remainingAmount;

    return Sale::create([
        'sale_number' => Sale::generateSaleNumber(),
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'subtotal' => $totalAmount,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => $downPayment,
        'remaining_amount' => $remainingAmount,
        'payment_status' => $paymentStatus,
        'total_amount' => $totalAmount,
        'status' => $status,
        'payment_method' => $downPayment > 0 ? 'cash' : null,
        'sale_date' => now(),
    ]);
}

test('client sans dette absent de la liste receivables', function () {
    $admin = receivablesAdmin();
    $customer = Customer::factory()->create();
    receivablesCreateSaleForCustomer($admin, $customer, 100000, 0, 'paid');

    $this->actingAs($admin)->get(route('customers.receivables'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Customers/Receivables')
            ->where('portfolioTotal', 0)
            ->has('debtors.data', 0)
        );
});

test('client avec vente entierement impayee present avec montant correct', function () {
    $admin = receivablesAdmin();
    $customer = Customer::factory()->create(['name' => 'Debiteur Total']);
    receivablesCreateSaleForCustomer($admin, $customer, 100000, 100000, 'pending');

    $this->actingAs($admin)->get(route('customers.receivables'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('portfolioTotal', 100000)
            ->has('debtors.data', 1)
            ->where('debtors.data.0.id', $customer->id)
            ->where('debtors.data.0.remaining_balance', 100000)
            ->where('debtors.data.0.unpaid_sales_count', 1)
        );
});

test('plusieurs ventes impayees regroupees en une ligne client', function () {
    $admin = receivablesAdmin();
    $customer = Customer::factory()->create();
    receivablesCreateSaleForCustomer($admin, $customer, 100000, 100000, 'pending');
    receivablesCreateSaleForCustomer($admin, $customer, 50000, 50000, 'pending');

    $this->actingAs($admin)->get(route('customers.receivables'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('debtors.data', 1)
            ->where('debtors.data.0.remaining_balance', 150000)
            ->where('debtors.data.0.unpaid_sales_count', 2)
            ->where('portfolioTotal', 150000)
        );
});

test('total portefeuille independant de la pagination', function () {
    $admin = receivablesAdmin();
    $customerA = Customer::factory()->create(['name' => 'Alpha']);
    $customerB = Customer::factory()->create(['name' => 'Beta']);
    receivablesCreateSaleForCustomer($admin, $customerA, 100000, 100000, 'pending');
    receivablesCreateSaleForCustomer($admin, $customerB, 80000, 80000, 'pending');

    $service = app(CustomerCrmService::class);
    $page1 = $service->receivablesReport($admin, null, 1, 1);
    $page2 = $service->receivablesReport($admin, null, 2, 1);

    expect($page1['portfolio_total'])->toBe(180000.0)
        ->and($page2['portfolio_total'])->toBe(180000.0)
        ->and($page1['debtors']->total())->toBe(2)
        ->and($page1['debtors']->count())->toBe(1)
        ->and($page2['debtors']->count())->toBe(1);
});

test('receivables respecte le perimetre vendeur', function () {
    $customer = Customer::factory()->create();
    $vendeurA = User::factory()->create(['role' => 'vendeur']);
    $vendeurB = User::factory()->create(['role' => 'vendeur']);

    (new PermissionSeeder())->run();
    $permissionIds = Permission::query()->whereIn('name', ['customers.view', 'sales.view'])->pluck('id');
    $vendeurA->permissions()->sync($permissionIds);

    receivablesCreateSaleForCustomer($vendeurA, $customer, 100000, 100000, 'pending');
    receivablesCreateSaleForCustomer($vendeurB, $customer, 80000, 80000, 'pending');

    $this->actingAs($vendeurA)->get(route('customers.receivables'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('portfolioTotal', 100000)
            ->has('debtors.data', 1)
            ->where('debtors.data.0.remaining_balance', 100000)
            ->where('debtors.data.0.unpaid_sales_count', 1)
        );
});

test('ventes annulees exclues des creances', function () {
    $admin = receivablesAdmin();
    $customer = Customer::factory()->create();
    receivablesCreateSaleForCustomer($admin, $customer, 100000, 100000, 'pending', 'cancelled');

    $this->actingAs($admin)->get(route('customers.receivables'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('portfolioTotal', 0)
            ->has('debtors.data', 0)
        );
});

test('solde diminue apres paiement partiel', function () {
    (new PermissionSeeder())->run();
    $admin = receivablesAdmin();
    $customer = Customer::factory()->create();
    $product = createSaleTestProduct(100000);

    $this->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'customer_id' => $customer->id,
    ]))->assertRedirect();

    $sale = Sale::query()->latest('id')->firstOrFail();

    $this->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 35000,
        'payment_method' => 'cash',
    ])->assertRedirect();

    $sale->refresh();
    expect((float) $sale->remaining_amount)->toBe(65000.0)
        ->and((float) $sale->down_payment_amount)->toBe(35000.0);

    $this->actingAs($admin)->get(route('customers.receivables'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('portfolioTotal', 65000)
            ->where('debtors.data.0.remaining_balance', 65000)
        );
});

test('solde remonte apres void', function () {
    (new PermissionSeeder())->run();
    $admin = receivablesAdmin();
    $customer = Customer::factory()->create();
    $product = createSaleTestProduct(100000);

    $this->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'customer_id' => $customer->id,
    ]))->assertRedirect();

    $sale = Sale::query()->latest('id')->firstOrFail();

    $this->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 100000,
        'payment_method' => 'wave',
    ])->assertRedirect();

    $payment = PaymentTransaction::query()->latest('id')->firstOrFail();

    $this->actingAs($admin)->get(route('customers.receivables'))
        ->assertInertia(fn ($page) => $page->where('portfolioTotal', 0));

    $this->actingAs($admin)->post(route('sales.payments.void', $payment), [
        'void_reason' => 'Test receivables void',
    ])->assertRedirect();

    $this->actingAs($admin)->get(route('customers.receivables'))
        ->assertInertia(fn ($page) => $page
            ->where('portfolioTotal', 100000)
            ->where('debtors.data.0.remaining_balance', 100000)
        );
});

test('vente legacy avec acompte sans transaction posted exclue', function () {
    $admin = receivablesAdmin();
    $customer = Customer::factory()->create();
    receivablesCreateSaleForCustomer($admin, $customer, 100000, 50000, 'partial');

    $this->actingAs($admin)->get(route('customers.receivables'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('portfolioTotal', 0)
            ->has('debtors.data', 0)
            ->where('unreliableExcludedSalesCount', 1)
        );
});

test('vente sans acompte ni transaction reste eligible si impayee', function () {
    $admin = receivablesAdmin();
    $customer = Customer::factory()->create();
    receivablesCreateSaleForCustomer($admin, $customer, 100000, 100000, 'pending');

    $report = app(CustomerCrmService::class)->receivablesReport($admin, null, 1, 15);

    expect($report['portfolio_total'])->toBe(100000.0)
        ->and($report['unreliable_excluded_sales_count'])->toBe(0)
        ->and($report['debtors']->total())->toBe(1)
        ->and($report['debtors']->items())->not->toBeEmpty();
});

test('vente avec desalignement acompte ledger exclue', function () {
    $admin = receivablesAdmin();
    $customer = Customer::factory()->create();
    $product = createSaleTestProduct(100000);

    $this->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'customer_id' => $customer->id,
    ]))->assertRedirect();

    $sale = Sale::query()->latest('id')->firstOrFail();

    $this->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 40000,
        'payment_method' => 'cash',
    ])->assertRedirect();

    $sale->update(['down_payment_amount' => 10000]);

    $this->actingAs($admin)->get(route('customers.receivables'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('portfolioTotal', 0)
            ->has('debtors.data', 0)
            ->where('unreliableExcludedSalesCount', 1)
        );
});

test('acces receivables interdit sans customers.view', function () {
    $user = User::factory()->create(['role' => 'vendeur']);

    $this->actingAs($user)->get(route('customers.receivables'))
        ->assertForbidden();
});

test('vendeur ne recoit pas le compteur ventes exclues admin', function () {
    (new PermissionSeeder())->run();
    $vendeur = User::factory()->create(['role' => 'vendeur']);
    $vendeur->permissions()->sync(
        Permission::query()->whereIn('name', ['customers.view', 'sales.view'])->pluck('id'),
    );

    $customer = Customer::factory()->create();
    receivablesCreateSaleForCustomer($vendeur, $customer, 100000, 50000, 'partial');

    $this->actingAs($vendeur)->get(route('customers.receivables'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('unreliableExcludedSalesCount', null));
});

test('vente entierement payee exclue malgre epsilon arrondi', function () {
    $admin = receivablesAdmin();
    $customer = Customer::factory()->create();
    $epsilon = SalePaymentTransactionService::MONEY_EPSILON;

    receivablesCreateSaleForCustomer($admin, $customer, 100000, $epsilon / 2, 'partial');

    $report = app(CustomerCrmService::class)->receivablesReport($admin, null, 1, 15);

    expect($report['portfolio_total'])->toBe(0.0)
        ->and($report['debtors']->total())->toBe(0);
});

test('receivables recherche filtre les debiteurs et le total portefeuille', function () {
    $admin = receivablesAdmin();
    $customerAlpha = Customer::factory()->create(['name' => 'ReceivableSearch Alpha']);
    $customerBeta = Customer::factory()->create(['name' => 'ReceivableSearch Beta']);
    receivablesCreateSaleForCustomer($admin, $customerAlpha, 120000, 120000, 'pending');
    receivablesCreateSaleForCustomer($admin, $customerBeta, 45000, 45000, 'pending');

    $this->actingAs($admin)->get(route('customers.receivables', ['search' => 'Alpha']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Customers/Receivables')
            ->has('debtors.data', 1)
            ->where('debtors.data.0.id', $customerAlpha->id)
            ->where('debtors.data.0.remaining_balance', 120000)
            ->where('portfolioTotal', 120000)
        );
});

test('receivables solde aligne avec crm summary pour ventes ledger fiables', function () {
    (new PermissionSeeder())->run();
    $admin = receivablesAdmin();
    $customer = Customer::factory()->create();
    $productA = createSaleTestProduct(100000);
    $productB = createSaleTestProduct(50000);

    $this->actingAs($admin)->post(route('sales.store'), saleStorePayload($productA, [
        'customer_id' => $customer->id,
    ]))->assertRedirect();

    $this->actingAs($admin)->post(route('sales.store'), saleStorePayload($productB, [
        'customer_id' => $customer->id,
    ]))->assertRedirect();

    $firstSale = Sale::query()->where('customer_id', $customer->id)->orderBy('id')->firstOrFail();

    $this->actingAs($admin)->post(route('sales.payments.store', $firstSale), [
        'amount' => 25000,
        'payment_method' => 'cash',
    ])->assertRedirect();

    $service = app(CustomerCrmService::class);
    $summary = $service->buildSummary($customer, $admin);
    $report = $service->receivablesReport($admin, null, 1, 15);

    $debtorRow = collect($report['debtors']->items())->firstWhere('id', $customer->id);

    expect($debtorRow)->not->toBeNull()
        ->and((float) $debtorRow['remaining_balance'])->toBe((float) $summary['remaining_balance'])
        ->and($report['portfolio_total'])->toBe((float) $summary['remaining_balance'])
        ->and($summary['remaining_balance'])->toBe(125000.0);
});
