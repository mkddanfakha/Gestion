<?php

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\User;
use App\Services\CustomerCrmService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('customer store redirects to show page on successful creation', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->post(route('customers.store'), [
        'name' => 'Nouveau Client CRM',
        'is_active' => true,
    ]);

    $customer = Customer::query()->where('name', 'Nouveau Client CRM')->first();

    $response->assertRedirect(route('customers.show', $customer));
    expect($customer)->not->toBeNull();
    $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Nouveau Client CRM']);
});

test('invalid customer store does not redirect to show page', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('customers.store'), [
        'name' => '',
        'is_active' => true,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
    expect(Customer::query()->count())->toBe(0);
});

function createCrmProduct(float $price = 100000): Product
{
    $category = Category::create([
        'name' => 'CRM Test',
        'slug' => 'crm-test-' . uniqid(),
    ]);

    return Product::create([
        'name' => 'Produit CRM',
        'sku' => 'CRM-' . uniqid(),
        'price' => $price,
        'stock_quantity' => 100,
        'min_stock_level' => 0,
        'unit' => 'pièce',
        'category_id' => $category->id,
        'is_active' => true,
    ]);
}

test('customer crm summary aggregates sales and quotes correctly', function () {
    $service = app(CustomerCrmService::class);
    $customer = Customer::factory()->create([
        'notes' => 'Préfère WhatsApp',
        'birthday' => '1990-09-15',
    ]);

    Sale::create([
        'sale_number' => 'FA2601001',
        'customer_id' => $customer->id,
        'user_id' => User::factory()->create()->id,
        'subtotal' => 100000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 100000,
        'remaining_amount' => 0,
        'payment_status' => 'paid',
        'total_amount' => 100000,
        'status' => 'completed',
        'payment_method' => 'cash',
    ]);

    Sale::create([
        'sale_number' => 'FA2601002',
        'customer_id' => $customer->id,
        'user_id' => User::factory()->create()->id,
        'subtotal' => 200000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 50000,
        'remaining_amount' => 150000,
        'payment_status' => 'partial',
        'total_amount' => 200000,
        'status' => 'completed',
        'payment_method' => 'wave',
        'due_date' => now()->addDays(10)->toDateString(),
    ]);

    Sale::create([
        'sale_number' => 'FA2601003',
        'customer_id' => $customer->id,
        'user_id' => User::factory()->create()->id,
        'subtotal' => 50000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 0,
        'remaining_amount' => 50000,
        'payment_status' => 'pending',
        'total_amount' => 50000,
        'status' => 'cancelled',
        'payment_method' => null,
    ]);

    Quote::create([
        'quote_number' => 'DE2601001',
        'customer_id' => $customer->id,
        'user_id' => User::factory()->create()->id,
        'subtotal' => 75000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 75000,
        'status' => 'sent',
    ]);

    Quote::create([
        'quote_number' => 'DE2601002',
        'customer_id' => $customer->id,
        'user_id' => User::factory()->create()->id,
        'subtotal' => 30000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 30000,
        'status' => 'accepted',
    ]);

    $summary = $service->buildSummary($customer, null);

    expect($summary['orders_count'])->toBe(2)
        ->and($summary['total_purchased'])->toBe(300000.0)
        ->and($summary['total_paid'])->toBe(150000.0)
        ->and($summary['remaining_balance'])->toBe(150000.0)
        ->and($summary['unpaid_invoices_count'])->toBe(1)
        ->and($summary['pending_quotes_count'])->toBe(1)
        ->and($summary['last_visit_at'])->toBeNull();
});

test('customer with no sales returns zeroed summary', function () {
    $service = app(CustomerCrmService::class);
    $customer = Customer::factory()->create();

    $summary = $service->buildSummary($customer, null);

    expect($summary['orders_count'])->toBe(0)
        ->and($summary['total_purchased'])->toBe(0.0)
        ->and($summary['total_paid'])->toBe(0.0)
        ->and($summary['remaining_balance'])->toBe(0.0)
        ->and($summary['last_sale_at'])->toBeNull();
});

test('customer show page exposes crm data', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = Customer::factory()->create(['notes' => 'Client VIP']);

    Sale::create([
        'sale_number' => 'FA2601004',
        'customer_id' => $customer->id,
        'user_id' => $admin->id,
        'subtotal' => 125000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 125000,
        'remaining_amount' => 0,
        'payment_status' => 'paid',
        'total_amount' => 125000,
        'status' => 'completed',
        'payment_method' => 'cash',
    ]);

    $response = $this->actingAs($admin)->get(route('customers.show', $customer));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Customers/Show')
            ->has('crm')
            ->where('crm.orders_count', 1)
            ->where('customer.notes', 'Client VIP')
            ->has('salesHistory.data', 1));
});

test('customer can be updated with birthday and notes', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = Customer::factory()->create();

    $response = $this->actingAs($admin)->put(route('customers.update', $customer), [
        'name' => $customer->name,
        'email' => $customer->email,
        'phone' => $customer->phone,
        'birthday' => '1985-03-20',
        'notes' => 'Préfère être contacté sur WhatsApp.',
        'is_active' => true,
    ]);

    $response->assertRedirect(route('customers.show', $customer));

    $customer->refresh();

    expect($customer->birthday?->toDateString())->toBe('1985-03-20')
        ->and($customer->notes)->toBe('Préfère être contacté sur WhatsApp.');
});

test('sales create accepts customer_id query param', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = Customer::factory()->create();
    createCrmProduct();

    $response = $this->actingAs($admin)->get(route('sales.create', ['customer_id' => $customer->id]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Sales/Create')
            ->where('initialCustomerId', $customer->id));
});

test('customer show preserves active tab when paginating activity', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = Customer::factory()->create();

    for ($i = 0; $i < 11; $i++) {
        \App\Models\ActivityLog::create([
            'user_id' => $admin->id,
            'action' => 'update',
            'module' => 'customers',
            'description' => "Activité test {$i}",
            'subject_type' => Customer::class,
            'subject_id' => $customer->id,
        ]);
    }

    $response = $this->actingAs($admin)->get(route('customers.show', [
        'customer' => $customer,
        'tab' => 'activity',
        'activity_page' => 2,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Customers/Show')
            ->where('activeTab', 'activity')
            ->where('activityHistory.current_page', 2));
});

test('customer show infers active tab from pagination query when tab is missing', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = Customer::factory()->create();

    for ($i = 0; $i < 11; $i++) {
        \App\Models\ActivityLog::create([
            'user_id' => $admin->id,
            'action' => 'update',
            'module' => 'customers',
            'description' => "Activité fallback {$i}",
            'subject_type' => Customer::class,
            'subject_id' => $customer->id,
        ]);
    }

    $response = $this->actingAs($admin)->get(route('customers.show', [
        'customer' => $customer,
        'activity_page' => 2,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activeTab', 'activity')
            ->where('activityHistory.current_page', 2));
});

test('customer show tab change defaults pagination to first page', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = Customer::factory()->create();

    for ($i = 0; $i < 11; $i++) {
        Sale::create([
            'sale_number' => 'FA2602' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'user_id' => $admin->id,
            'subtotal' => 10000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'down_payment_amount' => 10000,
            'remaining_amount' => 0,
            'payment_status' => 'paid',
            'total_amount' => 10000,
            'status' => 'completed',
            'payment_method' => 'cash',
        ]);
    }

    $response = $this->actingAs($admin)->get(route('customers.show', [
        'customer' => $customer,
        'tab' => 'sales',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activeTab', 'sales')
            ->where('salesHistory.current_page', 1));
});
