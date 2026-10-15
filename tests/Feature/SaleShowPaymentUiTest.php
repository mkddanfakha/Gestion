<?php

use App\Models\Permission;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/SalePaymentTest.php';

function saleShowUserWithPermissions(array $permissionNames): User
{
    (new PermissionSeeder())->run();

    $user = User::factory()->create(['role' => 'vendeur']);
    $ids = Permission::query()->whereIn('name', $permissionNames)->pluck('id');
    $user->permissions()->sync($ids);

    return $user;
}

test('sale show payment ui respects view permission for history', function () {
    $user = saleShowUserWithPermissions(['sales.view', 'sales.create', 'sales.payments.record']);
    $product = createSaleTestProduct();

    $this->actingAs($user)->post(route('sales.store'), saleStorePayload($product, [
        'down_payment_amount' => 20000,
        'payment_method' => 'cash',
    ]))->assertRedirect();
    $sale = Sale::query()->latest('id')->first();

    $this->actingAs($user)
        ->get(route('sales.show', $sale))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Sales/Show')
            ->where('can_view_payment_history', false)
            ->where('can_record_payment', true)
            ->has('payment_transactions', 0)
        );

    $user->permissions()->sync(
        Permission::query()->whereIn('name', [
            'sales.view',
            'sales.create',
            'sales.payments.view',
            'sales.payments.record',
        ])->pluck('id'),
    );

    $this->actingAs($user)
        ->get(route('sales.show', $sale))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can_view_payment_history', true)
            ->has('payment_transactions', 1)
        );
});

test('sale show hides collect flag when sale is fully paid', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = createSaleTestProduct();

    $this->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'down_payment_amount' => 100000,
        'payment_method' => 'cash',
    ]))->assertRedirect();

    $sale = Sale::query()->latest('id')->first();

    $this->actingAs($admin)
        ->get(route('sales.show', $sale))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can_record_payment', false)
            ->where('payment_summary.remaining_balance', 0)
        );
});

test('sale show exposes ledger blocked message for unbackfilled legacy sale', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $sale = Sale::query()->create([
        'sale_number' => 'FA'.uniqid(),
        'user_id' => $admin->id,
        'subtotal' => 100000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount'  => 30000,
        'remaining_amount' => 70000,
        'payment_status' => 'partial',
        'total_amount' => 100000,
        'status' => 'completed',
        'payment_method' => 'cash',
        'sale_date' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('sales.show', $sale))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can_record_payment', false)
            ->where('payment_ledger_blocked_message', fn ($message) => is_string($message) && str_contains($message, 'historique'))
        );
});

test('vendeur cannot view another users sale show payment flags', function () {
    $owner = saleShowUserWithPermissions(['sales.view', 'sales.create', 'sales.payments.view', 'sales.payments.record']);
    $other = saleShowUserWithPermissions(['sales.view', 'sales.payments.view', 'sales.payments.record']);
    $product = createSaleTestProduct();

    $this->actingAs($owner)->post(route('sales.store'), saleStorePayload($product))->assertRedirect();
    $sale = Sale::query()->latest('id')->first();

    $this->actingAs($other)->get(route('sales.show', $sale))->assertForbidden();
});
