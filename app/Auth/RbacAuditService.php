<?php

namespace App\Auth;

use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Traçabilité des changements RBAC via le journal ActivityLog existant.
 * N'autorise jamais : observe uniquement.
 */
final class RbacAuditService
{
    public const MODULE = 'RBAC';

    public const REASON_LAST_ACTIVE_ADMIN = 'last_active_admin';

    /**
     * @param  list<string>  $permissionNames
     * @return list<string>
     */
    public function canonicalizePermissionNames(array $permissionNames): array
    {
        $canonical = [];

        foreach ($permissionNames as $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $canonical[] = PermissionCatalog::exists($name)
                ? PermissionCatalog::canonicalName($name)
                : $name;
        }

        $canonical = array_values(array_unique($canonical));
        sort($canonical);

        return $canonical;
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return array{added: list<string>, removed: list<string>}
     */
    public function diffPermissions(array $before, array $after): array
    {
        $beforeSet = $this->canonicalizePermissionNames($before);
        $afterSet = $this->canonicalizePermissionNames($after);

        return [
            'added' => array_values(array_diff($afterSet, $beforeSet)),
            'removed' => array_values(array_diff($beforeSet, $afterSet)),
        ];
    }

    /**
     * Noms de permissions actuellement en base pour l'utilisateur.
     *
     * @return list<string>
     */
    public function currentPermissionNames(User $user): array
    {
        return $user->permissions()->pluck('name')->all();
    }

    /**
     * @param  list<int>  $permissionIds
     * @return list<string>
     */
    public function permissionNamesFromIds(array $permissionIds): array
    {
        if ($permissionIds === []) {
            return [];
        }

        return Permission::query()
            ->whereIn('id', $permissionIds)
            ->pluck('name')
            ->all();
    }

    public function recordRoleChanged(
        User $target,
        string $oldRole,
        string $newRole,
        array $permissionDiff = ['added' => [], 'removed' => []],
        ?User $actor = null,
        bool $viaConsole = false,
    ): void {
        if ($oldRole === $newRole) {
            return;
        }

        $added = $permissionDiff['added'] ?? [];
        $removed = $permissionDiff['removed'] ?? [];

        $this->writeAfterCommit(function () use ($target, $oldRole, $newRole, $added, $removed, $actor, $viaConsole): void {
            $label = $target->name;
            $suffix = $viaConsole ? ' (via console)' : '';

            ActivityLogger::log(
                ActivityLog::ACTION_RBAC_ROLE_CHANGED,
                self::MODULE,
                sprintf('a changé le rôle de « %s » : %s → %s%s', $label, $oldRole, $newRole, $suffix),
                $target,
                $this->resolveActor($actor, $viaConsole),
                oldValues: [
                    'role' => $oldRole,
                    'permissions' => null,
                ],
                newValues: [
                    'target_user_id' => $target->id,
                    'old_role' => $oldRole,
                    'new_role' => $newRole,
                    'role' => $newRole,
                    'permissions' => [
                        'added' => $added,
                        'removed' => $removed,
                    ],
                ],
            );
        });
    }

    /**
     * @param  array{added: list<string>, removed: list<string>}  $diff
     */
    public function recordPermissionsChanged(User $target, array $diff, ?User $actor = null): void
    {
        if (($diff['added'] ?? []) === [] && ($diff['removed'] ?? []) === []) {
            return;
        }

        $this->writeAfterCommit(function () use ($target, $diff, $actor): void {
            ActivityLogger::log(
                ActivityLog::ACTION_RBAC_PERMISSIONS_CHANGED,
                self::MODULE,
                sprintf('a modifié les permissions de « %s »', $target->name),
                $target,
                $this->resolveActor($actor, false),
                oldValues: [
                    'removed' => $diff['removed'],
                ],
                newValues: [
                    'target_user_id' => $target->id,
                    'added' => $diff['added'],
                    'removed' => $diff['removed'],
                ],
            );
        });
    }

    /**
     * @param  array{added: list<string>, removed: list<string>}  $diff
     */
    public function recordPermissionsSynced(User $target, string $role, array $diff, ?User $actor = null): void
    {
        if (($diff['added'] ?? []) === [] && ($diff['removed'] ?? []) === []) {
            return;
        }

        $this->writeAfterCommit(function () use ($target, $role, $diff, $actor): void {
            ActivityLogger::log(
                ActivityLog::ACTION_RBAC_PERMISSIONS_SYNCED,
                self::MODULE,
                sprintf('a synchronisé les permissions preset « %s » pour « %s »', $role, $target->name),
                $target,
                $this->resolveActor($actor, false),
                oldValues: [
                    'removed' => $diff['removed'],
                ],
                newValues: [
                    'target_user_id' => $target->id,
                    'role' => $role,
                    'added' => $diff['added'],
                    'removed' => $diff['removed'],
                ],
            );
        });
    }

    public function recordActivationChanged(
        User $target,
        bool $oldActive,
        bool $newActive,
        ?User $actor = null,
    ): void {
        if ($oldActive === $newActive) {
            return;
        }

        $action = $newActive
            ? ActivityLog::ACTION_RBAC_USER_ACTIVATED
            : ActivityLog::ACTION_RBAC_USER_DEACTIVATED;

        $verb = $newActive ? 'activé' : 'désactivé';

        $this->writeAfterCommit(function () use ($target, $oldActive, $newActive, $action, $verb, $actor): void {
            ActivityLogger::log(
                $action,
                self::MODULE,
                sprintf('a %s le compte « %s »', $verb, $target->name),
                $target,
                $this->resolveActor($actor, false),
                oldValues: [
                    'is_active' => $oldActive,
                ],
                newValues: [
                    'target_user_id' => $target->id,
                    'old_is_active' => $oldActive,
                    'new_is_active' => $newActive,
                    'is_active' => $newActive,
                ],
            );
        });
    }

    public function recordAdminRemoved(User $target, string $role, ?User $actor = null): void
    {
        $this->writeAfterCommit(function () use ($target, $role, $actor): void {
            ActivityLogger::log(
                ActivityLog::ACTION_RBAC_ADMIN_REMOVED,
                self::MODULE,
                sprintf('a supprimé le compte administrateur « %s »', $target->name),
                $target,
                $this->resolveActor($actor, false),
                oldValues: [
                    'role' => $role,
                    'target_user_id' => $target->id,
                    'name' => $target->name,
                    'email' => $target->email,
                ],
                newValues: null,
            );
        });
    }

    /**
     * Refus last-admin : écriture immédiate (appeler hors transaction échouée).
     * Les contrôleurs catchent LastAdminProtectionException après rollback du withAdminLock.
     */
    public function recordLastAdminChangeDenied(
        User $target,
        string $operation,
        ?User $actor = null,
        bool $viaConsole = false,
    ): void {
        $suffix = $viaConsole ? ' (via console)' : '';

        ActivityLogger::log(
            ActivityLog::ACTION_RBAC_LAST_ADMIN_CHANGE_DENIED,
            self::MODULE,
            sprintf(
                'a tenté une opération refusée sur le dernier administrateur « %s » (%s)%s',
                $target->name,
                $operation,
                $suffix
            ),
            $target,
            $this->resolveActor($actor, $viaConsole),
            oldValues: null,
            newValues: [
                'target_user_id' => $target->id,
                'operation' => $operation,
                'reason' => self::REASON_LAST_ACTIVE_ADMIN,
            ],
        );
    }

    /**
     * Émet les événements RBAC pertinents après une mutation user réussie.
     *
     * @param  list<string>  $permissionsBefore
     * @param  list<string>  $permissionsAfter
     */
    public function recordUserMutation(
        User $target,
        string $oldRole,
        string $newRole,
        bool $oldActive,
        bool $newActive,
        array $permissionsBefore,
        array $permissionsAfter,
        bool $roleChanged,
        bool $presetSynced,
        ?User $actor = null,
        bool $viaConsole = false,
    ): void {
        $diff = $this->diffPermissions($permissionsBefore, $permissionsAfter);

        if ($roleChanged) {
            $this->recordRoleChanged($target, $oldRole, $newRole, $diff, $actor, $viaConsole);
        } elseif ($presetSynced) {
            $this->recordPermissionsSynced($target, $newRole, $diff, $actor);
        } else {
            $this->recordPermissionsChanged($target, $diff, $actor);
        }

        $this->recordActivationChanged($target, $oldActive, $newActive, $actor);
    }

    /**
     * Journalise une migration CLI des pivots legacy → canoniques.
     *
     * @param  list<array<string, mixed>>  $mappings
     */
    public function recordLegacyPermissionsMigrated(
        int $usersAffected,
        int $canonicalAdded,
        int $legacyRemoved,
        array $mappings,
        bool $viaConsole = true,
    ): void {
        if ($usersAffected === 0 && $canonicalAdded === 0 && $legacyRemoved === 0) {
            return;
        }

        $this->writeAfterCommit(function () use ($usersAffected, $canonicalAdded, $legacyRemoved, $mappings, $viaConsole): void {
            $suffix = $viaConsole ? ' (via console)' : '';

            ActivityLogger::log(
                ActivityLog::ACTION_RBAC_LEGACY_PERMISSIONS_MIGRATED,
                self::MODULE,
                sprintf(
                    'a migré les permissions legacy RBAC (%d utilisateur(s), +%d / -%d pivots)%s',
                    $usersAffected,
                    $canonicalAdded,
                    $legacyRemoved,
                    $suffix
                ),
                subject: null,
                user: $this->resolveActor(null, $viaConsole),
                oldValues: null,
                newValues: [
                    'operation' => 'legacy_permissions_migrated',
                    'users_affected' => $usersAffected,
                    'permissions_added_count' => $canonicalAdded,
                    'permissions_removed_count' => $legacyRemoved,
                    'mappings' => $mappings,
                ],
            );
        });
    }

    private function resolveActor(?User $actor, bool $viaConsole): ?User
    {
        if ($viaConsole) {
            return null;
        }

        return $actor ?? Auth::user();
    }

    private function writeAfterCommit(callable $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
    }
}
