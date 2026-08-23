# RBAC MKD-Pro — Architecture cible (V1)

**Date :** 2026-08-23  
**Statut :** Architecture V1 validée — **Phase E implémentée** (AuthorizationService)  
**Référence audit :** [`docs/rbac-audit.md`](./rbac-audit.md)  
**Catalogue :** [`docs/rbac-permission-catalog.md`](./rbac-permission-catalog.md), [`docs/rbac-permissions.md`](./rbac-permissions.md)  
**Presets :** [`docs/rbac-role-presets.md`](./rbac-role-presets.md)  
**Autorisation :** [`docs/rbac-authorization.md`](./rbac-authorization.md)  
**Spatie Permission :** interdit  
**Table `roles` :** reportée à V2  

---

## 1. Architecture actuelle (confirmée)

```
User
 ├── users.role (string: admin | vendeur | gestionnaire | user)
 └── user_permissions (pivot)
        └── Permission (name = "{resource}.{action}")
```

| Élément | État réel |
|---------|-----------|
| Modèle Role | Absent |
| Spatie | Absent |
| Source d’autorisation | `AuthorizationService` via `User::hasPermission()` + `Controller::checkPermission()` |
| Middleware `CheckPermission` | **Supprimé (Phase N1)** — mort, non utilisé |
| Admin | Bypass code (`role === 'admin'`) |
| Presets | `RolePresets` (Phase D) ; sync via `Admin\UserController` uniquement |
| Catalogue | `PermissionCatalog` + `PermissionName` (Phase C) ; seeder idempotent |
| Frontend | `usePermissions.ts` + props inventaire |
| Cache permissions | Intra-requête (relation Eloquent) — Phase J |
| Audit sync permissions | Phase K — `RbacAuditService` + `ActivityLog` (`rbac.*`) |
| User ↔ Company / Store | Aucune relation |

---

## 2. Principe RBAC V1

### 2.1 Séparation des responsabilités

| Couche | Question | Mécanisme |
|--------|----------|-----------|
| **RBAC** | L’utilisateur a-t-il le droit de faire X ? | Permission `module.action` |
| **Isolation** | A-t-il le droit de faire X **sur cette donnée** ? | Contexte company / propriétaire / IDOR |
| **Frontend** | Faut-il afficher le bouton ? | UX uniquement — jamais source de vérité |

### 2.2 Modèle conservé (pas de table `roles`)

```
User.role          → étiquette / preset (compatibilité)
user_permissions   → source de vérité des droits granulaires
Permission         → catalogue DB (synchronisé depuis catalogue PHP)
```

Les « rôles » `admin`, `vendeur`, `gestionnaire`, `user` sont des **presets de permissions**, pas des entités DB.

### 2.3 Diagramme cible V1

```
                    ┌──────────────────────┐
                    │ PermissionCatalog.php│  ← source de vérité code
                    └──────────┬───────────┘
                               │ seed / sync
                    ┌──────────▼───────────┐
                    │   permissions (DB)   │
                    └──────────┬───────────┘
                               │
┌────────────┐    sync     ┌───▼────────────────┐
│ RolePresets│────────────▶│ user_permissions   │
└────────────┘             └───▲────────────────┘
                               │
                    ┌──────────┴───────────┐
                    │        User          │
                    │  role + isAdmin()    │
                    └──────────┬───────────┘
                               │
              ┌────────────────┼────────────────┐
              ▼                ▼                ▼
        Authorization    usePermissions    ActivityLogger
          Service            (UX)            (audit)
```

---

## 3. Permissions — catalogue central

### 3.1 Emplacement prévu

```
app/Auth/Permissions/PermissionCatalog.php
app/Auth/Permissions/RolePresets.php
app/Auth/Authorization/AuthorizationService.php
app/Auth/Authorization/AdminGuard.php          (optionnel, logique dernier admin)
```

### 3.2 Convention de nommage

```
{module}.{action}
```

Modules existants (à conserver tels quels) :

| Module | Commentaire |
|--------|-------------|
| `products` | |
| `categories` | |
| `customers` | |
| `sales` | |
| `quotes` | |
| `expenses` | |
| `suppliers` | |
| `purchase-orders` | kebab-case conservé |
| `delivery-notes` | kebab-case conservé |
| `company` | |
| `dashboard` | |
| `inventory` | |
| `backups` | |

### 3.3 Catalogue basé sur l’existant (74 seedées)

Ne pas inventer de nouvelles permissions hors besoins confirmés.

