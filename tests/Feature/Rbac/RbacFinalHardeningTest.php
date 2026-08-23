<?php

use App\Auth\AuthorizationService;
use App\Auth\RolePresets;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->authorization = app(AuthorizationService::class);
});

function n4UserWithPreset(string $role): User
{
    $user = User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    if ($role !== User::ROLE_ADMIN) {
        $user->permissions()->sync(RolePresets::permissionIds($role));
    }

    return $user->fresh();
}

test('gestionnaire recoit 403 sur sales index create et update', function () {
    $gestionnaire = n4UserWithPreset(User::ROLE_GESTIONNAIRE);

    foreach (['sales.view', 'sales.create', 'sales.update', 'sales.delete', 'sales.invoice'] as $permission) {
        expect($this->authorization->allows($gestionnaire, $permission))->toBeFalse($permission);
    }

    $this->actingAs($gestionnaire)->get(route('sales.index'))->assertForbidden();
    $this->actingAs($gestionnaire)->get(route('sales.create'))->assertForbidden();
});

test('vendeur accede dashboard et sales mais pas inventaire reopen ni backups ni admin users', function () {
    $vendeur = n4UserWithPreset(User::ROLE_VENDEUR);

    expect($this->authorization->allows($vendeur, 'dashboard.view'))->toBeTrue()
        ->and($this->authorization->allows($vendeur, 'sales.view'))->toBeTrue()
        ->and($this->authorization->allows($vendeur, 'inventory.reopen'))->toBeFalse()
        ->and($this->authorization->allows($vendeur, 'backups.view'))->toBeFalse();

    $this->actingAs($vendeur)->get(route('dashboard'))->assertOk();
    $this->actingAs($vendeur)->get(route('sales.index'))->assertOk();
    $this->actingAs($vendeur)->get(route('admin.users.index'))->assertRedirect(route('dashboard'));
    $this->actingAs($vendeur)->get(route('admin.roles-permissions.index'))->assertRedirect(route('dashboard'));
});

test('admin allows true avec permissions vides puis demotion retire le bypass', function () {
    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    expect($admin->permissions()->count())->toBe(0)
        ->and($this->authorization->allows($admin, 'backups.restore'))->toBeTrue();

    $admin->role = User::ROLE_VENDEUR;
    $admin->save();
    $admin->permissions()->sync(RolePresets::permissionIds(User::ROLE_VENDEUR));
    $this->authorization->forgetCachedPermissions($admin);
    $admin = $admin->fresh();

    expect($this->authorization->allows($admin, 'backups.restore'))->toBeFalse()
        ->and($this->authorization->allows($admin, 'sales.view'))->toBeTrue();
});

test('permission inconnue refusee', function () {
    $user = n4UserWithPreset(User::ROLE_VENDEUR);

    expect($this->authorization->allows($user, 'inventory.fake_action'))->toBeFalse()
        ->and($this->authorization->allows($user, ''))->toBeFalse();
});
