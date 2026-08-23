# RBAC MKD-Pro — Rapport d’audit (Phase 0–1)

**Date :** 2026-08-22 (complété Phase A le 2026-08-23)  
**Statut :** AUDIT TERMINÉ — architecture cible dans [`docs/rbac-architecture.md`](./rbac-architecture.md)  
**Périmètre :** inventaire complet avant stabilisation / durcissement  
**Spatie Permission :** **non utilisé** (système maison)  
**Implémentation :** en attente de validation GO (Phases C+)

---

## 1. Architecture actuelle

### 1.1 Modèle conceptuel réel

```
User
 ├── role (string sur users.role)     ← PAS de table roles / modèle Role
 └── permissions (N–N via user_permissions)
        └── Permission (resource + action → name = "{resource}.{action}")
```

**Il n’existe pas de chaîne User → Role → Permission au sens table `roles`.**  
Le « rôle » est une **étiquette métier** (`admin`, `vendeur`, `gestionnaire`, `user`) stockée en colonne. Les permissions granulaires sont attachées **directement à l’utilisateur**.

### 1.2 Bypass administrateur

```
User.role === 'admin'
  → User::hasPermission() / hasPermissionByName() = true (toujours)
  → Inertia : auth.user.permissions = [] (liste vide volontaire)
  → Frontend usePermissions() : isAdmin → true pour toutes les vérifications
```

### 1.3 Entreprise / magasin (contexte isolation)

```
Installation mono-entreprise (pratique actuelle)
  Company::getInstance()  ← singleton métier
       └── Store (plusieurs magasins possibles par company)
            └── ProductStock / InventorySession / StockMovement

User ──✗── Company   (aucune relation User ↔ Company)
User ──✗── Store     (aucune relation User ↔ Store)
```

L’isolation inventaire repose sur `company_id` de la session + `Company::getInstance()`, **pas** sur un tenant utilisateur.

### 1.4 Où chaque relation est stockée

| Relation | Stockage |
|----------|----------|
| User.role | `users.role` (string) |
| User ↔ Permission | pivot `user_permissions` (`user_id`, `permission_id`) |
| Permission | table `permissions` (`name`, `resource`, `action`, `description`) |
| Company | table `companies` (singleton via `getInstance()`) |
| Store | table `stores` (`company_id`, …) |
| Role entity | **inexistant** |
| Role ↔ Permission | **inexistant** (presets hardcodés dans `Admin\UserController`) |

---

## 2. Modèles

### 2.1 User (`users`)

| Aspect | Détail |
|--------|--------|
| Table | `users` |
| Relations | `permissions()` BelongsToMany ; `activityLogs()` HasMany |
| Traits | HasFactory, Notifiable, TwoFactorAuthenticatable |
| Casts | email_verified_at, password hashed, 2FA, is_active bool |
| Constantes | `ROLE_ADMIN`, `ROLE_USER`, `ROLE_VENDEUR`, `ROLE_GESTIONNAIRE` |
| Méthodes RBAC | `isAdmin()`, `hasRole()`, `isVendeur()`, `isGestionnaire()`, `hasPermission()`, `hasPermissionByName()`, `getPermissionsArray()` |
| Risques | Bypass admin total ; `hasPermission` fait une requête SQL à chaque appel (pas de cache) ; pas de lien company/store |

### 2.2 Permission (`permissions`)

| Aspect | Détail |
|--------|--------|
| Table | `permissions` |
| Relations | `users()` BelongsToMany |
| Unicité | `name` unique ; `(resource, action)` unique |
| Helper | `Permission::generateName($resource, $action)` → `"{$resource}.{$action}"` |
| Risques | Noms avec tirets (`purchase-orders`, `delivery-notes`) vs convention cible `module.action` OK mais modules non uniformes en naming |

### 2.3 Role

**Absent.** Pas de modèle, pas de migration `roles`, pas de `RoleSeeder`.

### 2.4 Company (`companies`)

