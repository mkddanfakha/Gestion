<?php

use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk();
});

test('non admin peut mettre a jour son email sans changer son nom', function () {
    $user = User::factory()->create([
        'role' => 'vendeur',
        'name' => 'Nom Original',
        'email' => 'vendeur@example.com',
    ]);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'email' => 'nouveau@example.com',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Nom Original')
        ->and($user->email)->toBe('nouveau@example.com')
        ->and($user->email_verified_at)->toBeNull();
});

test('administrateur peut modifier son nom via le profil', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'name' => 'Admin Original',
        'email' => 'admin@example.com',
    ]);

    $this->actingAs($admin)
        ->patch(route('profile.update'), [
            'name' => 'Admin Renomme',
            'email' => 'admin@example.com',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($admin->refresh()->name)->toBe('Admin Renomme');
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create([
        'role' => 'vendeur',
        'name' => 'Vendeur Stable',
    ]);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'email' => $user->email,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull()
        ->and($user->name)->toBe('Vendeur Stable');
});

test('gestionnaire ne peut pas modifier son nom via le profil', function () {
    $gestionnaire = User::factory()->create([
        'role' => 'gestionnaire',
        'name' => 'Gestionnaire Original',
        'email' => 'gestionnaire@example.com',
    ]);

    $this->actingAs($gestionnaire)
        ->patch(route('profile.update'), [
            'name' => 'Nom Pirate',
            'email' => 'gestionnaire@example.com',
        ])
        ->assertSessionHasErrors('name');

    expect($gestionnaire->refresh()->name)->toBe('Gestionnaire Original');
});

test('vendeur ne peut pas modifier son nom via le profil', function () {
    $vendeur = User::factory()->create([
        'role' => 'vendeur',
        'name' => 'Vendeur Original',
        'email' => 'vendeur2@example.com',
    ]);

    $this->actingAs($vendeur)
        ->patch(route('profile.update'), [
            'name' => 'Nom Pirate',
            'email' => 'vendeur2@example.com',
        ])
        ->assertSessionHasErrors('name');

    expect($vendeur->refresh()->name)->toBe('Vendeur Original');
});

test('non admin ne peut pas contourner le frontend en envoyant name dans la requete', function () {
    $user = User::factory()->create([
        'role' => 'user',
        'name' => 'User Original',
        'email' => 'user@example.com',
    ]);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Contournement DevTools',
            'email' => 'autre@example.com',
        ])
        ->assertSessionHasErrors('name');

    $user->refresh();

    expect($user->name)->toBe('User Original')
        ->and($user->email)->toBe('user@example.com');
});

test('administrateur peut modifier le nom d un autre utilisateur via admin users', function () {
    (new PermissionSeeder())->run();

    $admin = User::factory()->create(['role' => 'admin']);
    $target = User::factory()->create([
        'role' => 'vendeur',
        'name' => 'Jean Dupont',
        'email' => 'jean@example.com',
    ]);

    $this->actingAs($admin)
        ->put(route('admin.users.update', $target), [
            'name' => 'Jean Diop',
            'email' => 'jean@example.com',
            'role' => 'vendeur',
            'is_active' => true,
        ])
        ->assertRedirect();

    expect($target->refresh()->name)->toBe('Jean Diop');
});

test('audit utilisateur reste coherent apres renommage admin', function () {
    (new PermissionSeeder())->run();

    $admin = User::factory()->create(['role' => 'admin', 'name' => 'Admin Acteur']);
    $target = User::factory()->create([
        'role' => 'vendeur',
        'name' => 'Ancien Nom',
        'email' => 'audit-target@example.com',
    ]);

    $this->actingAs($admin)
        ->put(route('admin.users.update', $target), [
            'name' => 'Nouveau Nom',
            'email' => 'audit-target@example.com',
            'role' => 'vendeur',
            'is_active' => true,
            'permissions' => [],
        ])
        ->assertRedirect();

    $target->refresh();
    expect($target->name)->toBe('Nouveau Nom');

    $log = ActivityLog::query()
        ->where('module', 'Utilisateur')
        ->where('action', ActivityLog::ACTION_UPDATE)
        ->where('subject_type', $target->getMorphClass())
        ->where('subject_id', $target->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->subject_id)->toBe($target->id);

    // L'historique reste rattaché au même user_id (pas de réécriture d'identité technique).
    expect($target->id)->toBe($log->subject_id);
});

test('user cannot delete their own account from profile', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ])
        ->assertSessionHas('error')
        ->assertRedirect(route('profile.edit'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh())->not->toBeNull();
});

test('administrator cannot delete their own account from profile', function () {
    $user = User::factory()->create([
        'role' => 'admin',
    ]);

    $this->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ])
        ->assertSessionHas('error')
        ->assertRedirect(route('profile.edit'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh())->not->toBeNull();
});
