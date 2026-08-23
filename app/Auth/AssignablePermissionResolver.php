<?php

namespace App\Auth;

use App\Models\Permission;
use Illuminate\Support\Collection;

/**
 * Attribution admin : permissions canoniques uniquement (Phase N3 / N4).
 *
 * Règle N4 :
 * - Legacy = lecture / compatibilité uniquement (AuthorizationService)
 * - Écriture = noms catalogue canoniques uniquement
 */
final class AssignablePermissionResolver
{
    /**
     * Remplace les IDs legacy par leurs IDs canoniques (dédupliqués).
     * Ignore les IDs inconnus du PermissionCatalog (pas d’écriture arbitraire).
     *
     * @param  list<int|string>  $permissionIds
     * @return list<int>
     */
    public static function canonicalizeIds(array $permissionIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $permissionIds)));

        if ($ids === []) {
            return [];
        }

        $permissions = Permission::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name']);

        $canonicalNames = [];

        foreach ($permissions as $permission) {
            $name = (string) $permission->name;

            if (! PermissionCatalog::exists($name)) {
                continue;
            }

            $canonicalNames[] = PermissionCatalog::canonicalName($name);
        }

        $canonicalNames = array_values(array_unique($canonicalNames));

        if ($canonicalNames === []) {
            return [];
        }

        return Permission::query()
            ->whereIn('name', $canonicalNames)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Grille admin : uniquement permissions catalogue non-legacy.
     *
     * @return Collection<string, Collection<int, array{id: int, name: string, action: string, description: ?string}>>
     */
    public static function adminGridByResource(): Collection
    {
        $all = Permission::query()
            ->orderBy('resource')
            ->orderBy('action')
            ->get();

        $assignable = $all->filter(function (Permission $permission): bool {
            $name = (string) $permission->name;

            if (! PermissionCatalog::exists($name)) {
                return false;
            }

            return ! PermissionCatalog::isLegacy($name);
        });

        return $assignable
            ->groupBy('resource')
            ->map(static function (Collection $permissions): Collection {
                return $permissions->map(static function (Permission $permission): array {
                    return [
                        'id' => (int) $permission->id,
                        'name' => (string) $permission->name,
                        'action' => (string) $permission->action,
                        'description' => $permission->description,
                    ];
                })->values();
            });
    }

    /**
     * Indique si un nom de permission est autorisé à l’écriture (non legacy, catalogue).
     */
    public static function isWritableName(string $name): bool
    {
        if (! PermissionCatalog::exists($name)) {
            return false;
        }

        return ! PermissionCatalog::isLegacy($name)
            && PermissionCatalog::canonicalName($name) === $name;
    }
}
