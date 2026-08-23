<?php

use App\Auth\PermissionCatalog;
use App\Auth\RolePresets;
use App\Enums\PermissionName;
use App\Models\User;

test('roles connus admin gestionnaire vendeur user', function () {
    expect(RolePresets::roles())->toBe([
        User::ROLE_ADMIN,
        User::ROLE_GESTIONNAIRE,
        User::ROLE_VENDEUR,
        User::ROLE_USER,
    ]);

    foreach (RolePresets::roles() as $role) {
        expect(RolePresets::has($role))->toBeTrue();
    }
});

test('role inconnu leve une exception explicite', function () {
    expect(fn () => RolePresets::for('superviseur'))->toThrow(InvalidArgumentException::class);
    expect(RolePresets::has('superviseur'))->toBeFalse();
});

test('aucun doublon dans les presets', function () {
    foreach (RolePresets::roles() as $role) {
        $names = RolePresets::permissionNames($role);

        expect($names)->toHaveCount(count(array_unique($names)));
    }
});

test('toutes les permissions des presets existent dans PermissionCatalog', function () {
    expect(RolePresets::validate())->toBe([]);
});

test('gestionnaire contient inventory view create et products view', function () {
    $names = RolePresets::permissionNames(User::ROLE_GESTIONNAIRE);

    expect($names)->toContain('inventory.view');
    expect($names)->toContain('inventory.create');
    expect($names)->toContain('products.view');
});

test('gestionnaire contient suppliers et purchase orders et delivery notes', function () {
    $names = RolePresets::permissionNames(User::ROLE_GESTIONNAIRE);

    expect($names)->toContain('suppliers.view');
    expect($names)->toContain('purchase-orders.view');
    expect($names)->toContain('delivery-notes.view');
});

test('gestionnaire cannot sell', function () {
    foreach (RolePresets::for(User::ROLE_GESTIONNAIRE) as $permission) {
        expect(PermissionCatalog::module($permission))->not->toBe('sales');
    }

    $names = RolePresets::permissionNames(User::ROLE_GESTIONNAIRE);

    expect($names)->not->toContain('sales.view');
    expect($names)->not->toContain('sales.create');
    expect($names)->not->toContain('sales.update');
    expect($names)->not->toContain('sales.delete');
});

test('vendeur has dashboard view', function () {
    expect(RolePresets::permissionNames(User::ROLE_VENDEUR))->toContain('dashboard.view');
});

test('vendeur contient sales view create et products view', function () {
    $names = RolePresets::permissionNames(User::ROLE_VENDEUR);

    expect($names)->toContain('sales.view');
    expect($names)->toContain('sales.create');
    expect($names)->toContain('products.view');
});

test('vendeur contient customers view', function () {
    expect(RolePresets::permissionNames(User::ROLE_VENDEUR))->toContain('customers.view');
});

test('vendeur ne contient pas les permissions administratives', function () {
    $names = RolePresets::permissionNames(User::ROLE_VENDEUR);

    foreach ($names as $name) {
        expect(str_starts_with($name, 'backups.'))->toBeFalse();
    }

    expect($names)->not->toContain('inventory.apply');
    expect($names)->not->toContain('inventory.validate');
    expect($names)->not->toContain('inventory.close');
});

test('admin utilise bypass et preset vide', function () {
    expect(RolePresets::usesBypass(User::ROLE_ADMIN))->toBeTrue();
    expect(RolePresets::for(User::ROLE_ADMIN))->toBe([]);
});

test('user preset est vide pour permissions personnalisees', function () {
    expect(RolePresets::for(User::ROLE_USER))->toBe([]);
});

test('role presets n utilise pas edit lorsque update est disponible', function () {
    foreach ([User::ROLE_VENDEUR, User::ROLE_GESTIONNAIRE] as $role) {
        foreach (RolePresets::permissionNames($role) as $name) {
            expect(str_ends_with($name, '.edit'))->toBeFalse();
        }
    }
});

test('inventory reopen est utilise dans le preset gestionnaire', function () {
    $gestionnaire = RolePresets::permissionNames(User::ROLE_GESTIONNAIRE);

    expect($gestionnaire)->toContain('inventory.reopen');
    expect($gestionnaire)->not->toContain('inventory.review');
});