**Actions CRUD standard :** `view`, `create`, `update`, `delete`  
**Actions document :** `download`, `print`, `invoice`, `export`  
**Actions inventaire :** `count`, `submit`, `reopen` (cible), `validate`, `apply`, `cancel`, `close`, `export`  
**Actions backups :** `download`, `restore`

### 3.4 Permissions seedées jamais / peu utilisées côté `checkPermission`

| Permission | Observation |
|------------|-------------|
| `company.edit` | Seedée ; contrôleurs utilisent `company.view` + `company.update`. Route nommée `company.edit` ≠ permission. |
| `*.edit` (tous modules) | Utilisées pour les GET formulaires ; redondantes avec `update` (voir §4). |

---

## 4. Normalisation `edit` / `update`

### 4.1 État actuel

| Usage | `edit` | `update` |
|-------|--------|----------|
| GET formulaire (edit) | `checkPermission(..., 'edit')` | — |
| PUT/PATCH (update) | — | `checkPermission(..., 'update')` |
| Frontend boutons | souvent `canEdit()` | parfois `canUpdate()` / `canAny(['edit','update'])` |
| Autocomplétions OR | `sales.edit`, `quotes.edit`, … | — |
| Presets | listent les deux | listent les deux |

### 4.2 Décision V1

**Permission canonique backend : `update`.**

Migration progressive :

1. `AuthorizationService::hasPermission($user, 'products.update')`  
2. Alias temporaire : `products.edit` → traité comme `products.update` (lecture seule, pas d’écriture DB d’alias)  
3. Migrer tous les `checkPermission(..., 'edit')` vers `'update'`  
4. Migrer frontend `canEdit` → vérifie `update` (ou alias)  
5. Quand zéro usage de `*.edit` : déprécier puis retirer du seeder (migration data : copier `edit` → `update` sur pivot si manquant)

**Ne pas supprimer `*.edit` de la DB tant que la migration des usages n’est pas complète.**

---

## 5. Inventaire — `review` → `reopen`

### 5.1 Clarification métier (confirmée)

| Concept | Signification |
|---------|---------------|
| Statut session `review` | État workflow (enum `InventorySessionStatus::Review`) — **inchangé** |
| Route `inventory.reopen` | Action HTTP qui rouvre le comptage |
| Permission actuelle `inventory.review` | Utilisée **uniquement** pour autoriser `reopen()` |
| UI | Bouton « Rouvrir » si `session.permissions?.review` |

**Conclusion :** `inventory.review` n’est pas une action métier distincte de « réouvrir » ; c’est un mauvais nom de permission.

### 5.2 Décision

| Avant | Après (canonique) |
|-------|-------------------|
| `inventory.review` | `inventory.reopen` |

Compatibilité temporaire dans `AuthorizationService` :

```
hasPermission('inventory.reopen') 
  OR hasPermission('inventory.review')  // legacy
```

Migration data : lors du seed/sync, créer `inventory.reopen` et, pour chaque user ayant `review`, ajouter `reopen` au pivot.  
Ne retirer `inventory.review` qu’après migration complète des tests + code.

### 5.3 Interdictions

Ne pas modifier : `StockService`, `ProductStock`, `StockMovement`, `BarcodeInput.vue`, logique de comptage, transitions de statut (hors check permission).

Modification autorisée minimale : contrôleur `checkPermission(..., 'reopen')` + clé props UI `reopen` (avec alias legacy `review` si besoin UX temporaire).

---

## 6. Presets centralisés

### 6.1 Emplacement

`app/Auth/Permissions/RolePresets.php`

### 6.2 Rôles / presets V1

| Preset | Comportement |
|--------|--------------|
| `admin` | Bypass code ; pivot vidé (pas de liste à maintenir) |
| `vendeur` | Liste explicite de permissions |
| `gestionnaire` | Liste explicite de permissions |
| `user` | Aucun preset auto ; permissions manuelles uniquement |

### 6.3 Contenu proposé (à valider métier)

**Ne pas recopier aveuglément** les listes actuelles — écarts connus :

| Preset actuel | Manques notables |
|---------------|------------------|
| Vendeur | Pas d’inventaire, dashboard, dépenses, fournisseurs, BC, BL, company |
| Gestionnaire | Pas de `sales.*`, `customers.*`, `inventory.*`, `company.*` |

**Proposition de révision (GO métier requis) :**

#### Vendeur (opérationnel vente)
- `dashboard.view`
- `sales.*` (view, create, update, delete, invoice) — après normalisation sans `edit`
- `quotes.*`
- `products.view`
- `customers.view`, `customers.create`, `customers.update`
- **Pas** inventaire apply/validate/close ; **pas** backups ; **pas** company update

