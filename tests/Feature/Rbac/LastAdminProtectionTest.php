<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('dernier admin ne peut pas etre rétrogradé en vendeur', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => User::ROLE_VENDEUR,
            'is_active' => true,
        ])
        ->assertForbidden();

    expect($admin->fresh()->role)->toBe(User::ROLE_ADMIN);
});

test('dernier admin ne peut pas etre supprimé', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    // Seul admin actif : tentative d'auto-suppression → 403 métier (avant le garde-fou « propre compte »).
    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $admin))
        ->assertForbidden();

    expect(User::query()->whereKey($admin->id)->exists())->toBeTrue()
        ->and($admin->fresh()->role)->toBe(User::ROLE_ADMIN)
        ->and($admin->fresh()->is_active)->toBeTrue();
});

test('avec deux admins le changement de rôle est autorisé', function () {
    $adminA = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $adminB = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($adminB)
        ->put(route('admin.users.update', $adminA), [
            'name' => $adminA->name,
            'email' => $adminA->email,
            'role' => User::ROLE_VENDEUR,
            'is_active' => true,
        ])
        ->assertRedirect(route('admin.users.index'));

    expect($adminA->fresh()->role)->toBe(User::ROLE_VENDEUR)
        ->and($adminB->fresh()->role)->toBe(User::ROLE_ADMIN);
});

test('avec deux admins la suppression est autorisée', function () {
    $adminA = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $adminB = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($adminB)
        ->delete(route('admin.users.destroy', $adminA))
        ->assertRedirect(route('admin.users.index'));

    expect(User::query()->whereKey($adminA->id)->exists())->toBeFalse()
        ->and($adminB->fresh()->role)->toBe(User::ROLE_ADMIN);
});

test('dernier admin ne peut pas rétrograder son propre rôle', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => User::ROLE_GESTIONNAIRE,
            'is_active' => true,
        ])
        ->assertForbidden();

    expect($admin->fresh()->role)->toBe(User::ROLE_ADMIN);
});

test('dernier admin ne peut pas se désactiver', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => User::ROLE_ADMIN,
            'is_active' => false,
        ])
        ->assertForbidden();

    expect($admin->fresh()->is_active)->toBeTrue();
});

test('utilisateur non admin peut etre modifié sans bloquer la protection', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $vendeur = User::factory()->create([
        'role' => User::ROLE_VENDEUR,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->put(route('admin.users.update', $vendeur), [
            'name' => 'Nouveau nom',
            'email' => $vendeur->email,
            'role' => User::ROLE_GESTIONNAIRE,
            'is_active' => true,
        ])
        ->assertRedirect(route('admin.users.index'));

    expect($vendeur->fresh()->role)->toBe(User::ROLE_GESTIONNAIRE)
        ->and($admin->fresh()->role)->toBe(User::ROLE_ADMIN);
});