| Aspect | Détail |
|--------|--------|
| Relations | `stores()`, `stockMovements()`, `inventorySessions()`, `defaultStore()` |
| Méthode clé | `Company::getInstance()` |
| Lien User | **aucun** |
| Risques | Multi-entreprise réelle non supportée au niveau User ; isolation = installation, pas tenant |

### 2.5 Store (`stores`)

| Aspect | Détail |
|--------|--------|
| Relations | `company()`, `productStocks()`, `stockMovements()`, `inventorySessions()` |
| Lien User | **aucun** |
| Risques | Scope magasin par utilisateur non préparé côté RBAC |

---

## 3. Permissions existantes (source : `PermissionSeeder`)

**Total seedé : 74 permissions** (idempotent via `firstOrCreate` sur `resource` + `action`).

`PermissionSeeder` **n’est pas appelé** par `DatabaseSeeder` — seed manuel / tests uniquement.

### 3.1 Liste complète par module

#### products.* (5)
- `products.view`, `products.create`, `products.edit`, `products.update`, `products.delete`

#### categories.* (5)
- `categories.view`, `categories.create`, `categories.edit`, `categories.update`, `categories.delete`

#### customers.* (6)
- `customers.view`, `customers.create`, `customers.edit`, `customers.update`, `customers.delete`, `customers.export`

#### sales.* (6)
- `sales.view`, `sales.create`, `sales.edit`, `sales.update`, `sales.delete`, `sales.invoice`

#### quotes.* (7)
- `quotes.view`, `quotes.create`, `quotes.edit`, `quotes.update`, `quotes.delete`, `quotes.download`, `quotes.print`

#### expenses.* (5)
- `expenses.view`, `expenses.create`, `expenses.edit`, `expenses.update`, `expenses.delete`

#### suppliers.* (6)
- `suppliers.view`, `suppliers.create`, `suppliers.edit`, `suppliers.update`, `suppliers.delete`, `suppliers.export`

#### purchase-orders.* (7)
- `purchase-orders.view`, `purchase-orders.create`, `purchase-orders.edit`, `purchase-orders.update`, `purchase-orders.delete`, `purchase-orders.download`, `purchase-orders.print`

#### delivery-notes.* (8)
- `delivery-notes.view`, `delivery-notes.create`, `delivery-notes.edit`, `delivery-notes.update`, `delivery-notes.delete`, `delivery-notes.validate`, `delivery-notes.download`, `delivery-notes.print`

#### company.* (3)
- `company.view`, `company.edit`, `company.update`

#### dashboard.* (1)
- `dashboard.view`

#### inventory.* (10)
- `inventory.view`, `inventory.create`, `inventory.count`, `inventory.submit`, `inventory.review`, `inventory.validate`, `inventory.apply`, `inventory.cancel`, `inventory.close`, `inventory.export`

#### backups.* (5)
- `backups.view`, `backups.create`, `backups.download`, `backups.delete`, `backups.restore`

### 3.2 Permissions absentes du catalogue (modules métier existants)

Aucune permission dédiée seedée pour :
- notifications (accès utilisateur = auth ; admin settings = middleware admin)
- audit log / activity-logs (admin only via policy + middleware)
- users / roles / permissions administration (admin only)
- stock (pas de module permissions `stock.*` — stock via inventaire / produits)
- attachments (autorisation via `AttachmentAuthorizer`, pas permissions seedées)
- form drafts (auth only)

### 3.3 Doublons / incohérences de nommage

| Problème | Détail |
|----------|--------|
| `edit` vs `update` | Présents en parallèle sur presque tous les modules CRUD. Backend : `edit` pour formulaires GET, `update` pour PUT/PATCH. Frontend `canEdit` / `canUpdate` séparés. Sémantique redondante pour l’admin UI. |
| `company.edit` | Seedée mais contrôleurs utilisent surtout `company.view` + `company.update` |
| `inventory.review` | Nom permission = review ; action métier UI/route = **reopen** (`inventory.reopen`) |
| Modules kebab-case | `purchase-orders`, `delivery-notes` vs `products` (pluriel anglais) — cohérent en interne mais à documenter |
| Pas de `sales.export` | Facture = `sales.invoice` |
| Pas de `products.export` | Non seedé |