#### Gestionnaire (opérationnel stock / achats / catalogue)
- Tout le preset vendeur **ou** équivalent ventes/clients selon validation
- `products.*`, `categories.*`
- `expenses.*`, `suppliers.*`, `purchase-orders.*`, `delivery-notes.*`
- `inventory.view`, `create`, `count`, `submit`, `reopen`, `validate`, `apply`, `cancel`, `close`, `export`
- `company.view` (pas forcément update)
- **Pas** backups (admin)

#### User
- Aucune permission par défaut

> **Point de validation explicite :** le gestionnaire doit-il gérer les ventes et clients ? L’audit montre que non aujourd’hui — probablement un oubli métier.

---

## 7. Admin bypass

### 7.1 Règles

1. Une seule implémentation : `User::isAdmin()` + `AuthorizationService::allows()`  
2. Bypass **uniquement** si `role === 'admin'` (pas via pivot)  
3. Frontend : `isAdmin` → `can()` true ; Inertia peut garder `permissions: []` pour admin  
4. Tests : admin sans pivot → 200 ; non-admin sans permission → 403  

### 7.2 Protection dernier admin

Backend obligatoire :

| Action | Règle |
|--------|-------|
| Suppression user | Refus si cible = dernier admin actif |
| Changement rôle admin → autre | Refus si c’était le dernier admin |
| `is_active = false` sur admin | Refus si c’était le dernier admin actif |

Exception métier claire + message FR + tests Feature.

---

## 8. Backend authorization — convention unique

### 8.1 Décision middleware `CheckPermission`

**Historique (Phase E) :** middleware prêt, non branché — option B « réactiver via service central ».  
**Phase N1 :** fichier **supprimé** (jamais branché) — voir [`rbac-checkpermission-removal.md`](./rbac-checkpermission-removal.md).

| Couche | Rôle |
|--------|------|
| `AuthorizationService` | Source unique `allows(User, string $permissionName): bool` |
| `User::hasPermission*` | Façades modèles → service |
| `Controller::checkPermission` | Garde HTTP des contrôleurs → service |
| Middleware `CheckPermission` | **Supprimé (N1)** |
| Policies | Conservées pour ActivityLog / Notifications ; pas de double RBAC métier |

### 8.2 Routes insuffisamment protégées (confirmées)

| Route | Problème | Correction prévue |
|-------|----------|-------------------|
| `POST products/upload-image` | Auth only | Exiger `products.create` **OU** `products.update` |
| `POST products/generate-sku` | Auth only | Idem |
| `POST delivery-notes/{id}/validate` | `EnsureUserIsAdmin` + permission | Retirer middleware admin ; garder `delivery-notes.validate` |
| Routes `/dev/barcode-*` | Auth only | Hors RBAC métier : restreindre `local` / admin (décision séparée) |
| `FormDraftController` | Auth + scope user | OK (données personnelles) |
| `NotificationController` | Auth + own notifications | OK ; `testNotification` à restreindre admin/local |
| Attachments | `AttachmentAuthorizer` | OK (mappe déjà vers permissions module) |

### 8.3 Autocomplétions (volontaires OR)

Conserver le pattern « permission métier OU view ressource » ; le formaliser via le service (`allowsAny`).

---

## 9. Company isolation

### 9.1 Périmètre V1

- Installation **mono-entreprise** : `Company::getInstance()`
- Pas de `User.company_id`
- RBAC = droits fonctionnels globaux à l’installation
- Isolation données inventaire = `company_id` sur session + asserts existants (`InventoryIdorTest`)

### 9.2 Règles

1. Ne pas utiliser le RBAC pour « simuler » un multi-tenant  
2. Tout nouvel accès ressource doit vérifier le contexte company existant  
3. Tests isolation : conserver / étendre sans changer la convention 403/404 projet  

### 9.3 Store scope (préparation, non implémenté)

Document compagnon prévu : `docs/rbac-store-scope.md` (phase ultérieure).

```
V1 : RBAC company-level (installation)
V2+ : User → Stores autorisés + permissions éventuellement scoped
```

Le filtrage magasin actuel reste **métier** (choix de store dans inventaire), pas ACL utilisateur.

---

## 10. Cache

### 10.1 État (Phase J)

Cache **intra-requête** via relation Eloquent `permissions` dans `AuthorizationService`.  
**Pas** de cache Laravel inter-requêtes (Redis/file).  
Doc : [`docs/rbac-cache.md`](./rbac-cache.md).

### 10.2 Stratégie V1

**Option A (retenue Phase J) :** charger les permissions une fois par instance `User` (`load` + `relationLoaded`). Invalidation : `forgetCachedPermissions()` après sync/rôle.

