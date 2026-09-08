<?php

namespace App\Auth;

use App\Enums\PermissionName;
use InvalidArgumentException;

/**
 * Catalogue central des permissions MKD-Pro (Phase C / H).
 *
 * Métadonnées, libellés FR, statut legacy/canonique.
 * Ne modifie pas les attributions utilisateur — fondation pour AuthorizationService et RolePresets.
 */
final class PermissionCatalog
{
    /**
     * @return list<array{
     *     name: string,
     *     module: string,
     *     action: string,
     *     label: string,
     *     description: string,
     *     legacy: bool,
     *     canonical: string,
     *     sort: int
     * }>
     */
    public static function all(): array
    {
        $definitions = [];

        foreach (PermissionName::cases() as $permission) {
            $definitions[] = self::buildDefinition($permission);
        }

        usort(
            $definitions,
            static fn (array $left, array $right): int => [$left['module'], $left['sort'], $left['name']]
                <=> [$right['module'], $right['sort'], $right['name']],
        );

        return $definitions;
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return PermissionName::values();
    }

    /**
     * @return array{
     *     name: string,
     *     module: string,
     *     action: string,
     *     label: string,
     *     description: string,
     *     legacy: bool,
     *     canonical: string,
     *     sort: int
     * }
     */
    public static function find(PermissionName $permission): array
    {
        return self::buildDefinition($permission);
    }

    /**
     * @return array{
     *     name: string,
     *     module: string,
     *     action: string,
     *     label: string,
     *     description: string,
     *     legacy: bool,
     *     canonical: string,
     *     sort: int
     * }|null
     */
    public static function findByName(string $name): ?array
    {
        $permission = PermissionName::tryFromName($name);

        return $permission ? self::find($permission) : null;
    }

    public static function exists(string $name): bool
    {
        return PermissionName::tryFromName($name) !== null;
    }

    /**
     * @return list<array{
     *     name: string,
     *     module: string,
     *     action: string,
     *     label: string,
     *     description: string,
     *     legacy: bool,
     *     canonical: string,
     *     sort: int
     * }>
     */
    public static function forModule(string $module): array
    {
        return array_values(
            array_filter(
                self::all(),
                static fn (array $definition): bool => $definition['module'] === $module,
            ),
        );
    }

    public static function label(PermissionName|string $permission): string
    {
        return self::resolveDefinition($permission)['label'];
    }

    public static function module(PermissionName|string $permission): string
    {
        return self::resolveDefinition($permission)['module'];
    }

    public static function description(PermissionName|string $permission): string
    {
        return self::resolveDefinition($permission)['description'];
    }

    public static function isLegacy(PermissionName|string $permission): bool
    {
        return self::resolveDefinition($permission)['legacy'];
    }

    /**
     * Retourne le nom canonique cible (string).
     * Pour inventory.review → inventory.reopen.
     * Pour *.edit → *.update.
     */
    public static function canonicalName(PermissionName|string $permission): string
    {
        return self::resolveDefinition($permission)['canonical'];
    }

    /**
     * Retourne l'enum PermissionName canonique si elle existe en catalogue.
     */
    public static function canonical(PermissionName|string $permission): ?PermissionName
    {
        $canonicalName = self::canonicalName($permission);

        return PermissionName::tryFromName($canonicalName);
    }

    /**
     * @deprecated Phase H — inventory.reopen est désormais dans l'enum ; conserve pour API stable.
     * Retourne un nom canonique planifié non présent en enum, sinon null.
     */
    public static function plannedCanonicalName(PermissionName|string $permission): ?string
    {
        $canonicalName = self::canonicalName($permission);

        if (PermissionName::tryFromName($canonicalName) !== null) {
            return null;
        }

        return $canonicalName;
    }

    /**
     * @return array<string, string> legacy => canonical
     */
    public static function legacyMappings(): array
    {
        $mappings = [];

        foreach (self::all() as $definition) {
            if (! $definition['legacy']) {
                continue;
            }

            $mappings[$definition['name']] = $definition['canonical'];
        }

        return $mappings;
    }

