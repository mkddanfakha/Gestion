<?php

use App\Auth\RbacAuditService;
use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->rbacAudit = app(RbacAuditService::class);
});

test('diff permissions detecte ajout et suppression', function () {
    $diff = $this->rbacAudit->diffPermissions(
        ['products.view', 'sales.view'],
        ['products.view', 'customers.view'],
    );

    expect($diff['added'])->toBe(['customers.view'])
        ->and($diff['removed'])->toBe(['sales.view']);
});

test('diff permissions normalise edit update sans faux evenement', function () {
    $diff = $this->rbacAudit->diffPermissions(
        ['products.edit'],
        ['products.update'],
    );

    expect($diff['added'])->toBe([])
        ->and($diff['removed'])->toBe([]);
});

test('diff permissions normalise review reopen', function () {
    $diff = $this->rbacAudit->diffPermissions(
        ['inventory.review'],
        ['inventory.reopen'],
    );

    expect($diff['added'])->toBe([])
        ->and($diff['removed'])->toBe([]);
});

test('role inchange ne cree pas evenement', function () {
    $user = User::factory()->create(['role' => User::ROLE_VENDEUR]);

    $this->rbacAudit->recordRoleChanged($user, User::ROLE_VENDEUR, User::ROLE_VENDEUR);

    expect(ActivityLog::query()->where('action', ActivityLog::ACTION_RBAC_ROLE_CHANGED)->count())->toBe(0);
});

test('role change cree evenement', function () {
    $user = User::factory()->create(['role' => User::ROLE_VENDEUR, 'name' => 'Cible']);

    $this->rbacAudit->recordRoleChanged(
        $user,
        User::ROLE_VENDEUR,
        User::ROLE_GESTIONNAIRE,
        ['added' => ['inventory.view'], 'removed' => ['sales.create']],
    );

    $log = ActivityLog::query()->where('action', ActivityLog::ACTION_RBAC_ROLE_CHANGED)->first();

    expect($log)->not->toBeNull()
        ->and($log->module)->toBe('RBAC')
        ->and($log->new_values['old_role'])->toBe(User::ROLE_VENDEUR)
        ->and($log->new_values['new_role'])->toBe(User::ROLE_GESTIONNAIRE)
        ->and($log->new_values['permissions']['added'])->toContain('inventory.view')
        ->and($log->subject_id)->toBe($user->id);
});

test('permissions inchangees ne creent pas evenement', function () {
    $user = User::factory()->create(['role' => User::ROLE_USER]);

    $this->rbacAudit->recordPermissionsChanged($user, ['added' => [], 'removed' => []]);

    expect(ActivityLog::query()->where('action', ActivityLog::ACTION_RBAC_PERMISSIONS_CHANGED)->count())->toBe(0);
});

test('permission ajoutee et retiree cree evenement', function () {
    $user = User::factory()->create(['role' => User::ROLE_USER, 'name' => 'U']);

    $this->rbacAudit->recordPermissionsChanged($user, [
        'added' => ['products.view'],
        'removed' => ['expenses.view'],
    ]);

    $log = ActivityLog::query()->where('action', ActivityLog::ACTION_RBAC_PERMISSIONS_CHANGED)->first();

    expect($log->new_values['added'])->toBe(['products.view'])
        ->and($log->new_values['removed'])->toBe(['expenses.view']);
});

test('activation et desactivation', function () {
    $user = User::factory()->create(['role' => User::ROLE_VENDEUR, 'is_active' => true]);

    $this->rbacAudit->recordActivationChanged($user, true, true);
    expect(ActivityLog::query()->where('module', 'RBAC')->count())->toBe(0);

    $this->rbacAudit->recordActivationChanged($user, true, false);
    expect(ActivityLog::query()->where('action', ActivityLog::ACTION_RBAC_USER_DEACTIVATED)->count())->toBe(1);

    $this->rbacAudit->recordActivationChanged($user, false, true);
    expect(ActivityLog::query()->where('action', ActivityLog::ACTION_RBAC_USER_ACTIVATED)->count())->toBe(1);
});

test('refus dernier admin demote est audite', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

    $this->rbacAudit->recordLastAdminChangeDenied($admin, 'demote');

    $log = ActivityLog::query()
        ->where('action', ActivityLog::ACTION_RBAC_LAST_ADMIN_CHANGE_DENIED)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->new_values['operation'])->toBe('demote')
        ->and($log->new_values['reason'])->toBe(RbacAuditService::REASON_LAST_ACTIVE_ADMIN);
});

test('refus dernier admin deactivate et delete sont audites', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

    $this->rbacAudit->recordLastAdminChangeDenied($admin, 'deactivate');
    $this->rbacAudit->recordLastAdminChangeDenied($admin, 'delete');

    $ops = ActivityLog::query()
        ->where('action', ActivityLog::ACTION_RBAC_LAST_ADMIN_CHANGE_DENIED)
        ->pluck('new_values')
        ->map(fn ($v) => $v['operation'] ?? null)
        ->all();

    expect($ops)->toContain('deactivate')
        ->and($ops)->toContain('delete');
});

test('rollback transaction n ecrit pas evenement succes', function () {
    $user = User::factory()->create(['role' => User::ROLE_VENDEUR]);

    try {
        DB::transaction(function () use ($user) {
            $this->rbacAudit->recordRoleChanged(
                $user,
                User::ROLE_VENDEUR,
                User::ROLE_GESTIONNAIRE,
            );
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(ActivityLog::query()->where('action', ActivityLog::ACTION_RBAC_ROLE_CHANGED)->count())->toBe(0);
});