**Option B (non retenue) :** cache clé `user.{id}.permissions` TTL + version — reportée tant que la mesure ne le justifie pas.

Ne pas introduire de cache inter-requêtes sans invalidation testée.

---

## 11. Synchronisation & audit

### 11.1 État Phase K

Service : `App\Auth\RbacAuditService` (observe uniquement).  
Journal : réutilisation de `ActivityLogger` / `activity_logs` — module **`RBAC`**.  
Doc : section Phase K dans [`docs/rbac-audit.md`](./rbac-audit.md).

Événements : `rbac.role_changed`, `rbac.permissions_changed`, `rbac.permissions_synced`, `rbac.user_activated`, `rbac.user_deactivated`, `rbac.admin_removed`, `rbac.last_admin_change_denied`.

Succès : `DB::afterCommit`. Refus last-admin : audit après rollback (`LastAdminProtectionException` catchée hors lock).

### 11.2 `PermissionPresetService` (prévu V2 / Phase ultérieure)

```
applyPreset(User $user, string $preset): void
syncPermissions(User $user, array $permissionIds, ?string $context): void
```

Responsabilités futures : centraliser sync + audit (aujourd’hui dans `UserController` + `RbacAuditService`).

### 11.3 Événements audit (réutiliser `ActivityLogger`)

| Événement | Contenu |
|-----------|---------|
| `role.updated` | old/new role |
| `permissions.synced` | added[], removed[] |
| `preset.applied` | preset name + diff |
| `admin.promote` / `admin.demote` | cible |
| `admin.last_protected` | tentative refusée |

Pas de second système d’audit.

---

## 12. Frontend

### 12.1 Réutiliser `usePermissions.ts`

API cible :

```ts
can('products.update')
canAny(['sales.create', 'quotes.create'])
canAll([...])
hasPermission(resource, action) // legacy
canView / canCreate / canUpdate / canDelete // wrappers
isAdmin
```

`canEdit(resource)` → délègue à `canUpdate` après normalisation (compat).

### 12.2 Inertia

Conserver : non-admin reçoit la liste des noms ; admin reçoit `[]` + bypass `isAdmin`.  
Ne pas exposer de secrets ; permissions = noms publics.

### 12.3 Inventaire

Props booléennes backend restent la référence UI session ; aligner la clé `review` → `reopen` avec alias temporaire.

---

## 13. Interface admin premium (Phase M — implémenté)

Voir [`docs/rbac-ui.md`](./rbac-ui.md) et audit [`docs/rbac-ui-audit.md`](./rbac-ui-audit.md).

- Route `/admin/roles-permissions`
- Presenter lecture seule `RbacUiPresenter`
- Cartes rôles, détail, matrice, journal RBAC
- Permissions custom Users via `RbacPermissionPicker`

---

Page : **Administration → Utilisateurs & permissions**

- Liste users : preset, statut, nb permissions, actions  
- Fiche : profil, preset, permissions par module (accordéons), résumé  
- Distinction visuelle : héritée preset / personnalisée / absente  
- Confirmations : promote/demote admin, apply preset, purge permissions  

Design system MKD-Pro, light + dark, mobile.

**Hors scope immédiat des phases A–B.**

---

## 14. Future V2 — table `roles`

Sans casser V1 :

```
roles
role_permissions
users.role_id  (nullable)  + users.role legacy
```

Migration path :

1. Créer table `roles` peuplée depuis `RolePresets`  
2. Lier users via `role_id`  
3. Option A : permissions toujours sur user (overrides)  
4. Option B : permissions héritées du rôle + overrides user  

V1 prépare ce chemin en centralisant catalogue + presets hors contrôleur.

---

## 15. Comment ajouter une permission (processus cible)

1. Ajouter l’entrée dans `PermissionCatalog`  
2. Mettre à jour `PermissionSeeder` (ou sync depuis catalogue)  
3. Ajouter au(x) preset(s) si pertinent  
4. Protéger l’endpoint backend  
5. Brancher le frontend (`can(...)`)  
6. Tests Feature + Vitest  
7. Documenter dans la matrice  

---

## 16. Comment modifier un preset

1. Éditer `RolePresets.php` uniquement  
2. Tests `RbacPresetTest`  
3. Décider : sync automatique des users existants du preset **ou** migration manuelle admin  
4. Auditer les syncs  

---

## 17. Plan de migration (ordre d’implémentation)

