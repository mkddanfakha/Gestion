<?php

use App\Auth\RolePresets;
use App\Models\Permission;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

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
