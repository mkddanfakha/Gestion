# MKD-Pro — Architecture technique Inventaire & Fondation Multi-Magasins

> **Document de conception** — Phase audit / design + suivi d'implémentation.  
> **Date initiale :** 2026-08-21  
> **Dernière mise à jour :** 2026-08-21 (Phase 1 implémentée)

---

## 1. Résumé exécutif

MKD-Pro gère aujourd'hui le stock via une **colonne unique** `products.stock_quantity`, modifiée directement à **six points d'entrée** (ventes, devis→vente, BL validés, inventaire scan, CRUD produit, commande artisan). Il n'existe **ni magasin, ni stock par entrepôt, ni journal de mouvements, ni session d'inventaire**.

L'inventaire actuel (`StockInventoryController` + page Vue) est un **ajustement immédiat par scan code-barres** : il remplace la quantité en base sans session, sans écarts figés, sans verrouillage concurrentiel.

**Décision architecturale centrale :**

```
Stock actuel matérialisé (product_stocks.quantity)
        +
Journal immuable (stock_movements)
        =
Source de vérité fiable, performante et auditable
```

La V1 fonctionne avec **un seul magasin principal** (`stores.is_default = true`), mais toutes les tables portent un `store_id` pour activer le multi-magasins sans refonte ultérieure.

**Priorités d'implémentation :**

1. Fondation `stores` + `product_stocks` + migration des données existantes  
2. `StockService` centralisé + `stock_movements`  
3. Refactor des flux ventes / BL vers le service  
4. Module inventaire physique complet  
5. Douchette HID en mode unitaire dans le comptage  
6. Dashboard stock + activation multi-magasins  

---

## 2. État actuel du projet

### 2.1 Modèle Product

**Fichier :** `app/Models/Product.php`

Champs stock :

| Champ | Type | Rôle |
|---|---|---|
| `stock_quantity` | `integer`, défaut 0 | Quantité en stock (unique, globale) |
| `min_stock_level` | `integer`, défaut 0 | Seuil d'alerte stock bas |
| `location` | `string` nullable | Emplacement physique **dans le magasin** (étagère, rayon) — **≠ magasin** |

Relations : `category()`, `saleItems()`. Pas de lien vers achats, BL, mouvements.

Helper : `isLowStock()` → `stock_quantity <= min_stock_level`.

### 2.2 Où le stock est stocké

**Réponse :** uniquement sur `products.stock_quantity`. Aucune autre table.

Migration initiale : `database/migrations/2025_10_18_005102_create_products_table.php`.

### 2.3 Comment le stock est modifié

| # | Source | Fichier | Opération | Moment |
|---|---|---|---|---|
| 1 | Création vente | `SaleController.php` | `decrement` | Immédiat, statut `completed` |
| 2 | Mise à jour vente | `SaleController.php` | `increment` puis `decrement` | Restaure anciens items, recrée nouveaux |
| 3 | Suppression vente | `SaleController.php` | `increment` | Restaure avant delete |
| 4 | Devis → vente | `QuoteController.php` | `decrement` | Conversion, sans transaction |
| 5 | BL validé | `PurchaseOrderDeliveryService.php` | `increment` via `applyStockDelta` | Validation BL `pending → validated` |
| 6 | BL annulé (validé) | `PurchaseOrderDeliveryService.php` | `decrement` via delta négatif | Annulation |
| 7 | BL validé modifié | `PurchaseOrderDeliveryService.php` | delta | Ajustement quantités |
| 8 | Inventaire scan | `StockInventoryController.php` | `update` absolu | Remplace `stock_quantity` |
| 9 | CRUD produit | `ProductController.php` | create/update direct | Saisie manuelle |
| 10 | Commande artisan | `RefreshProductsStockExpirationCommand.php` | bulk update | Maintenance |

**Calcul vs modification directe :** le stock est **toujours modifié directement**, jamais recalculé depuis un historique.

### 2.4 Ventes et stock

- Vérification stock avant création (`stock_quantity < totalQuantity` → erreur).
- Décrément à la **création** de la vente, statut toujours `'completed'` (ligne ~235 `SaleController`).
- Le paiement (`payment_status: pending|partial|paid`) **n'influence pas** le stock.
- Édition : restitution virtuelle pour l'UI (`edit`), puis restore + delete items + recreate + decrement (`update`).
- Suppression : restaure le stock puis supprime la vente.
- **Pas de `lockForUpdate`**, **pas de transaction atomique** sur delete/update.
- Statut `cancelled` existe sur `Sale` mais n'est **pas utilisé** à la création pour restaurer le stock.

### 2.5 Bons de livraison (BL)

- Le contrôleur délègue à `PurchaseOrderDeliveryService`.
- BL créé en `pending` ; stock **non touché** tant que non validé.
- Validation : `lockForUpdate` sur BL + produit, idempotence, audit explicite via `ActivityLogger`.
- **Meilleur flux existant** — modèle de référence pour le futur `StockService`.

### 2.6 Bons de commande (BC)

- **Aucun impact stock.** Le stock entre uniquement via validation des BL liés.

### 2.7 Inventaire existant

**Backend :** `app/Http/Controllers/StockInventoryController.php`  
**Frontend :** `resources/js/pages/StockInventory/Index.vue`  
**Routes :** `stock-inventory.index`, `stock-inventory.count`

Comportement :
- Permission `products.view` (page) / `products.update` (comptage).
- Vendeurs bloqués (`hasRole('vendeur')`).
- Scan → `ProductBarcodeService::findByBarcode` → saisie quantité → **remplacement absolu** du stock.
- Audit : `ActivityLogger::logUpdate('Inventaire', $product, ...)`.
- **Pas de session**, pas d'items, pas d'écarts figés, pas de workflow.

### 2.8 Mouvements de stock

**Inexistants.** Seuls des logs audit partiels (`ActivityLog`) existent pour BL et inventaire scan.

### 2.9 Code-barres

Infrastructure mature :

| Composant | Rôle |
|---|---|
| `ProductBarcodeService` | Normalisation, lookup exact, disponibilité, payload JSON |
| `ProductController::findByBarcode` | Endpoint API lookup |
| `useProductBarcodeLookup` | Composable frontend (fetch API) |
| `useDocumentProductBarcode` | Ajout produit dans ventes/BC/BL |
| `BarcodeInput` + `barcodeKeyboardScanner.ts` | Douchette HID (clavier) |
| `ProductBarcodeField` | Create/Edit produit |
| `StockInventory/Index.vue` | Inventaire proto (douchette) |

### 2.10 Permissions (RBAC)

Modèle : `Permission` avec `{resource}.{action}`. Admin bypass.

Stock couvert indirectement par :
- `products.view/update` → CRUD stock, inventaire proto
- `sales.create/update/delete` → décrément/restaure
- `delivery-notes.validate` → entrée stock

Pas de permissions `inventory.*` ni `stock.*` dédiées.

Restriction vendeur : `stock_quantity` et `min_stock_level` **prohibited** à la mise à jour produit.

### 2.11 Audit

`ActivityLogger` + `ActivityLog` (immuable : update/delete bloqués en `booted()`).

Audit stock explicite : BL (`applyStockDelta`), inventaire scan. Ventes : audit facture sans détail mouvement stock.

### 2.12 Tests existants

| Fichier | Couverture |
|---|---|
| `PurchaseOrderDeliveryTest.php` | BL validation, idempotence, annulation, over-delivery — **exhaustif** |
| `StockInventoryTest.php` | Comptage barcode, leading zeros, 404 |
| `SalePaymentTest.php` | Paiements — stock initial seulement |
| Notifications | Alertes stock bas / rupture via `ProductObserver` |

**Gap majeur :** aucun test Feature ventes → stock (create/update/delete/convertToSale).

### 2.13 Entreprise

`Company::getInstance()` — singleton (une ligne `companies`). Pas de notion de magasin.

---

## 3. Problèmes actuels identifiés

| # | Problème | Gravité | Détail |
|---|---|---|---|
| P1 | Stock = colonne unique | Haute | Impossible multi-magasins, pas de traçabilité structurée |
| P2 | Pas de journal de mouvements | Haute | Réconciliation manuelle, audit incomplet |
| P3 | Concurrence ventes | Haute | Pas de `lockForUpdate` ; stock négatif silencieux possible |
| P4 | Update vente non atomique | Haute | Restore stock + delete items **hors** transaction |
| P5 | Delete vente non atomique | Moyenne | Restaure stock puis delete sans transaction |
| P6 | convertToSale sans transaction | Moyenne | Vente + décréments non atomiques |
| P7 | Inventaire = écrasement direct | Haute | Pas de session, pas d'écarts figés, conflit avec ventes concurrentes |
| P8 | Inventaire sans lock | Haute | Peut écraser des ventes en cours |
| P9 | Annulation BL sans vérif stock | Moyenne | `decrement` peut rendre stock négatif |
| P10 | Stock modifiable manuellement | Moyenne | CRUD produit permet modification directe sans mouvement |
| P11 | Pas de tests ventes/stock | Moyenne | Régression non détectée |
| P12 | Double sémantique « magasin » | Faible | `products.location` = étagère, pas entité magasin |
| P13 | Inventaire proto ≠ inventaire métier | Moyenne | `StockInventoryController` devra être remplacé/étendu |

---

## 4. Principes architecturaux

1. **Stock non modifiable directement** — toute variation passe par `StockService`.
2. **Stock matérialisé + journal immuable** — performance + traçabilité.
3. **Un produit + un magasin = un enregistrement** (`product_stocks` unique constraint).
4. **Multi-magasins by design, mono-magasin en V1** — `store_id` partout, UI magasin unique.
5. **Mouvements immuables** — pas de update/delete sur `stock_movements` (soft policy en application).
6. **Transactions + verrouillage pessimiste** — `DB::transaction` + `lockForUpdate` sur `product_stocks`.
7. **Réutiliser l'infrastructure code-barres** — pas de duplication lookup/normalisation.
8. **Compatibilité progressive** — migration dual-read puis bascule, sans suppression de `products.stock_quantity` immédiate.
9. **Simplicité V1** — pas de `reserved_quantity` tant que non requis.
10. **Séparation audit métier / journal stock** — `ActivityLog` pour actions utilisateur, `stock_movements` pour comptabilité stock.

---

## 5. Modèle de données cible

```
Company (existant, singleton)
   │
   └── Store (magasin)
           │
           ├── ProductStock (stock matérialisé par produit/magasin)
           │
           ├── StockMovement (journal immuable)
           │
           └── Inventory (session d'inventaire physique)
                   │
                   └── InventoryItem (ligne comptée)
```

**Product** reste le catalogue (prix, barcode, SKU, catégorie). Le stock sort de `products`.

---

## 6. Store / Magasin

### 6.1 Nommage

| Élément | Nom retenu | Justification |
|---|---|---|
| Modèle | `Store` | Standard Laravel, distinct de `products.location` |
| Table | `stores` | Cohérent avec conventions projet |
| UI | « Magasin » | Terme métier français déjà utilisé (`Emplacement du produit dans le magasin`) |

### 6.2 Table `stores`

| Colonne | Type | Contraintes | Description |
|---|---|---|---|
| `id` | bigint PK | | |
| `company_id` | FK → companies | NOT NULL, index | Lien entreprise (singleton V1) |
| `name` | string(255) | NOT NULL | Ex. « Magasin principal » |
| `code` | string(32) | UNIQUE par company | Code court : `MAIN`, `ABJ-01` |
| `address` | text | nullable | Adresse physique |
| `phone` | string(50) | nullable | |
| `is_active` | boolean | default true | Désactivation sans suppression |
| `is_default` | boolean | default false | Magasin par défaut (un seul true/company) |
| `deleted_at` | timestamp | nullable | Soft delete |
| `created_at` / `updated_at` | timestamps | | |

**Index :**
- `UNIQUE(company_id, code)`
- `INDEX(company_id, is_active)`
- Contrainte applicative : **un seul** `is_default = true` par `company_id`

### 6.3 Relations

| Entité | Relation |
|---|---|
| Company | `hasMany(Store)` |
| Store | `belongsTo(Company)`, `hasMany(ProductStock)`, `hasMany(StockMovement)`, `hasMany(Inventory)` |
| User | `belongsToMany(Store)` via pivot `store_user` (V1 : tous les users → magasin default) |
| Sale | `store_id` FK nullable → default store (V1) |
| DeliveryNote | `store_id` FK nullable → default store (V1) |
| Inventory | `store_id` FK NOT NULL |

### 6.4 Règles métier

- **Magasin principal :** créé par seeder/migration, `code = 'MAIN'`, `is_default = true`.
- **Désactivation :** `is_active = false` ; interdit si seul magasin actif.
- **Suppression :** soft delete ; interdit si stock > 0 ou inventaire ouvert.
- **V1 :** aucune UI de gestion magasins ; magasin default injecté côté backend.

### 6.5 Table pivot `store_user` (préparation multi-magasins)

| Colonne | Type |
|---|---|
| `store_id` | FK |
| `user_id` | FK |
| `is_default` | boolean |
| `created_at` | timestamp |

`UNIQUE(store_id, user_id)`

---

## 7. ProductStock

### 7.1 Table `product_stocks`

| Colonne | Type | Contraintes | Description |
|---|---|---|---|
| `id` | bigint PK | | |
| `store_id` | FK → stores | NOT NULL | |
| `product_id` | FK → products | NOT NULL | |
| `quantity` | integer | NOT NULL, default 0 | Stock matérialisé |
| `min_quantity` | integer | NOT NULL, default 0 | Seuil alerte (migré depuis `min_stock_level`) |
| `max_quantity` | integer | nullable | Plafond optionnel (V2+) |
| `created_at` / `updated_at` | timestamps | | |

**Contraintes :**
- `UNIQUE(store_id, product_id)` — **un enregistrement par couple**
- `INDEX(product_id)` — recherche par produit
- `INDEX(store_id, quantity)` — alertes stock bas / dashboard

### 7.2 Valeurs stockées vs calculées

| Valeur | Stockée | Calculée |
|---|---|---|
| `quantity` | ✅ | — |
| `min_quantity` | ✅ | — |
| `max_quantity` | ✅ (optionnel) | — |
| `available_quantity` | — | `quantity` (V1 sans réservation) |
| `reserved_quantity` | — | 0 en V1 ; table future si besoin |
| Stock global produit | — | `SUM(quantity) GROUP BY product_id` |
| Valeur stock | — | `quantity * products.cost_price` |

### 7.3 Migration depuis `products`

| Ancien | Nouveau |
|---|---|
| `products.stock_quantity` | `product_stocks.quantity` (store default) |
| `products.min_stock_level` | `product_stocks.min_quantity` (store default) |

`products.stock_quantity` conservé temporairement en **colonne miroir** (sync lecture seule ou dual-write) puis déprécié.

---

## 8. StockMovement

### 8.1 Table `stock_movements`

| Colonne | Type | Contraintes | Description |
|---|---|---|---|
| `id` | bigint PK | | |
| `store_id` | FK → stores | NOT NULL, index | |
| `product_id` | FK → products | NOT NULL, index | |
| `user_id` | FK → users | nullable, index | Utilisateur à l'origine |
| `type` | string(50) | NOT NULL, index | Enum `StockMovementType` |
| `direction` | enum: `in`,`out`,`adjustment`,`transfer` | NOT NULL | Catégorie mouvement |
| `quantity` | integer | NOT NULL, > 0 | Toujours positif ; direction portée par `direction`/`type` |
| `stock_before` | integer | NOT NULL | Snapshot avant |
| `stock_after` | integer | NOT NULL | Snapshot après |
| `reason` | string(500) | nullable | Commentaire libre |
| `source_type` | string | nullable, index | Morph class (Sale, DeliveryNote, Inventory, etc.) |
| `source_id` | bigint | nullable, index | Morph id |
| `metadata` | json | nullable | Données contextuelles (barcode scanné, etc.) |
| `created_at` | timestamp | NOT NULL | **Pas de updated_at** — immuable |

**Index composites :**
- `(store_id, product_id, created_at)` — historique produit/magasin
- `(source_type, source_id)` — mouvements d'un document
- `(type, created_at)` — rapports par type

**Politique d'immutabilité :** modèle sans `$timestamps` update ; `booted()` bloque update/delete (comme `ActivityLog`).

### 8.2 Champs retenus vs écartés

| Champ | Retenu | Raison |
|---|---|---|
| `stock_before` / `stock_after` | ✅ | Audit instantané sans recalcul |
| `direction` | ✅ | Filtrage rapide entrées/sorties |
| `quantity` absolue | ✅ | Évite ambiguïté signe |
| `updated_at` | ❌ | Immuabilité |
| `deleted_at` | ❌ | Pas de suppression ; contre-passation via mouvement inverse |

---

## 9. Inventory

### 9.1 Table `inventories`

| Colonne | Type | Contraintes | Description |
|---|---|---|---|
| `id` | bigint PK | | |
| `store_id` | FK → stores | NOT NULL, index | |
| `reference` | string(50) | UNIQUE | Ex. `INV-2026-0001` |
| `name` | string(255) | NOT NULL | Libellé session |
| `description` | text | nullable | |
| `status` | string(30) | NOT NULL, index | Enum `InventoryStatus` |
| `scheduled_at` | date | nullable | Date prévue |
| `started_at` | timestamp | nullable | Début comptage |
| `validated_at` | timestamp | nullable | Validation écarts |
| `applied_at` | timestamp | nullable | Application mouvements |
| `closed_at` | timestamp | nullable | Clôture |
| `created_by` | FK → users | NOT NULL | |
| `validated_by` | FK → users | nullable | |
| `closed_by` | FK → users | nullable | |
| `notes` | text | nullable | |
| `metadata` | json | nullable | Stats, filtres appliqués |
| `created_at` / `updated_at` | timestamps | | |
| `deleted_at` | timestamp | nullable | Soft delete (brouillon uniquement) |