    /**
     * @return list<string>
     */
    public static function modules(): array
    {
        return array_values(
            array_unique(
                array_map(
                    static fn (PermissionName $permission): string => self::parseName($permission->value)['module'],
                    PermissionName::cases(),
                ),
            ),
        );
    }

    /**
     * @return array{
     *     name: string,
     *     module: string,
     *     action: string,
     *     label: string,
     *     description: string,
     *     legacy: bool,
     *     canonical: string,
     *     sort: int
     * }
     */
    private static function buildDefinition(PermissionName $permission): array
    {
        $parsed = self::parseName($permission->value);
        $meta = self::metadata()[$permission->value];

        return [
            'name' => $permission->value,
            'module' => $parsed['module'],
            'action' => $parsed['action'],
            'label' => $meta['label'],
            'description' => $meta['description'],
            'legacy' => self::computeLegacy($parsed['module'], $parsed['action']),
            'canonical' => self::computeCanonicalName($permission->value),
            'sort' => $meta['sort'],
        ];
    }

    /**
     * @return array{module: string, action: string}
     */
    private static function parseName(string $name): array
    {
        $separator = strrpos($name, '.');

        if ($separator === false) {
            throw new InvalidArgumentException("Permission invalide : {$name}");
        }

        return [
            'module' => substr($name, 0, $separator),
            'action' => substr($name, $separator + 1),
        ];
    }

    private static function computeLegacy(string $module, string $action): bool
    {
        if ($action === 'edit') {
            return true;
        }

        return $module === 'inventory' && $action === 'review';
    }

    private static function computeCanonicalName(string $name): string
    {
        if ($name === PermissionName::InventoryReview->value) {
            return PermissionName::InventoryReopen->value;
        }

        if (str_ends_with($name, '.edit')) {
            return substr($name, 0, -strlen('.edit')).'.update';
        }

        return $name;
    }

    /**
     * @return array{
     *     name: string,
     *     module: string,
     *     action: string,
     *     label: string,
     *     description: string,
     *     legacy: bool,
     *     canonical: string,
     *     sort: int
     * }
     */
    private static function resolveDefinition(PermissionName|string $permission): array
    {
        if ($permission instanceof PermissionName) {
            return self::find($permission);
        }

        $definition = self::findByName($permission);

        if ($definition === null) {
            throw new InvalidArgumentException("Permission inconnue : {$permission}");
        }

        return $definition;
    }

    private static function permissionName(PermissionName|string $permission): string
    {
        return $permission instanceof PermissionName ? $permission->value : $permission;
    }

    /**
     * @return array<string, array{label: string, description: string, sort: int}>
     */
    private static function metadata(): array
    {
        $entries = [];

        foreach (self::moduleMetadata() as $module => $actions) {
            foreach ($actions as $action => [$label, $description, $sort]) {
                $entries["{$module}.{$action}"] = [
                    'label' => $label,
                    'description' => $description,
                    'sort' => $sort,
                ];
            }
        }

        return $entries;
    }

