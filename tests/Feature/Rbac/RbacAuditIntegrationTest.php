<?php

use App\Auth\RolePresets;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function rbacAuditAdmin(): User
{
    return User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

test('admin modifie role utilisateur et cree evenement rbac', function () {
    $admin = rbacAuditAdmin();
    $user = User::factory()->create([
        'role' => User::ROLE_VENDEUR,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->permissions()->sync(RolePresets::permissionIds(User::ROLE_VENDEUR));

    $this->actingAs($admin)
        ->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => User::ROLE_GESTIONNAIRE,
            'is_active' => true,
        ])
        ->assertRedirect();

    $log = ActivityLog::query()
        ->where('action', ActivityLog::ACTION_RBAC_ROLE_CHANGED)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->subject_id)->toBe($user->id)
        ->and($log->new_values['old_role'])->toBe(User::ROLE_VENDEUR)
        ->and($log->new_values['new_role'])->toBe(User::ROLE_GESTIONNAIRE)
        ->and($log->new_values['permissions']['removed'])->toContain('sales.create')
        ->and($user->fresh()->role)->toBe(User::ROLE_GESTIONNAIRE);
});

test('modification permissions custom est auditee avec added removed', function () {
    $admin = rbacAuditAdmin();
    $user = User::factory()->create([
        'role' => User::ROLE_USER,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $viewId = Permission::query()->where('name', 'products.view')->value('id');
    $updateId = Permission::query()->where('name', 'products.update')->value('id');
    $user->permissions()->sync([$viewId]);

    $this->actingAs($admin)
        ->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => User::ROLE_USER,
            'is_active' => true,
            'permissions' => [$updateId],
        ])
        ->assertRedirect();

    $log = ActivityLog::query()
        ->where('action', ActivityLog::ACTION_RBAC_PERMISSIONS_CHANGED)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->new_values['added'])->toContain('products.update')
        ->and($log->new_values['removed'])->toContain('products.view');
});

test('activation et desactivation sont auditees', function () {
    $admin = rbacAuditAdmin();
    $user = User::factory()->create([
        'role' => User::ROLE_VENDEUR,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->permissions()->sync(RolePresets::permissionIds(User::ROLE_VENDEUR));

    $this->actingAs($admin)
        ->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => User::ROLE_VENDEUR,
            'is_active' => false,
        ])
        ->assertRedirect();

    expect(ActivityLog::query()->where('action', ActivityLog::ACTION_RBAC_USER_DEACTIVATED)->exists())->toBeTrue();

    $this->actingAs($admin)
        ->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => User::ROLE_VENDEUR,
            'is_active' => true,
        ])
        ->assertRedirect();

    expect(ActivityLog::query()->where('action', ActivityLog::ACTION_RBAC_USER_ACTIVATED)->exists())->toBeTrue();
});

test('suppression admin est auditee', function () {
    $actor = rbacAuditAdmin();
    $target = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($actor)
        ->delete(route('admin.users.destroy', $target))
        ->assertRedirect();

    expect(ActivityLog::query()->where('action', ActivityLog::ACTION_RBAC_ADMIN_REMOVED)->exists())->toBeTrue()
        ->and(User::query()->whereKey($target->id)->exists())->toBeFalse();
});

test('dernier admin refuse genere evenement securite', function () {
    $admin = rbacAuditAdmin();

    $this->actingAs($admin)
        ->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => User::ROLE_VENDEUR,
            'is_active' => true,
        ])
        ->assertForbidden();

    $log = ActivityLog::query()
        ->where('action', ActivityLog::ACTION_RBAC_LAST_ADMIN_CHANGE_DENIED)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->new_values['operation'])->toBe('demote')
        ->and($admin->fresh()->role)->toBe(User::ROLE_ADMIN)
        ->and(ActivityLog::query()->where('action', ActivityLog::ACTION_RBAC_ROLE_CHANGED)->count())->toBe(0);
});

test('cli user set-role est audite', function () {
    $user = User::factory()->create([
        'role' => User::ROLE_USER,
        'email' => 'cli-target@example.com',
        'is_active' => true,
    ]);
    // Second admin so demote of another isn't needed — promoting user to admin
    User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

    Artisan::call('user:set-role', [
        'email' => 'cli-target@example.com',
        'role' => 'admin',
    ]);

    $log = ActivityLog::query()
        ->where('action', ActivityLog::ACTION_RBAC_ROLE_CHANGED)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBeNull()
        ->and($log->new_values['new_role'])->toBe('admin')
        ->and($user->fresh()->role)->toBe(User::ROLE_ADMIN)
        ->and(str_contains($log->description, 'console'))->toBeTrue();
});

test('admin bypass reste fonctionnel apres audit', function () {
    $admin = rbacAuditAdmin();

    expect($admin->hasPermissionByName('products.delete'))->toBeTrue();
});
