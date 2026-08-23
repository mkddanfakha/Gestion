# RBAC — Normalisation edit → update (Phase G)

**Date :** 2026-08-23  
**Statut :** Implémenté  
**Références :** [`rbac-authorization.md`](./rbac-authorization.md), [`rbac-permission-catalog.md`](./rbac-permission-catalog.md)

---

## 1. État initial

Le projet utilisait deux conventions parallèles pour la modification :

- `*.edit` — permission legacy (accès formulaire GET)
- `*.update` — permission canonique (action PUT/PATCH)

Les contrôleurs vérifiaient souvent `edit` pour les pages de formulaire, tandis que `RolePresets` utilisait déjà `update`.

---

## 2. Permissions concernées (10 paires)

| Legacy | Canonique | Statut |
|--------|-----------|--------|
| `products.edit` | `products.update` | legacy |
| `categories.edit` | `categories.update` | legacy |
| `customers.edit` | `customers.update` | legacy |
| `sales.edit` | `sales.update` | legacy |
| `quotes.edit` | `quotes.update` | legacy |
| `expenses.edit` | `expenses.update` | legacy |
| `suppliers.edit` | `suppliers.update` | legacy |
| `purchase-orders.edit` | `purchase-orders.update` | legacy |
| `delivery-notes.edit` | `delivery-notes.update` | legacy |
| `company.edit` | `company.update` | legacy |

**Hors périmètre Phase G :** `inventory.review` → `inventory.reopen` (Phase H).

---

## 3. Mapping legacy → canonique

Géré par `PermissionCatalog::legacyMappings()` et `AuthorizationService::resolveAliases()` :

- Vérifier `products.update` accepte un utilisateur avec `products.edit` en base
- Vérifier `products.edit` accepte un utilisateur avec `products.update` en base
- Bidirectionnel pendant la transition

---

## 4. Contrôleurs migrés

Tous les `checkPermission(..., 'edit')` → `checkPermission(..., 'update')` :

| Contrôleur | Méthode |
|------------|---------|
| `ProductController` | `edit()` + `ensureUserCanSearchProducts()` |
| `CategoryController` | `edit()` |
| `CustomerController` | `edit()` + `autocomplete()` |
| `SaleController` | `edit()` |
| `QuoteController` | `edit()` |
| `ExpenseController` | `edit()` |
| `SupplierController` | `edit()` + `autocomplete()` |
| `PurchaseOrderController` | `edit()` |
| `DeliveryNoteController` | `edit()` |

`CompanyController` utilisait déjà `update` pour les mutations et `view` pour la page.

---

## 5. Frontend migré

| Fichier | Changement |
|---------|------------|
| `usePermissions.ts` | `canUpdate()` canonique + compat `edit` ; `canEdit()` = alias |
| `Products/Index.vue` | `canUpdate('products')` |
| `Products/Show.vue` | `canUpdate('products')` |
| `Customers/Show.vue` | `canUpdate('customers')` |
| `DeliveryNotes/Show.vue` | `canUpdate('delivery-notes')` |
| `Admin/Users/Create.vue` | Presets UI : uniquement `*.update` |
| `Admin/Users/Edit.vue` | Presets UI : uniquement `*.update` |

**Non modifiés (noms de route / pages) :** `route('products.edit')`, `Products/Edit.vue`, etc.

---

## 6. RolePresets

Déjà conformes depuis Phase D — uniquement `PermissionName::*Update`. Aucune modification.

---

## 7. Utilisateurs existants

- **Aucun force-sync**
- **Aucune modification massive de `user_permissions`**
- Compatibilité via `AuthorizationService` + `canUpdate()` frontend
- Un utilisateur avec uniquement `products.edit` conserve l'accès modification

---

## 8. Seeder

`PermissionSeeder` inchangé — idempotent, conserve les permissions legacy en base.

Test : double exécution → pivot utilisateur identique.

---

## 9. Stratégie de suppression future

La suppression physique des lignes `*.edit` en base sera traitée dans une phase ultérieure après :

1. Audit des pivots `user_permissions` encore sur `*.edit`
2. Migration ciblée utilisateur par utilisateur (ou script admin)
3. Période de compatibilité validée en production

---

## 10. Occurrences `*.edit` restantes (justifiées)

| Type | Exemple | Justification |
|------|---------|---------------|
| A — Legacy DB | `permissions.name = products.edit` | Conservé volontairement |
| A — Enum | `PermissionName::ProductsEdit` | Référence catalogue legacy |
| B — Route | `route('products.edit')` | Nom de route Laravel, pas permission |
| C — Méthode | `ProductController::edit()` | Action HTTP GET formulaire |
| D — Composant | `Products/Edit.vue` | Page UI |
| E — Documentation | `docs/rbac-edit-update.md` | Référence |
| F — Test compat | `EditUpdateCompatibilityTest` | Vérifie legacy |

---

## 11. Risques résiduels

- UI admin affiche encore les deux permissions `edit` et `update` dans la grille de sélection (données catalogue)
- `inventory.review` → `inventory.reopen` (Phase H)
- Suppression physique des legacy `*.edit` (phase future)
