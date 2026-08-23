<?php

namespace App\Auth;

use App\Models\ActivityLog;
use App\Models\User;

/**
 * Agrège les données de lecture pour l'UI admin « Rôles & permissions ».
 * Ne duplique pas la logique métier : lit PermissionCatalog, RolePresets et ActivityLog.
 */
final class RbacUiPresenter
{
    private const MODULE_LABELS = [
        'products' => 'Produits',
        'categories' => 'Catégories',
        'customers' => 'Clients',
        'sales' => 'Ventes',
        'quotes' => 'Devis',
        'expenses' => 'Dépenses',
        'suppliers' => 'Fournisseurs',
        'purchase-orders' => 'Bons de commande',
        'delivery-notes' => 'Bons de livraison',
        'company' => 'Entreprise',
        'dashboard' => 'Tableau de bord',
        'inventory' => 'Inventaire',
        'backups' => 'Sauvegardes',
    ];

    private const ROLE_META = [
        User::ROLE_ADMIN => [
            'label' => 'Administrateur',
            'badge' => 'Accès complet',
            'description' => 'Accès à l’ensemble des fonctionnalités de l’application.',
            'detail' => 'L’administrateur dispose d’un accès global aux fonctionnalités autorisées par l’application (bypass des permissions).',
            'icon' => 'bi-shield-fill-check',
        ],
        User::ROLE_GESTIONNAIRE => [
            'label' => 'Gestionnaire',
            'badge' => 'Opérations & stock',
            'description' => 'Gère produits, achats, inventaire et opérations — sans accès aux ventes.',
            'detail' => 'Preset métier hors ventes (aucun sales.*). Inclut notamment produits, catégories, devis, dépenses, fournisseurs, bons de commande, bons de livraison, inventaire et tableau de bord.',
            'icon' => 'bi-clipboard-data',
        ],
        User::ROLE_VENDEUR => [
            'label' => 'Vendeur',
            'badge' => 'Commercial',
            'description' => 'Accès aux ventes, devis, clients et consultation des produits.',
            'detail' => 'Preset commercial : ventes, devis, produits (lecture), clients et tableau de bord.',
            'icon' => 'bi-cart3',
        ],
        User::ROLE_USER => [
            'label' => 'Utilisateur',
            'badge' => 'Personnalisé',
            'description' => 'Permissions attribuées individuellement par un administrateur.',
            'detail' => 'Aucun preset fixe : les permissions sont choisies au cas par cas lors de la création ou de la modification du compte.',
            'icon' => 'bi-person-gear',
        ],
    ];

    /**
     * @return array{
     *     stats: array{roles: int, permissions: int, users: int},
     *     roles: list<array<string, mixed>>,
     *     permissions: list<array<string, mixed>>,
     *     modules: list<array{key: string, label: string}>,
     *     auditEvents: list<array<string, mixed>>
     * }
     */
    public function forIndex(int $auditLimit = 15): array
    {
        $userCounts = User::query()
            ->selectRaw('role, COUNT(*) as aggregate')
            ->groupBy('role')
            ->pluck('aggregate', 'role')
            ->map(static fn ($count): int => (int) $count)
            ->all();

        $catalog = PermissionCatalog::all();
        $displayPermissions = [];
        $legacyPermissions = [];

        foreach ($catalog as $definition) {
            $row = [
                'name' => $definition['name'],
                'module' => $definition['module'],
                'action' => $definition['action'],
                'label' => $definition['label'],
                'description' => $definition['description'],
                'legacy' => $definition['legacy'],
                'canonicalName' => $definition['canonical'],
                'sort' => $definition['sort'],
                'sensitive' => $this->isSensitiveUi($definition['module'], $definition['action'], $definition['name']),
            ];

            if ($definition['legacy']) {
                $legacyPermissions[] = $row;
            } else {
                $displayPermissions[] = $row;
            }
        }

        $roles = [];
        foreach (RolePresets::roles() as $roleName) {
            $meta = self::ROLE_META[$roleName];
            $permissionNames = RolePresets::permissionNames($roleName);
            $isBypass = RolePresets::usesBypass($roleName);
            $modules = $this->modulesForPermissionNames($permissionNames);

            $roles[] = [
                'name' => $roleName,
                'label' => $meta['label'],
                'badge' => $meta['badge'],
                'description' => $meta['description'],
                'detail' => $meta['detail'],
                'icon' => $meta['icon'],
                'isBypass' => $isBypass,
                'isCustom' => $roleName === User::ROLE_USER,
                'permissions' => $permissionNames,
                'permissionCount' => $isBypass ? null : count($permissionNames),
                'usersCount' => $userCounts[$roleName] ?? 0,
                'modules' => $modules,
            ];
        }

        $modules = [];
        foreach (PermissionCatalog::modules() as $moduleKey) {
            $modules[] = [
                'key' => $moduleKey,
                'label' => self::MODULE_LABELS[$moduleKey] ?? $moduleKey,
            ];
        }

        return [
            'stats' => [
                'roles' => count($roles),
                'permissions' => count($displayPermissions),
                'users' => array_sum($userCounts),
            ],
            'roles' => $roles,
            'permissions' => $displayPermissions,
            'legacyPermissions' => $legacyPermissions,
            'modules' => $modules,
            'moduleLabels' => self::MODULE_LABELS,
            'auditEvents' => $this->recentAuditEvents($auditLimit),
        ];
    }

    /**
     * Classification purement UI (documentée) — n’affecte pas le backend.
     */
    private function isSensitiveUi(string $module, string $action, string $name): bool
    {
        if ($module === 'backups') {
            return true;
        }

        return in_array($action, [
            'delete',
            'validate',
            'apply',
            'cancel',
            'close',
            'export',
            'reopen',
        ], true) || str_contains($name, 'admin');
    }

    /**
     * @param  list<string>  $permissionNames
     * @return list<array{key: string, label: string}>
     */
    private function modulesForPermissionNames(array $permissionNames): array
    {
        $keys = [];

        foreach ($permissionNames as $name) {
            $definition = PermissionCatalog::findByName($name);
            if ($definition === null || $definition['legacy']) {
                continue;
            }
            $keys[$definition['module']] = true;
        }

        $modules = [];
        foreach (array_keys($keys) as $key) {
            $modules[] = [
                'key' => $key,
                'label' => self::MODULE_LABELS[$key] ?? $key,
            ];
        }

        return $modules;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentAuditEvents(int $limit): array
    {
        return ActivityLog::query()
            ->with(['user:id,name'])
            ->where('module', RbacAuditService::MODULE)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(static function (ActivityLog $log): array {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'actionLabel' => $log->action_label,
                    'description' => $log->description,
                    'actorName' => $log->user?->name,
                    'createdAt' => $log->created_at?->toIso8601String(),
                    'oldValues' => $log->old_values,
                    'newValues' => $log->new_values,
                ];
            })
            ->all();
    }
}