### 9.2 Statuts (`InventoryStatus` enum)

| Statut | Code | Description |
|---|---|---|
| Brouillon | `draft` | Créé, non démarré |
| Comptage | `counting` | Comptage en cours |
| Contrôle | `review` | Revue écarts, produits non comptés |
| Validé | `validated` | Écarts approuvés, pas encore appliqués |
| Appliqué | `applied` | Mouvements générés |
| Clôturé | `closed` | Session terminée, lecture seule |
| Annulé | `cancelled` | Abandonné sans impact stock |

**Transitions autorisées :**

```
draft → counting → review → validated → applied → closed
  ↓         ↓        ↓
cancelled cancelled cancelled
```

### 9.3 Permissions par statut

| Action | Statuts autorisés | Permission |
|---|---|---|
| Créer | — | `inventory.create` |
| Démarrer comptage | `draft` | `inventory.count` |
| Saisir/modifier comptage | `counting` | `inventory.count` |
| Passer en contrôle | `counting` | `inventory.count` |
| Valider écarts | `review` | `inventory.validate` |
| Appliquer écarts | `validated` | `inventory.adjust` |
| Clôturer | `applied` | `inventory.close` |
| Annuler | `draft`, `counting`, `review` | `inventory.create` |
| Exporter | tous sauf `draft` | `inventory.export` |

---

## 10. InventoryItem

### 10.1 Table `inventory_items`

| Colonne | Type | Contraintes | Description |
|---|---|---|---|
| `id` | bigint PK | | |
| `inventory_id` | FK → inventories | NOT NULL, index | |
| `product_id` | FK → products | NOT NULL | |
| `quantity_expected` | integer | NOT NULL | **Figée** au démarrage comptage |
| `quantity_counted` | integer | nullable | Null = non compté |
| `variance` | integer | nullable | Calculé : `counted - expected` |
| `status` | string(20) | NOT NULL | `pending`, `counted`, `skipped`, `adjusted` |
| `counted_by` | FK → users | nullable | |
| `counted_at` | timestamp | nullable | |
| `note` | text | nullable | |
| `metadata` | json | nullable | Mode scan, barcode, etc. |
| `created_at` / `updated_at` | timestamps | | |

**Contraintes :**
- `UNIQUE(inventory_id, product_id)` — pas de doublon produit dans une session
- `INDEX(inventory_id, status)` — progression comptage

### 10.2 quantity_expected : figée ou dynamique ?

**Décision : FIGÉE au passage `draft → counting`.**

Raisons :
- Un inventaire de janvier ne doit pas changer si des ventes ont lieu en février avant clôture.
- Les écarts comparent « ce qu'on avait au moment du comptage » vs « ce qu'on a compté ».
- Les mouvements survenus **pendant** le comptage seront visibles en contrôle (alerte optionnelle) mais ne modifient pas `quantity_expected`.

**Workflow :**
1. Création inventaire : sélection produits (tous / catégorie / liste) — items créés avec `quantity_expected = product_stocks.quantity` au moment du **start counting**.
2. Pendant comptage : seul `quantity_counted` est modifiable.
3. À l'application : mouvement `inventory_adjustment` avec delta = `quantity_counted - product_stocks.quantity` **actuel** ou **expected** ?

**Règle d'application (recommandée) :**
- Delta appliqué = `quantity_counted - quantity_expected` (écart figé).
- Si des mouvements ont eu lieu entre comptage et application, le stock final sera `stock_actuel + delta_écart` — cohérent car l'écart mesure l'écart physique constaté.
- Alternative plus stricte : bloquer l'application si stock actuel ≠ stock au moment du dernier comptage → **trop rigide pour V1**.

### 10.3 Produit compté deux fois / scan répété

- `UNIQUE(inventory_id, product_id)` empêche deux lignes.
- Scan répété en **mode unitaire** : incrémente `quantity_counted` de +1 sur la même ligne.
- Scan répété en **mode quantité** : ouvre la ligne existante pour resaisie.

---

## 11. Types de mouvements

### 11.1 Enum `StockMovementType`

| Type | Direction | Description |
|---|---|---|
| `sale` | out | Sortie vente |
| `sale_reversal` | in | Annulation/modification vente (contre-passation) |
| `purchase_receipt` | in | Entrée BL validé |
| `purchase_receipt_reversal` | out | Annulation BL validé |
| `customer_return` | in | Retour client revendable |
| `customer_return_damaged` | adjustment | Retour endommagé (sortie ou mise au rebut) |
| `supplier_return` | out | Retour fournisseur |
| `manual_in` | in | Entrée manuelle |
| `manual_out` | out | Sortie manuelle |
| `inventory_adjustment` | adjustment | Écart inventaire appliqué |
| `loss` | out | Perte |
| `damage` | out | Casse |
| `expiration` | out | Péremption |
| `transfer_in` | transfer | Réception transfert inter-magasins |
| `transfer_out` | transfer | Envoi transfert inter-magasins |
| `correction` | adjustment | Correction administrative |
| `opening_balance` | in | Solde initial migration |

### 11.2 Convention de nommage

- **Enum PHP :** `StockMovementType` (PascalCase cases : `Sale`, `PurchaseReceipt`, …)
- **Valeur DB :** snake_case (`sale`, `purchase_receipt`, …) — aligné sur statuts existants (`partially_received`, etc.)
- **Direction :** champ séparé `direction` pour agrégations SQL simples

### 11.3 Classification

| Catégorie | Types |
|---|---|
| ENTRÉE (`in`) | `purchase_receipt`, `customer_return`, `manual_in`, `transfer_in`, `sale_reversal`, `opening_balance` |
| SORTIE (`out`) | `sale`, `supplier_return`, `manual_out`, `loss`, `damage`, `expiration`, `transfer_out`, `purchase_receipt_reversal` |
| AJUSTEMENT | `inventory_adjustment`, `correction`, `customer_return_damaged` |
| TRANSFERT | `transfer_in`, `transfer_out` (liés par metadata `transfer_id`) |

---

## 12. Source des mouvements

### 12.1 Stratégie retenue : relation polymorphe Laravel

```php
// stock_movements
$table->nullableMorphs('source'); // source_type, source_id
```

**Exemples :**

| Origine | source_type | source_id |
|---|---|---|
| Vente #123 | `App\Models\Sale` | 123 |
| BL #45 | `App\Models\DeliveryNote` | 45 |
| Inventaire #8 | `App\Models\Inventory` | 8 |
| Ajustement manuel | `null` | `null` (+ reason obligatoire) |

### 12.2 Avantages / inconvénients

| Approche | Avantages | Inconvénients |
|---|---|---|
| **Polymorphe (retenu)** | Idiomatique Laravel, extensible, requêtes `whereMorphedTo` | Pas de FK DB strictes, index composite nécessaire |
| Colonnes nullable multiples (`sale_id`, `bl_id`, …) | FK strictes | Explosion colonnes, non extensible |
| JSON `source` | Flexible | Non indexable proprement, requêtes lentes |

### 12.3 Mouvements liés (vente modifiée)

Une modification de vente génère :
1. Mouvements `sale_reversal` (restauration anciennes quantités)
2. Mouvements `sale` (nouvelles quantités)

Liés au même `source` (Sale) avec metadata `{ "operation": "update", "version": 2 }`.

---

## 13. Règles de calcul du stock

### 13.1 Source de vérité

| Option | Retenu |
|---|---|
| A. `products.stock_quantity` | ❌ Déprécié |
| B. Somme des mouvements | ❌ Seul — trop lent à l'échelle |
| C. `product_stocks.quantity` | ✅ Stock courant matérialisé |
| D. Hybride C + B | ✅ **Retenu** |

**Stratégie :**

```
product_stocks.quantity = stock affiché et vérifié
stock_movements         = journal auditable, jamais recalculé pour l'affichage courant
```

### 13.2 Cohérence

1. **Écriture atomique :** dans une transaction, lire `quantity` (lock), calculer `stock_after`, insérer mouvement, mettre à jour `product_stocks`.
2. **Vérification périodique (job optionnel) :** comparer `product_stocks.quantity` vs `opening_balance + SUM(mouvements)` par produit/magasin ; alerter si écart.
3. **Pas de UPDATE direct** sur `product_stocks` hors `StockService`.
4. **Migration initiale :** mouvement `opening_balance` par produit pour tracer le solde importé.

### 13.3 Lecture pour l'UI

- Liste produits : join `product_stocks` sur magasin actif (default V1).
- Dashboard : agrégations SQL sur `product_stocks`, pas sur mouvements.
- Historique produit : pagination sur `stock_movements`.

---

## 14. Transactions et concurrence

### 14.1 Risque

```
Stock = 5
Vente A (-3) et Vente B (-3) simultanées
→ sans verrou : stock = -1 silencieux
```

### 14.2 Stratégie recommandée

```php
DB::transaction(function () use ($storeId, $productId, $quantity, ...) {
    $stock = ProductStock::query()
        ->where('store_id', $storeId)
        ->where('product_id', $productId)
        ->lockForUpdate()
        ->firstOrFail();

    if ($strictMode && $stock->quantity < $quantity) {
        throw new InsufficientStockException(...);
    }

    $before = $stock->quantity;
    $after = $before - $quantity; // ou + selon direction

    $stock->update(['quantity' => $after]);

    StockMovement::create([
        'stock_before' => $before,
        'stock_after' => $after,
        // ...
    ]);
});
```

### 14.3 Règles

| Règle | Détail |
|---|---|
| Transaction | Toute mutation stock dans `DB::transaction` |
| Verrou | `lockForUpdate()` sur ligne `product_stocks` |
| Création lazy | Si pas de `product_stocks`, créer avec quantity=0 dans la transaction |
| Idempotence BL | Conserver pattern existant (`PurchaseOrderDeliveryService`) |
| Retry | Pas de retry auto ; message utilisateur clair |
| Deadlock | MySQL gère ; log + message générique |

### 14.4 Ventes multi-produits

Verrouiller les produits **dans l'ordre croissant des `product_id`** pour éviter les deadlocks.

### 14.5 État actuel vs cible

| Flux | Actuel | Cible |
|---|---|---|
| BL validation | ✅ lockForUpdate | Conserver via StockService |
| Vente create | ❌ pas de lock | StockService + lock |
| Vente update/delete | ❌ hors transaction | Transaction unique |
| Inventaire | ❌ update direct | InventoryService + lock à l'application |

---

## 15. Stock négatif

### 15.1 Modes

| Mode | Code | Comportement |
|---|---|---|
| Strict | `strict` | Refus si `quantity < delta` sortie |
| Tolérant | `tolerant` | Autorise négatif + notification/alerte |

### 15.2 Configuration

**Emplacement recommandé :** table `companies` (singleton) ou table dédiée `stock_settings` :

```sql
-- Option simple V1 (colonne sur companies)
allow_negative_stock BOOLEAN DEFAULT FALSE
stock_negative_mode ENUM('strict','tolerant') DEFAULT 'strict'
```

| Paramètre | Valeur défaut MKD-Pro | Justification |
|---|---|---|
| `stock_negative_mode` | `strict` | Commerçant : vente impossible si rupture |
| Modifiable par | Admin / permission `stock.configure` | Sensibilité métier |
| Vente | Appliquer mode au décrément | |
| Ajustement manuel | Admin peut forcer avec `manual_out` + reason | Traçabilité |
| Inventaire | Application écarts respecte le mode | Écart négatif large → validation admin |

### 15.3 Comportement vente (strict)

```
Stock disponible = 2, vente demande 5
→ HTTP 422 « Stock insuffisant pour {produit} »
→ aucun mouvement, aucune vente partielle silencieuse
```

---

## 16. Workflow inventaire

### 16.1 Étapes

| # | Étape | Statut | Actions |
|---|---|---|---|
| 1 | Brouillon | `draft` | Créer session, choisir magasin (default V1), nom, périmètre produits |
| 2 | Comptage | `counting` | Snapshot `quantity_expected`, saisie `quantity_counted` (douchette/recherche) |
| 3 | Contrôle | `review` | Synthèse écarts +/- , produits non comptés, validation manager |
| 4 | Validation | `validated` | Approbation écarts significatifs |
| 5 | Application | `applied` | Génération mouvements `inventory_adjustment` via StockService |
| 6 | Clôture | `closed` | Archivage lecture seule |

### 16.2 Règles métier

- Un seul inventaire `counting|review|validated` par magasin à la fois (contrainte applicative).
- Produits inactifs : exclus par défaut, inclus si explicitement demandé.
- Produits sans code-barres : comptables via recherche nom/SKU.
- Annulation : uniquement avant `applied` ; aucun mouvement stock.
- Inventaire clôturé : immuable.

### 16.3 Remplacement de l'inventaire proto

Le `StockInventoryController` actuel (ajustement direct) sera :
- **Phase 4 :** redirigé vers le workflow inventaire complet, ou
- Conservé temporairement comme « ajustement rapide » (`manual_in/out` via StockService) avec permission `stock.adjust` — **décision à valider**.

---

## 17. Douchette HID

### 17.1 Infrastructure existante (à réutiliser)

```
Douchette HID (clavier)
  → BarcodeInput / barcodeKeyboardScanner.ts
  → useProductBarcodeLookup → GET /products/barcode/{barcode}
  → ProductBarcodeService::findByBarcode (exact, actif)
```

**Ne pas dupliquer** la normalisation (`normalize`, zéros en tête préservés).

### 17.2 Modes de comptage

| Mode | Comportement | Avantages | Inconvénients |
|---|---|---|---|
| **Unitaire** | Chaque scan = +1 sur `quantity_counted` | Rapide, mobile, naturel douchette | Lent pour gros volumes homogènes |
| **Quantité** | Scan → sélection produit → saisie quantité | Précis gros volumes | Plus d'étapes |

### 17.3 Recommandation V1

**Implémenter d'abord le mode unitaire**, avec bascule optionnelle vers quantité sur la fiche produit compté.

Justification :
- Aligné avec la douchette en caisse (scan répété).
- Mobile-first : pas de clavier numérique obligatoire à chaque scan.
- Le mode quantité existe déjà dans l'inventaire proto (`StockInventory/Index.vue`) — le conserver comme mode secondaire.

### 17.4 Workflow cible comptage

```
Scan barcode
  → lookupProductByBarcode (existant)
  → si produit absent de l'inventaire : créer InventoryItem (expected figé) OU erreur si hors périmètre
  → mode unitaire : quantity_counted += 1
  → feedback visuel immédiat (flash vert, compteur)
  → focus retour sur BarcodeInput
```

---

## 18. Ventes

### 18.1 Flux actuel

- Stock décrémenté à la **création** (`status = completed`).
- Paiement indépendant du stock.
- Modification : restore + recreate.
- Suppression : restore puis delete.

### 18.2 Règle métier cible (unique)

| Événement | Impact stock |
|---|---|
| Création vente validée (`completed`) | Sortie immédiate (`sale`) |
| Modification vente | `sale_reversal` + `sale` (compensatoire) |
| Suppression vente | `sale_reversal` (restauration) |
| Annulation vente (`cancelled`) | `sale_reversal` si statut était `completed` |
| Paiement | **Aucun** impact stock |
| Brouillon vente | **N'existe pas** actuellement — si ajouté futur : pas de stock |

### 18.3 Questions métier

| Question | Réponse recommandée |
|---|---|
| Vente annulée restaure le stock ? | **Oui**, via mouvements `sale_reversal` |
| Modification crée des compensatoires ? | **Oui**, pas de modification silencieuse du stock |
| Suppression autorisée ? | **Oui** (permission `sales.delete`), avec reversal |
| Moment du décrément ? | **Création** (conserver comportement actuel, corriger atomicité) |

### 18.4 store_id

V1 : `sales.store_id = default store`. Futur : magasin actif utilisateur.

---

## 19. BL

### 19.1 Comportement actuel (à préserver fonctionnellement)

- BL `pending` : **aucun** stock.
- BL `validated` : entrée stock (`increment`).
- BL validé annulé : sortie stock (delta négatif).
- BL validé modifié : delta quantités.

### 19.2 Stratégie cible

Remplacer `applyStockDelta` par appels `StockService::receivePurchase($deliveryNote, $items)` :
- Type `purchase_receipt` / `purchase_receipt_reversal`
- Conserver `lockForUpdate` + idempotence
- `source` = `DeliveryNote`

### 19.3 Éviter double mouvement

- Vérifier statut BL avant application (pattern existant).
- Index `(source_type, source_id, type)` + contrainte applicative idempotence.
- Modification BL validé : mouvements delta, pas re-application complète.

---

## 20. Achats / réceptions

### 20.1 Règle

| Document | Impact stock |
|---|---|
| Bon de commande (PO) | **Aucun** |
| BL `pending` | **Aucun** |
| BL `validated` | **Entrée** |

### 20.2 Workflow cible

Conserver l'architecture actuelle `PurchaseOrderDeliveryService` en remplaçant les appels directs à `Product::increment/decrement` par `StockService`.

Futur : document « Réception autonome » sans PO → même type `purchase_receipt`, `source` = `DeliveryNote` standalone (déjà supporté).

---

## 21. Retours

### 21.1 Retour client

| Cas | Mouvement | Stock |
|---|---|---|
| Produit revendable | `customer_return` | +quantity |
| Produit endommagé | `customer_return_damaged` ou `damage` | 0 ou mise au rebut (configurable) |
| Quantité partielle | Mouvement proportionnel | Delta partiel |

**V1 :** pas de module retour dédié — ajustement manuel `customer_return` via permission `stock.adjust`. Module retour client = phase ultérieure.

### 21.2 Retour fournisseur

| Cas | Mouvement |
|---|---|
| Produit renvoyé | `supplier_return` (sortie) |
| Partiel | Delta partiel |
| Endommagé accepté par fournisseur | `supplier_return` |

