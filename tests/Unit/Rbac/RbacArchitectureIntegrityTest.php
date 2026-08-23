<?php

use App\Auth\AssignablePermissionResolver;
use App\Auth\PermissionCatalog;
use App\Auth\RolePresets;
use App\Enums\PermissionName;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

test('aucune permission canonique dupliquee dans le catalogue', function () {
    $names = array_column(PermissionCatalog::all(), 'name');

    expect($names)->toHaveCount(count(array_unique($names)))
        ->and($names)->toHaveCount(count(PermissionName::cases()));
});

test('tous les presets referent des permissions catalogue non legacy', function () {
    foreach (RolePresets::roles() as $role) {
        foreach (RolePresets::permissionNames($role) as $name) {
            expect(PermissionCatalog::exists($name))->toBeTrue("Preset {$role} : {$name}")
                ->and(PermissionCatalog::isLegacy($name))->toBeFalse("Preset {$role} legacy : {$name}");
        }
    }
});

test('grille assignable uniquement writable', function () {
    $names = AssignablePermissionResolver::adminGridByResource()
        ->flatten(1)
        ->pluck('name')
        ->all();

    expect($names)->not->toBeEmpty();

    foreach ($names as $name) {
        expect(AssignablePermissionResolver::isWritableName($name))->toBeTrue();
    }
});

test('mappings legacy coherents edit update et review reopen', function () {
    $mappings = PermissionCatalog::legacyMappings();

    expect($mappings)->toHaveCount(11)
        ->and($mappings['products.edit'])->toBe('products.update')
        ->and($mappings['inventory.review'])->toBe('inventory.reopen');

    foreach ($mappings as $legacy => $canonical) {
        expect(PermissionCatalog::isLegacy($legacy))->toBeTrue()
            ->and(PermissionCatalog::isLegacy($canonical))->toBeFalse()
            ->and(PermissionCatalog::exists($canonical))->toBeTrue()
            ->and(PermissionCatalog::canonicalName($legacy))->toBe($canonical);
    }
});

test('inventory.reopen canonique et inventory.review uniquement legacy', function () {
    expect(PermissionCatalog::isLegacy('inventory.reopen'))->toBeFalse()
        ->and(PermissionCatalog::isLegacy('inventory.review'))->toBeTrue()
        ->and(PermissionCatalog::canonicalName('inventory.review'))->toBe('inventory.reopen')
        ->and(AssignablePermissionResolver::isWritableName('inventory.reopen'))->toBeTrue()
        ->and(AssignablePermissionResolver::isWritableName('inventory.review'))->toBeFalse();
});

test('star update sont canoniques', function () {
    foreach (['products', 'sales', 'customers', 'quotes', 'company'] as $module) {
        $update = "{$module}.update";
        $edit = "{$module}.edit";

        expect(PermissionCatalog::isLegacy($update))->toBeFalse()
            ->and(PermissionCatalog::isLegacy($edit))->toBeTrue()
            ->and(PermissionCatalog::canonicalName($edit))->toBe($update);
    }
});