| Phase | Contenu | Risque |
|-------|---------|--------|
| A | Audit final | — |
| B | Ce document | — |
| **⏹ Validation** | GO / ajustements métier presets | — |
| C | `PermissionCatalog` | Faible |
| D | `RolePresets` | Moyen (contenu métier) |
| E | `AuthorizationService` | Moyen |
| F | Routes upload/SKU + BL validate | Moyen |
| G | Normalisation edit→update | Moyen/élevé |
| H | inventory.review→reopen | Moyen (tests inventaire) |
| I | Dernier admin | Faible |
| J | Cache request-level / invalidation | Faible |
| K | Audit log RBAC | Faible |
| L | Frontend `can()` | Faible |
| M | UI admin premium | Moyen UX |
| N | Suite `tests/Feature/Rbac/*` | — |
| O | Docs finales + store-scope | — |
| P | Build + stock:check-consistency | — |

---

## 18. Risques de régression

| Zone | Risque | Mitigation |
|------|--------|------------|
| Inventaire | Rename permission review | Alias + migration pivot + tests Feature inventaire |
| edit/update | Boutons masqués / 403 | Alias + `canEdit`→update + tests routes |
| Presets | Users existants désalignés | Ne pas force-sync sans décision ; outils applyPreset explicite |
| BL validate | Gestionnaires bloqués aujourd’hui par admin middleware | Après fix : permission seule — tester non-admin avec permission |
| Admin empty permissions | UI qui ignore `isAdmin` | Tests frontend + convention documentée |
| Stock | Tentation de « corriger » via StockService | Interdit — corriger uniquement RBAC |

---

## 19. Matrice permissions (état seed actuel → canonique)

| Module | Seed actuel | Canonique V1 |
|--------|-------------|--------------|
| products | view, create, edit, update, delete | view, create, update, delete (+ edit alias) |
| categories | idem | idem |
| customers | + export | + export |
| sales | + invoice | + invoice |
| quotes | + download, print | idem |
| expenses | CRUD | CRUD |
| suppliers | + export | idem |
| purchase-orders | + download, print | idem |
| delivery-notes | + validate, download, print | idem |
| company | view, edit, update | view, update (+ edit alias/deprecated) |
| dashboard | view | view |
| inventory | view…review…export | review → **reopen** |
| backups | view, create, download, delete, restore | idem |

---

## Permission Catalog — Phase C (implémenté)

**Date :** 2026-08-23  
**Verdict :** catalogue central livré, comportement applicatif inchangé.

### Fichiers

| Fichier | Rôle |
|---------|------|
| `app/Enums/PermissionName.php` | 74 permissions actives (backed enum) |
| `app/Auth/PermissionCatalog.php` | Métadonnées, legacy/canonique, API |
| `database/seeders/PermissionSeeder.php` | Seed idempotent depuis le catalogue |
| `tests/Unit/Rbac/PermissionCatalogTest.php` | Tests unitaires catalogue |
| `tests/Feature/Rbac/PermissionSeederTest.php` | Tests idempotence + pivot préservé |
| `docs/rbac-permission-catalog.md` | Rapport Phase C |
| `docs/rbac-permissions.md` | Matrice complète |

### Stratégies documentées

- **edit / update :** `*.update` canonique ; `*.edit` legacy conservé en base  
- **inventory.review :** legacy pour l’action `reopen` ; cible future `inventory.reopen` (non seedée)  
- **RolePresets, AuthorizationService, UI :** reportés Phase D+

### Critère Phase C coché

- [x] Source de vérité catalogue PHP (`PermissionName` + `PermissionCatalog`)
- [x] Presets centralisés (`RolePresets`)

---

## Role Presets — Phase D (implémenté)

**Date :** 2026-08-23  
**Verdict :** presets extraits, bypass admin inchangé, pas de force-sync global.

| Fichier | Rôle |
|---------|------|
| `app/Auth/RolePresets.php` | Presets admin / gestionnaire / vendeur / user |
| `app/Http/Controllers/Admin/UserController.php` | Appelle `RolePresets::permissionIds()` |
| `tests/Unit/Rbac/RolePresetsTest.php` | 15 tests unitaires |
| `tests/Feature/Rbac/RolePresetUserTest.php` | 5 tests feature |
| `docs/rbac-role-presets.md` | Matrice et règles métier |

**Règles métier appliquées au preset cible :**

- Gestionnaire : **aucune** permission `sales.*` ; inventaire complet
- Vendeur : `dashboard.view` + ventes ; pas d'inventaire sensible
- Admin : bypass inchangé ; pivot vide
- User : permissions personnalisées

**Reporté Phase F+ :** routes sensibles (F), cache (J), audit (K), frontend (L).

---

## AuthorizationService — Phase E (implémenté)

