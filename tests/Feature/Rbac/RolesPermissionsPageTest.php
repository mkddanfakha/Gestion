<?php

use App\Auth\RbacUiPresenter;
use App\Auth\RolePresets;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

test('page roles-permissions accessible aux admins uniquement', function () {
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
    $vendeur->permissions()->sync(RolePresets::permissionIds(User::ROLE_VENDEUR));

    $this->actingAs($vendeur)
        ->get(route('admin.roles-permissions.index'))
        ->assertRedirect(route('dashboard'));

    $this->actingAs($admin)
        ->get(route('admin.roles-permissions.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/RolesPermissions/Index')
            ->has('roles', 4)
            ->has('permissions')
            ->has('stats')
            ->has('auditEvents')
            ->where('roles', function ($roles) {
                $roles = collect($roles);
                $adminRole = $roles->firstWhere('name', User::ROLE_ADMIN);
                $gestionnaire = $roles->firstWhere('name', User::ROLE_GESTIONNAIRE);
                $vendeur = $roles->firstWhere('name', User::ROLE_VENDEUR);

                return $adminRole['isBypass'] === true
                    && $adminRole['permissionCount'] === null
                    && ! collect($gestionnaire['permissions'])->contains(fn ($p) => str_starts_with($p, 'sales.'))
                    && collect($vendeur['permissions'])->contains('sales.view')
                    && collect($gestionnaire['permissions'])->contains('inventory.reopen')
                    && ! collect($gestionnaire['permissions'])->contains('inventory.review');
            })
            ->where('permissions', function ($permissions) {
                $names = collect($permissions)->pluck('name');

                return $names->contains('inventory.reopen')
                    && ! $names->contains('inventory.review')
                    && ! $names->contains('products.edit');
            })
        );
});

test('presenter compte les utilisateurs par role sans n+1 roles', function () {
    User::factory()->count(2)->create(['role' => User::ROLE_VENDEUR]);
    User::factory()->count(3)->create(['role' => User::ROLE_GESTIONNAIRE]);
    User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

    $payload = app(RbacUiPresenter::class)->forIndex();

    $byRole = collect($payload['roles'])->keyBy('name');

    expect($byRole[User::ROLE_VENDEUR]['usersCount'])->toBe(2)
        ->and($byRole[User::ROLE_GESTIONNAIRE]['usersCount'])->toBe(3)
        ->and($byRole[User::ROLE_ADMIN]['isBypass'])->toBeTrue()
        ->and($payload['stats']['roles'])->toBe(4);
});

test('filtre role sur liste utilisateurs', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    User::factory()->create(['role' => User::ROLE_VENDEUR]);
    User::factory()->create(['role' => User::ROLE_GESTIONNAIRE]);

    $this->actingAs($admin)
        ->get(route('admin.users.index', ['role' => User::ROLE_VENDEUR]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Users/Index')
            ->where('filters.role', User::ROLE_VENDEUR)
            ->has('users.data', 1)
            ->where('users.data.0.role', User::ROLE_VENDEUR)
        );
});
