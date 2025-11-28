<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Liste des ressources et leurs actions possibles
     */
    private array $resources = [
        'products' => [
            'view' => 'Voir les produits',
            'create' => 'Créer un produit',
            'edit' => 'Modifier un produit',
            'update' => 'Mettre à jour un produit',
            'delete' => 'Supprimer un produit',
        ],
        'categories' => [
            'view' => 'Voir les catégories',
            'create' => 'Créer une catégorie',
            'edit' => 'Modifier une catégorie',
            'update' => 'Mettre à jour une catégorie',
            'delete' => 'Supprimer une catégorie',
        ],
        'customers' => [
            'view' => 'Voir les clients',
            'create' => 'Créer un client',
            'edit' => 'Modifier un client',
            'update' => 'Mettre à jour un client',
            'delete' => 'Supprimer un client',
            'export' => 'Exporter les clients',
        ],
        'sales' => [
            'view' => 'Voir les ventes',
            'create' => 'Créer une vente',
            'edit' => 'Modifier une vente',
            'update' => 'Mettre à jour une vente',
            'delete' => 'Supprimer une vente',
            'invoice' => 'Télécharger/Imprimer les factures',
        ],
        'quotes' => [
            'view' => 'Voir les devis',
            'create' => 'Créer un devis',
            'edit' => 'Modifier un devis',
            'update' => 'Mettre à jour un devis',
            'delete' => 'Supprimer un devis',
            'download' => 'Télécharger un devis',
            'print' => 'Imprimer un devis',
        ],
        'expenses' => [
            'view' => 'Voir les dépenses',
            'create' => 'Créer une dépense',
            'edit' => 'Modifier une dépense',
            'update' => 'Mettre à jour une dépense',
            'delete' => 'Supprimer une dépense',
        ],
        'suppliers' => [
            'view' => 'Voir les fournisseurs',
            'create' => 'Créer un fournisseur',
            'edit' => 'Modifier un fournisseur',
            'update' => 'Mettre à jour un fournisseur',
            'delete' => 'Supprimer un fournisseur',
            'export' => 'Exporter les fournisseurs',
        ],
        'purchase-orders' => [
            'view' => 'Voir les bons de commande',
            'create' => 'Créer un bon de commande',
            'edit' => 'Modifier un bon de commande',
            'update' => 'Mettre à jour un bon de commande',
            'delete' => 'Supprimer un bon de commande',
            'download' => 'Télécharger un bon de commande',
            'print' => 'Imprimer un bon de commande',
        ],
        'delivery-notes' => [
            'view' => 'Voir les bons de livraison',
            'create' => 'Créer un bon de livraison',
            'edit' => 'Modifier un bon de livraison',
            'update' => 'Mettre à jour un bon de livraison',
            'delete' => 'Supprimer un bon de livraison',
            'validate' => 'Valider un bon de livraison',
            'download' => 'Télécharger un bon de livraison',
            'print' => 'Imprimer un bon de livraison',
            'invoice' => 'Gérer les factures/BL fournisseur',
        ],
        'company' => [
            'view' => 'Voir les informations de l\'entreprise',
            'edit' => 'Modifier les informations de l\'entreprise',
            'update' => 'Mettre à jour les informations de l\'entreprise',
        ],
        'dashboard' => [
            'view' => 'Voir le tableau de bord',
        ],
        'backups' => [
            'view' => 'Voir les sauvegardes',
            'create' => 'Créer une sauvegarde',
            'download' => 'Télécharger une sauvegarde',
            'delete' => 'Supprimer une sauvegarde',
            'restore' => 'Restaurer une sauvegarde',
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->resources as $resource => $actions) {
            foreach ($actions as $action => $description) {
                Permission::firstOrCreate(
                    [
                        'resource' => $resource,
                        'action' => $action,
                    ],
                    [
                        'name' => Permission::generateName($resource, $action),
                        'description' => $description,
                    ]
                );
            }
        }
    }
}