---

## 22. Transferts futurs

### 22.1 Modèle anticipé (non implémenté V1)

Table `stock_transfers` :

| Colonne | Description |
|---|---|
| `reference` | `TRF-2026-0001` |
| `from_store_id` | Magasin source |
| `to_store_id` | Magasin destination |
| `status` | `draft`, `in_transit`, `received`, `cancelled` |
| `created_by`, timestamps | |

Table `stock_transfer_items` : `product_id`, `quantity`, `quantity_received`.

### 22.2 Mouvements

Transfert de 10 unités Riz, Magasin A → B :

```
Magasin A : transfer_out, quantity=10, metadata={transfer_id: X}
Magasin B : transfer_in,  quantity=10, metadata={transfer_id: X}
```

**V1 :** enums et metadata prévus ; tables et UI absents.

---

## 23. Permissions

### 23.1 Permissions proposées

| Permission | Description | Sensibilité |
|---|---|---|
| `inventory.view` | Voir liste inventaires et détail | Basse |
| `inventory.create` | Créer / annuler brouillon | Moyenne |
| `inventory.count` | Comptage (scan, saisie) | Moyenne |
| `inventory.validate` | Valider écarts en contrôle | Haute |
| `inventory.adjust` | Appliquer écarts au stock | **Critique** |
| `inventory.close` | Clôturer session | Haute |
| `inventory.export` | Exporter rapport inventaire | Basse |
| `stock.view` | Voir stock par magasin, historique mouvements | Basse |
| `stock.adjust` | Ajustement manuel (hors inventaire) | **Critique** |
| `stock.configure` | Mode stock négatif, seuils globaux | **Critique** |
| `stock.transfer` | Transferts inter-magasins | **Critique** (futur) |

### 23.2 Permissions supprimées / non créées

- Pas de `inventory.delete` séparé → couvert par `inventory.create` + statut brouillon.
- Pas de duplication avec `products.update` pour le stock — **déprécier** la modification directe `stock_quantity` dans ProductController.

### 23.3 Mapping rôles V1

| Rôle | Permissions |
|---|---|
| Admin | Toutes |
| Gérant | inventory.*, stock.view, stock.adjust |
| Magasinier | inventory.view, inventory.count |
| Vendeur | stock.view (lecture seule magasin default) — **pas** adjust/count |

---

## 24. Audit

### 24.1 Distinction ActivityLog vs StockMovement

| | ActivityLog | StockMovement |
|---|---|---|
| **But** | Qui a fait quoi dans l'application | Pourquoi le stock a changé |
| **Granularité** | Action utilisateur (CRUD, validation) | Delta quantitatif par produit |
| **Immutabilité** | ✅ | ✅ |
| **Consultation** | Audit admin, conformité | Historique stock, rapports |
| **Redondance** | Acceptable en complément | Source stock |

**Recommandation :** un mouvement stock **ne duplique pas** tout le détail dans ActivityLog. Logger dans ActivityLog l'**action** (« a appliqué l'inventaire INV-2026-001 »), le détail quantitatif reste dans `stock_movements`.

### 24.2 Actions à auditer (ActivityLog)

| Action | Module |
|---|---|
| Création inventaire | Inventaire |
| Démarrage comptage | Inventaire |
| Modification quantité comptée | Inventaire |
| Validation inventaire | Inventaire |
| Application écarts | Inventaire |
| Clôture | Inventaire |
| Ajustement manuel stock | Stock |
| Changement seuil min_quantity | Stock |
| Modification config stock négatif | Entreprise |

---

## 25. Services Laravel

### 25.1 Architecture retenue (2 services principaux)

#### `StockService`

**Responsabilité unique :** toute mutation de `product_stocks` + création `stock_movements`.

Méthodes indicatives :

```php
adjust(ProductStockKey $key, int $delta, StockMovementType $type, ?Model $source, ?User $user, ?string $reason, array $metadata = []): StockMovement
receivePurchase(DeliveryNote $note): void
dispatchSale(Sale $sale): void
reverseSale(Sale $sale, string $reason): void
manualAdjust(int $storeId, int $productId, int $targetQuantity, User $user, string $reason): StockMovement
getOrCreateStock(int $storeId, int $productId): ProductStock
```

#### `InventoryService`

**Responsabilité :** cycle de vie inventaire, délègue les écritures stock à `StockService`.

```php
create(array $data, User $user): Inventory
startCounting(Inventory $inventory): void  // snapshot expected
recordCount(Inventory $inventory, Product $product, int $quantity, User $user): InventoryItem
incrementCount(Inventory $inventory, Product $product, User $user): InventoryItem  // mode unitaire
submitForReview(Inventory $inventory): void
validate(Inventory $inventory, User $user): void
applyAdjustments(Inventory $inventory, User $user): void
close(Inventory $inventory, User $user): void
cancel(Inventory $inventory, User $user): void
```

### 25.2 Services non créés (éviter sur-ingénierie)

| Service écarté | Raison |
|---|---|
| `StockMovementService` | Fusionné dans StockService |
| `InventoryCountingService` | Fusionné dans InventoryService |
| `StockAvailabilityService` | Méthode `StockService::getAvailable()` suffit en V1 |

### 25.3 Helpers / Queries

- `StockQuery` ou scopes Eloquent sur `ProductStock` pour dashboard (KPI, stock bas).
- `ProductBarcodeService` inchangé.

---

## 26. Modèles / relations

### 26.1 Modèles

| Modèle | Table | Traits |
|---|---|---|
| `Store` | `stores` | SoftDeletes |
| `ProductStock` | `product_stocks` | — |
| `StockMovement` | `stock_movements` | Pas de update |
| `Inventory` | `inventories` | SoftDeletes |
| `InventoryItem` | `inventory_items` | — |

### 26.2 Relations Eloquent

```php
// Store
belongsTo(Company)
hasMany(ProductStock)
hasMany(StockMovement)
hasMany(Inventory)
belongsToMany(User)

// Product
hasMany(ProductStock)
hasMany(StockMovement)

// ProductStock
belongsTo(Store)
belongsTo(Product)

// StockMovement
belongsTo(Store)
belongsTo(Product)
belongsTo(User)
morphTo(source)

// Inventory
belongsTo(Store)
belongsTo(User, 'created_by')
hasMany(InventoryItem)

// InventoryItem
belongsTo(Inventory)
belongsTo(Product)
belongsTo(User, 'counted_by')
```

### 26.3 Enums PHP 8.1+

```php
enum StockMovementType: string { ... }
enum StockMovementDirection: string { case In = 'in'; case Out = 'out'; ... }
enum InventoryStatus: string { case Draft = 'draft'; ... }
enum InventoryItemStatus: string { case Pending = 'pending'; ... }
enum StockNegativeMode: string { case Strict = 'strict'; case Tolerant = 'tolerant'; }
```

### 26.4 Scopes utiles

```php
// ProductStock
scopeForStore($q, $storeId)
scopeLowStock($q)  // quantity <= min_quantity
scopeOutOfStock($q) // quantity <= 0

// StockMovement
scopeForProduct($q, $productId)
scopeForStore($q, $storeId)
scopeOfType($q, StockMovementType $type)

// Inventory
scopeOpen($q) // counting, review, validated
scopeForStore($q, $storeId)
```

### 26.5 Casts

- `StockMovement.metadata` → `array`
- `Inventory.metadata` → `array`
- Enums castés sur `type`, `status`, `direction`

---

## 27. Migrations proposées

> Design uniquement — ne pas exécuter dans cette phase.

### 27.1 `create_stores_table`

Voir section 6. FK `company_id → companies.id`.

### 27.2 `create_store_user_table`

Pivot V1 prep. FK cascade.

### 27.3 `create_product_stocks_table`

Voir section 7. `UNIQUE(store_id, product_id)`.

### 27.4 `create_stock_movements_table`

Voir section 8. Pas de `updated_at`.

### 27.5 `create_inventories_table`

Voir section 9.

### 27.6 `create_inventory_items_table`

Voir section 10.

### 27.7 `add_store_id_to_sales_table`

`store_id` FK nullable → stores, index. Backfill default store.

### 27.8 `add_store_id_to_delivery_notes_table`

Idem.

### 27.9 `add_stock_settings_to_companies_table`

`stock_negative_mode` string default `'strict'`.

### 27.10 `seed_default_store_and_migrate_stock`

Migration data :
1. Insérer magasin `MAIN`
2. Pour chaque produit : créer `product_stocks`
3. Insérer mouvements `opening_balance`
4. Backfill `store_id` sur sales/delivery_notes

### 27.11 Index critiques performance

```sql
-- product_stocks
INDEX(store_id, product_id) UNIQUE
INDEX(store_id, quantity)

-- stock_movements
INDEX(store_id, product_id, created_at)
INDEX(source_type, source_id)
INDEX(product_id, created_at)

-- inventory_items
INDEX(inventory_id, status)
UNIQUE(inventory_id, product_id)

-- products (existant)
INDEX(barcode) -- déjà ajouté 2026_08_17
```

---

## 28. Migration des données existantes

### 28.1 Plan (sans suppression)

| Étape | Action |
|---|---|
| 1 | Créer table `stores`, insérer « Magasin principal » (`MAIN`, `is_default=true`) |
| 2 | Créer table `product_stocks` |
| 3 | `INSERT INTO product_stocks (store_id, product_id, quantity, min_quantity) SELECT default_store, id, stock_quantity, min_stock_level FROM products` |
| 4 | Créer mouvements `opening_balance` miroir |
| 5 | Vérification : `SUM(product_stocks.quantity)` = `SUM(products.stock_quantity)` |
| 6 | Ajouter `store_id` sur sales/delivery_notes, backfill |
| 7 | Basculer lectures UI vers `product_stocks` (magasin default) |
| 8 | Basculer écritures vers `StockService` |
| 9 | Dual-write temporaire `products.stock_quantity` = sync depuis `product_stocks` (compatibilité) |
| 10 | Déprécier champs `products.stock_quantity` / `min_stock_level` (commentaires, validation interdit direct) |
| 11 | **Ne pas supprimer** les colonnes legacy avant stabilisation (≥ 1 release) |

### 28.2 Rollback

- Tant que dual-write actif : rollback possible depuis `products.stock_quantity`.
- Après bascule complète : restaurer depuis backup ou recalculer depuis mouvements.

---

## 29. Performance

### 29.1 Volumes cibles

| Volume | Stratégie |
|---|---|
| 10 000 produits | Join `product_stocks` indexé ; pagination |
| 100 000 mouvements | Pagination historique ; pas d'agrégation live globale |
| 1 000 000 mouvements | Partition future par `created_at` (année) si nécessaire ; archivage |

### 29.2 Règles

| Besoin | Approche |
|---|---|
| Affichage stock courant | `product_stocks.quantity` — jamais SUM(mouvements) |
| Dashboard KPI | Requêtes agrégées sur `product_stocks` |
| Historique mouvements | Pagination `created_at DESC`, eager load `user`, `source` |
| Recherche barcode | Index `products.barcode` + lookup exact (existant) |
| Inventaire comptage | Chargement par batch (pagination / lazy) ; pas 10k lignes d'un coup |
| Valeur stock | `SUM(product_stocks.quantity * products.cost_price)` SQL |
| Eager loading | `Product::with(['productStocks' => fn($q) => $q->where('store_id', $activeStoreId)])` |

### 29.3 Anti-patterns interdits

- Recalcul complet stock à chaque page.
- Charger tous les mouvements en mémoire.
- N+1 sur liste produits (toujours eager load stock du magasin actif).

---

## 30. UX Desktop

### 30.1 Page Inventaire (dashboard stock)

**Route suggérée :** `/stock` ou `/inventory/dashboard`

**KPI (cartes) :**
- Produits en stock (count quantity > 0)
- Stock faible (quantity <= min_quantity)
- Ruptures (quantity = 0)
- Stock négatif (quantity < 0, mode tolerant)
- Valeur stock (cost_price × quantity)

**Table :**

| Colonne | Source |
|---|---|
| Produit | products.name |
| Code-barres | products.barcode |
| Stock | product_stocks.quantity |
| Seuil | product_stocks.min_quantity |
| Statut | calculé (OK / Bas / Rupture / Négatif) |
| Valeur | quantity × cost_price |
| Dernier mouvement | subquery stock_movements.created_at |
| Actions | Voir historique, ajuster (permission) |

**Filtres :** catégorie, statut stock, recherche nom/SKU/barcode (BarcodeInput).

### 30.2 Page nouvel inventaire

**Route :** `/inventory/create`

- Magasin (select, default disabled en V1)
- Nom, date planifiée, description
- Périmètre : tous produits / catégories / sélection manuelle
- Bouton « Démarrer » → statut `counting`

### 30.3 Page comptage

**Route :** `/inventory/{id}/count`

- Barre recherche + BarcodeInput (douchette)
- Liste items : produit, expected, counted, écart, statut
- Progression : `counted / total`
- Mode unitaire / quantité (toggle)
- Mobile-first responsive (voir section 31)

### 30.4 Page contrôle

**Route :** `/inventory/{id}/review`

- Totaux : écarts +, écarts -, non comptés
- Filtres par magnitude écart
- Actions : valider, retour comptage, annuler

### 30.5 Page détail inventaire

**Route :** `/inventory/{id}`

- Onglets : résumé, items, mouvements générés, audit ActivityLog
- Timeline statuts

### 30.6 Page détail produit (extension)

- Onglet stock : quantité par magasin (V1 : une ligne), historique mouvements paginé, inventaires passés.

---

## 31. UX Mobile

### 31.1 Principes comptage mobile-first