### 3.4 Usages seed vs code

| Cas | Observation |
|-----|-------------|
| Seed sans usage probable | `company.edit` (peu/pas de `checkPermission(..., 'edit')`) |
| Backend sans permission seed | Admin users, activity-logs, notifications settings (rôle admin) |
| Frontend + backend | Inventaire : props `permissions` + `checkPermission` sur chaque action |
| Autocomplétions | Permissions « OR » métier (`sales.create` OR `products.view`, etc.) — documentées dans contrôleurs |

---

## 4. Rôles existants

### 4.1 Valeurs `users.role`

| Valeur | Constante | Comportement permissions |
|--------|-----------|--------------------------|
| `admin` | `ROLE_ADMIN` | Bypass total ; pivot vidé à create/update |
| `vendeur` | `ROLE_VENDEUR` | Preset hardcodé sync à create/update |
| `gestionnaire` | `ROLE_GESTIONNAIRE` | Preset hardcodé sync à create/update |
| `user` | `ROLE_USER` | Permissions manuelles (checkboxes admin) |

### 4.2 Preset Vendeur (`Admin\UserController::getVendeurPermissionIds`)

- sales : view, create, edit, update, delete, invoice  
- quotes : toutes  
- products : view uniquement  
- customers : view, create, edit, update  
- **Pas d’inventaire, dépenses, fournisseurs, BC, BL, company, dashboard, backups**

### 4.3 Preset Gestionnaire

- dashboard.view  
- products.*, categories.*, quotes.*, expenses.*, suppliers.*, purchase-orders.*, delivery-notes.*  
- **Pas de sales.***, **pas de customers.***, **pas d’inventory.***, **pas de company.***, **pas de backups.***

### 4.4 Protection rôle admin

| Contrôle | État |
|----------|------|
| Routes `/admin/*` | `EnsureUserIsAdmin` |
| Auto-suppression | Interdite (ne peut pas supprimer son propre compte) |
| Dernier admin | **Non protégé** — un admin peut supprimer/rétrograder le dernier admin |
| Création d’admin | Tout admin peut créer un autre admin |
| Rôle « système » | **Inexistant** |
| Logs debug | `EnsureUserIsAdmin` loggue à chaque requête admin (`Log::info`) — bruit / fuite info |

---

## 5. Vérifications backend

### 5.1 Mécanisme principal

`Controller::checkPermission($request, $resource, $action)` :
1. auth requise  
2. `$user->refresh()`  
3. `hasPermission` → sinon **403**

### 5.2 Middleware

| Middleware | Statut |
|------------|--------|
| `CheckPermission` | **Existe, non enregistré, non utilisé sur les routes** |
| `EnsureUserIsAdmin` | Utilisé (groupe admin + validation BL) |
| `EnsureUserIsActive` | Global web |

### 5.3 Policies / Gates

| Policy | Usage |
|--------|-------|
| `ActivityLogPolicy` | Admin only (view) |
| `NotificationPolicy` | Module NotificationCenter |
| Policies métier produits/ventes/… | **Absentes** |
| Gates Spatie / Gate::define permissions | **Non utilisés** comme couche principale |

### 5.4 Couverture `checkPermission` par module

Couverture **bonne** sur : Product, Category, Customer, Sale, Quote, Expense, Supplier, PurchaseOrder, DeliveryNote, Company, Dashboard, Inventory, Backup.

### 5.5 Écarts / endpoints sensibles à traiter en Phase E

