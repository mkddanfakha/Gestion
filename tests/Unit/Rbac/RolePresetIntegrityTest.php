<?php

use App\Auth\AuthorizationService;
use App\Auth\PermissionCatalog;
use App\Auth\RolePresets;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->authorization = app(AuthorizationService::class);
});

test('admin bypass sans pivot et sans legacy dans preset', function () {
    expect(RolePresets::usesBypass(User::ROLE_ADMIN))->toBeTrue()
        ->and(RolePresets::permissionNames(User::ROLE_ADMIN))->toBe([]);

    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

    expect($this->authorization->allows($admin, 'sales.delete'))->toBeTrue()
        ->and($this->authorization->forUser($admin))->toBe([]);
});

test('vendeur preset canonique commercial avec dashboard', function () {
    $names = RolePresets::permissionNames(User::ROLE_VENDEUR);

    expect($names)->toContain('dashboard.view')
        ->and($names)->toContain('sales.view')
        ->and($names)->toContain('sales.create')
        ->and($names)->toContain('products.view')
        ->and($names)->toContain('customers.view')
        ->and($names)->toContain('customers.create')
        ->and($names)->toContain('customers.update')
        ->and($names)->not->toContain('inventory.reopen')
        ->and($names)->not->toContain('inventory.apply')
        ->and($names)->not->toContain('inventory.validate')
        ->and($names)->not->toContain('inventory.close');

    foreach ($names as $name) {
        expect(str_ends_with($name, '.edit'))->toBeFalse();
        expect(PermissionCatalog::exists($name))->toBeTrue();
        expect(PermissionCatalog::isLegacy($name))->toBeFalse();
    }
});

test('gestionnaire preset sans sales et avec inventory.reopen', function () {
    $names = RolePresets::permissionNames(User::ROLE_GESTIONNAIRE);

    expect($names)->toContain('inventory.reopen')
        ->and($names)->toContain('inventory.view')
        ->and($names)->not->toContain('inventory.review');

    foreach ($names as $name) {
        expect(str_starts_with($name, 'sales.'))->toBeFalse();
        expect(str_ends_with($name, '.edit'))->toBeFalse();
    }

    $user = User::factory()->create(['role' => User::ROLE_GESTIONNAIRE]);
    $user->permissions()->sync(RolePresets::permissionIds(User::ROLE_GESTIONNAIRE));
    $user = $user->fresh();

    expect($this->authorization->allows($user, 'sales.view'))->toBeFalse()
        ->and($this->authorization->allows($user, 'sales.create'))->toBeFalse()
        ->and($this->authorization->allows($user, 'inventory.reopen'))->toBeTrue();
});

test('user preset vide pour permissions personnalisees', function () {
    expect(RolePresets::permissionNames(User::ROLE_USER))->toBe([])
        ->and(RolePresets::usesBypass(User::ROLE_USER))->toBeFalse();
});
