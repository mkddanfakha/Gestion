<?php

use App\Models\Permission;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

function createVendeurWithSalesView(): User
{
    (new PermissionSeeder())->run();

    $vendeur = User::factory()->create(['role' => 'vendeur']);
    $permission = Permission::where('name', 'sales.view')->firstOrFail();
    $vendeur->permissions()->attach($permission);

    return $vendeur;
}

function createSaleForUser(User $user): Sale
{
    return Sale::create([
        'sale_number' => Sale::generateSaleNumber(),
        'user_id' => $user->id,
        'subtotal' => 1000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 1000,
        'remaining_amount' => 0,
        'payment_status' => 'paid',
        'total_amount' => 1000,
        'status' => 'completed',
        'payment_method' => 'cash',
    ]);
}

test('vendeur only sees their own sales in index', function () {
    $vendeur = createVendeurWithSalesView();
    $otherVendeur = User::factory()->create(['role' => 'vendeur']);

    $ownSale = createSaleForUser($vendeur);
    createSaleForUser($otherVendeur);

    $response = $this->actingAs($vendeur)
        ->get(route('sales.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Sales/Index')
        ->has('sales.data', 1)
        ->where('sales.data.0.id', $ownSale->id)
    );
});

test('vendeur cannot view another users sale', function () {
    $vendeur = createVendeurWithSalesView();
    $otherVendeur = User::factory()->create(['role' => 'vendeur']);
    $otherSale = createSaleForUser($otherVendeur);

    $this->actingAs($vendeur)
        ->get(route('sales.show', $otherSale))
        ->assertForbidden();
});

test('admin can view all sales', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $vendeur = User::factory()->create(['role' => 'vendeur']);

    createSaleForUser($vendeur);
    createSaleForUser($admin);

    $this->actingAs($admin)
        ->get(route('sales.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Sales/Index')
            ->has('sales.data', 2)
        );
});
