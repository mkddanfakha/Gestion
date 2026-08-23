<?php

use App\Auth\PermissionCatalog;
use App\Enums\PermissionName;

test('toutes les valeurs enum sont uniques', function () {
    $values = PermissionName::values();

    expect($values)->toHaveCount(count(array_unique($values)));
});

test('le catalogue contient une entrée pour chaque permission enum', function () {
    expect(PermissionCatalog::all())->toHaveCount(count(PermissionName::cases()));
});

test('chaque permission possède module action label description legacy et canonical', function () {
    foreach (PermissionCatalog::all() as $definition) {
        expect($definition)->toHaveKeys(['name', 'module', 'action', 'label', 'description', 'legacy', 'canonical', 'sort']);
        expect($definition['module'])->not->toBeEmpty();
        expect($definition['action'])->not->toBeEmpty();
        expect($definition['label'])->not->toBeEmpty();
        expect($definition['description'])->not->toBeEmpty();
        expect($definition['name'])->toBe("{$definition['module']}.{$definition['action']}");
    }
});

test('products.view inventory.view inventory.export existent', function () {
    expect(PermissionCatalog::exists('products.view'))->toBeTrue();
    expect(PermissionCatalog::exists('inventory.view'))->toBeTrue();
    expect(PermissionCatalog::exists('inventory.export'))->toBeTrue();
});

test('inventory.reopen est canonique et inventory.review est legacy', function () {
    expect(PermissionCatalog::exists('inventory.reopen'))->toBeTrue();
    expect(PermissionCatalog::isLegacy(PermissionName::InventoryReopen))->toBeFalse();
    expect(PermissionCatalog::canonicalName(PermissionName::InventoryReopen))->toBe('inventory.reopen');
    expect(PermissionCatalog::canonical(PermissionName::InventoryReopen))->toBe(PermissionName::InventoryReopen);

    expect(PermissionCatalog::isLegacy(PermissionName::InventoryReview))->toBeTrue();
    expect(PermissionCatalog::canonicalName(PermissionName::InventoryReview))->toBe('inventory.reopen');
    expect(PermissionCatalog::canonical(PermissionName::InventoryReview))->toBe(PermissionName::InventoryReopen);
    expect(PermissionCatalog::plannedCanonicalName(PermissionName::InventoryReview))->toBeNull();
});

test('products.edit est legacy et products.update est canonique', function () {
    expect(PermissionCatalog::isLegacy(PermissionName::ProductsEdit))->toBeTrue();
    expect(PermissionCatalog::canonicalName(PermissionName::ProductsEdit))->toBe('products.update');
    expect(PermissionCatalog::canonical(PermissionName::ProductsEdit))->toBe(PermissionName::ProductsUpdate);
    expect(PermissionCatalog::isLegacy(PermissionName::ProductsUpdate))->toBeFalse();
});

test('forModule retourne uniquement les permissions du module demandé', function () {
    $products = PermissionCatalog::forModule('products');

    expect($products)->not->toBeEmpty();
    expect(collect($products)->pluck('module')->unique()->all())->toBe(['products']);
});

test('exists find et names fonctionnent', function () {
    expect(PermissionCatalog::exists('unknown.permission'))->toBeFalse();

    $definition = PermissionCatalog::find(PermissionName::SalesView);

    expect($definition['name'])->toBe('sales.view');
    expect(PermissionCatalog::findByName('sales.view'))->toBe($definition);
    expect(PermissionCatalog::names())->toContain('sales.view');
});

test('le catalogue ne contient pas de doublons de noms', function () {
    $names = array_column(PermissionCatalog::all(), 'name');

    expect($names)->toHaveCount(count(array_unique($names)));
});

test('legacyMappings couvre edit update et inventory review reopen', function () {
    $mappings = PermissionCatalog::legacyMappings();

    expect($mappings['products.edit'])->toBe('products.update');
    expect($mappings['inventory.review'])->toBe('inventory.reopen');
    expect($mappings)->not->toHaveKey('inventory.reopen');
    expect($mappings)->toHaveCount(11);
});

test('chaque legacy a un canonique existant et unique', function () {
    $mappings = PermissionCatalog::legacyMappings();
    $seenCanonical = [];

    foreach ($mappings as $legacy => $canonical) {
        expect(PermissionCatalog::exists($legacy))->toBeTrue();
        expect(PermissionCatalog::exists($canonical))->toBeTrue();
        expect(PermissionCatalog::isLegacy($legacy))->toBeTrue();
        expect(PermissionCatalog::isLegacy($canonical))->toBeFalse();
        expect(PermissionCatalog::canonicalName($legacy))->toBe($canonical);
        expect($seenCanonical)->not->toHaveKey($canonical);
        $seenCanonical[$canonical] = $legacy;
    }
});

test('les permissions canoniques ne sont pas marquees legacy', function () {
    foreach (['products.update', 'inventory.reopen', 'sales.view', 'dashboard.view'] as $name) {
        expect(PermissionCatalog::isLegacy($name))->toBeFalse();
        expect(PermissionCatalog::canonicalName($name))->toBe($name);
    }
});
