<?php

use App\Auth\AssignablePermissionResolver;
use App\Auth\PermissionCatalog;
use App\Auth\RolePresets;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function gridPermissionNames(mixed $permissionsByResource): array
{
    return collect($permissionsByResource)
        ->flatten(1)
        ->pluck('name')
        ->values()
        ->all();
}

test('grille utilisateurs expose toutes les permissions historiques et user-activities.view', function () {
    $grid = AssignablePermissionResolver::adminGridByResource();
    $names = gridPermissionNames($grid);

    expect($names)->toContain('dashboard.view')
        ->and($names)->toContain('products.view')
        ->and($names)->toContain('products.create')
        ->and($names)->toContain('sales.view')
        ->and($names)->toContain('inventory.view')
        ->and($names)->toContain('user-activities.view')
        ->and($names)->not->toContain('products.edit')
        ->and(count($names))->toBeGreaterThan(20);
});

test('user-activities.view a un libelle francais dans la grille', function () {
    $grid = AssignablePermissionResolver::adminGridByResource();
    $permission = collect($grid['user-activities'] ?? [])->firstWhere('name', 'user-activities.view');

    expect($permission)->not->toBeNull()
        ->and($permission['label'])->toBe('Voir l\'activité des utilisateurs');
});

test('preset gestionnaire contient user-activities.view et les permissions historiques', function () {
    $names = RolePresets::permissionNames(User::ROLE_GESTIONNAIRE);

    expect($names)->toContain('user-activities.view')
        ->and($names)->toContain('products.view')
        ->and($names)->toContain('inventory.view')
        ->and($names)->not->toContain('sales.view');
});

test('preset vendeur n expose pas user-activities.view', function () {
    $names = RolePresets::permissionNames(User::ROLE_VENDEUR);

    expect($names)->toContain('sales.view')
        ->and($names)->not->toContain('user-activities.view');
});

test('page edit utilisateur transmet la grille complete et les presets RolePresets', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $target = User::factory()->create([
        'role' => User::ROLE_GESTIONNAIRE,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $target->permissions()->sync(RolePresets::permissionIds(User::ROLE_GESTIONNAIRE));

    $this->actingAs($admin)
        ->get(route('admin.users.edit', $target))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Users/Edit')
            ->has('permissionsByResource')
            ->has('rolePresets.vendeur')
            ->has('rolePresets.gestionnaire')
            ->where('rolePresets.gestionnaire', function ($permissions) {
                $names = collect($permissions);

                return $names->contains('user-activities.view')
                    && $names->contains('products.view')
                    && $names->contains('inventory.reopen')
                    && ! $names->contains('sales.view');
            })
            ->where('rolePresets.vendeur', function ($permissions) {
                $names = collect($permissions);

                return $names->contains('sales.view')
                    && ! $names->contains('user-activities.view');
            })
            ->where('permissionsByResource', function ($grid) {
                $names = collect(gridPermissionNames($grid));
                $userActivities = collect($grid['user-activities'] ?? [])
                    ->firstWhere('name', 'user-activities.view');

                return $names->contains('products.view')
                    && $names->contains('sales.view')
                    && $names->contains('user-activities.view')
                    && is_array($userActivities)
                    && $userActivities['label'] === 'Voir l\'activité des utilisateurs';
            })
        );
});

test('page create utilisateur transmet aussi la grille et les presets', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.users.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Users/Create')
            ->where('permissionsByResource', function ($grid) {
                $names = collect(gridPermissionNames($grid));

                return $names->contains('dashboard.view')
                    && $names->contains('user-activities.view')
                    && $names->count() > 20;
            })
            ->where('rolePresets.gestionnaire', fn ($permissions) => collect($permissions)->contains('user-activities.view'))
        );
});

test('page roles-permissions conserve toutes les permissions catalogue y compris user-activities', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $nonLegacyCount = count(array_filter(
        PermissionCatalog::all(),
        static fn (array $definition): bool => ! $definition['legacy'],
    ));

    $this->actingAs($admin)
        ->get(route('admin.roles-permissions.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/RolesPermissions/Index')
            ->where('permissions', function ($permissions) use ($nonLegacyCount) {
                $names = collect($permissions)->pluck('name');

                return $names->count() === $nonLegacyCount
                    && $names->contains('user-activities.view')
                    && $names->contains('products.view')
                    && $names->contains('sales.view');
            })
            ->where('moduleLabels', function ($labels) {
                return ($labels['user-activities'] ?? null) === 'Activité des utilisateurs';
            })
            ->where('roles', function ($roles) {
                $gestionnaire = collect($roles)->firstWhere('name', User::ROLE_GESTIONNAIRE);
                $vendeur = collect($roles)->firstWhere('name', User::ROLE_VENDEUR);

                return collect($gestionnaire['permissions'])->contains('user-activities.view')
                    && ! collect($vendeur['permissions'])->contains('user-activities.view');
            })
        );
});

test('aucune permission catalogue non legacy n est absente de la grille apres seed', function () {
    $catalogNames = collect(PermissionCatalog::all())
        ->reject(fn (array $definition) => $definition['legacy'])
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    $gridNames = collect(gridPermissionNames(AssignablePermissionResolver::adminGridByResource()))
        ->sort()
        ->values()
        ->all();

    expect($gridNames)->toBe($catalogNames);
});
