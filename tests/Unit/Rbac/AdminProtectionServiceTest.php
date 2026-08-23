<?php

use App\Auth\AdminProtectionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->protection = app(AdminProtectionService::class);
});

test('zero admin actif est un état détectable', function () {
    User::factory()->create(['role' => User::ROLE_VENDEUR, 'is_active' => true]);
    User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => false]);

    expect($this->protection->countActiveAdmins())->toBe(0);
    // État anormal : impossible via les routes protégées ; peut exister si seed/DB manuelle.
});

test('countActiveAdmins ignore les admins inactifs', function () {
    User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => false]);
    User::factory()->create(['role' => User::ROLE_VENDEUR, 'is_active' => true]);

    expect($this->protection->countActiveAdmins())->toBe(1);
});

test('isLastAdmin true quand un seul admin actif', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

    expect($this->protection->isLastAdmin($admin))->toBeTrue();
});

test('isLastAdmin false quand plusieurs admins actifs', function () {
    $adminA = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

    expect($this->protection->isLastAdmin($adminA))->toBeFalse();
});

test('isLastAdmin false pour non admin', function () {
    $user = User::factory()->create(['role' => User::ROLE_VENDEUR, 'is_active' => true]);

    expect($this->protection->isLastAdmin($user))->toBeFalse();
});

test('dernier admin ne peut pas etre rétrogradé en vendeur', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

    expect($this->protection->canChangeRole($admin, User::ROLE_VENDEUR))->toBeFalse();

    expect(fn () => $this->protection->assertCanChangeRole($admin, User::ROLE_VENDEUR))
        ->toThrow(HttpException::class);
});

test('dernier admin ne peut pas etre rétrogradé en gestionnaire', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

    expect($this->protection->canChangeRole($admin, User::ROLE_GESTIONNAIRE))->toBeFalse();
});

test('dernier admin ne peut pas etre supprimé', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

    expect($this->protection->canRemoveAdmin($admin))->toBeFalse();

    expect(fn () => $this->protection->assertCanRemoveAdmin($admin))
        ->toThrow(HttpException::class);
});

test('dernier admin ne peut pas etre désactivé', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

    expect($this->protection->canDeactivate($admin, false))->toBeFalse();
});

test('admin non dernier peut changer de rôle', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

    expect($this->protection->canChangeRole($admin, User::ROLE_VENDEUR))->toBeTrue();
});

test('admin non dernier peut etre supprimé', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

    expect($this->protection->canRemoveAdmin($admin))->toBeTrue();
});

test('utilisateur non admin ne declenche pas la protection', function () {
    $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
    User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

    expect($this->protection->canChangeRole($user, User::ROLE_VENDEUR))->toBeTrue()
        ->and($this->protection->canRemoveAdmin($user))->toBeTrue()
        ->and($this->protection->canDeactivate($user, false))->toBeTrue();
});

test('admin conserve admin autorise la modification', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

    expect($this->protection->canChangeRole($admin, User::ROLE_ADMIN))->toBeTrue()
        ->and($this->protection->canDeactivate($admin, true))->toBeTrue();
});
