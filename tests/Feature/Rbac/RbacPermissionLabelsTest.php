<?php

use App\Auth\AssignablePermissionResolver;
use App\Auth\PermissionCatalog;
use App\Auth\RbacUiPresenter;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

test('PermissionCatalog expose les libelles FR des permissions treasury et sales payments', function () {
    expect(PermissionCatalog::label('treasury.view'))->toBe('Consulter la trésorerie')
        ->and(PermissionCatalog::label('sales.payments.view'))->toBe('Consulter les règlements clients')
        ->and(PermissionCatalog::label('sales.payments.record'))->toBe('Enregistrer un règlement client')
        ->and(PermissionCatalog::label('sales.payments.void'))->toBe('Annuler un règlement client')
        ->and(PermissionCatalog::findByName('treasury.view')['name'])->toBe('treasury.view')
        ->and(PermissionCatalog::findByName('sales.payments.record')['name'])->toBe('sales.payments.record');
});

test('RbacUiPresenter fournit les libelles de modules treasury et sales payments', function () {
    $labels = RbacUiPresenter::moduleLabels();

    expect($labels['treasury'])->toBe('Trésorerie')
        ->and($labels['sales.payments'])->toBe('Règlements clients');
});

test('grille utilisateurs transmet les libelles FR pour treasury et sales payments', function () {
    $grid = AssignablePermissionResolver::adminGridByResource();

    $treasury = collect($grid['treasury'] ?? [])->firstWhere('name', 'treasury.view');
    $record = collect($grid['sales.payments'] ?? [])->firstWhere('name', 'sales.payments.record');

    expect($treasury)->not->toBeNull()
        ->and($treasury['label'])->toBe('Consulter la trésorerie')
        ->and($treasury['name'])->toBe('treasury.view')
        ->and($record)->not->toBeNull()
        ->and($record['label'])->toBe('Enregistrer un règlement client')
        ->and($record['name'])->toBe('sales.payments.record');
});

test('page edit utilisateur transmet moduleLabels depuis RbacUiPresenter', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $target = User::factory()->create([
        'role' => User::ROLE_VENDEUR,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.users.edit', $target))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Users/Edit')
            ->where('moduleLabels', fn ($labels) => ($labels['treasury'] ?? null) === 'Trésorerie'
                && ($labels['sales.payments'] ?? null) === 'Règlements clients')
        );
});

test('page roles permissions transmet les libelles de modules treasury et sales payments', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.roles-permissions.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/RolesPermissions/Index')
            ->where('moduleLabels', fn ($labels) => ($labels['treasury'] ?? null) === 'Trésorerie'
                && ($labels['sales.payments'] ?? null) === 'Règlements clients')
            ->where('permissions', function ($permissions) {
                $byName = collect($permissions)->keyBy('name');

                return ($byName['treasury.view']['label'] ?? null) === 'Consulter la trésorerie'
                    && ($byName['sales.payments.void']['name'] ?? null) === 'sales.payments.void';
            })
        );
});