**Date :** 2026-08-23  
**Verdict :** décision centralisée, rétrocompatible, sans force-sync.

| Fichier | Rôle |
|---------|------|
| `app/Auth/AuthorizationService.php` | `allows`, `denies`, `any`, `all`, `forUser` |
| `app/Models/User.php` | Façade `hasPermission` / `hasPermissionByName` |
| `app/Http/Controllers/Controller.php` | `checkPermission` → service |
| `app/Http/Middleware/CheckPermission.php` | Prêt, non branché routes |
| `tests/Unit/Rbac/AuthorizationServiceTest.php` | 17 tests |
| `tests/Feature/Rbac/AuthorizationIntegrationTest.php` | 7 tests |
| `docs/rbac-authorization.md` | Documentation |

**Alias legacy :** `edit` ↔ `update` bidirectionnel ; `inventory.review` seul (reopen non en DB).

---

## Phase F — Sensitive Routes Hardening (implémenté)

**Date :** 2026-08-23  
**Verdict :** trous de sécurité corrigés, tests verts, périmètre respecté.

| Fichier | Rôle |
|---------|------|
| `app/Http/Controllers/Controller.php` | `checkAnyPermission()` partagé |
| `app/Http/Controllers/ProductController.php` | `uploadImage`, `generateSku` protégés |
| `routes/web.php` | BL validate sans admin middleware ; `/dev/*` admin-only |
| `app/Modules/NotificationCenter/Http/Routes/notifications.php` | `/test` admin-only |
| `tests/Feature/Rbac/SensitiveRoutesTest.php` | 19 tests feature |
| `docs/rbac-sensitive-routes.md` | Cartographie et décisions |

**Corrections clés :** upload-image / generate-sku → `products.create|update` ; BL validate → `delivery-notes.validate` ; `/dev/*` admin-only local ; notifications test admin-only.

**Non modifié :** StockService, inventaire, RolePresets, PermissionCatalog, CheckPermission global.

---

## Phase G — Permission normalization: edit → update (implémenté)

**Date :** 2026-08-23  
**Verdict :** `*.update` canonique, `*.edit` legacy conservé, compatibilité bidirectionnelle.

| Fichier | Rôle |
|---------|------|
| 9 contrôleurs | `checkPermission(..., 'update')` pour formulaires edit |
| `usePermissions.ts` | `canUpdate()` canonique + compat `edit` |
| `Admin/Users/Create\|Edit.vue` | Presets UI sans `*.edit` |
| `tests/Feature/Rbac/EditUpdateCompatibilityTest.php` | 12 tests |
| `resources/js/utils/rbac.test.ts` | 6 tests Vitest |
| `docs/rbac-edit-update.md` | Documentation |

**Non modifié :** AuthorizationService (alias existants), PermissionCatalog, RolePresets, routes `*.edit`, inventaire.

---

## Phase H — inventory.review → inventory.reopen (implémenté)

**Date :** 2026-08-23  
**Verdict :** `inventory.reopen` canonique ; `inventory.review` legacy avec compatibilité bidirectionnelle.

| Élément | Détail |
|---------|--------|
| PermissionName | `InventoryReopen` ajouté ; `InventoryReview` conservé |
| PermissionCatalog | reopen canonique ; review → reopen ; `PLANNED_INVENTORY_REOPEN` retiré |
| RolePresets | gestionnaire : `inventory.reopen` |
| Controller | `checkPermission(..., 'reopen')` |
| Frontend | `permissions.reopen` + `canReopenInventory()` |
| Docs | `docs/rbac-inventory-reopen.md` |

**Aucun force-sync.** Stock / comptage / BarcodeInput non touchés.

---

## Last Administrator Protection — Phase I (implémenté)

**Date :** 2026-08-23  
**Verdict :** Au moins un administrateur fonctionnel est toujours garanti via les opérations applicatives.  
**Doc dédiée :** [`docs/rbac-last-admin.md`](./rbac-last-admin.md)

| Élément | Détail |
|---------|--------|
| Service | `App\Auth\AdminProtectionService` |
| Admin fonctionnel | `role === admin` **et** `is_active === true` |
| Opérations | update (rôle + désactivation), destroy, CLI `user:set-role` |
| HTTP | 403 + messages métier FR |
| Concurrence | `DB::transaction` + `lockForUpdate` sur cible et admins actifs |
| Frontend | `isLastActiveAdmin` / `lastActiveAdminId` (UX seulement) |
| RBAC | Aucune nouvelle permission ; `AuthorizationService` inchangé |
| Tests | `AdminProtectionServiceTest`, `LastAdminProtectionTest` |

