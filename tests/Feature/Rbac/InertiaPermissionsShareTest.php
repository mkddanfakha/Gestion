<?php

use App\Auth\RolePresets;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

test('inertia expose permissions via AuthorizationService pour un vendeur', function () {
    $user = User::factory()->create([
        'role' => User::ROLE_VENDEUR,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->permissions()->sync(RolePresets::permissionIds(User::ROLE_VENDEUR));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.role', User::ROLE_VENDEUR)
            ->has('auth.user.permissions')
            ->where('auth.user.permissions', fn ($permissions) => collect($permissions)->contains('dashboard.view')
                && collect($permissions)->contains('sales.create')
                && ! collect($permissions)->contains('inventory.apply'))
        );
});

test('inertia admin recoit permissions vides (bypass)', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.role', User::ROLE_ADMIN)
            ->where('auth.user.permissions', [])
        );
});

test('inertia gestionnaire n expose pas sales.*', function () {
    $user = User::factory()->create([
        'role' => User::ROLE_GESTIONNAIRE,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->permissions()->sync(RolePresets::permissionIds(User::ROLE_GESTIONNAIRE));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.permissions', function ($permissions) {
                $names = collect($permissions);

                foreach ($names as $name) {
                    if (is_string($name) && str_starts_with($name, 'sales.')) {
                        return false;
                    }
                }

                return $names->contains('inventory.view')
                    && $names->contains('inventory.reopen');
            })
        );
});

test('guest n a pas auth.user', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user', null)
        );
});
