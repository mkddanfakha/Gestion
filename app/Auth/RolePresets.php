<?php

namespace App\Auth;

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\User;
use InvalidArgumentException;

/**
 * Presets de permissions par rôle métier (V1).
 *
 * Source de configuration — n'est pas le moteur d'autorisation final.
 * Les attributions utilisateur restent sur user_permissions ; pas de force-sync global.
 */
final class RolePresets
{
    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return [
            User::ROLE_ADMIN,
            User::ROLE_GESTIONNAIRE,
            User::ROLE_VENDEUR,
            User::ROLE_USER,
        ];
    }

    public static function has(string $role): bool
    {
        return in_array($role, self::roles(), true);
    }

    /**
     * Indique si le rôle s'appuie sur le bypass admin (pivot vide).
     */
    public static function usesBypass(string $role): bool
    {
        return $role === User::ROLE_ADMIN;
    }

    /**
     * Permissions documentées pour un rôle (enum).
     *
     * Admin → [] (bypass code, pas de pivot).
     * User → [] (permissions personnalisées).
     *
     * @return list<PermissionName>
     */
    public static function for(string $role): array
    {
        if (! self::has($role)) {
            throw new InvalidArgumentException("Rôle inconnu : {$role}");
        }

        return match ($role) {
            User::ROLE_ADMIN, User::ROLE_USER => [],
            User::ROLE_VENDEUR => self::vendeurPermissions(),
            User::ROLE_GESTIONNAIRE => self::gestionnairePermissions(),
        };
    }

    /**
     * @return list<string>
     */
    public static function permissionNames(string $role): array
    {
        return array_map(
            static fn (PermissionName $permission): string => $permission->value,
            self::for($role),
        );
    }

    /**
     * Alias explicite de permissionNames().
     *
     * @return list<string>
     */
    public static function permissions(string $role): array
    {
        return self::permissionNames($role);
    }

    /**
     * Résout les IDs DB pour un preset (création / sync explicite via UserController).
     *
     * @return list<int>
     */
    public static function permissionIds(string $role): array
    {
        $names = self::permissionNames($role);

        if ($names === []) {
            return [];
        }

        return Permission::query()
            ->whereIn('name', $names)
            ->pluck('id')
            ->all();
    }

    /**
     * Valide tous les presets contre PermissionCatalog.
     *
     * @return list<string> Messages d'erreur (vide si valide)
     */
    public static function validate(): array
    {
        $errors = [];

        foreach (self::roles() as $role) {
            $permissions = self::for($role);
            $seen = [];

            foreach ($permissions as $permission) {
                $name = $permission->value;

                if (! PermissionCatalog::exists($name)) {
                    $errors[] = "Permission inconnue dans le preset {$role} : {$name}";
                }

                if (isset($seen[$name])) {
                    $errors[] = "Permission dupliquée dans le preset {$role} : {$name}";
                }

                $seen[$name] = true;
            }

            if ($role === User::ROLE_GESTIONNAIRE) {
                foreach ($permissions as $permission) {
                    if (PermissionCatalog::module($permission) === 'sales') {
                        $errors[] = "Le preset gestionnaire ne doit pas contenir sales.* : {$permission->value}";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<PermissionName>
     */
    private static function vendeurPermissions(): array
    {
        return self::uniquePermissions([
            PermissionName::DashboardView,
            PermissionName::SalesView,
            PermissionName::SalesCreate,
            PermissionName::SalesUpdate,
            PermissionName::SalesDelete,
            PermissionName::SalesInvoice,
            PermissionName::QuotesView,
            PermissionName::QuotesCreate,
            PermissionName::QuotesUpdate,
            PermissionName::QuotesDelete,
            PermissionName::QuotesDownload,
            PermissionName::QuotesPrint,
            PermissionName::ProductsView,
            PermissionName::CustomersView,
            PermissionName::CustomersCreate,
            PermissionName::CustomersUpdate,
        ]);
    }

    /**
     * @return list<PermissionName>
     */
    private static function gestionnairePermissions(): array
    {
        return self::uniquePermissions([
            PermissionName::DashboardView,
            PermissionName::ProductsView,
            PermissionName::ProductsCreate,
            PermissionName::ProductsUpdate,
            PermissionName::ProductsDelete,
            PermissionName::CategoriesView,
            PermissionName::CategoriesCreate,
            PermissionName::CategoriesUpdate,
            PermissionName::CategoriesDelete,
            PermissionName::QuotesView,
            PermissionName::QuotesCreate,
            PermissionName::QuotesUpdate,
            PermissionName::QuotesDelete,
            PermissionName::QuotesDownload,
            PermissionName::QuotesPrint,
            PermissionName::ExpensesView,
            PermissionName::ExpensesCreate,
            PermissionName::ExpensesUpdate,
            PermissionName::ExpensesDelete,
            PermissionName::SuppliersView,
            PermissionName::SuppliersCreate,
            PermissionName::SuppliersUpdate,
            PermissionName::SuppliersDelete,
            PermissionName::SuppliersExport,
            PermissionName::PurchaseOrdersView,
            PermissionName::PurchaseOrdersCreate,
            PermissionName::PurchaseOrdersUpdate,
            PermissionName::PurchaseOrdersDelete,
            PermissionName::PurchaseOrdersDownload,
            PermissionName::PurchaseOrdersPrint,
            PermissionName::DeliveryNotesView,
            PermissionName::DeliveryNotesCreate,
            PermissionName::DeliveryNotesUpdate,
            PermissionName::DeliveryNotesDelete,
            PermissionName::DeliveryNotesValidate,
            PermissionName::DeliveryNotesDownload,
            PermissionName::DeliveryNotesPrint,
            PermissionName::InventoryView,
            PermissionName::InventoryCreate,
            PermissionName::InventoryCount,
            PermissionName::InventorySubmit,
            PermissionName::InventoryReopen,
            PermissionName::InventoryValidate,
            PermissionName::InventoryApply,
            PermissionName::InventoryCancel,
            PermissionName::InventoryClose,
            PermissionName::InventoryExport,
            PermissionName::UserActivitiesView,
        ]);
    }

    /**
     * @param  list<PermissionName>  $permissions
     * @return list<PermissionName>
     */
    private static function uniquePermissions(array $permissions): array
    {
        $unique = [];
        $seen = [];

        foreach ($permissions as $permission) {
            if (isset($seen[$permission->value])) {
                continue;
            }

            $seen[$permission->value] = true;
            $unique[] = $permission;
        }

        return $unique;
    }
}
