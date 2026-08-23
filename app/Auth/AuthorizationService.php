<?php

namespace App\Auth;

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\User;

/**
 * Source unique de décision d'autorisation RBAC (Phase E).
 *
 * RBAC = « peut-il effectuer cette action ? » — pas l'isolation des données.
 *
 * Phase J : cache intra-requête via la relation Eloquent `permissions`
 * (chargée une fois par instance User). Pas de cache inter-requêtes Laravel.
 */
class AuthorizationService
{
    public function allows(User $user, string|PermissionName $permission): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $name = $this->normalizePermissionName($permission);

        if ($name === null) {
            return false;
        }

        $namesToCheck = $this->resolveAliases($name);
        $owned = $this->permissionNameSet($user);

        foreach ($namesToCheck as $candidate) {
            if (isset($owned[$candidate])) {
                return true;
            }
        }

        return false;
    }

    public function denies(User $user, string|PermissionName $permission): bool
    {
        return ! $this->allows($user, $permission);
    }

    /**
     * @param  list<string|PermissionName>  $permissions
     */
    public function any(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->allows($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string|PermissionName>  $permissions
     */
    public function all(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->allows($user, $permission)) {
                return false;
            }
        }

        return $permissions !== [];
    }

    /**
     * Permissions effectives pour le frontend (noms tels qu'en base).
     *
     * Admin → [] (bypass via isAdmin côté client).
     *
     * @return list<string>
     */
    public function forUser(User $user): array
    {
        if ($user->isAdmin()) {
            return [];
        }

        $names = array_keys($this->permissionNameSet($user));
        sort($names);

        return $names;
    }

    /**
     * Vérifie une permission au format resource + action (compatibilité existante).
     */
    public function allowsResourceAction(User $user, string $resource, string $action): bool
    {
        return $this->allows($user, Permission::generateName($resource, $action));
    }

    /**
     * Invalide le cache intra-requête des permissions pour cette instance User.
     *
     * À appeler après sync/attach/detach/changement de rôle sur la même instance,
     * afin que les décisions suivantes rechargent depuis la DB.
     */
    public function forgetCachedPermissions(User $user): void
    {
        $user->unsetRelation('permissions');
    }

    /**
     * Ensemble des noms de permissions (clés) pour lookup O(1).
     *
     * Charge la relation `permissions` une seule fois par instance User
     * tant que forgetCachedPermissions() n'a pas été appelé.
     *
     * @return array<string, true>
     */
    private function permissionNameSet(User $user): array
    {
        if (! $user->relationLoaded('permissions')) {
            $user->load('permissions');
        }

        $set = [];
        foreach ($user->permissions as $permission) {
            $set[$permission->name] = true;
        }

        return $set;
    }

    private function normalizePermissionName(string|PermissionName $permission): ?string
    {
        if ($permission instanceof PermissionName) {
            return $permission->value;
        }

        if ($permission === '') {
            return null;
        }

        return $permission;
    }

    /**
     * Résout les alias legacy ↔ canonique pour une vérification unique.
     *
     * Ex. products.edit ↔ products.update
     * inventory.review ↔ inventory.reopen (legacy ↔ canonique)
     *
     * @return list<string>
     */
    private function resolveAliases(string $name): array
    {
        $aliases = [$name];

        if (! PermissionCatalog::exists($name)) {
            return $aliases;
        }

        if (PermissionCatalog::isLegacy($name)) {
            $canonical = PermissionCatalog::canonicalName($name);

            if (PermissionCatalog::exists($canonical)) {
                $aliases[] = $canonical;
            }

            return array_values(array_unique($aliases));
        }

        foreach (PermissionCatalog::legacyMappings() as $legacy => $canonical) {
            if ($canonical === $name) {
                $aliases[] = $legacy;
            }
        }

        return array_values(array_unique($aliases));
    }
}