**Hors périmètre Phase I :** Spatie, table roles, force-sync, cache RBAC, audit RBAC.

---

## RBAC Cache & Invalidation — Phase J (implémenté)

**Date :** 2026-08-23  
**Verdict :** Cache intra-requête uniquement ; cache inter-requêtes volontairement non implémenté.  
**Doc dédiée :** [`docs/rbac-cache.md`](./rbac-cache.md)

| Élément | Détail |
|---------|--------|
| Mécanisme | `AuthorizationService` charge `permissions` une fois / instance User |
| Invalidation | `forgetCachedPermissions()` après sync (UserController) et `user:set-role` |
| Inter-requêtes | Non (mesure : 8 → 1 requête suffit) |
| Admin | Bypass sans requête permissions |
| Mesure | Cold 8→1 ; eager 3→0 |
| Tests | `AuthorizationCacheTest`, `AuthorizationCacheIntegrationTest` |

**Hors périmètre Phase J :** Spatie, table roles, force-sync, audit RBAC (Phase K), frontend premium.

---

## RBAC Audit & Traceability — Phase K (implémenté)

**Date :** 2026-08-23  
**Verdict :** Changements RBAC et refus last-admin tracés via `ActivityLog` existant.  
**Doc :** [`docs/rbac-audit.md`](./rbac-audit.md) (section Phase K)

| Élément | Détail |
|---------|--------|
| Service | `RbacAuditService` |
| Journal | `ActivityLogger` — module `RBAC` |
| Succès | `DB::afterCommit` |
| Refus | `LastAdminProtectionException` + audit hors lock |
| CLI | `user:set-role` audité |
| Non audité | chaque `allows()` / 403 métier courant |
| Tests | `RbacAuditTest`, `RbacAuditIntegrationTest` |

---

## Frontend RBAC Normalization — Phase L (implémenté)

**Date :** 2026-08-23  
**Verdict :** API frontend unique via `usePermissions` ; exposition Inertia via `AuthorizationService::forUser`.  
**Doc :** [`docs/rbac-frontend.md`](./rbac-frontend.md)

| Élément | Détail |
|---------|--------|
| Inertia | `auth.user.permissions` = `AuthorizationService::forUser` |
| API | `can` / `canAny` / `canAll` / `canUpdate` / `canEdit` (alias) / `canReopenInventory` |
| Legacy | `edit`↔`update`, `review`↔`reopen` dans `rbacPermissions.ts` |
| Admin Users | Grille canonique ; presets UI alignés RolePresets |
| Tests | `rbac.test.ts` (Vitest), `InertiaPermissionsShareTest` |

**Hors Phase L :** Spatie, force-sync, cache Redis.  
**Suite :** Phase M (UI admin) — implémentée.

---

## Phase M — RBAC Administration UI (implémenté)

**Date :** 2026-08-23  
**Verdict :** Interface premium d’administration des rôles & permissions sans modifier le moteur RBAC.  
**Doc :** [`docs/rbac-ui.md`](./rbac-ui.md) · audit [`docs/rbac-ui-audit.md`](./rbac-ui-audit.md)

| Élément | Détail |
|---------|--------|
| Route | `admin.roles-permissions.index` |
| Presenter | `RbacUiPresenter` (catalogue + presets + counts + audit) |
| Page | `Admin/RolesPermissions/Index.vue` |
| Composants | `RbacRoleCard`, `RbacPermissionGroup`, `RbacPermissionDetail`, `RbacPermissionMatrix`, `RbacAuditTimeline`, `RbacPermissionPicker` |
| Helpers | `resources/js/utils/rbacUi.ts` |
| Users | Create/Edit avec picker + recherche |
| Tests | `RolesPermissionsPageTest`, `rbac-ui.test.ts` |

**Non modifié :** AuthorizationService (règles), RolePresets (métier), last-admin, audit write path, stock/inventaire.

---

## Phase N1 — CheckPermission Removal (implémenté)

**Date :** 2026-08-23  
**Verdict :** Middleware mort supprimé ; autorisation HTTP via `Controller::checkPermission` → `AuthorizationService` uniquement.  
**Doc :** [`docs/rbac-checkpermission-removal.md`](./rbac-checkpermission-removal.md)

| Élément | Détail |
|---------|--------|
| Constat | `CheckPermission` non branché (0 route, 0 alias, 0 import) |
| Suppression | `app/Http/Middleware/CheckPermission.php` |
| Architecture | Controllers → `checkPermission` → `AuthorizationService::allows` |
| Recherche | Occurrences restantes : docs uniquement |
| Tests | `CheckPermissionRemovalTest` + suites Unit/Feature RBAC |