| Endpoint / zone | Risque |
|-----------------|--------|
| `ProductController::uploadImage` | Auth only — **pas** de `products.create/update` |
| `ProductController::generateSku` | Auth only — **pas** de permission produit |
| `FormDraftController` | Auth only — pas de permission module |
| `NotificationController` | Auth only (OK pour ses notifications ; `testNotification` à revoir) |
| Routes `/dev/barcode-*` | Auth+verified — **pas** de gate admin/dev |
| `delivery-notes.validate` | Middleware **admin** + `checkPermission validate` — double règle (admin strict) |
| Admin users | Middleware admin uniquement (pas de permission `users.*`) |

Autocomplétions produits/clients/fournisseurs : autorisation OR documentée — **volontaire**, à conserver et formaliser.

---

## 6. Frontend

### 6.1 Source Inertia (`HandleInertiaRequests`)

```
auth.user = { id, name, email, role, is_active, permissions[] }
```

- Admin → `permissions: []`  
- Autres → `getPermissionsArray()` (liste de noms `resource.action`)

### 6.2 Abstraction existante

`resources/js/composables/usePermissions.ts` :
- `hasPermission(resource, action)`
- `hasPermissionByName(name)`
- `canView/Create/Edit/Update/Delete/Download/Restore`
- `canAny`
- Bypass `isAdmin`

**À réutiliser** (Phase H) — ne pas créer un second système. Éventuelle API `can('inventory.apply')` = alias de `hasPermissionByName`.

### 6.3 Menus

`BootstrapLayout.vue` : navigation filtrée via `canView(...)` ; section Administration via `isAdmin`.

### 6.4 Inventaire (pattern premium déjà en place)

Backend pousse un objet `permissions` booléen (create, count, submit, review, validate, apply, close, cancel, export).  
UI conditionnelle + **backend obligatoire** via `checkPermission`.

### 6.5 Administration RBAC actuelle

Pages : `Admin/Users/Index|Create|Edit`  
- Édition utilisateur = rôle + checkboxes permissions groupées par resource  
- **Pas** de page « Rôles & permissions » dédiée (rôles = presets, pas entités)  
- Pas d’UI matrice rôles premium (Phases 14–17)

---

## 7. Cache

| Élément | État |
|---------|------|
| Cache permissions utilisateur | **Aucun** |
| Cache rôles | N/A |
| Invalidation | N/A — `refresh()` + requête `exists()` à chaque check |
| Impact | Correctitude OK (permissions à jour dès la requête suivante) ; perf : N requêtes SQL possibles par page |

Phase I : introduire cache **avec** invalidation sur sync permissions / changement rôle — ne pas désactiver pour « corriger » un bug inexistant.

---

## 8. Audit log

Système existant : `ActivityLogger` + `ActivityLog` (create/update/delete avec old/new values).

| Action | Journalisé ? |
|--------|--------------|
| Création / maj / suppression User | Oui (`logCreate/Update/Delete`) |
| Changement de `role` | Oui si détecté dans changes du modèle User |
| Sync pivot `user_permissions` | **Non** (hors ChangeDetector Eloquent standard) |
| Attribution preset vendeur/gestionnaire | **Non** au niveau permission-by-permission |

Phase J : journaliser `permissions.synced` / diffs old→new sans second système d’audit.

---

## 9. Company isolation

| Contexte | État |
|----------|------|
| Multi-tenant User↔Company | **Non** |
| Inventaire IDOR | Tests `InventoryIdorTest` + `assertSessionAccessible` |
| Autres modules | Données globales installation (mono-entreprise) |
| Convention erreur | 403 permissions ; IDOR inventaire → convention projet (404/403 déjà testée) — **ne pas changer sans justification** |

Phase F : tests isolation formalisés ; architecture cible multi-entreprise **documentée** avant code si besoin métier réel.

---

## 10. Store scope

Préparation documentaire prévue : `docs/rbac-store-scope.md` (Phase G)  
**Ne pas implémenter** User↔Store maintenant.

Cible conceptuelle :
```
User → Role → Permissions
User → Company (futur)
User → Stores autorisés + niveau (futur)
```

---

## 11. Tests existants liés RBAC

