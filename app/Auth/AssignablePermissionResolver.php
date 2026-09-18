<?php

namespace App\Auth;

use App\Models\Permission;

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
     * Grille admin : permissions catalogue non-legacy, enrichies avec libellés FR.
     *
     * Source d’affichage = PermissionCatalog (comme « Rôles & permissions »).
     * Les IDs viennent de la table permissions (requis pour l’attribution).
     *
     * @return array<string, list<array{id: int, name: string, action: string, label: string, description: ?string}>>
     */
    public static function adminGridByResource(): array
    {
        $dbByName = Permission::query()
            ->orderBy('resource')
            ->orderBy('action')
            ->get()
            ->keyBy(static fn (Permission $permission): string => (string) $permission->name);

        $grid = [];

        foreach (PermissionCatalog::all() as $definition) {
            if ($definition['legacy']) {
                continue;
            }

            $permission = $dbByName->get($definition['name']);

            if ($permission === null) {
                continue;
            }

            $module = $definition['module'];

            $grid[$module][] = [
                'id' => (int) $permission->id,
                'name' => $definition['name'],
                'action' => $definition['action'],
                'label' => $definition['label'],
                'description' => $definition['description'] !== ''
                    ? $definition['description']
                    : $permission->description,
            ];
        }

        return $grid;
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