**Non modifié :** AuthorizationService, PermissionCatalog, RolePresets, AdminProtection, inventaire, stock, frontend, DB.

---

## Phase N2 — Legacy Permission Migration (implémenté)

**Date :** 2026-08-23  
**Verdict :** Migration contrôlée des pivots `*.edit` / `inventory.review` → `*.update` / `inventory.reopen`.  
**Doc :** [`docs/rbac-legacy-migration.md`](./rbac-legacy-migration.md)

| Élément | Détail |
|---------|--------|
| Service | `LegacyPermissionMigrator` |
| Commande | `rbac:migrate-legacy-permissions` (`--dry-run`, `--force`, `--status`) |
| Audit | `rbac.legacy_permissions_migrated` |
| Force-sync | **Non** |
| Lignes `permissions` legacy | **Conservées** temporairement |
| Tests | `LegacyPermissionMigrationTest`, `LegacyPermissionMigrationCommandTest` |

**État env. local (audit N2) :** 0 pivot legacy utilisateur ; définitions legacy + canoniques présentes.

---

## Phase N3 — Legacy Permission Cleanup (implémenté)

**Date :** 2026-08-23  
**Verdict :** Nettoyage des attributions / UI vers le canonique ; compatibilité de lecture et définitions DB legacy **conservées**.  
**Doc :** [`docs/rbac-legacy-cleanup.md`](./rbac-legacy-cleanup.md)

| Élément | Détail |
|---------|--------|
| Resolver | `AssignablePermissionResolver` (grille + canonicalize IDs) |
| UserController | Create/Edit/Store/Update sans proposer/persister de nouveaux pivots legacy |
| Aliases | AuthorizationService + usePermissions inchangés (lecture) |
| `canEdit()` | Conservé, documenté deprecated |
| Suppression DB legacy | **Non** (phase ultérieure) |
| Tests | `LegacyPermissionCompatibilityTest` + renforcement catalogue |

---

## Phase N4 — Final RBAC Hardening (implémenté)

**Date :** 2026-08-23  
**Verdict cible :** `PHASE N4 READY FOR FINAL RBAC SIGN-OFF`  
**Docs :** [`docs/rbac-write-path-audit.md`](./rbac-write-path-audit.md), [`docs/rbac-final-authorization-audit.md`](./rbac-final-authorization-audit.md), [`docs/rbac-phase-n4-report.md`](./rbac-phase-n4-report.md)

### Canonique

```text
*.update
inventory.reopen
```

### Legacy (lecture / compat uniquement)

```text
*.edit
inventory.review
```

### Règle

```text
Legacy = lecture/compatibilité uniquement
Canonical = seule forme autorisée à l’écriture
```

| Élément | Détail |
|---------|--------|
| Écritures | `AssignablePermissionResolver` (canonicalisation + grille sans legacy) |
| Presets | `RolePresets` ↔ `PermissionCatalog` (tests d’intégrité) |
| AuthZ | `AuthorizationService` source unique ; aliases lecture conservés |
| Suppression DB legacy | **Non effectuée en N4** |
| Pivots legacy locaux | **0** (cible / statut migrate) |
| Cache | Intra-requête uniquement |

---

## 20. Critères de succès V1

- [x] Une source de vérité catalogue PHP  
- [x] Une logique backend d’autorisation (`AuthorizationService`)  
- [x] Presets hors `UserController`  
- [x] Admin bypass centralisé + dernier admin protégé  
- [x] Routes upload-image / generate-sku protégées  
- [x] BL validate via permission granulaire  
- [x] `edit`/`update` normalisés (alias temporaire OK)  
- [x] `inventory.reopen` + compat `review`  
- [x] Cache request-level + invalidation (Phase J)  
- [x] Audit sync / changements RBAC (Phase K)  
- [x] Frontend `can()` sur `usePermissions` (Phase L)  
- [x] Interface admin rôles & permissions (Phase M)  
- [x] Pas de Spatie, pas de table roles  
- [x] Pas de régression inventaire / stock  
- [x] Tests `tests/Feature/Rbac/*` (échantillon + page UI)  
- [x] Documentation à jour  

---

## 21. Décisions en attente de validation

1. **Force-sync** des users existants après changement de preset, ou apply manuel seulement ?  
2. **Routes `/dev/*`** : restreindre à admin / environnement local ?  
3. **Phase N4** : hardening final (legacy write-guard, intégrité) — **fait** ; suppression physique legacy reportée.

---

*Document d’architecture RBAC V1 — mis à jour jusqu’à la Phase N4.*