| Zone | Fichiers | Couverture RBAC |
|------|----------|-----------------|
| Inventaire | Workflow, Counting, Review, Application, Export, Idor, … | Permissions inventaire exercées en feature tests |
| Visibilité | SaleVisibility, ExpenseVisibility | Partiel |
| Suite `tests/Feature/Rbac/*` | **Absente** | À créer Phase L |
| Vitest `rbac.test.ts` | **Absent** | À créer Phase L |
| usePermissions | Pas de test unit dédié | À créer |

---

## 12. Incohérences synthétiques

1. Pas de modèle Role alors que l’UI et le métier parlent de « rôles ».  
2. Presets vendeur/gestionnaire **figés dans le contrôleur**, non seedés, non versionnés DB.  
3. Gestionnaire **sans** sales/customers/inventory — écart métier potentiel vs intitulé « Gestionnaire ».  
4. Doublons `edit`/`update`.  
5. `inventory.review` vs route `reopen`.  
6. Middleware `CheckPermission` mort.  
7. `PermissionSeeder` hors `DatabaseSeeder`.  
8. Admin permissions Inertia vides → OK avec bypass frontend, fragile si un écran ignore `isAdmin`.  
9. Pas d’audit granulaire des permissions.  
10. Pas de cache + risque N+1 sur checks.  
11. Endpoints upload image / generate SKU sans permission produit.  
12. Validation BL réservée **admin** malgré permission `delivery-notes.validate`.  
13. Pas de protection dernier administrateur.  
14. Routes `/dev/*` accessibles à tout utilisateur authentifié.  
15. Logs verbeux dans `EnsureUserIsAdmin`.

---

## 13. Failles / risques de sécurité (confirmés ou fortement suspects)

| ID | Sévérité | Description |
|----|----------|-------------|
| S1 | Haute | `uploadImage` / `generateSku` : tout user auth peut uploader/générer sans permission produits |
| S2 | Moyenne | Dernier admin rétrogradable/supprimable par un autre admin |
| S3 | Moyenne | Pivot permissions non audité |
| S4 | Moyenne | Routes `/dev/*` barcode labs en production possible |
| S5 | Basse | Double règle BL validate = admin only (permission seedée inutile pour non-admin) |
| S6 | Info | `CheckPermission` middleware mort — confusion maintenance |
| S7 | Info | Multi-entreprise non isolée au User (OK si mono-install ; risque si multi-install partagée un jour) |

---

## 14. Fichiers concernés (cartographie)

