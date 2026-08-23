<?php

namespace App\Console\Commands;

use App\Auth\AdminProtectionService;
use App\Auth\AuthorizationService;
use App\Auth\RbacAuditService;
use App\Models\User;
use Illuminate\Console\Command;

class SetUserRole extends Command
{
    protected $signature = 'user:set-role {email} {role=admin}';

    protected $description = 'Définit le rôle d\'un utilisateur (admin ou user)';

    public function handle(
        AdminProtectionService $adminProtection,
        AuthorizationService $authorization,
        RbacAuditService $rbacAudit,
    ): int {
        $email = $this->argument('email');
        $role = $this->argument('role');

        if (! in_array($role, ['admin', 'user'], true)) {
            $this->error("Le rôle doit être 'admin' ou 'user'");

            return 1;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("Utilisateur non trouvé : {$email}");

            return 1;
        }

        try {
            return $adminProtection->withAdminLock($user, function (User $lockedUser) use ($role, $adminProtection, $authorization, $rbacAudit): int {
                if (! $adminProtection->canChangeRole($lockedUser, $role)) {
                    throw new \App\Auth\LastAdminProtectionException(
                        'demote',
                        $lockedUser,
                        AdminProtectionService::MESSAGE_CANNOT_DEMOTE
                    );
                }

                $oldRole = $lockedUser->role;
                $permissionsBefore = $rbacAudit->currentPermissionNames($lockedUser);

                if ($oldRole === $role) {
                    $this->info("Aucun changement : le rôle est déjà « {$role} ».");

                    return 0;
                }

                $lockedUser->role = $role;
                $lockedUser->save();

                $authorization->forgetCachedPermissions($lockedUser);

                $permissionsAfter = $rbacAudit->currentPermissionNames($lockedUser);

                $rbacAudit->recordRoleChanged(
                    $lockedUser,
                    $oldRole,
                    $role,
                    $rbacAudit->diffPermissions($permissionsBefore, $permissionsAfter),
                    actor: null,
                    viaConsole: true,
                );

                $this->info("✅ Rôle '{$role}' défini pour l'utilisateur : {$lockedUser->name} ({$lockedUser->email})");

                return 0;
            });
        } catch (\App\Auth\LastAdminProtectionException $e) {
            $rbacAudit->recordLastAdminChangeDenied($e->target, $e->operation, viaConsole: true);
            $this->error($e->getMessage());

            return 1;
        }
    }
}
