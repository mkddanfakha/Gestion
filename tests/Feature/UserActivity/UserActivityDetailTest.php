<?php

use App\Auth\RolePresets;
use App\Models\Customer;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

require_once __DIR__.'/../SalePaymentTestHelpers.php';

function seedUserActivitiesDetailFixtures(): void
{
    (new PermissionSeeder())->run();
}

function createSaleForDetail(User $user): Sale
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
        'sale_date' => now(),
    ]);
}

function createQuoteForDetail(User $user): Quote
{
    return Quote::create([
        'quote_number' => Quote::generateQuoteNumber(),
        'user_id' => $user->id,
        'subtotal' => 2000,
        'tax_amount' => 180,
        'discount_amount' => 0,
        'total_amount' => 2180,
        'status' => 'draft',
        'quote_date' => now(),
        'notes' => 'Note test devis',
    ]);
}

test('detail quote autorise avec quotes.view et user-activities.view', function () {
    seedUserActivitiesDetailFixtures();
    $user = User::factory()->create(['role' => 'gestionnaire']);
    $user->permissions()->sync(RolePresets::permissionIds(User::ROLE_GESTIONNAIRE));

    $quote = createQuoteForDetail($user);

    $this->actingAs($user->fresh())
        ->getJson(route('user-activities.detail', ['type' => 'quote', 'id' => $quote->id]))
        ->assertOk()
        ->assertJsonPath('type', 'quote')
        ->assertJsonPath('type_label', 'Devis')
        ->assertJsonFragment(['label' => 'Référence', 'value' => $quote->quote_number])
        ->assertJsonFragment(['label' => 'Montant TTC', 'value' => 2180]);
});

test('detail sale refuse gestionnaire sans sales.view', function () {
    seedUserActivitiesDetailFixtures();
    $gestionnaire = User::factory()->create(['role' => User::ROLE_GESTIONNAIRE]);
    $gestionnaire->permissions()->sync(RolePresets::permissionIds(User::ROLE_GESTIONNAIRE));

    $vendeur = User::factory()->create(['role' => 'vendeur']);
    $sale = createSaleForDetail($vendeur);

    // Accès journal OK
    $this->actingAs($gestionnaire->fresh())
        ->get(route('user-activities.index'))
        ->assertOk();

    // Détail vente refusé (pas de contournement RBAC)
    $this->actingAs($gestionnaire->fresh())
        ->getJson(route('user-activities.detail', ['type' => 'sale', 'id' => $sale->id]))
        ->assertForbidden();
});

test('detail document inexistant retourne 404', function () {
    seedUserActivitiesDetailFixtures();
    $user = User::factory()->create(['role' => 'admin']);

    $this->actingAs($user)
        ->getJson(route('user-activities.detail', ['type' => 'quote', 'id' => 999999]))
        ->assertNotFound();
});

test('detail type inconnu retourne 404', function () {
    seedUserActivitiesDetailFixtures();
    $user = User::factory()->create(['role' => 'admin']);

    $this->actingAs($user)
        ->getJson('/user-activities/unknown_type/1')
        ->assertNotFound();
});

test('detail refuse sans user-activities.view', function () {
    seedUserActivitiesDetailFixtures();
    $vendeur = User::factory()->create(['role' => 'vendeur']);
    $vendeur->permissions()->sync(RolePresets::permissionIds(User::ROLE_VENDEUR));
    $sale = createSaleForDetail($vendeur);

    $this->actingAs($vendeur->fresh())
        ->getJson(route('user-activities.detail', ['type' => 'sale', 'id' => $sale->id]))
        ->assertForbidden();
});

test('admin peut recuperer le detail d une vente', function () {
    seedUserActivitiesDetailFixtures();
    $admin = User::factory()->create(['role' => 'admin']);
    $sale = createSaleForDetail($admin);

    $this->actingAs($admin)
        ->getJson(route('user-activities.detail', ['type' => 'sale', 'id' => $sale->id]))
        ->assertOk()
        ->assertJsonPath('type', 'sale')
        ->assertJsonFragment(['label' => 'Référence', 'value' => $sale->sale_number]);
});

test('detail sale_payment autorise admin avec acces ventes', function () {
    seedUserActivitiesDetailFixtures();
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = Customer::factory()->create();
    $product = createSaleTestProduct(100000);

    test()->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'customer_id' => $customer->id,
    ]))->assertRedirect();

    $sale = Sale::query()->latest('id')->firstOrFail();

    test()->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 40000,
        'payment_method' => 'wave',
    ])->assertRedirect();

    $payment = PaymentTransaction::query()->latest('id')->firstOrFail();

    test()->actingAs($admin)
        ->getJson(route('user-activities.detail', ['type' => 'sale_payment', 'id' => $payment->id]))
        ->assertOk()
        ->assertJsonPath('type', 'sale_payment')
        ->assertJsonPath('type_label', 'Règlements clients');
});

test('detail sale_payment_void autorise admin pour reglement annule', function () {
    seedUserActivitiesDetailFixtures();
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = Customer::factory()->create();
    $product = createSaleTestProduct(100000);

    test()->actingAs($admin)->post(route('sales.store'), saleStorePayload($product, [
        'customer_id' => $customer->id,
    ]))->assertRedirect();

    $sale = Sale::query()->latest('id')->firstOrFail();

    test()->actingAs($admin)->post(route('sales.payments.store', $sale), [
        'amount' => 30000,
        'payment_method' => 'cash',
    ])->assertRedirect();

    $payment = PaymentTransaction::query()->latest('id')->firstOrFail();

    test()->actingAs($admin)->post(route('sales.payments.void', $payment), [
        'void_reason' => 'Test detail activite annulation',
    ])->assertRedirect();

    test()->actingAs($admin)
        ->getJson(route('user-activities.detail', ['type' => 'sale_payment_void', 'id' => $payment->id]))
        ->assertOk()
        ->assertJsonPath('type', 'sale_payment_void')
        ->assertJsonPath('type_label', 'Annulations de règlement');
});