### Backend cœur
- `app/Models/User.php`
- `app/Models/Permission.php`
- `app/Models/Company.php`
- `app/Models/Store.php`
- `app/Http/Controllers/Controller.php`
- `app/Http/Middleware/CheckPermission.php`
- `app/Http/Middleware/EnsureUserIsAdmin.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `app/Http/Controllers/Admin/UserController.php`
- `app/Policies/ActivityLogPolicy.php`
- `app/Services/ActivityLogger.php`
- `app/Services/AttachmentAuthorizer.php`
- `database/seeders/PermissionSeeder.php`
- `database/seeders/DatabaseSeeder.php`
- `database/migrations/2025_11_21_165637_create_permissions_table.php`
- `database/migrations/2025_11_21_165645_create_user_permissions_table.php`
- `routes/web.php`

### Contrôleurs métier (checkPermission)
- Product, Category, Customer, Sale, Quote, Expense, Supplier, PurchaseOrder, DeliveryNote, Company, Dashboard, InventorySession, Admin\Backup

### Frontend
- `resources/js/composables/usePermissions.ts`
- `resources/js/layouts/BootstrapLayout.vue`
- `resources/js/pages/Admin/Users/*.vue`
- Pages/modules utilisant `usePermissions` (Dashboard, Products, Customers, Sales, Quotes, Expenses, DeliveryNotes, PurchaseOrders, Company, Backups, …)
- Inventaire : props permissions via `InventorySessionService`

---

## 15. Architecture cible proposée (ÉTAPE C — proposition, non appliquée)

### Principes

1. **Ne pas introduire Spatie** tant que le système maison couvre le besoin.  
2. **Une seule source de vérité backend** : `User::hasPermission*` + `checkPermission` (ou Gate unique dérivée).  
3. **Rôles = presets métier** d’abord (admin / gestionnaire / vendeur) ; table `roles` seulement si l’admin premium l’exige vraiment.  
4. **Permissions = `module.action`** déjà majoritairement respecté.  
5. **Frontend = UX** via `usePermissions` ; jamais seule défense.  
6. **Company** : rester mono-install ; documenter isolation ; tests IDOR.  
7. **Store** : documenter scope futur, pas d’implémentation complexe.  
8. **Enum `PermissionName`** : possible (le projet a déjà des Enums) — centraliser les chaînes PHP ; migration progressive des appels.  
9. **Admin UI premium** : matrice permissions par rôle **après** stabilisation backend.

### Variante recommandée (risque minimal)

```
A. Garder User.role string + pivot user_permissions
B. Extraire presets vers RolePermissionCatalog (PHP) + seeder idempotent
C. Introduire PermissionName enum progressivement
D. Normaliser edit/update (décision : fusionner OU documenter la dualité GET/POST)
E. Durcir endpoints orphelins
F. Auditer sync permissions
G. Cache + invalidation
H. UI « Rôles & permissions » basée sur le catalogue (pas forcément table roles V1)
I. Table roles + role_permissions = Phase ultérieure si besoin multi-custom roles
```

### Variante lourde (à éviter en V1)

Créer immédiatement Spatie / table roles complète / multi-store ACL — **risque de régression élevé**, hors contrainte « ne pas casser ».

---

## 16. Plan de migration (après validation)

| Étape | Contenu | Risque |
|-------|---------|--------|
| A–B | Audit (ce document) | — |
| C | Valider architecture cible (ce §15) | — |
| D | Normalisation permissions (edit/update, review/reopen naming) | Moyen — migration usages + compat |
| E | Backend authorization (gaps S1, BL validate policy) | Moyen |
| F | Company isolation tests | Faible si mono-install |
| G | `docs/rbac-store-scope.md` | Nul |
| H | Frontend : étendre `usePermissions` (`can()`), pas de 2e système | Faible |
| I | Cache + invalidation | Moyen |
| J | Audit log permissions sync | Faible |
| K | UI admin premium | Moyen UX |
| L–M | Tests Rbac + régression | — |
| N | `docs/rbac-architecture.md` + rapport final | — |
| O | Build + stock:check-consistency | — |

**Aucune permission ne sera renommée/supprimée** sans `rg` exhaustif + plan de compatibilité.

---

## 17. Risques de régression

| Zone | Risque |
|------|--------|
| Inventaire | Élevé si rename `inventory.review` ou changement workflow — **interdire** changement StockService / ProductStock / BarcodeInput |
| Presets vendeur/gestionnaire | Moyen — synchroniser catalogue avec users existants |
| Admin empty permissions array | Moyen si on casse bypass `isAdmin` frontend |
| Fusion edit/update | Élevé — beaucoup d’appels `checkPermission(..., 'edit')` |
| Introduction table Role | Élevé — migration données users.role |
| Cache mal invalidé | Élevé (permissions périmées) |

---

## 18. Décisions attendues avant implémentation

Merci de valider explicitement :

1. **Variante A (minimale)** vs introduction table `roles` dès V1 ?  
2. Conserver la dualité **`edit` / `update`** ou fusionner vers `update` (GET edit = même permission) ?  
3. Garder **`inventory.review`** (aligné seed) ou alias vers action `reopen` ?  
4. Le preset **Gestionnaire** doit-il inclure `sales.*`, `customers.*`, `inventory.*` ?  
5. `delivery-notes.validate` : rester **admin only** ou passer à la permission granulaire ?  
6. Priorité immédiate : **durcissement S1** (upload/SKU) avant UI premium ?

---

## 19. Verdict audit

**BLOCKED FOR IMPLEMENTATION** jusqu’à validation logique des points §15–§18.

Livrable suivant après GO :  
- `docs/rbac-architecture.md` (cible figée)  
- puis Étapes D→O selon ordre Phase 28.

---

*Document généré dans le cadre du chantier RBAC Premium — Phase 0 inventaire + Phase 1 rapport. Aucune modification applicative RBAC n’a été appliquée lors de cette étape.*

---

# Phase K — Audit activité RBAC (2026-08-23)

**Statut :** Spécification + implémentation (réutilisation `ActivityLog` / `ActivityLogger`)  
**Décision :** **ne pas** créer un second système d’audit.

## K.1 Architecture d’audit existante

| Élément | Rôle |
|---------|------|
| Table `activity_logs` | `user_id` (acteur), `action`, `module`, `description`, morph `subject`, IP/UA, `old_values` / `new_values` JSON |
| `ActivityLogger` | API unique d’écriture |
| `ChangeDetector` | Diff attributs Eloquent (exclut password, tokens, etc. via `config/audit.php`) |
| UI | `Admin/ActivityLogs` Index/Show |
| CRUD User actuel | `logCreate` / `logUpdate` / `logDelete` module « Utilisateur » — **sans** diff pivot permissions ni refus last-admin |

## K.2 Limites actuelles (avant Phase K)

- Pas d’événements `rbac.*` structurés  
- Pivot `user_permissions` invisible dans le journal  
- Refus `AdminProtectionService` non journalisés  
- CLI `user:set-role` non journalisée côté RBAC  
- Risque de bruit si on audite tous les `allows()` / 403 métier → **exclu**

## K.3 Événements RBAC

| Action (`activity_logs.action`) | Quand |
|----------------------------------|--------|
| `rbac.role_changed` | Rôle modifié (succès) ; payload permissions added/removed si sync concomitante |
| `rbac.permissions_changed` | Permissions custom sans changement de rôle |
| `rbac.permissions_synced` | Sync preset sans changement de rôle, ou attribution initiale à la création |
| `rbac.user_activated` / `rbac.user_deactivated` | `is_active` flip |
| `rbac.admin_removed` | Suppression d’un utilisateur qui était admin |
| `rbac.last_admin_change_denied` | Refus demote / deactivate / delete dernier admin actif |

Module journal : **`RBAC`**.  
Sujet : `User` (morph).  
Acteur : `user_id` = utilisateur connecté ; CLI → `user_id` null + description « via console ».

## K.4 Format / normalisation permissions

Comparaison sur **noms canoniques** (`PermissionCatalog::canonicalName`) pour ne pas générer added+removed sur simple `edit`↔`update` / `review`↔`reopen`.

## K.5 Décision événements composites

Changement de rôle + sync preset → **un seul** événement `rbac.role_changed` avec :

```json
{
  "target_user_id": 1,
  "old_role": "vendeur",
  "new_role": "gestionnaire",
  "permissions": { "added": [...], "removed": [...] }
}
```

Pas de `rbac.permissions_synced` redondant dans ce cas.

## K.6 Transactions

- Succès : écriture via `DB::afterCommit` (pas de faux succès si rollback)  
- Refus last-admin : écriture **hors** transaction réussie (après rollback du lock) via exception dédiée catchée hors `withAdminLock`, ou `terminating` si assert abort in-tx  

## K.7 Confidentialité

Aucun password / token / secret dans `old_values` / `new_values` RBAC. Uniquement rôle, `is_active`, listes de noms de permissions, ids cibles, `operation`, `reason`.

## K.8 Service

`App\Auth\RbacAuditService` — observe uniquement ; **ne décide jamais** de l’autorisation.

## K.9 Stratégie de test

- Unit : diffs, no-op, normalisation, denials  
- Feature : routes admin users + CLI + rollback sans succès  
- Régression RBAC / inventaire / stock / build

---