    /**
     * @return array<string, array<string, array{0: string, 1: string, 2: int}>>
     */
    private static function moduleMetadata(): array
    {
        return [
            'backups' => [
                'view' => ['Voir les sauvegardes', 'Permet de consulter la liste des sauvegardes.', 10],
                'create' => ['Créer une sauvegarde', 'Permet de lancer une nouvelle sauvegarde.', 20],
                'download' => ['Télécharger une sauvegarde', 'Permet de télécharger une archive de sauvegarde.', 30],
                'delete' => ['Supprimer une sauvegarde', 'Permet de supprimer une sauvegarde existante.', 40],
                'restore' => ['Restaurer une base depuis une sauvegarde (DB-only)', 'Permet de restaurer uniquement le dump SQL vers une base allow-listée (jamais gestion).', 50],
                'restore_files' => ['Restaurer les fichiers applicatifs', 'Opération séparée, confirmation FILES_RESTORE, jamais couplée au restore DB.', 55],
            ],
            'categories' => [
                'view' => ['Voir les catégories', 'Permet de consulter les catégories de produits.', 10],
                'create' => ['Créer une catégorie', 'Permet de créer une nouvelle catégorie.', 20],
                'edit' => ['Modifier une catégorie (legacy)', 'Accès au formulaire de modification (permission legacy).', 25],
                'update' => ['Modifier les catégories', 'Permet de modifier une catégorie existante.', 30],
                'delete' => ['Supprimer une catégorie', 'Permet de supprimer une catégorie.', 40],
            ],
            'company' => [
                'view' => ['Voir l\'entreprise', 'Permet de consulter les informations de l\'entreprise.', 10],
                'edit' => ['Modifier l\'entreprise (legacy)', 'Accès au formulaire entreprise (permission legacy).', 25],
                'update' => ['Mettre à jour l\'entreprise', 'Permet de modifier les informations et visuels de l\'entreprise.', 30],
            ],
            'customers' => [
                'view' => ['Voir les clients', 'Permet de consulter la liste et les fiches clients.', 10],
                'create' => ['Créer un client', 'Permet de créer un nouveau client.', 20],
                'edit' => ['Modifier un client (legacy)', 'Accès au formulaire client (permission legacy).', 25],
                'update' => ['Modifier les clients', 'Permet de modifier un client existant.', 30],
                'delete' => ['Supprimer un client', 'Permet de supprimer un client.', 40],
                'export' => ['Exporter les clients', 'Permet d\'exporter la liste des clients (PDF / Excel).', 50],
            ],
            'dashboard' => [
                'view' => ['Voir le tableau de bord', 'Permet d\'accéder au tableau de bord.', 10],
            ],
            'delivery-notes' => [
                'view' => ['Voir les bons de livraison', 'Permet de consulter les bons de livraison.', 10],
                'create' => ['Créer un bon de livraison', 'Permet de créer un bon de livraison.', 20],
                'edit' => ['Modifier un bon de livraison (legacy)', 'Accès au formulaire BL (permission legacy).', 25],
                'update' => ['Modifier les bons de livraison', 'Permet de modifier un bon de livraison.', 30],
                'delete' => ['Supprimer un bon de livraison', 'Permet de supprimer un bon de livraison.', 40],
                'validate' => ['Valider un bon de livraison', 'Permet de valider un bon de livraison.', 50],
                'download' => ['Télécharger un bon de livraison', 'Permet de télécharger un bon de livraison en PDF.', 60],
                'print' => ['Imprimer un bon de livraison', 'Permet d\'imprimer un bon de livraison.', 70],
            ],
            'expenses' => [
                'view' => ['Voir les dépenses', 'Permet de consulter les dépenses.', 10],
                'create' => ['Créer une dépense', 'Permet d\'enregistrer une nouvelle dépense.', 20],
                'edit' => ['Modifier une dépense (legacy)', 'Accès au formulaire dépense (permission legacy).', 25],
                'update' => ['Modifier les dépenses', 'Permet de modifier une dépense existante.', 30],
                'delete' => ['Supprimer une dépense', 'Permet de supprimer une dépense.', 40],
            ],
            'inventory' => [
                'view' => ['Consulter les inventaires', 'Permet de consulter les sessions d\'inventaire.', 10],
                'create' => ['Créer un inventaire', 'Permet de créer et démarrer une session d\'inventaire.', 20],
                'count' => ['Effectuer le comptage', 'Permet de compter les produits d\'une session.', 30],
                'submit' => ['Soumettre un inventaire', 'Permet de soumettre une session en revue.', 40],
                'review' => ['Réouvrir un inventaire (legacy)', 'Permission legacy pour la réouverture ; utiliser inventory.reopen.', 45],
                'reopen' => ['Réouvrir un inventaire', 'Permet de rouvrir le comptage depuis l\'état revue.', 46],
                'validate' => ['Valider un inventaire', 'Permet de valider une session d\'inventaire.', 50],
                'apply' => ['Appliquer un inventaire', 'Permet d\'appliquer les écarts au stock.', 60],
                'cancel' => ['Annuler un inventaire', 'Permet d\'annuler une session d\'inventaire.', 70],
                'close' => ['Clôturer un inventaire', 'Permet de clôturer une session d\'inventaire.', 80],
                'export' => ['Exporter les inventaires', 'Permet d\'exporter un inventaire (PDF / Excel).', 90],
            ],
            'products' => [
                'view' => ['Voir les produits', 'Permet de consulter les produits.', 10],
                'create' => ['Créer un produit', 'Permet de créer un nouveau produit.', 20],
                'edit' => ['Modifier un produit (legacy)', 'Accès au formulaire produit (permission legacy).', 25],
                'update' => ['Modifier les produits', 'Permet de modifier un produit existant.', 30],
                'delete' => ['Supprimer un produit', 'Permet de supprimer un produit.', 40],
            ],
            'purchase-orders' => [
                'view' => ['Voir les bons de commande', 'Permet de consulter les bons de commande.', 10],
                'create' => ['Créer un bon de commande', 'Permet de créer un bon de commande.', 20],
                'edit' => ['Modifier un bon de commande (legacy)', 'Accès au formulaire BC (permission legacy).', 25],
                'update' => ['Modifier les bons de commande', 'Permet de modifier un bon de commande.', 30],
                'delete' => ['Supprimer un bon de commande', 'Permet de supprimer un bon de commande.', 40],
                'download' => ['Télécharger un bon de commande', 'Permet de télécharger un bon de commande en PDF.', 50],
                'print' => ['Imprimer un bon de commande', 'Permet d\'imprimer un bon de commande.', 60],
            ],
            'quotes' => [
                'view' => ['Voir les devis', 'Permet de consulter les devis.', 10],
                'create' => ['Créer un devis', 'Permet de créer un nouveau devis.', 20],
                'edit' => ['Modifier un devis (legacy)', 'Accès au formulaire devis (permission legacy).', 25],
                'update' => ['Modifier les devis', 'Permet de modifier un devis existant.', 30],
                'delete' => ['Supprimer un devis', 'Permet de supprimer un devis.', 40],
                'download' => ['Télécharger un devis', 'Permet de télécharger un devis en PDF.', 50],
                'print' => ['Imprimer un devis', 'Permet d\'imprimer un devis.', 60],
            ],
            'sales' => [
                'view' => ['Voir les ventes', 'Permet de consulter les ventes.', 10],
                'create' => ['Créer une vente', 'Permet de créer une nouvelle vente.', 20],
                'edit' => ['Modifier une vente (legacy)', 'Accès au formulaire vente (permission legacy).', 25],
                'update' => ['Modifier les ventes', 'Permet de modifier une vente existante.', 30],
                'delete' => ['Supprimer une vente', 'Permet de supprimer une vente.', 40],
                'invoice' => ['Factures vente', 'Permet de télécharger ou imprimer les factures de vente.', 50],
            ],
            'suppliers' => [
                'view' => ['Voir les fournisseurs', 'Permet de consulter les fournisseurs.', 10],
                'create' => ['Créer un fournisseur', 'Permet de créer un nouveau fournisseur.', 20],
                'edit' => ['Modifier un fournisseur (legacy)', 'Accès au formulaire fournisseur (permission legacy).', 25],
                'update' => ['Modifier les fournisseurs', 'Permet de modifier un fournisseur existant.', 30],
                'delete' => ['Supprimer un fournisseur', 'Permet de supprimer un fournisseur.', 40],
                'export' => ['Exporter les fournisseurs', 'Permet d\'exporter la liste des fournisseurs.', 50],
            ],
            'user-activities' => [
                'view' => [
                    'Voir l\'activité des utilisateurs',
                    'Permet de consulter le journal commercial des opérations par utilisateur.',
                    10,
                ],
            ],
        ];
    }
}