- **BarcodeInput** sticky en haut, toujours focus après action.
- Cartes produit (pas tableau wide) : nom, barcode, expected, counted, +/- .
- Boutons tactiles larges (min 44px).
- Feedback scan : flash couleur + vibration optionnelle.
- Mode unitaire par défaut : scan = +1, compteur visible.
- Édition quantité : modal bottom sheet.
- Progression visible (barre fixe bas d'écran).

### 31.2 Hors périmètre

- Scanner caméra (explicitement exclu).

---

## 32. Gestion des erreurs

| Cas | Comportement |
|---|---|
| Produit supprimé | Blocage comptage ; item marqué `skipped` avec note |
| Produit désactivé | Exclu par défaut ; warning si scan |
| Code-barres inconnu | Message « Produit non trouvé » ; focus scan (existant) |
| Produit sans code-barres | Recherche nom/SKU |
| Stock insuffisant (vente) | 422 explicite, aucune mutation |
| Deux users même inventaire | Optimistic lock (`inventory.updated_at`) ou lock item ; dernier write gagne avec log |
| Inventaire clôturé | 403 lecture seule |
| Vente concurrente pendant inventaire | Autorisée ; expected figé ; alerte en contrôle si écart runtime |
| Produit compté deux fois | UNIQUE constraint ; mode unitaire incrémente |
| Scan répété | +1 (unitaire) |
| Quantité négative saisie | Validation `min:0` |
| Quantité décimale | **Interdit** — `integer` partout (cohérent avec schéma actuel) |
| Annulation inventaire | Sans mouvement si avant `applied` |
| Échec transaction | Rollback complet, message utilisateur |

---

## 33. Tests

### 33.1 Unit tests

| Sujet | Cas |
|---|---|
| StockService::adjust | Entrée, sortie, strict mode, tolerant mode |
| StockService::adjust | stock_before/stock_after corrects |
| InventoryService | Calcul variance |
| InventoryService | Transitions statut invalides rejetées |
| Enums | Sérialisation DB |

### 33.2 Feature tests

| Sujet | Cas |
|---|---|
| Vente → stock | Create décrément, delete restore, update compensatoire |
| Devis → vente | Conversion atomique |
| BL → stock | Validation entrée, annulation sortie (existant + StockService) |
| Inventaire | Workflow complet draft → closed |
| Inventaire | Application génère bons mouvements |
| Permissions | Vendeur bloqué sur adjust/count |
| Migration data | Quantités préservées |

### 33.3 Concurrency tests

```php
// Parallel: two sales same product, stock=5, each qty=3
// Assert: one succeeds, one fails 422, final stock=2
```

Utiliser `ParallelTesting` ou processus séparés + `lockForUpdate` assertion.

### 33.4 Barcode tests

| Cas | Fichier suggéré |
|---|---|
| Scan HID simulé (POST clavier) | Extend `StockInventoryTest` |
| Recherche exacte | Existant `ProductBarcodeTest` |
| Code inconnu 404 | Existant |
| Leading zeros | Existant `StockInventoryTest` |
| Doublon barcode | Existant `ProductBarcodeFieldTest` |

### 33.5 Regression tests

- Tous tests `PurchaseOrderDeliveryTest` doivent passer après refactor StockService.
- Notifications stock bas (`ProductObserver`) → brancher sur `ProductStock`.
- ProductIndex, SaleForm, autocomplete — stock affiché depuis `product_stocks`.

---

## 34. Sécurité

| Risque | Mitigation |
|---|---|
| Autorisation serveur | `$this->checkPermission()` sur chaque action ; jamais confiance UI seule |
| Mass assignment | `$fillable` restrictif ; pas de `quantity` modifiable via Product update |
| Validation | FormRequests ; integers, min:0, exists:products,id |
| Manipulation IDs | Policy `InventoryPolicy::count($user, $inventory)` vérifie store access |
| Accès inter-magasins | Middleware `EnsureStoreAccess` (V1 : default store ; futur : pivot store_user) |
| Clôture inventaire | Statut serveur vérifié avant toute mutation |
| Modification mouvements | Modèle bloque update/delete |
| IDOR inventaire | Scope queries par magasins autorisés |
| CSRF | Déjà en place (Inertia/fetch) |

---

## 35. Fondation multi-magasins

### 35.1 Prévu maintenant (structure)

| Élément | Statut |
|---|---|
| Table `stores` + magasin default | Maintenant |
| `store_id` sur stocks, mouvements, inventaires | Maintenant |
| `store_id` sur sales, delivery_notes | Maintenant (backfill default) |
| Pivot `store_user` | Maintenant (seed all users → default) |
| Enums transfer_in/out | Maintenant |
| StockService paramétré par storeId | Maintenant |

### 35.2 Plus tard (UI / features)

| Élément | Phase |
|---|---|
| CRUD magasins | Phase 7 |
| Sélecteur magasin actif (session) | Phase 7 |
| Affectation users/magasins UI | Phase 7 |
| Permissions par magasin | Phase 7 |
| Transferts inter-magasins | Phase 7 |
| Rapports consolidés multi-magasins | Phase 6-7 |
| Stock global vue produit | Phase 6 |

---

## 36. Plan d'implémentation

### PHASE 1 — Fondation multi-magasins + stock matérialisé

- Migrations : `stores`, `product_stocks`, `store_user`
- Seeder magasin principal
- Migration données depuis `products.stock_quantity`
- Modèles `Store`, `ProductStock`
- Middleware magasin default
- Adapter affichage stock (Products Index, autocomplete payload)
- Tests migration + affichage

### PHASE 2 — Stock movements + StockService

- Migration `stock_movements`
- Enum `StockMovementType`
- `StockService` complet avec transactions/locks
- Mouvements `opening_balance`
- Tests unitaires StockService + concurrence

### PHASE 3 — Intégration ventes / achats / BL

- Refactor `SaleController`, `QuoteController` → StockService
- Refactor `PurchaseOrderDeliveryService` → StockService
- Interdire modification directe stock dans `ProductController`
- Tests Feature ventes + regression BL
- Config stock négatif strict

### PHASE 4 — Inventaire physique

- Migrations `inventories`, `inventory_items`
- `InventoryService` + workflow complet
- Permissions `inventory.*`
- Pages : create, count, review, detail
- Remplacement/evolution `StockInventoryController`
- Tests workflow complet

### PHASE 5 — Douchette dans inventaire

- Mode unitaire scan +1
- Mode quantité (reuse `StockInventory/Index.vue` patterns)
- Intégration `BarcodeInput` + `useProductBarcodeLookup`
- Tests barcode inventaire

### PHASE 6 — Dashboard et rapports

- Page KPI stock
- Historique mouvements par produit
- Export inventaire
- Valeur stock

### PHASE 7 — Activation multi-magasins

- UI gestion magasins
- Magasin actif utilisateur
- Transferts (`stock_transfers`)
- Rapports par magasin / consolidés

---

## 37. Risques

| Risque | Impact | Mitigation |
|---|---|---|
| Régression ventes stock | Haut | Phase 3 + tests Feature exhaustifs |
| Dual-write désynchronisé | Moyen | Job vérification + alerte |
| Performance historique | Moyen | Index + pagination |
| Complexité inventaire vs proto | Moyen | Migration progressive, formation users |
| Concurrence non testée | Haut | Tests parallèles Phase 2 |
| Scope creep multi-magasins | Moyen | V1 strict mono-magasin UI |
| Confusion location vs store | Faible | Documentation, labels UI |

---

## 38. Décisions recommandées

| # | Décision | Choix |
|---|---|---|
| D1 | Source de vérité stock | `product_stocks.quantity` + journal mouvements |
| D2 | Stock modifiable directement | **Interdit** (sauf migration/outil admin) |
| D3 | Mouvements immuables | Oui |
| D4 | Stock négatif défaut | Strict |
| D5 | Moment décrément vente | Création vente `completed` |
| D6 | quantity_expected inventaire | Figé au démarrage comptage |
| D7 | Mode douchette V1 | Unitaire (+1/scan) |
| D8 | Nom entité magasin | `Store` / table `stores` |
| D9 | Inventaire proto | Remplacé par workflow complet Phase 4 |
| D10 | Permissions | Ressources `inventory.*` et `stock.*` |
| D11 | Audit mouvements | StockMovement = détail ; ActivityLog = action |
| D12 | Colonnes legacy products | Conservées temporairement, dual-write puis dépréciation |
| D13 | Quantités décimales | Non (integer, cohérent existant) |
| D14 | reserved_quantity | Reporté V2+ |

---

## Matrice des décisions

| Sujet | Décision recommandée | Justification | Maintenant / Plus tard |
|---|---|---|---|
| Stock matérialisé | `product_stocks.quantity` | Performance affichage 10k produits | **Maintenant** |
| Mouvements immuables | Table `stock_movements` sans update | Traçabilité, audit, réconciliation | **Maintenant** |
| Stock négatif | Mode `strict` par défaut | Commerçant pro : pas de vente sans stock | **Maintenant** (config) |
| Inventaire | Workflow 6 états + items figés | Proto actuel insuffisant (pas de session) | Structure Phase 4 |
| Douchette | Mode unitaire d'abord | Mobile, rapide, aligné caisse | Phase 5 |
| Magasin | Table `stores`, 1 default | Fondation multi-magasins sans UI | **Maintenant** |
| Transferts | Enums + metadata, pas de tables | Anticipation sans complexité V1 | Structure now, UI Phase 7 |
| Permissions | `inventory.*`, `stock.*` | Granularité, séparation vendeur | Phase 4 (inventory), Phase 2-3 (stock) |
| Audit | ActivityLog + StockMovement distincts | Rôles complémentaires, pas redondance lourde | **Maintenant** |
| Concurrence | `DB::transaction` + `lockForUpdate` | Pattern BL existant à généraliser | **Maintenant** |
| Migration stock existant | Dual-write puis bascule | Zéro perte données | **Maintenant** |
| products.stock_quantity | Déprécier, ne pas supprimer | Compatibilité progressive | Maintenant → supprimer plus tard |
| store_id sur ventes/BL | Backfill default | Traçabilité future | **Maintenant** |
| reserved_quantity | Non implémenté | Pas de besoin actuel | Plus tard |
| Scanner caméra | Hors périmètre | Décision produit existante | Jamais (inventaire) |
| Retours client/fournisseur | Mouvements types définis, pas de module | Extensibilité | Plus tard |
| ProductObserver | Brancher sur ProductStock | Notifications stock bas | Phase 1-2 |

---

## Annexe A — Fichiers analysés

### Backend PHP

| Fichier | Rôle analysé |
|---|---|
| `app/Models/Product.php` | Champs stock, relations, isLowStock |
| `app/Models/Sale.php` | Statuts, relations, absence logique stock |
| `app/Models/Company.php` | Singleton entreprise |
| `app/Models/User.php` | Rôles, permissions |
| `app/Models/ActivityLog.php` | Immutabilité audit |
| `app/Models/Permission.php` | RBAC |
| `app/Http/Controllers/ProductController.php` | CRUD stock, restrictions vendeur, barcode |
| `app/Http/Controllers/SaleController.php` | Décrément/restaure stock |
| `app/Http/Controllers/QuoteController.php` | convertToSale décrément |
| `app/Http/Controllers/StockInventoryController.php` | Inventaire proto |
| `app/Http/Controllers/DeliveryNoteController.php` | Délégation service BL |
| `app/Services/PurchaseOrderDeliveryService.php` | applyStockDelta, lockForUpdate |
| `app/Services/ProductBarcodeService.php` | Lookup, normalisation |
| `app/Services/ActivityLogger.php` | Audit |
| `app/Observers/ProductObserver.php` | Notifications stock |
| `routes/web.php` | Routes stock-inventory, products.barcode |

### Migrations

| Fichier |
|---|
| `database/migrations/2025_10_18_005102_create_products_table.php` |
| `database/migrations/2025_11_08_234456_add_location_to_products_table.php` |
| `database/migrations/2026_08_17_000003_add_barcode_index_to_products_table.php` |

### Frontend Vue/TS

| Fichier | Rôle analysé |
|---|---|
| `resources/js/pages/StockInventory/Index.vue` | Inventaire proto douchette |
| `resources/js/pages/Products/Index.vue` | Affichage stock, recherche barcode |
| `resources/js/pages/Products/Create.vue` | Saisie stock manuelle |
| `resources/js/pages/Products/Edit.vue` | Saisie stock, restriction vendeur UI |
| `resources/js/composables/useProductBarcodeLookup.ts` | Lookup API |
| `resources/js/composables/useDocumentProductBarcode.ts` | Ventes/BC/BL |
| `resources/js/components/BarcodeInput.vue` | Douchette HID |
| `resources/js/components/products/ProductBarcodeField.vue` | Create/Edit barcode |

### Tests

| Fichier |
|---|
| `tests/Feature/PurchaseOrderDeliveryTest.php` |
| `tests/Feature/StockInventoryTest.php` |
| `tests/Feature/ProductBarcodeTest.php` |
| `tests/Feature/ProductBarcodeFieldTest.php` |
| `tests/Feature/ProductIndexSearchTest.php` |
| `tests/Feature/SalePaymentTest.php` |

### Seeders

| Fichier |
|---|
| `database/seeders/PermissionSeeder.php` |
| `database/seeders/ProductSeeder.php` |

---

## Annexe B — Conflits avec l'architecture actuelle

| Conflit | Détail | Résolution proposée |
|---|---|---|
| Stock sur Product | Toute l'UI lit `product.stock_quantity` | Adapter progressivement via accessor ou prop Inertia |
| Inventaire proto | Écrasement direct incompatible workflow cible | Remplacer Phase 4 |
| SaleController patterns | Update/delete non transactionnels | Refactor Phase 3 |
| products.location | Sémantique « étagère » vs magasin | Conserver ; ne pas confondre avec Store |
| Permission products.update | Couvre stock + inventaire proto | Scinder stock.adjust / inventory.* |
| ProductObserver | Écoute Product.stock_quantity | Migrer vers ProductStock Phase 2 |
| Payload barcode JSON | Retourne stock_quantity produit | Retourner stock du magasin actif |

---

## Annexe C — Décisions nécessitant validation métier

| # | Question | Recommandation |
|---|---|---|
| V1 | Conserver un « ajustement rapide » hors inventaire (proto actuel) ? | Non — tout via inventaire ou stock.adjust |
| V2 | Bloquer ventes pendant inventaire ouvert ? | Non — expected figé suffit |
| V3 | Supprimer un jour products.stock_quantity ? | Oui, après ≥ 1 release stable |
| V4 | Mode tolerant stock négatif proposé aux admins ? | Oui, option cachée settings |
| V5 | Inventaire partiel (par catégorie) en V1 ? | Oui |
| V6 | Coût moyen pondéré vs dernier prix achat sur BL ? | Conserver dernier prix (comportement actuel) |

---

## Phase 1 — Foundation implemented

**Statut :** implémentée le 2026-08-21.

### Tables créées

| Table | Description |
|---|---|
| `stores` | Magasins rattachés à une `Company` |
| `product_stocks` | Stock matérialisé par couple `(product_id, store_id)` |

### Modèles et relations

```
Company
   │ hasMany
   ▼
Store ── hasMany ──► ProductStock ◄── belongsTo ── Product
   │                      │
   └── belongsTo ── Company
```

- `Store::ensureDefaultForCompany()` — crée/récupère le magasin `MAIN` (idempotent).
- `Store::initializeProductStocksForPrimaryCompany()` — copie `products.stock_quantity` vers `product_stocks` (idempotent, sans écrasement).
- `Product::productStocks()`, `Product::stockForStore($storeId)` — helper lecture seule, pas de création implicite.
- `ProductStock::assertStoreCompatibleWithProduct()` — empêche un magasin d'une autre entreprise (via `Company::getInstance()`).

### Migrations

| Fichier | Rôle |
|---|---|
| `2026_08_21_000001_create_stores_table.php` | Schéma `stores` + `UNIQUE(company_id, code)` |
| `2026_08_21_000002_create_product_stocks_table.php` | Schéma `product_stocks` + `UNIQUE(product_id, store_id)` |
| `2026_08_21_000003_initialize_main_stores_and_product_stocks.php` | Données : MAIN par company + copie stock |

### Magasin principal

- Code : `MAIN`
- Nom : « Magasin principal »
- Créé automatiquement à la création d'une `Company` (`Company::booted`).
- Un seul `is_default = true` par entreprise (protection applicative dans `Store::booted`).

### Compatibilité `products.stock_quantity`

- **Conservé** — toujours utilisé par tous les flux métier existants.
- `product_stocks.quantity` initialisé à l'identique lors de la migration.
- **Pas de synchronisation automatique** entre les deux champs dans cette phase.
- Période de transition : les deux colonnes coexistent.

### Limites volontaires de la Phase 1

- Pas de `stock_movements`.
- Pas de `StockService`.
- Ventes, BL, inventaire proto, CRUD produit : **inchangés** (modifient toujours `products.stock_quantity`).
- Pas d'UI magasin, pas de sélecteur multi-magasin.
- Pas de permissions `inventory.*` / `stores.*` implémentées.
- Catalogue produits sans `company_id` : seul le magasin `MAIN` de l'entreprise **primaire** (`Company::orderBy('id')->first()`) reçoit les `product_stocks` initialisés.

### Tests ajoutés

- `tests/Feature/StoreStockFoundationTest.php` (14 tests)

### Permissions futures (non implémentées — Phase 4+)

| Permission | Usage prévu |
|---|---|
| `inventory.view` | Consulter inventaires |
| `inventory.create` | Créer / annuler brouillon |
| `inventory.count` | Comptage |
| `inventory.review` | Contrôle écarts |
| `inventory.validate` | Validation manager |
| `inventory.adjust` | Application écarts |
| `inventory.export` | Export rapport |
| `stores.view` | Liste magasins |
| `stores.create` | Créer magasin |
| `stores.update` | Modifier magasin |

---

## Phase 2 — Stock Movements + StockService implemented

**Statut :** implémentée le 2026-08-21.

### Table `stock_movements`

Journal **immuable** (update/delete bloqués au niveau modèle).

| Colonne | Type | Description |
|---|---|---|
| `id` | bigint PK | |
| `company_id` | FK | Entreprise du magasin |
| `store_id` | FK | Magasin |
| `product_id` | FK | Produit |
| `type` | string(50) | Enum `StockMovementType` |
| `quantity` | integer | **Delta signé** (+ entrée, − sortie) |
| `quantity_before` | integer | Stock matérialisé avant |
| `quantity_after` | integer | Stock matérialisé après |
| `reference_type` / `reference_id` | morph nullable | Document source (vente, BL, etc.) |
| `reason` | string nullable | Commentaire |
| `user_id` | FK nullable | Utilisateur |
| `metadata` | json nullable | Contexte additionnel |
| `created_at` | timestamp | Pas de `updated_at` |

**Index :** `(company_id, created_at)`, `(store_id, created_at)`, `(product_id, created_at)`, `(product_id, store_id, created_at)`, `type`.  
`nullableMorphs('reference')` crée déjà l'index morph.

### Enum `StockMovementType`

Valeurs DB stables (snake_case) :

`opening_balance`, `purchase`, `sale`, `sale_cancel`, `delivery_note`, `delivery_note_cancel`, `inventory_adjustment`, `transfer_in`, `transfer_out`, `return_in`, `return_out`, `manual_adjustment`.

Méthodes utilitaires : `isInbound()`, `isOutbound()`, `quantitySign()`.

### `StockService`

**Enregistré** comme singleton dans `AppServiceProvider`.

| Méthode | Rôle |
|---|---|
| `adjust($product, $store, $delta, $type, ...)` | Cœur : transaction + lock + mouvement |
| `increase(...)` | Delta positif |
| `decrease(...)` | Delta négatif |
| `set(...)` | Ajuste vers une quantité cible |
| `getStock(...)` | Lit `product_stocks.quantity` |

**Règles :**

- `DB::transaction` + `ProductStock::lockForUpdate()`
- Stock négatif **refusé** (mode strict V1) → `InsufficientStockException`
- Ligne `ProductStock` **obligatoire** → `ProductStockNotFoundException` si absente
- Pas de création silencieuse de stock
- Isolation entreprise via `Company::getInstance()` + validation magasin

### Exceptions métier

| Exception | Cas |
|---|---|
| `InsufficientStockException` | Sortie impossible (expose `product_id`, `store_id`, `requested_quantity`, `available_quantity`) |
| `ProductStockNotFoundException` | Couple produit/magasin sans ligne stock |

### Opening balance

Migration `2026_08_21_000005_create_opening_balance_movements.php` :

- Pour chaque `ProductStock` existant sans mouvement `opening_balance`
- `quantity_before = 0`, `quantity = stock actuel`, `quantity_after = stock actuel`
- **Ne modifie pas** `product_stocks` (traçabilité seulement)
- Idempotent via `StockMovement::hasOpeningBalance()`
- Logique réutilisable : `StockMovement::createOpeningBalancesFromExistingStocks()`

### Diagnostic

Commande Artisan :

```bash
php artisan stock:check-consistency
php artisan stock:check-consistency --json
```

Compare `product_stocks.quantity` avec le dernier `stock_movements.quantity_after` par couple produit/magasin.

### Tests ajoutés

- `tests/Unit/StockServiceTest.php` (16 tests)
- Configuration Pest : `tests/Pest.php` étend `TestCase` pour ce fichier

### Limites actuelles (Phase 2)

- **Les flux métier existants utilisaient encore `products.stock_quantity`** — migration BL en Phase 3A.
- Pas de synchronisation automatique globale `products.stock_quantity` ↔ `product_stocks.quantity` (sauf flux migrés explicitement).
- Test de concurrence multi-processus non implémenté ; couverture via verrou transactionnel + test séquentiel strict.
- Isolation entreprise basée sur `Company::getInstance()` (mono-entreprise par installation).

### Fichiers créés (Phase 2)

| Fichier |
|---|
| `app/Enums/StockMovementType.php` |
| `app/Models/StockMovement.php` |
| `app/Services/StockService.php` |
| `app/Exceptions/InsufficientStockException.php` |
| `app/Exceptions/ProductStockNotFoundException.php` |
| `app/Console/Commands/CheckStockConsistencyCommand.php` |
| `database/migrations/2026_08_21_000004_create_stock_movements_table.php` |
| `database/migrations/2026_08_21_000005_create_opening_balance_movements.php` |
| `tests/Unit/StockServiceTest.php` |

### Fichiers modifiés (Phase 2)

| Fichier |
|---|
| `app/Providers/AppServiceProvider.php` |
| `app/Models/Company.php` |
| `app/Models/Store.php` |
| `app/Models/Product.php` |
| `tests/Pest.php` |

---

## Phase 3A — Purchase/Delivery flow migrated

**Statut :** implémentée le 2026-08-21.

### Flux migré

`PurchaseOrderDeliveryService` — validation, annulation et ajustement de BL validés.

| Opération | Type mouvement | Delta |
|---|---|---|
| Validation BL | `purchase` | +quantité reçue |
| Annulation BL validé | `delivery_note_cancel` | −quantité reçue |
| Ajustement BL validé (delta +) | `purchase` | +delta |
| Ajustement BL validé (delta −) | `delivery_note_cancel` | −delta |

### Magasin cible

- Magasin **MAIN** via `Company::getInstance()->defaultStore`
- Échec explicite si magasin principal absent ou inactif
- **Pas** de création silencieuse de magasin pendant une réception

### StockService

- `applyStockDelta()` délègue à `StockService::increase()` / `decrease()`
- Référence polymorphe : `DeliveryNote` (`reference_type` / `reference_id`)
- `user_id` : utilisateur authentifié (`Auth::user()`)
- Transaction externe `PurchaseOrderDeliveryService` + transaction interne `StockService` (savepoints Laravel)

### Idempotence

- Conservée via statut BL : `validateDeliveryNote()` ne modifie le stock que si `status === pending`
- Second appel sur BL déjà validé : retour anticipé, **aucun** nouveau `StockMovement`

### Miroir temporaire `products.stock_quantity`

Après chaque opération `StockService`, synchronisation **explicite** :

```php
$mirroredQuantity = $this->stockService->getStock($product, $store);
$product->update(['stock_quantity' => $mirroredQuantity]);
```

- Pas d'Observer
- `ProductStock` = source de vérité du mouvement
- `products.stock_quantity` = miroir UI/legacy pour le magasin MAIN

### Prérequis ProductStock

- Réception impossible sans ligne `product_stocks` sur le magasin MAIN
- `ProductStockNotFoundException` si absent (pas de création silencieuse)

### Tests complétés

- `tests/Feature/PurchaseOrderDeliveryTest.php` : 29 tests (+4 nouveaux)
- Fixture `createProcurementFixture` crée désormais un `ProductStock` sur MAIN
- Helpers : `procurementMainStore()`, `ensureProductStockForMainStore()`

### Flux **non** migrés (Phase 3A)

- Ventes (`SaleController`)
- Conversion devis → vente (`QuoteController`)
- Inventaire proto (`StockInventoryController`)
- Modification directe stock produit (`ProductController`)

### Fichiers modifiés (Phase 3A)

| Fichier |
|---|
| `app/Services/PurchaseOrderDeliveryService.php` |
| `tests/Feature/PurchaseOrderDeliveryTest.php` |
| `docs/inventory-architecture-design.md` |

---

## Phase 3B — Sales migrated to StockService

**Statut :** implémentée le 2026-07-31.

### Flux migré

`SaleStockService` — point unique de sortie/restitution stock pour les ventes.

| Opération | Type mouvement | Delta |
|---|---|---|
| Création vente | `sale` | −quantité vendue |
| Modification (augmentation qty) | `sale` | −delta net |
| Modification (diminution qty) | `sale_cancel` | +delta net |
| Produit retiré de la vente | `sale_cancel` | +qty retirée |
| Nouveau produit sur vente | `sale` | −qty ajoutée |
| Suppression vente | `sale_cancel` | +qty restituée |
| Conversion devis → vente | `sale` | −quantité (via `applySaleCreation`) |

### Cycle de vie documenté

**Création (`SaleController::store`)**

1. Validation métier (doublons, péremption, stock via `ProductStock` MAIN)
2. Transaction atomique : `Sale` → `SaleItem`(s) → `SaleStockService::applySaleCreation()`
3. Miroir `products.stock_quantity` resynchronisé après chaque mouvement
4. `ActivityLog` après commit

**Modification (`SaleController::update`)**

1. Agrégation anciennes/nouvelles quantités par `product_id`
2. Pré-validation : stock disponible = `getStock()` + qty déjà vendue sur cette vente
3. Transaction atomique : deltas nets (`applySaleUpdateDeltas`) → mise à jour vente → remplacement lignes
4. Stratégie delta net : pas de restitution globale puis re-décrément ; un seul mouvement par produit modifié

**Suppression (`SaleController::destroy`)**

1. Transaction : `applySaleCancellation()` puis suppression vente
2. Les mouvements `sale` initiaux restent dans le journal immuable

**Devis → vente (`QuoteController::convertToSale`)**

1. Validation stock via `SaleStockService`
2. Transaction : création vente + lignes + `applySaleCreation()` (un seul point de sortie)
3. Pas de double décrément

### Magasin cible

- Magasin **MAIN** via `Company::getInstance()->defaultStore`
- `ProductStockNotFoundException` si ligne MAIN absente (pas de création silencieuse)

### Prérequis ProductStock

Un produit vendable doit disposer d'une ligne `product_stocks` sur le magasin MAIN. La création automatique à la création produit est **recommandée pour la phase suivante** (Phase 3C ou CRUD produit) — non implémentée en 3B pour respecter le périmètre.

### Idempotence

- Modification sans changement de quantité : aucun mouvement (`applyNetSoldDelta` delta = 0)
- Concurrence : `lockForUpdate()` sur `product_stocks` + mode strict `InsufficientStockException`
- Pas de statut intermédiaire vente (contrairement au BL) ; l'atomicité transactionnelle empêche vente sans stock

### Miroir temporaire `products.stock_quantity`

Identique Phase 3A — synchronisation explicite dans `SaleStockService::syncProductMirror()` après chaque mouvement.

### Tests complétés

- `tests/Feature/SaleStockTest.php` : 15 tests (création, insuffisant, multi-produits, modification delta, suppression, devis, concurrence)
- `tests/Feature/SalePaymentTest.php` : fixture enrichie (`ProductStock` MAIN)
- Régression : 111 tests ciblés stock/BL/ventes/produits OK

### Écritures legacy restantes (post-3B)

| Flux | Fichier | Phase future |
|---|---|---|
| Inventaire proto | `StockInventoryController.php` | Phase 4 |
| CRUD produit | `ProductController.php` | Phase 3C |
| Commande artisan | `RefreshProductsStockExpirationCommand.php` | Maintenance |
| Affichage édition vente | `SaleController::edit` (+qty virtuelle UI) | OK — lecture seule |
| Miroir explicite | `SaleStockService`, `PurchaseOrderDeliveryService` | Conservé jusqu'à suppression colonne |

### Fichiers créés / modifiés (Phase 3B)

| Fichier | Action |
|---|---|
| `app/Services/SaleStockService.php` | Créé |
| `app/Http/Controllers/SaleController.php` | Migré |
| `app/Http/Controllers/QuoteController.php` | Migré |
| `tests/Feature/SaleStockTest.php` | Créé |
| `tests/Feature/SalePaymentTest.php` | Fixture ProductStock |
| `docs/inventory-architecture-design.md` | Section 3B |

---

## Phase 3C — Product CRUD migrated to ProductStock

**Statut :** implémentée le 2026-07-31.

### Problème résolu

Les produits créés après la Phase 1 pouvaient exister sans ligne `product_stocks` sur le magasin MAIN, provoquant des échecs BL/ventes (`ProductStockNotFoundException`).

### Création produit (`ProductController::store`)

Transaction atomique :

1. `Product::create()` avec `stock_quantity` initial (formulaire existant)
2. `ProductStockInitializationService::initializeMainStock()`
   - crée `ProductStock` MAIN avec la même quantité
   - resynchronise le miroir `products.stock_quantity`
   - si stock initial > 0 : mouvement `opening_balance` (référence `Product`, metadata `product_creation`)
3. si stock initial = 0 : pas de mouvement

### Modification produit (`ProductController::update`)

- `stock_quantity` **interdit** (`prohibited`) — le CRUD ne modifie plus le stock existant
- `min_stock_level` reste modifiable (seuil d'alerte, pas stock physique)
- UI Edit : champ stock en **lecture seule** pour tous les rôles

### ProductStock MAIN obligatoire

Chaque nouveau produit reçoit une ligne MAIN à la création. Pas de création silencieuse lors des ventes/réceptions.

### Opening balance

| Condition | Mouvement |
|---|---|
| stock initial > 0 | `opening_balance`, qty=+N, before=0, after=N |
| stock initial = 0 | aucun mouvement |

Metadata : `{ "source": "product_creation", "initial_stock": true }`

### Miroir legacy

`products.stock_quantity` = miroir MAIN, synchronisé à la création et par les flux BL/ventes (Phases 3A/3B).

### Diagnostic `stock:check-consistency`

Détecte désormais :

- produit sans ProductStock MAIN
- ProductStock orphelin / magasin introuvable
- divergence legacy vs ProductStock MAIN
- ProductStock sur magasin d'une autre entreprise
- incohérence journal vs `product_stocks.quantity`
- magasins `is_default` incohérents

Outil de **diagnostic uniquement** (pas de correction automatique).

### ProductObserver

Conservé pour les **notifications** (stock faible / expiration). Ne modifie pas le stock. Réagit aux mises à jour miroir `stock_quantity` issues des flux StockService.

### Flux non migrés (Phase 3C)

- `StockInventoryController` (Phase 4)
- Suppression colonne `products.stock_quantity`

### Fichiers créés / modifiés (Phase 3C)

| Fichier | Action |
|---|---|
| `app/Services/ProductStockInitializationService.php` | Créé |
| `app/Http/Controllers/ProductController.php` | Création transactionnelle, update sans stock |
| `app/Console/Commands/CheckStockConsistencyCommand.php` | Diagnostic enrichi |
| `resources/js/pages/Products/Edit.vue` | Stock lecture seule |
| `tests/Feature/ProductStockTest.php` | Créé (15 tests) |
| `tests/Feature/ProductBarcodeFieldTest.php` | Payload update sans stock |
| `docs/inventory-architecture-design.md` | Section 3C |

### Prochaine phase

**Phase 4** — module Inventaire professionnel (conception terminée — voir section ci-dessous).

---

## Phase 4 — Conception du module Inventaire (CONCEPTION UNIQUEMENT)

**Statut :** conception terminée le 2026-07-31. **Aucun code applicatif modifié.**

> Ce chapitre remplace l'inventaire proto par un module contrôlé, auditable et compatible StockService. Il sert de blueprint d'implémentation pour les sous-phases 4A→4G.

---

### 4.0 Synthèse exécutive

| Avant (proto) | Après (Phase 4 cible) |
|---|---|
| Ajustement immédiat `products.stock_quantity` | Session d'inventaire + workflow |
| Pas de snapshot | `stock_snapshot` figé au démarrage |
| Pas de StockMovement | `inventory_adjustment` via StockService |
| Pas d'idempotence | Statut `applied` + verrou métier |
| Permissions `products.update` | RBAC `inventory.*` dédié |
| Comptage = écriture stock | Comptage ≠ mutation stock |

**Principe cardinal :** le comptage modifie uniquement `inventory_items.quantity_counted`. Le stock réel (`ProductStock`) n'est touché qu'à l'**application** via `StockService`.

---

### 4.1 État actuel — inventaire proto

#### Fichiers concernés

| Fichier | Rôle |
|---|---|
| `app/Http/Controllers/StockInventoryController.php` | 2 actions : `index`, `count` |
| `resources/js/pages/StockInventory/Index.vue` | UI scan + saisie qty + validation immédiate |
| `routes/web.php` | `GET /stock-inventory`, `POST /stock-inventory/count` |
| `tests/Feature/StockInventoryTest.php` | 3 tests proto |
| `resources/js/layouts/BootstrapLayout.vue` | Lien menu « Inventaire » |

#### Comportement actuel

1. **Index** : rendu Inertia, permission `products.view`, flag `canAdjustStock` si non-vendeur + `products.update`.
2. **Count** : POST JSON `{ barcode, counted_quantity }`.
   - Normalise barcode via `ProductBarcodeService`.
   - Lookup produit actif par barcode exact.
   - **`$product->update(['stock_quantity' => $countedQuantity])`** — écriture directe legacy.
   - `ActivityLogger::logUpdate('Inventaire', $product, ...)`.
   - Retourne delta vs ancien `stock_quantity`.

#### Tables utilisées

- `products` uniquement (`stock_quantity`).
- **Aucune** table session/item.
- **Aucun** `ProductStock`, **aucun** `StockMovement`.

#### Problèmes identifiés

| # | Problème | Gravité |
|---|---|---|
| P1 | Contourne StockService / ProductStock | **CRITIQUE** |
| P2 | Pas de journal immuable | **CRITIQUE** |
| P3 | Écrase le stock sans tenir compte des ventes/BL concurrentes | **CRITIQUE** |
| P4 | Pas de snapshot → impossible d'expliquer un écart | **ÉLEVÉ** |
| P5 | Pas de session reprise / historique | **ÉLEVÉ** |
| P6 | Permission produit inadaptée (vendeur exclu mais gestionnaire stock trop large) | **MOYEN** |
| P7 | UI pré-remplit qty comptée = stock actuel (pas mode scan +1) | **MOYEN** |
| P8 | Pas de périmètre partiel structuré | **MOYEN** |
| P9 | ActivityLog mélange audit utilisateur et vérité stock | **FAIBLE** |

#### À conserver du proto

- Composant `BarcodeInput.vue` + `barcodeKeyboardScanner.ts` (HID).
- `useProductBarcodeLookup` → route `products.barcode`.
- Feedback visuel scan (succès / inconnu / erreur).
- Focus automatique après scan.
- Normalisation barcode (`ProductBarcodeService`).
- Exclusion vendeur des ajustements stock.

#### À supprimer après migration

- `StockInventoryController::count()` (logique directe).
- Route `stock-inventory.count`.
- Tests proto remplaçables par `InventorySessionTest`.
- Écriture `$product->update(['stock_quantity'])` dans inventaire.

---

### 4.2 Objectifs métier

Permettre un **inventaire physique contrôlé** :

```
Stock système (snapshot)  →  50
Comptage physique         →  47
Écart documenté           →  -3
Utilisateur / date / session / motif
Application traçable      →  StockMovement inventory_adjustment
```

Le commerçant doit comprendre **qui** a compté, **quand**, **sur quel périmètre**, **avec quels écarts**, sans risquer d'écraser une vente survenue entre-temps.

---

### 4.3 Modèle de données

#### Table `inventory_sessions`

| Colonne | Type | Null | Description |
|---|---|---|---|
| `id` | bigint PK | non | |
| `company_id` | bigint FK → companies | non | Isolation entreprise |
| `store_id` | bigint FK → stores | non | Magasin inventorié (V1 = MAIN) |
| `reference` | string(32) | non | Numéro lisible ex. `INV2608001` |
| `name` | string(255) | oui | Libellé optionnel |
| `status` | string(32) | non | Machine à états (voir §4.5) |
| `scope_type` | string(32) | non | `full`, `category`, `in_stock`, `manual` |
| `scope_payload` | json | oui | Filtres (category_id, product_ids, …) |
| `notes` | text | oui | Notes libres |
| `started_at` | timestamp | oui | Passage → counting |
| `submitted_at` | timestamp | oui | Passage → review |
| `validated_at` | timestamp | oui | Passage → validated |
| `applied_at` | timestamp | oui | Passage → applied |
| `closed_at` | timestamp | oui | Passage → closed |
| `cancelled_at` | timestamp | oui | Passage → cancelled |
| `created_by` | bigint FK → users | non | |
| `submitted_by` | bigint FK → users | oui | |
| `validated_by` | bigint FK → users | oui | |
| `applied_by` | bigint FK → users | oui | |
| `closed_by` | bigint FK → users | oui | |
| `cancelled_by` | bigint FK → users | oui | |
| `cancel_reason` | text | oui | |
| `items_total` | integer | non | Dénormalisé : nb lignes |
| `items_counted` | integer | non | Dénormalisé : nb comptés |
| `items_with_variance` | integer | non | Dénormalisé : nb avec écart ≠ 0 |
| `total_variance_qty` | integer | non | Somme signed des écarts snapshot |
| `created_at` / `updated_at` | timestamps | non | |

**Index :**

- `UNIQUE(company_id, reference)`
- `INDEX(company_id, store_id, status)`
- `INDEX(status, created_at)`

**Contraintes :**

- `store_id` doit appartenir à `company_id` (validation applicative + FK).
- Une seule session `counting|review|validated` **par store** recommandée (contrainte métier V1, pas DB unique stricte — voir §4.13).

**Suppression :** interdite si `status IN (applied, closed)`. Annulation logique via `cancelled`.

#### Table `inventory_items`

| Colonne | Type | Null | Description |
|---|---|---|---|
| `id` | bigint PK | non | |
| `inventory_session_id` | bigint FK | non | ON DELETE CASCADE si draft/cancelled uniquement* |
| `product_id` | bigint FK → products | non | |
| `stock_snapshot` | integer | non | Stock théorique au **démarrage** counting |
| `quantity_counted` | integer | oui | NULL = non compté |
| `difference_snapshot` | integer | oui | `quantity_counted - stock_snapshot` (computed/store) |
| `is_counted` | boolean | non | default false |
| `counted_at` | timestamp | oui | Dernier comptage |
| `counted_by` | bigint FK → users | oui | |
| `count_mode_last` | string(16) | oui | `scan_increment`, `manual_absolute`, `manual_delta` |
| `applied_delta` | integer | oui | Delta réel appliqué (rempli à apply) |
| `applied_at` | timestamp | oui | |
| `notes` | text | oui | Motif ligne |
| `created_at` / `updated_at` | timestamps | non | |

\* **Recommandation :** pas de DELETE CASCADE après soumission ; archiver la session entière.

**Index :**

- `UNIQUE(inventory_session_id, product_id)`
- `INDEX(inventory_session_id, is_counted)`
- `INDEX(product_id)`

#### Tables additionnelles — non requises en V1

| Table | Verdict |
|---|---|
| `inventory_scan_events` | **V2** — journal scan unitaire ; V1 suffit avec `quantity_counted` persisté + ActivityLog ciblé |
| `inventory_session_events` | **V2** — ActivityLog + statuts timestamps suffisent en V1 |

---

### 4.4 Modèle logique — InventorySession

Exemple métier :

```
Inventaire #INV2608017
Magasin : MAIN
Créé par : Moussa — 21/08/2026 10:00
Statut : COUNTING
Périmètre : Catégorie « Boissons » (28 produits)
```

Champs utilisateur visibles : `reference`, `name`, `status`, `store`, auteurs, dates workflow, KPI (items_total, items_counted, écarts).

---

### 4.5 Machine à états

```
                    ┌──────────┐
                    │  draft   │
                    └────┬─────┘
                         │ start (inventory.create)
                         ▼
                    ┌──────────┐     cancel      ┌───────────┐
              ┌────│ counting │────────────────►│ cancelled │
              │    └────┬─────┘                 └───────────┘
              │         │ submit (inventory.count)
              │         ▼
              │    ┌──────────┐     cancel      ┌───────────┐
              └────│  review  │────────────────►│ cancelled │
                   └────┬─────┘                 └───────────┘
                        │ validate (inventory.validate)
                        ▼
                   ┌──────────┐
                   │ validated│
                   └────┬─────┘
                        │ apply (inventory.apply) — idempotent
                        ▼
                   ┌──────────┐
                   │ applied  │──── close (inventory.close) ───► closed
                   └──────────┘
```

#### Détail par statut

| Statut | Signification | Modifiable | Verrouillé |
|---|---|---|---|
| `draft` | Session créée, périmètre défini, items générables | name, scope, notes | stock réel |
| `counting` | Comptage actif | `quantity_counted`, notes item | snapshot |
| `review` | Comptage soumis, contrôle écarts | notes, retour counting autorisé | snapshot |
| `validated` | Écarts acceptés, prêt à appliquer | rien (sauf annulation globale) | tout item |
| `applied` | Stock modifié via StockService | rien | tout + mouvements créés |
| `closed` | Session archivée administrativement | rien | tout |
| `cancelled` | Abandon sans effet stock | rien | audit conservé |

#### Transitions autorisées

| De | Vers | Action | Permission | Condition |
|---|---|---|---|---|
| draft | counting | `start` | create | ProductStock MAIN existant pour items |
| counting | review | `submit` | count ou submit* | ≥1 produit compté |
| review | counting | `reopen` | review | Pas encore validated |
| review | validated | `validate` | validate | Règle non-comptés respectée (§4.8) |
| validated | applied | `apply` | apply | Idempotence statut |
| applied | closed | `close` | close | Optionnel V1 auto-close après apply |
| draft/counting/review | cancelled | `cancel` | cancel | Jamais si applied |

\* **Décision V1 :** fusionner `inventory.count` (comptage) et `inventory.submit` (soumission) en permissions distinctes mais `submit` réservé gestionnaire.

#### Retours interdits (recommandation)

- `validated` → `review` : **interdit** (annuler et recréer session si erreur grave).
- `applied` → * : **interdit** (correction = nouvel inventaire ou ajustement manuel futur).
- `closed` → * : **interdit**.

---

### 4.6 Comptage physique

Pour chaque `inventory_item` :

```
stock_snapshot     = stock ProductStock MAIN au moment start (ex. 25)
quantity_counted   = comptage physique (ex. 23)
difference_snapshot = quantity_counted - stock_snapshot (ex. -2)
```

**Règle :** `stock_snapshot` est **immutable** après `start`. Toute comparaison « écart théorique » utilise ce snapshot.

**Application stock (§4.15) :** le delta StockService n'utilise **pas** `stock_snapshot` directement — voir stratégie concurrence §4.13.

---

### 4.7 Périmètre produits — V1 / V2

| Mode | scope_type | V1 | V2 |
|---|---|---|---|
| Inventaire complet | `full` | **Oui** | — |
| Stock > 0 uniquement | `in_stock` | **Oui** | — |
| Par catégorie | `category` | **Oui** | — |
| Par emplacement (`products.location`) | `location` | Non | Oui |
| Par fournisseur | `supplier` | Non | Oui (si lien produit↔fournisseur fiable) |
| Sélection manuelle | `manual` | Optionnel V1.1 | Oui |

**Génération items (`start`) :**

1. Résoudre liste produits actifs du magasin MAIN selon `scope_type` + `scope_payload`.
2. Pour chaque produit : lire `ProductStock.quantity` (échec si absent).
3. Créer `inventory_item` avec `stock_snapshot = quantity`.
4. Mettre à jour dénormalisations session.

---

### 4.8 Produits non comptés

**Décision : NON COMPTÉ ≠ ZÉRO**

| Situation | Comportement V1 |
|---|---|
| `quantity_counted IS NULL` | Produit **non compté** — aucun ajustement à l'application |
| Validation (`validate`) | **Bloquer** si items non comptés > 0, sauf flag session `allow_partial_apply` (admin, défaut false) |
| Rapport | Lister explicitement « non comptés » |
| Application | Seuls items `is_counted = true` sont ajustés |

**Justification :** éviter de mettre silencieusement à zéro des produits oubliés.

Option V2 : « forcer validation avec exclusion » documentée dans ActivityLog.

---

### 4.9 Douchette HID

Réutiliser **sans modification du pipeline** :

```
BarcodeInput.vue
  → barcodeKeyboardScanner.ts (Enter, focus, debounce)
  → useProductBarcodeLookup / POST count endpoint session
  → ProductBarcodeService (normalisation, lookup exact)
```

#### Workflow scan recommandé (V1)

1. Focus permanent sur champ barcode (desktop + mobile).
2. Scan → lookup produit.
3. **Mode increment** : `quantity_counted = (quantity_counted ?? 0) + 1`.
4. Persistance **immédiate** API `POST .../items/{product}/count` `{ mode: 'increment' }`.
5. Feedback : « Riz 25 kg — compté : 24 (écart snapshot : -1) ».
6. Refocus barcode (< 300 ms).

#### Cas limites

| Cas | Comportement |
|---|---|
| Produit inconnu | Message « Code-barres inconnu » — pas de création auto |
| Produit hors périmètre V1 | Message « Produit non inclus » — bouton admin « Ajouter au périmètre » (V1.1) ou refus strict V1 |
| Barcode absent | Fallback recherche manuelle nom/SKU |
| Double scan | +1 intentionnel (feature, pas bug) |
| Scan pendant saving | Queue ou ignore avec message « enregistrement… » |

**Pas de caméra. Pas de BarcodePipelineDiagnostics.**

---

### 4.10 Quantité manuelle

Modes API :

| mode | Effet |
|---|---|
| `increment` | +1 (douchette) |
| `decrement` | -1 (bouton −) |
| `absolute` | `quantity_counted = value` (saisie directe) |
| `set` | alias absolute |

Validation : `quantity_counted >= 0` (mode strict — §4.30).

UI : champ numérique + boutons `[−] [+]`, bouton « Mettre à X ».

---

### 4.11 Stock pendant le comptage — stratégie concurrence

#### Options analysées

| Option | Description | Verdict |
|---|---|---|
| A | Bloquer ventes/BL | Simple mais inacceptable métier |
| B | Snapshot + apply sur stock **courant** | **RETENU** |
| C | Recalcul auto snapshot | Opaque, dangereux |
| D | Re-vérification manuelle | Complément review, pas seul mécanisme |

#### Stratégie retenue (Option B affinée)

**Phase comptage :** aucune mutation `ProductStock`.

**Phase application :** pour chaque item compté :

```php
$currentStock = StockService::getStock($product, $store); // lockForUpdate
$targetStock  = $item->quantity_counted;
$delta        = $targetStock - $currentStock;

if ($delta === 0) { /* pas de mouvement */ continue; }

StockService::increase|decrease(..., InventoryAdjustment, delta abs, reference: $session);
$item->applied_delta = $delta;
```

`stock_snapshot` sert à :

- afficher l'écart « depuis le début d'inventaire » ;
- alerter en review si `|quantity_counted - stock_snapshot|` est grand **ET** des mouvements ont eu lieu (flag `movements_during_session` calculé à la volée ou denormalisé).

#### Cas concrets (§4.40)

**Cas 1 — Snapshot=100, vente −10, comptage=90, apply :**

- Stock courant à apply = 90.
- Target = 90 → **delta = 0** → pas de mouvement. **Correct.**

**Cas 2 — Snapshot=100, vente −10, comptage=100 (physique erroné) :**

- Stock courant = 90, target = 100 → **delta = +10**.
- Review doit afficher : « Mouvements depuis snapshot : −10 ; compté supérieur au stock courant ».

**Cas 3 — Snapshot=100, BL +20, comptage=110 :**

- Stock courant = 120, target = 110 → **delta = −10**. **Correct.**

**Cas 4 — Snapshot=100, comptage=97, aucun mouvement :**

- Stock courant = 100, target = 97 → delta = −3. Mouvement `inventory_adjustment` −3.

---

### 4.12 Concurrence technique

| Risque | Mitigation |
|---|---|
| Double application | Statut `applied` + check en transaction + unique `applied_at` |
| Lost update comptage | Update optimiste `updated_at` ou row lock item |
| Vente vs apply | `lockForUpdate` ProductStock dans StockService (existant) |
| Deux sessions actives même store | Alerte V1 ; blocage V1.1 si session `counting|review|validated` existe |
| Stock négatif | Mode strict StockService (existant) |

Application = **une transaction globale** par session :

```
BEGIN
  lock session (status = validated)
  foreach items counted (sorted product_id):
    lock ProductStock
    compute delta vs current
    StockService adjust if delta != 0
    sync mirror products.stock_quantity
  update session status = applied
  ActivityLogger
COMMIT
```

Rollback complet si un item échoue (`InsufficientStockException` si decrement trop important).

---

### 4.13 Application inventaire — StockService

```php
// InventoryApplicationService (conceptuel)
$movement = $stockService->decrease(
    $product, $store, abs($delta),
    StockMovementType::InventoryAdjustment,
    user: $user,
    reason: "Inventaire {$session->reference} ({$item->difference_snapshot} vs snapshot)",
    reference: $session, // morph InventorySession
    metadata: [
        'inventory_session_id' => $session->id,
        'inventory_item_id'    => $item->id,
        'stock_snapshot'       => $item->stock_snapshot,
        'quantity_counted'     => $item->quantity_counted,
        'difference_snapshot'  => $item->difference_snapshot,
        'applied_delta'        => $delta,
        'stock_before_apply'   => $currentStock,
        'stock_after_apply'    => $targetStock,
    ],
);
$product->update(['stock_quantity' => $stockService->getStock($product, $store)]); // miroir
```

**Interdit :** `$product->update(['stock_quantity' => ...])` comme mécanisme principal.

---

### 4.14 Idempotence application

```php
if ($session->status === 'applied') {
    return; // ou 409 Conflict « déjà appliqué »
}
// en transaction, re-vérifier status après lock
```

Option DB V2 : `UNIQUE(stock_movements.reference_id)` par session+type — non retenu V1 (plusieurs items = plusieurs mouvements).

---

### 4.15 Audit — ActivityLog vs StockMovement

| Événement | ActivityLog | StockMovement |
|---|---|---|
| Création session | ✓ create | — |
| Start counting | ✓ custom | — |
| Comptage item | ✓ update (optionnel, batchable) | — |
| Submit / validate | ✓ validate | — |
| Apply session | ✓ validate/apply | ✓ par produit ajusté |
| Cancel | ✓ cancel | — |
| Close | ✓ update | — |

**StockMovement** = vérité quantité. **ActivityLog** = qui a fait quoi.

---

### 4.16 Historique & KPI session

Écran détail `#INV2608017` :

| KPI | Source |
|---|---|
| Produits périmètre | `items_total` |
| Comptés | `items_counted` |
| Non comptés | `items_total - items_counted` |
| Avec écart (vs snapshot) | `items_with_variance` |
| Écarts positifs / négatifs | agrégation `difference_snapshot` |
| Quantité nette ajustée | somme `applied_delta` post-apply |
| Statut / auteurs / dates | session |

Export PDF/CSV : **V2**.

---

### 4.17 Permissions RBAC (futures)

Ressource `inventory` dans PermissionSeeder (**implémentation Phase 4B**, pas maintenant) :

| Permission | Rôle typique |
|---|---|
| `inventory.view` | admin, gestionnaire |
| `inventory.create` | admin, gestionnaire |
| `inventory.count` | admin, gestionnaire, magasinier |
| `inventory.submit` | admin, gestionnaire |
| `inventory.review` | admin, gestionnaire |
| `inventory.validate` | admin, gestionnaire |
| `inventory.apply` | admin (gestionnaire optionnel) |
| `inventory.cancel` | admin |
| `inventory.close` | admin, gestionnaire |

**Vendeur :** aucune permission inventory (ne peut pas modifier stock via inventaire).

Policies Laravel : `InventorySessionPolicy` miroir BL (`DeliveryNotePolicy` pattern).

---

### 4.18 Inventaire partiel — cas concret

100 produits magasin, périmètre catégorie « Boissons » = 30 produits.

- **Snapshot :** 30 lignes `inventory_items` uniquement.
- **Comptage / apply :** seuls ces 30 produits.
- **70 autres :** **inchangés** — pas de ligne, pas de mouvement.
- **Rapport :** périmètre explicite dans `scope_payload`.

---

### 4.19 UX Desktop (conception)

```
┌─────────────────────────────────────────────────────────────┐
│ INVENTAIRE #INV2608017          MAIN           COUNTING     │
│ Catégorie : Boissons — 28 produits                          │
├─────────────────────────────────────────────────────────────┤
│ [🔫 Scanner code-barres___________________________] [Chercher]│
│ ✓ Riz 25 kg — compté : 24 (snapshot 25, écart -1)           │
├─────────────────────────────────────────────────────────────┤
│ Comptés : 18/28    Écarts : 5    Non comptés : 10           │
├─────────────────────────────────────────────────────────────┤
│ Produit          Snapshot  Compté  Écart    Action          │
│ Riz 25 kg           25       24      -1     [−][+] [✎]      │
│ Sucre 1 kg          12       12       0     [−][+] [✎]      │
│ ...                                                          │
├─────────────────────────────────────────────────────────────┤
│ [Soumettre pour révision]              [Annuler session]     │
└─────────────────────────────────────────────────────────────┘
```

Pages Inertia proposées :

- `Inventory/Index.vue` — liste sessions
- `Inventory/Create.vue` — choix périmètre
- `Inventory/Count.vue` — comptage (desktop/mobile responsive)
- `Inventory/Review.vue` — écarts + mouvements pendant session
- `Inventory/Show.vue` — historique / détail read-only

---

### 4.20 UX Mobile

- Layout single-column, barcode en haut sticky.
- Gros boutons `+1` / `−1`.
- Dernière ligne comptée en carte pleine largeur.
- Peu de modales ; feedback toast inline.
- Même API que desktop (pas de logique dupliquée).
- Safe area + clavier numérique `inputmode="numeric"`.

---

### 4.21 Feedback scan

| État | Message |
|---|---|
| Succès | « {name} — compté : {qty} » |
| Inconnu | « Code-barres inconnu » |
| Hors périmètre | « Produit non inclus dans cet inventaire » |
| Erreur API | « Impossible d'enregistrer le comptage » |
| Stock négatif refusé | « Quantité invalide » |

Limiter notifications push ; feedback inline prioritaire (pattern proto existant).

---

### 4.22 Performance

| Échelle | Stratégie |
|---|---|
| ≤100 produits | Charger items paginés 50/lot ; index local barcode→product_id en mémoire session |
| ≤1000 | Pagination serveur + recherche ; scan → API directe item par product_id |
| 10000 | **V2** — indexer barcode, lazy load, comptage API-only sans table DOM complète |

Éviter N+1 : `InventoryItem::with('product:id,name,barcode,sku,unit')`.

Chaque scan = **1 requête** PATCH count (pas rechargement page).

---

### 4.23 Sauvegarde comptage — décision V1

**Option A retenue :** persister `quantity_counted` immédiatement en DB à chaque action (scan/+1/saisie).

| Avantages | Inconvénients |
|---|---|
| Reprise navigateur | Plus d'écritures |
| Pas de perte | — |
| Simple | — |

Pas de journal scan séparé en V1.

---

### 4.24 Reprise session

Utilisateur ferme navigateur → session reste `counting` en DB.

À la reconnexion : `GET /inventory/{id}` reprend comptage avec items déjà persistés.

Timeout auto : **non** en V1 (session ouverte jusqu'à submit/cancel).

---

### 4.25 Annulation

`cancel` depuis draft/counting/review :

- Statut → `cancelled`.
- **Aucun** StockMovement.
- Items conservés pour audit.
- Session non supprimée physiquement.

---

### 4.26 Valeur financière — V1

**Ne pas afficher en V1.**

Raisons :

- `cost_price` produit ≠ coût moyen pondéré ;
- pas de valorisation stock fiable multi-flux ;
- risque de décisions erronées.

V2 : valorisation optionnelle via `cost_price` avec disclaimer.

---

### 4.27 Sécurité

| Menace | Mitigation |
|---|---|
| IDOR session autre company | Scope queries `company_id = Company::getInstance()` |
| Manipulation store_id | Valider store ∈ company |
| Mass assignment status | DTO + service transitions |
| Apply twice | Statut + transaction |
| Modify after validated | Policy + guard statut |
| Vendeur apply stock | Permissions inventory.* |

---

### 4.28 Routes API futures (proposition)

```
GET    /inventory                          inventory.index
POST   /inventory                          inventory.store
GET    /inventory/{session}                inventory.show
POST   /inventory/{session}/start          inventory.start
PATCH  /inventory/{session}/items/{item}   inventory.items.count
POST   /inventory/{session}/submit         inventory.submit
POST   /inventory/{session}/reopen         inventory.reopen
POST   /inventory/{session}/validate       inventory.validate
POST   /inventory/{session}/apply          inventory.apply
POST   /inventory/{session}/close          inventory.close
POST   /inventory/{session}/cancel         inventory.cancel
GET    /inventory/{session}/items          inventory.items.index (pagination, search)
```

Remplacer routes proto :

- `GET /stock-inventory` → redirect ou remplacement menu vers `/inventory`
- Supprimer `POST /stock-inventory/count`

---

### 4.29 Services — responsabilités

```
InventorySessionService
  - CRUD draft, start, submit, reopen, validate, cancel, close
  - génération items + snapshot
  - machine à états + autorisations

InventoryCountingService
  - recordCount(item, mode, value)
  - resolve product by barcode dans périmètre
  - règles qty >= 0

InventoryApplicationService
  - apply(session) → StockService + miroir
  - idempotence
  - transaction globale

StockService (existant)
  - seul mutateur ProductStock + StockMovement
```

Pas de duplication StockService.

---

### 4.30 Architecture cible (diagramme)

```
                    Company
                       │
                    Store MAIN
                       │
              InventorySession ─────┐
                       │            │
              InventoryItems        │
                       │            │
                    Product         │
                                    │
         Application phase          │
                    │               │
     InventoryApplicationService    │
                    │               │
              StockService ◄────────┘
                    │
         ┌──────────┴──────────┐
         ▼                     ▼
   ProductStock          StockMovement
   (source vérité)       (inventory_adjustment)
         │
         ▼
 products.stock_quantity (miroir legacy)

ActivityLogger → ActivityLog (workflow utilisateur)
```

---

### 4.31 Migration inventaire proto

| Élément | Action |
|---|---|
| `StockInventoryController` | Supprimer après 4E |
| `StockInventory/Index.vue` | Remplacer par `Inventory/Count.vue` |
| `StockInventoryTest` | Remplacer par `InventorySessionTest` |
| Menu BootstrapLayout | Pointer vers `/inventory` |
| Permissions `products.update` pour inventaire | Retirer |

**Période transition (4F) :** feature flag ou redirect avec bannière « nouvel inventaire ».

Objectif final : **une seule** logique d'inventaire.

---

### 4.32 Plan retrait `products.stock_quantity`

| Phase | Action |
|---|---|
| 4 | Miroir synchronisé à l'apply inventaire (comme BL/ventes) |
| 5 | Migrer lectures UI (Index produits, dashboard alertes) vers ProductStock MAIN |
| 6 | Supprimer colonne + Observer notifications basé sur ProductStock |

---

### 4.33 Multi-magasins futur

- `inventory_sessions.store_id` déjà prévu.
- V1 UI : store = MAIN implicite.
- V2 : sélecteur magasin à la création session.
- Aucun refactor module requis si `store_id` respecté partout.

---

### 4.34 Matrice tests future

| Cas | Type |
|---|---|
| Création session + items snapshot | Feature |
| Scan increment | Feature |
| Double scan +2 | Feature |
| Produit inconnu | Feature |
| Hors périmètre | Feature |
| qty négative refusée | Feature |
| Reprise session | Feature |
| Submit / validate / apply | Feature |
| Double apply idempotent | Feature |
| Cancel sans mouvement | Feature |
| Concurrence vente + apply (cas 1-4) | Feature |
| Company / store isolation | Feature |
| StockMovement metadata | Unit |
| Miroir legacy | Feature |
| Rollback apply | Feature |
| Permissions vendeur | Feature |
| Performance 100 items | Feature (seuil temps) |

E2E browser (douchette simulée Enter) : **optionnel 4G**.

---

### 4.35 Audit des risques

| Niveau | Risque | Impact | Prob. | Solution |
|---|---|---|---|---|
| **CRITIQUE** | Apply écrase mouvements concurrents | Stock faux | Moyenne | Delta vs stock courant (§4.11) |
| **CRITIQUE** | Double apply | Double ajustement | Faible | Statut + transaction |
| **CRITIQUE** | Proto reste actif en parallèle | Deux vérités | Moyenne | Suppression stricte post-4E |
| **ÉLEVÉ** | Non-comptés → zéro implicite | Rupture fictive | Moyenne | Règle §4.8 |
| **ÉLEVÉ** | Session sans ProductStock | Apply fail | Faible | Prérequis Phase 3C + check start |
| **MOYEN** | Deux sessions parallèmes même store | Confusion | Faible | Garde-fou métier V1.1 |
| **MOYEN** | Review sans alerte mouvements | Décision aveugle | Moyenne | KPI « mouvements depuis snapshot » |
| **FAIBLE** | Scan spam ActivityLog | Bruit audit | Faible | Logger batch ou seuil |

---

### 4.36 Décisions V1 / V2

| Décision | Recommandation | Justification | Version |
|---|---|---|---|
| Inventaire complet | V1 | Besoin core | V1 |
| Inventaire partiel catégorie | V1 | Pratique commerçant | V1 |
| Inventaire stock > 0 | V1 | Réduit bruit | V1 |
| Snapshot immutable | V1 | Audit | V1 |
| Apply vs stock courant | V1 | Concurrence sûre | V1 |
| Non-compté ≠ 0 | V1 | Sécurité métier | V1 |
| Douchette +1 scan | V1 | UX terrain | V1 |
| Saisie manuelle absolute | V1 | Complément | V1 |
| Workflow 6 états | V1 | Contrôle | V1 |
| Permissions inventory.* | V1 | Sécurité | V1 |
| Valeur financière | Ne pas afficher | Données non fiables | V2 |
| Ajout produit hors périmètre au scan | Refus strict | Simplicité | V1 ; ass SO V1.1 |
| Multi-session store | Warning | V1 ; block V1.1 | V1.1 |
| Journal scan unitaire | Non | Complexité | V2 |
| Multi-magasin UI | MAIN only | Périmètre | V2 |
| Miroir legacy | Conserver | Compat | V1-V5 |
| Annulation post-validate | Admin cancel only | — | V1 |

---

### 4.37 Plan d'implémentation

#### Phase 4A — Database + Models

- Migrations `inventory_sessions`, `inventory_items`
- Models + relations + casts + enums `InventorySessionStatus`, `InventoryScopeType`
- Factory test
- **Critères :** migrations rollback OK ; FK cohérentes

#### Phase 4B — Backend workflow

- `InventorySessionService` (draft → counting → …)
- Controller + routes + FormRequests + Policy + permissions seeder
- **Critères :** transitions statut testées ; vendeur bloqué

#### Phase 4C — Counting + HID

- `InventoryCountingService`
- `Inventory/Count.vue` + réutilisation BarcodeInput
- API increment/absolute
- **Critères :** scan +1 persisté ; reprise session

#### Phase 4D — Review + Validation

- `Inventory/Review.vue`
- Règles non-comptés ; KPI mouvements depuis snapshot (query StockMovement entre started_at et now)
- **Critères :** validate bloque si non-comptés

#### Phase 4E — Application StockService

- `InventoryApplicationService`
- Idempotence ; miroir legacy ; metadata mouvement
- Supprimer proto controller count
- **Critères :** cas concurrence §4.11 ; aucun write direct stock_quantity

#### Phase 4F — Frontend premium + migration menu

- Index, Create, Show
- Mobile responsive
- Redirect `/stock-inventory` → `/inventory`
- **Critères :** UX douchette desktop + mobile

#### Phase 4G — Tests + hardening

- `tests/Feature/InventorySessionTest.php` (matrice §4.34)
- Concurrence ; permissions ; régression BL/ventes/produits
- **Critères :** 100% cas critiques verts ; proto supprimé

---

### 4.38 Critères d'acceptation futurs

- [ ] Aucun comptage ne modifie directement `ProductStock`
- [ ] Application exclusivement via `StockService`
- [ ] Chaque ajustement produit un `StockMovement` `inventory_adjustment`
- [ ] Session non applicable deux fois
- [ ] Session annulée ne modifie jamais le stock
- [ ] Produit non compté jamais auto-zéro
- [ ] Vente concurrente non écrasée (delta vs courant)
- [ ] HID sans nouveau pipeline barcode
- [ ] Reprise navigateur OK
- [ ] Isolation Company / Store
- [ ] `products.stock_quantity` = miroir uniquement
- [ ] Proto inventaire supprimé
- [ ] Permissions `inventory.*` actives

---

### 4.39 Points nécessitant validation métier

1. **Auto-close après apply** ou étape `closed` manuelle ?
2. **Gestionnaire peut-il `apply`** ou admin seul ?
3. **Blocage strict** deux sessions actives même store ?
4. **Ajout dynamique** produit hors périmètre au scan en V1 ?
5. **Alerte obligatoire** si mouvements pendant session avant validate ?

**Recommandations par défaut :** auto-close OK ; apply = admin ; bloc warning V1 ; refus hors périmètre V1 ; alerte review oui (informatif).

---

*Document généré dans le cadre de la conception MKD-Pro — Phases 1, 2, 3A, 3B, 3C implémentées ; Phase 4 conçue ; Phase 4A implémentée.*

*PHASE 4 — CONCEPTION TERMINÉE — AUCUN CODE APPLICATIF MODIFIÉ (Phase 4A : fondation DB/modèles uniquement).*

---

## Phase 4A — Inventory foundation (Database + Models)

**Statut :** implémentée le 2026-07-31.

### Périmètre

Schéma + modèles Eloquent uniquement. Aucun workflow, route, service métier, UI ou effet sur le stock réel.

### Fichiers créés

| Fichier |
|---|
| `app/Enums/InventorySessionStatus.php` |
| `app/Enums/InventoryScopeType.php` |
| `app/Models/InventorySession.php` |
| `app/Models/InventoryItem.php` |
| `database/migrations/2026_08_21_000006_create_inventory_sessions_table.php` |
| `database/migrations/2026_08_21_000007_create_inventory_items_table.php` |
| `tests/Feature/InventoryFoundationTest.php` |

### Relations ajoutées

- `Company::inventorySessions()`
- `Store::inventorySessions()`
- `Product::inventoryItems()`

### Contraintes

- `UNIQUE(inventory_session_id, product_id)`
- `quantity_counted` nullable — **NULL ≠ 0**
- `stock_snapshot` integer NOT NULL
- FK acteurs : `nullOnDelete()` ; produit : `restrictOnDelete()`

### Prochaine étape

**Phase 4B** — workflow backend.

---

## Phase 4B — Backend Workflow Implemented

**Statut :** implémentée le 2026-08-22.

### Périmètre

Workflow backend transactionnel **draft → counting → review → validated** (+ `cancel`). Aucune application stock, aucun `StockMovement`, aucun remplacement du proto legacy.

### Services

| Fichier | Responsabilités |
|---|---|
| `app/Services/InventorySessionService.php` | `create`, `start`, `countItem`, `submit`, `validate`, `cancel`, `close` (refusé avant `applied`) |

### Transitions autorisées (Phase 4B)

| De | Vers | Méthode |
|---|---|---|
| — | `draft` | `create()` |
| `draft` | `counting` | `start()` |
| `counting` | `review` | `submit()` |
| `review` | `validated` | `validate()` |
| `draft/counting/review/validated` | `cancelled` | `cancel()` |
| `applied` | `closed` | `close()` — **refusé en 4B** (nécessite Phase 4E) |

**Interdit en 4B :** `validated → applied`, transitions arbitraires, `reopen` review → counting.

### Règles snapshot

- Source : `ProductStock.quantity` (magasin MAIN), jamais `products.stock_quantity`
- Figé au `start()`, immuable ensuite (modèle + service)
- Écart audit : `quantity_counted - stock_snapshot` (≠ delta d'application Phase 4E)

### Règles comptage

- Uniquement en statut `counting`
- `quantity_counted` nullable → non compté ; `0` = compté à zéro
- Produit hors `inventory_items` refusé
- Aucune mutation `ProductStock` / `StockMovement`

### Isolation

- `Company::getInstance()` pour toutes les opérations
- Magasin MAIN V1 uniquement
- Une session active par magasin (draft/counting/review/validated) — contrôle transactionnel

### Permissions (`PermissionSeeder`)

`inventory.view`, `inventory.create`, `inventory.count`, `inventory.submit`, `inventory.review`, `inventory.validate`, `inventory.apply` (préparée, non utilisée), `inventory.cancel`, `inventory.close`

### Routes

```
GET  /inventory
POST /inventory
POST /inventory/{session}/start
POST /inventory/{session}/items/{item}/count
POST /inventory/{session}/submit
POST /inventory/{session}/validate
POST /inventory/{session}/cancel
POST /inventory/{session}/close
```

### Tests

- `tests/Feature/InventoryWorkflowTest.php` — 40 tests
- Régression : 182 tests PHP passés

### Limites restantes

- Pas d'`apply` stock (Phase 4E)
- Pas de douchette (Phase 4C)
- Pas d'UI premium (Phase 4F)
- `StockInventoryController` legacy inchangé

### Prochaine étape

**Phase 4C** — comptage HID / douchette.

---

## Phase 4C — Comptage HID

**Statut :** implémentée le 2026-08-22.

### Flux de scan

```
Douchette HID → BarcodeInput → POST /inventory/{session}/scan
    → ProductBarcodeService (normalisation + lookup)
    → vérification InventoryItem dans la session
    → quantity_counted : NULL → 1, sinon +1
    → réponse JSON immédiate
```

### Endpoint

`POST /inventory/{session}/scan` — route `inventory.scan`

Corps : `{ "barcode": "..." }`

Permission : `inventory.count`

### Sécurité

- Isolation `Company::getInstance()`
- Session en statut `counting` obligatoire
- Produit hors périmètre refusé
- Code-barres inconnu refusé
- `lockForUpdate()` sur session + item (concurrence)

### NULL vs 0

- Premier scan : `NULL → 1` (jamais `NULL → 0`)
- Produit compté à `0` puis scan : `0 → 1`

### Absence de mutation stock

- Aucune modification `ProductStock` / `products.stock_quantity`
- Aucun `StockMovement`
- Aucun `ActivityLog` par scan (volumétrie)

### Frontend

- `Inventory/Index.vue` — mode comptage avec `BarcodeInput`
- Utilitaires : `resources/js/utils/inventoryCounting.ts`

### Tests

- `tests/Feature/InventoryCountingTest.php` — 18 tests
- `resources/js/utils/inventoryCounting.test.ts` — 5 tests

### Prochaine étape

**Phase 4D** — review / submit / validate (implémentée ci-dessous).

---

## Phase 4D — Review / Submit / Validate

**Statut :** implémentée le 2026-08-22.

### Workflow

```
counting → submit → review → validate → validated
review → reopen → counting (correction avant validation)
```

### Règles NULL / 0

- `quantity_counted IS NULL` → **non compté** (bloque submit)
- `quantity_counted = 0` → **compté à zéro** (submit autorisé)
- Jamais de conversion automatique `NULL → 0`

### Progression

Payload `progress` :

- `total`, `counted`, `uncounted`, `percentage`
- Unités comptées via `summary.total_units`

### Écarts (indicateurs review uniquement)

- `difference = quantity_counted - stock_snapshot`
- Statuts : `conforme`, `manque`, `surplus`, `uncounted`
- **Ne modifie pas** le stock réel (Phase 4E uniquement)

### Submit (`counting → review`)

- Permission : `inventory.submit`
- Transaction + `lockForUpdate()`
- Refus si produits NULL (réponse structurée : totaux + liste limitée)
- Idempotent : double submit refusé proprement
- ActivityLog : « a terminé le comptage de l'inventaire … »

### Review

- Page de contrôle : résumé écarts, filtres, tableau desktop / cartes mobile
- Permission réouverture : `inventory.review` → `POST /inventory/{session}/reopen`

### Validate (`review → validated`)

- Permission : `inventory.validate`
- Transaction + `lockForUpdate()`
- Refus si NULL ou statut incorrect
- ActivityLog : « a validé l'inventaire … »
- État affiché : **« Inventaire validé — en attente d'application »**

### Permissions utilisées

`inventory.view`, `inventory.count`, `inventory.submit`, `inventory.review`, `inventory.validate` (pas de bouton apply fonctionnel — Phase 4E)

### Absence de mutation stock

Submit et validate :

- **Aucune** modification `ProductStock.quantity`
- **Aucune** modification `products.stock_quantity`
- **Aucun** `StockMovement`
- **Aucun** appel `StockService`

### Backend

- `InventorySessionService` : `getProgress()`, `getSummary()`, `getUncountedItems()`, `canSubmit()`, `canValidate()`, `reopen()`, `formatSessionDetailPayload()`
- Routes : `inventory.submit`, `inventory.reopen`, `inventory.validate`

### Tests

- `tests/Feature/InventoryReviewTest.php`
- `resources/js/utils/inventoryCounting.test.ts` (filtres, résumé, NULL/0)

### Prochaine étape

**Phase 4F** — UI premium / UX inventaire.

---

## Phase 4E — Application du stock

**Statut :** implémentée le 2026-08-22.

### Workflow

```
validated → apply → applied → close → closed
```

### InventoryApplicationService

- Vérifie `status === validated`
- Transaction globale + `lockForUpdate()` session et items
- Pour chaque item (`product_id ASC`) :
  - verrouille `ProductStock`
  - `delta = quantity_counted - stock_courant`
  - `delta > 0` → `StockService::increase(InventoryAdjustment)`
  - `delta < 0` → `StockService::decrease(InventoryAdjustment)`
  - `delta === 0` → aucun mouvement
- Synchronise le miroir `products.stock_quantity` après chaque ajustement
- Persiste `application_summary` sur la session

### Règle absolue

**Le snapshot n'est jamais utilisé comme nouvelle quantité de stock.**

Formule d'application :

```
delta = quantity_counted - stock_courant_au_moment_apply
```

### StockMovement

- Type : `inventory_adjustment`
- Référence morph : `InventorySession`
- Metadata : `inventory_session_id`, `stock_snapshot`, `quantity_counted`, `stock_before_apply`, `stock_after_apply`, `delta_from_current`, `variance_from_snapshot`, `source`

### Idempotence

Double apply refusé (`status === applied`).

### Close

`applied → closed` via `inventory.close` (déjà présent en 4B, activé en 4E).

### Retrait proto legacy

Supprimés :

- `StockInventoryController`
- `resources/js/pages/StockInventory/Index.vue`
- Routes `/stock-inventory`
- `tests/Feature/StockInventoryTest.php`

Menu → `/inventory` (`inventory.view`).

### Tests

- `tests/Feature/InventoryApplicationTest.php`

### Prochaine étape

**Phase 4F** — UI premium / UX inventaire (implémentée le 2026-08-22).

---

# Phase 4G — Audit & Consolidation

**Statut :** audit documentaire uniquement — **aucune modification de code applicatif** (2026-08-22).

**Objectif :** consolider l'état réel du module Inventaire après les phases 4A→4F, identifier les risques avant mise en production, et proposer une roadmap priorisée sans implémentation.

---

## 1. Résumé exécutif

Le module Inventaire MKD-Pro est **architecturalement sain** : workflow complet, séparation snapshot/comptage/application, intégration correcte avec `StockService`, suppression du proto legacy, tests backend solides (129 tests inventaire).

**Points forts majeurs :**

- Formule d'application `delta = quantity_counted - stock_courant` correcte et testée (scénario vente concurrente pendant comptage).
- Idempotence apply/close/submit/validate protégée par statut + `lockForUpdate`.
- Isolation Company via `Company::getInstance()` + assertions 404.
- RBAC backend complet sur toutes les routes.
- Traçabilité workflow via `ActivityLog` + journal `StockMovement` à l'application.
- UI Phase 4F fonctionnelle pour inventaires modestes (< ~500 produits).

**Risques prioritaires avant production à grande échelle :**

| Priorité | Risque | Gravité |
|----------|--------|---------|
| P0 | Ordre de verrouillage inversé apply inventaire vs ventes/BL (deadlock) | **CRITIQUE** |
| P0 | Payload JSON monolithique (tous les items) sur show/start/apply | **HAUTE** |
| P1 | Transaction apply monolithique sur N produits (timeout) | **HAUTE** |
| P1 | Pagination liste sessions ignorée côté frontend | **HAUTE** |
| P1 | Liste produits non paginée/virtualisée (gros inventaires) | **HAUTE** |
| P2 | Preview application stale (sans verrou) | **MOYENNE** |
| P2 | Miroir legacy non resynchronisé sur items delta=0 | **MOYENNE** |
| P2 | Comptage sérialisé (lock session entière par scan) | **MOYENNE** |

**Verdict :** prêt pour **production mono-magasin MAIN** avec inventaires **< 500 produits**. Au-delà, des optimisations performance et fiabilisation concurrence sont nécessaires avant déploiement intensif.

*(Sections 2 à 22 : voir contenu complet ci-dessous.)*

---

## 2. État actuel

### Backend

| Composant | Rôle | État |
|-----------|------|------|
| `InventorySession` | Session workflow, statuts, scope, application_summary | OK |
| `InventoryItem` | Snapshot immuable, comptage nullable/0, garde-fous Eloquent | OK |
| `InventorySessionService` | create/start/count/submit/review/validate/cancel/close | OK |
| `InventoryApplicationService` | apply via StockService, preview, rollback | OK |
| `InventorySessionController` | Inertia + JSON workflow, permissions, IDOR Company | OK |
| `StockService` | Moteur central increase/decrease/adjust | Inchangé, conforme |
| `StockMovementType::InventoryAdjustment` | Type mouvement inventaire | OK |

### Frontend

| Composant | Rôle | État |
|-----------|------|------|
| `Inventory/Index.vue` | Orchestrateur liste/détail | OK |
| `InventoryListView.vue` | Dashboard + liste sessions | OK sauf pagination |
| `InventoryDetailView.vue` | Workspace comptage/review/apply | OK sauf gros volumes |
| `components/inventory/*` | 7 composants présentation | OK |
| `inventoryCounting.ts` / `inventoryUi.ts` | Helpers filtres/progression | OK, testés Vitest |
| `BarcodeInput` + scan endpoint | HID douchette | OK, pipeline inchangé |

### Tests existants

| Fichier | Tests | Couverture |
|---------|-------|------------|
| `InventoryFoundationTest.php` | 16 | Modèles, contraintes, snapshot |
| `InventoryWorkflowTest.php` | 40 | Workflow, permissions, concurrence store |
| `InventoryCountingTest.php` | 18 | Scan HID, increments, isolation |
| `InventoryReviewTest.php` | 24 | Submit/validate/reopen, summary |
| `InventoryApplicationTest.php` | 31 | Apply/close, delta courant, rollback |
| **Total backend** | **129** | |
| Vitest (`inventory*.test.ts`) | 17 | Filtres, UI helpers, application flags |

---

## 3. Points solides

1. Séparation des responsabilités : comptage ≠ application ≠ stock courant.
2. Snapshot immuable après `start()` (garde-fou modèle + tests).
3. Scénario concurrence vente validé par tests automatisés.
4. Atomicité apply avec rollback complet.
5. Une session active par magasin.
6. Références uniques par company.
7. Pas de log ActivityLog par scan.
8. Permissions granulaires alignées workflow.
9. Proto legacy supprimé.
10. UI mobile partielle opérationnelle.

---

## 4. Risques critiques

### R-CRIT-01 — Deadlock apply inventaire vs ventes/BL

| Attribut | Valeur |
|----------|--------|
| **Gravité** | CRITIQUE |
| **Fichiers** | `InventoryApplicationService::apply()` ; `SaleStockService` ; `PurchaseOrderDeliveryService` |
| **Cause** | Ordre de verrouillage incohérent : inventaire `ProductStock → Product` ; ventes/BL `Product → ProductStock`. |
| **Impact** | Deadlock MySQL si apply et vente/BL concurrentes sur mêmes produits. |
| **Recommandation** | Uniformiser : **Product (ASC) → ProductStock (ASC) → InventorySession**. |
| **Priorité** | P0 — Phase 4G-1 |

### R-CRIT-02 — Payload JSON O(N) complet

| Attribut | Valeur |
|----------|--------|
| **Gravité** | CRITIQUE (à l'échelle) |
| **Fichiers** | `formatSessionDetailPayload()` ; controller JSON responses |
| **Cause** | Tous les items embarqués dans chaque réponse workflow. |
| **Impact** | Timeout dès ~1 000–5 000 produits. |
| **Recommandation** | Items paginés ; réponses workflow légères. |
| **Priorité** | P0 — Phase 4G-2 |

---

## 5. Risques importants (HAUTE)

| ID | Problème | Fichier | Recommandation | Priorité |
|----|----------|---------|----------------|----------|
| R-HIGH-01 | Transaction apply monolithique | `InventoryApplicationService::apply()` | Batch/chunk interne, monitoring timeout | P1 |
| R-HIGH-02 | Pagination sessions ignorée | `InventoryListView.vue` | `PagePagination` | P1 |
| R-HIGH-03 | Liste produits non virtualisée | `InventoryProductList.vue` | Pagination serveur ou virtual scroll | P1 |
| R-HIGH-04 | Snapshot start sans lock ProductStock | `InventorySessionService::start()` | Documenter ; lock optionnel | P2 |
| R-HIGH-05 | Scanner HID perte de focus | `InventoryDetailView.vue` | Refocus auto ; placeholder recherche | P1 |

---

## 6. Risques moyens

| ID | Problème | Priorité |
|----|----------|----------|
| R-MED-01 | Preview application stale | P2 |
| R-MED-02 | Miroir legacy non sync si delta=0 | P2 |
| R-MED-03 | Comptage sérialisé (lock session/scan) | P2 |
| R-MED-04 | `can_submit`/`can_validate` ignorés UI | P2 |
| R-MED-05 | Erreurs HTTP non-JSON mal parsées | P2 |
| R-MED-06 | Create modal sans onError | P3 |
| R-MED-07 | Migrations historiques Pending (media, activity_logs) | P3 |
| R-MED-08 | `RefreshProductsStockExpirationCommand` bypass StockService | P3 |
| R-MED-09 | UI/notifications lisent miroir legacy | P3 |

---

## 7. Performance

### Seuils estimés (inventaire complet, MAIN)

| Volume | start() | Payload show | apply() | UI |
|--------|---------|--------------|---------|-----|
| 100 | OK | ~50–80 Ko | OK | Fluide |
| 500 | OK | ~200–400 Ko | Limite | Acceptable |
| 1 000 | Limite | ~400–800 Ko | Risque timeout | Lag |
| 5 000 | Risque timeout | ~2–4 Mo | Timeout probable | Inutilisable |
| 10 000 | Timeout probable | ~4–8 Mo | Timeout très probable | Inutilisable |

### start() — points clés

- Insert batch chunks **500** — bon.
- Snapshot lu sans lock ProductStock — fenêtre courte acceptable MVP.
- Réponse JSON start inclut tous items — à supprimer en 4G-2.

### apply() — points clés

- ~3–5 requêtes SQL + savepoint nested par item ajusté.
- Ne jamais bypass StockService pour performance.
- Batch future : chunks 100–200 product_id ASC dans transaction unique.

### Frontend

- Filtres O(n) client, pas de debounce.
- Double DOM desktop+mobile.
- Seuil problématique : **~500–1 000 produits**.

---

## 8. Concurrence

### Scénario validé

```
start → snapshot=100 ; vente -10 → stock=90 ; count=90 ; apply delta=0 → OK
```

### Matrice scénarios

| Scénario | Protection | Risque |
|----------|------------|--------|
| Double apply/close/submit | Statut + lock | OK |
| Deux scans simultanés | Lock session+item | Sérialisé, cohérent |
| Apply + vente/BL | Locks ordre différent | **Deadlock** |
| Second inventaire même store | assertNoActiveSessionOnStore | OK |
| Session autre Company | 404 | OK |
| Reopen | review → counting | OK |

### Stratégie verrous recommandée

```
Product (id ASC) → ProductStock → InventorySession/Item
```

---

## 9. Sécurité / RBAC

Permissions complètes : view, create, count, submit, review, validate, apply, cancel, close.

Protections IDOR : company_id, item.session_id, store validation.

Frontend ne remplace jamais backend — conforme.

Manque : test HTTP IDOR item cross-session.

---

## 10. Audit / traçabilité

**ActivityLog :** create, start, submit, validate, cancel, close, apply — pas de log par scan.

**StockMovement :** before/after, metadata riche, référence InventorySession.

**Couverture audit :** qui/quand workflow OK ; comptage par item via counted_by ; ajustements via StockMovement + application_summary.

**Manque :** export agrégé ; lien UI item→movement ; commentaire libre par ajustement.

---

## 11. UX (Phase 4F)

Positif : scanner HID, dernier scan, confirmations apply/validate, badges FR, mobile cartes.

À améliorer : pagination sessions, focus scanner, can_submit/validate serveur, bannière cancelled.

---

## 12. Gros volumes — stratégie

**Backend :** API items paginée, recherche serveur, workflow responses légères, apply batch interne.

**Frontend :** virtualisation ou pagination, filtre serveur, rendu unique responsive.

---

## 13. Export PDF / Excel (conception)

**PDF :** page synthèse KPI + détail écarts + annexe mouvements.

**Excel :** feuilles Résumé, Détail, Écarts, Mouvements.

**Source :** inventory_items + StockMovement InventoryAdjustment + application_summary.

Déclencheur : session `closed`.

---

## 14. Historique `/inventory/history` (conception)

Réutiliser `InventoryListView` avec filtres closed/applied, période, magasin, reference.

Backend : scopes + pagination existante.

---

## 15. Notifications (conception)

**Utiles :** soumis (review), validé, appliqué, annulé.

**Inutiles :** scan, start, close seul.

Intégration NotificationCenter existante, respect critical_only.

---

## 16. Multi-magasin readiness (~40%)

**Prêt :** store_id, ProductStock par store, apply StockService, assertNoActiveSessionOnStore.

**Non prêt :** création forcée MAIN, UI sélection magasin, permissions store, dashboard par magasin.

---

## 17. products.stock_quantity

**B — Miroir :** SaleStock, InventoryApplication, PurchaseOrderDelivery, ProductStockInit.

**C — Lecture :** Dashboard, notifications, autocomplete, Products UI.

**E — Risque :** RefreshProductsStockExpirationCommand.

**Stratégie :** lectures → ProductStock → CI blocking → suppression colonne (future).

---

## 18. stock:check-consistency — extension `--inventory`

Contrôles proposés : session active sans items, applied sans movements, items NULL en closed, InventoryAdjustment orphelin.

---

## 19. Tests manquants

| Test | Classification |
|------|----------------|
| Deadlock apply + vente | CRITIQUE |
| Apply 1000+ produits | IMPORTANT |
| HTTP IDOR item cross-session | IMPORTANT |
| Preview stale | IMPORTANT |
| Miroir divergent delta=0 | IMPORTANT |
| Pagination sessions > 20 | IMPORTANT |
| Application après mouvements concurrents | CRITIQUE |

---

## 20. Roadmap priorisée

| Phase | Objectif | Effort |
|-------|----------|--------|
| **4G-1 Fiabilisation** | Verrous, pagination sessions, tests deadlock, scanner focus | 2–4 j |
| **4G-2 Performance** | Items paginés, workflow léger, virtualisation, apply batch | 5–8 j |
| **4G-3 Historique/exports** | /history, PDF, Excel | 4–6 j |
| **4G-4 Notifications** | 4 événements ciblés | 2–3 j |
| **4G-5 Multi-magasin** | Sélection store, permissions, filtres | 5–10 j |

---

## 21. Synthèse problèmes

| Gravité | Nombre |
|---------|--------|
| CRITIQUE | 2 |
| HAUTE | 5 |
| MOYENNE | 9 |
| FAIBLE | 5 |
| **Total** | **21** |

---

## 22. Prochaine phase recommandée

**Phase 4G-1 — Fiabilisation** : ordre verrous (R-CRIT-01) + pagination sessions (R-HIGH-02).

---

# Phase 4G-1 — Fiabilisation

*Document mis à jour le 2026-07-31 — Phase 4G-1 implémentée.*

## Stratégie de verrouillage

**Ordre retenu (intercalé, product_id ASC)** : pour chaque produit, `Product` puis `ProductStock`, identique à `SaleStockService` et `PurchaseOrderDeliveryService`.

Service central : `App\Services\StockLockOrdering` avec `lockProductAndStock()` et `lockManyProductsAndStocks()`.

`InventoryApplicationService::apply()` :
1. Verrouille la session et les items inventaire
2. Acquiert tous les verrous Product → ProductStock via `StockLockOrdering` (tri ASC)
3. Calcule `delta = quantity_counted - stock_courant` (jamais vs snapshot)
4. Mutations via `StockService::increase/decrease()`
5. Miroir `products.stock_quantity` sur Product déjà verrouillé (sans second lock)

**Pourquoi cela évite le deadlock R-CRIT-01** : avant apply verrouillait ProductStock puis Product (miroir) ; ventes/BL verrouillent Product puis ProductStock. L’ordre inverse créait un cycle d’attente. L’uniformisation Product → ProductStock supprime ce cycle.

`StockService::adjust()` conserve son `lockForUpdate()` sur ProductStock (réentrant dans la même transaction).

## Pagination serveur

Backend : `paginate(20)->withQueryString()` dans `InventorySessionController::index`.

Frontend : `InventoryListView.vue` consomme `sessions.links/from/to/total` via `PagePagination` (pattern identique aux BL). Navigation Inertia, filtres conservés dans les query params.

## Scanner HID / focus

Helper `shouldRefocusInventoryScanner()` dans `inventoryCounting.ts`.

`InventoryDetailView.vue` : refocus automatique après scan (succès/erreur/réseau), fermeture modale quantité, annulation SweetAlert — sauf si recherche active, modale ouverte, workflow en cours.

`BarcodeInput.vue` non modifié.

## Tests ajoutés

| Fichier | Contenu |
|---------|---------|
| `InventoryLockOrderTest.php` | Niveau 1 — ordre Product → ProductStock ASC (DB::listen) |
| `InventoryConcurrencyTest.php` | Niveau 2 séquentiel — vente puis apply, delta sur stock courant |
| `InventoryIdorTest.php` | HTTP 404 cross-entreprise (show, scan, count, submit, validate, apply, cancel, close) |
| `inventoryCounting.test.ts` | `shouldRefocusInventoryScanner` |

## Limites

- Concurrence multi-processus MySQL réelle non exécutable en PHPUnit mono-processus (documenté).
- Payload JSON O(N) sur show/start/apply : hors périmètre 4G-1 (Phase 4G-2).

*Document mis à jour le 2026-08-22 — Phase 4G audit READ-ONLY.*
