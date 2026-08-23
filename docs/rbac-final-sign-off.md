# RBAC FINAL SIGN-OFF — MKD-Pro

**Date :** 2026-08-23  
**Périmètre :** Audit final, validation, go production (phases C → N4)  
**Modifications applicatives durant ce sign-off :** **aucune**

---

## 1. Verdict

```text
RBAC FINAL SIGN-OFF: READY FOR PRODUCTION
```

Tous les critères de succès de l’étape 20 sont satisfaits. Aucune faille de sécurité RBAC bloquante n’a été identifiée. Aucune correction applicative n’a été nécessaire.

---

## 2. Architecture finale

```text
PermissionCatalog (+ PermissionName)
        ↓
RolePresets / AssignablePermissionResolver (attribution)
        ↓
AuthorizationService  ← source unique de décision
        ↓
User::hasPermission* / Controller::checkPermission*
        ↓
Inertia (HandleInertiaRequests → forUser)
        ↓
usePermissions (UX uniquement)
        ↓
Vue
```

Services satellites (ne décident pas l’AuthZ métier catalogue) :

| Service | Rôle |
|---------|------|
| `AdminProtectionService` | Intégrité dernier admin |
| `RbacAuditService` | Journalisation `rbac.*` |
| `LegacyPermissionMigrator` | Migration pivots legacy (outil) |
| `EnsureUserIsAdmin` | Porte d’entrée zone admin (rôle) |

**Spatie Permission :** absent  
**Table `roles` :** absente (V1)  
**Middleware `CheckPermission` :** fichier et alias absents

---

## 3. Audit des chemins d’autorisation

### Chaîne backend

| Couche | Statut |
|--------|--------|
| Contrôleurs métier | `checkPermission` / `checkAnyPermission` → `AuthorizationService` |
| `User::hasPermission*` | façade → `AuthorizationService` |
| `InventorySessionService` props UI | `hasPermission(..., 'reopen')` (canonique) |
| `AttachmentAuthorizer` | permissions via user / AuthZ |

### Contournements examinés

| Occurrence | Classement | Décision |
|------------|------------|----------|
| `AuthorizationService` + `$user->isAdmin()` | Bypass centralisé | **OK** |
| `EnsureUserIsAdmin` / `UserController::destroy` | Admin-only justifié | **OK** |
| `UserController` `role ===` pour sync presets | Attribution, pas AuthZ | **OK** |
| `AdminProtectionService` `role !== admin` | Last-admin | **OK** |
| Frontend `isAdmin` / `role ===` badges / presets UI | UX | **OK** |
| `if ($user->role === 'gestionnaire')` pour AuthZ métier | **Non trouvé** | — |

Aucun chemin métier n’autorise/interdit une opération via un test de rôle brut hors services justifiés.

---

## 4. Matrice Admin / Gestionnaire / Vendeur / User

| Rôle | Ventes (`sales.*`) | Inventaire | Admin UI | Bypass AuthZ |
|------|--------------------|------------|----------|--------------|
| **Admin** | Bypass | Bypass | Oui (`EnsureUserIsAdmin`) | Oui (pivot souvent vide) |
| **Gestionnaire** | **Non** (toutes `sales.*` = false) | Oui + `inventory.reopen` | Non | Non |
| **Vendeur** | Oui (view/create/update/delete/invoice) | Non (pas reopen/apply/validate/close) | Non | Non |
| **User** | Custom | Custom | Non | Non |

Détails presets (source `RolePresets`) :

- **Vendeur :** `dashboard.view`, `sales.*`, `quotes.*`, `products.view`, `customers.view|create|update`
- **Gestionnaire :** dashboard, products, categories, quotes, expenses, suppliers, purchase-orders, delivery-notes, inventory (dont `reopen`) — **zéro** `sales.*`
- **User :** preset vide — permissions personnalisées uniquement

---

## 5. Audit des écritures de permissions

| Chemin | Canonicalisation | Statut |
|--------|------------------|--------|
| `UserController` store/update custom | `AssignablePermissionResolver::canonicalizeIds` | OK |
| Presets vendeur/gestionnaire | `RolePresets::permissionIds` (canoniques) | OK |
| Admin → `sync([])` | Bypass | OK |
| Grille UI | `adminGridByResource` (non-legacy) | OK |
| `LegacyPermissionMigrator` | Outil explicite | OK |
| `user:set-role` | Change rôle seulement (pas de sync pivots) | Documenté |
| `PermissionSeeder` | Upsert définitions catalogue | OK |

**Aucune nouvelle attribution `*.edit` / `inventory.review` possible** via les chemins applicatifs contrôlés.

---

## 6. Legacy permissions

```text
Legacy user pivots: 0
Legacy permission definitions: retained intentionally
```

Statut commande (`rbac:migrate-legacy-permissions --status`) :

- Définitions `*.edit` + `inventory.review` : **PRESENT**
- Assignations utilisateur legacy : **0**
- Status : `NO LEGACY USER ASSIGNMENTS`

Conservés volontairement : `legacyMappings()`, aliases AuthZ, `canEdit()`, migrator, commande artisan.

---

## 7. Routes sensibles

Couverture validée (suite `SensitiveRoutesTest` + reopen) :

| Route / zone | Protection |
|--------------|------------|
| `products/upload-image`, `generate-sku` | Auth + `products.create\|update` (+ alias edit lecture) |
| `delivery-notes/{id}/validate` | Auth + `delivery-notes.validate` + isolation |
| `inventory/*` / reopen | Auth + permissions inventaire granulaire + company IDOR |
| `Admin/users`, `roles-permissions`, backups | `EnsureUserIsAdmin` (+ permissions backups si applicable) |
| `notifications/test` | Admin |
| `/dev/*` | Hors local non enregistré / inaccessible |
| Company singleton | Permission company |

---

## 8. Isolation / IDOR

RBAC ≠ isolation. Vérifié sans modification :

- `InventoryIdorTest` : accès cross-company → **404**
- Reopen autre company → **404**
- Ressource manquante BL validate → **404**
- `AttachmentAuthorizer` conservé

Aucune régression d’isolation détectée.

---

## 9. Protection dernier administrateur

`AdminProtectionService` + `UserController` (+ CLI `SetUserRole`) :

- Dernier admin actif : pas de rétrogradation / désactivation / suppression
- Plusieurs admins : opérations autorisées
- Auto-action dernier admin : refus
- Verrouillage transactionnel conservé
- Frontend : aide UX uniquement — **sécurité = backend**

Suites `AdminProtectionServiceTest` + `LastAdminProtectionTest` : vertes.

---

## 10. Audit RBAC

Événements confirmés (`ActivityLog` + `RbacAuditService`) :

```text
rbac.role_changed
rbac.permissions_changed
rbac.permissions_synced
rbac.user_activated
rbac.user_deactivated
rbac.admin_removed
rbac.last_admin_change_denied
rbac.legacy_permissions_migrated
```

Acteur / cible / old-new / added-removed ; pas de password/token ; `afterCommit` ; rollback sans faux succès. L’audit **ne décide pas** l’autorisation.

---

## 11. Cache

```text
Intra-request only
No global RBAC cache
```

- Pas de Redis / TTL pour décisions RBAC
- `forgetCachedPermissions()` après mutations UserController
- `Cache::remember` présent uniquement hors RBAC (NotificationCenter) — hors périmètre décision AuthZ

---

## 12. Frontend

- Source : `AuthorizationService::forUser` via Inertia
- API : `usePermissions` (`can`, `canAny`, `canAll`, `canView/Create/Update/Delete`, `canReopenInventory`, `isAdmin/Vendeur/Gestionnaire`)
- Compat lecture `edit`/`review` côté client uniquement
- UI admin : grille sans legacy ; presets alignés `RolePresets`
- **Ne constitue pas la sécurité finale**

---

## 13. Inventaire

```text
inventory.review = legacy (lecture / compat)
inventory.reopen = canonical (écriture + checkPermission)
```

- Gestionnaire : reopen **true**
- Vendeur : reopen **false**
- Admin : bypass
- Ancien pivot `review` : encore autorisé en lecture
- `checkPermission(..., 'review')` métier : **0**

Aucune modification du workflow inventaire durant le sign-off.

---

## 14. Tests (résultats réels)

| Suite | Résultat |
|-------|----------|
| `tests/Unit/Rbac` + `tests/Feature/Rbac` | **212 passed** (2516 assertions) |
| Inventaire ciblé (Workflow, Review, History, Export, Search, ListFilters, CreateSession, IDOR, Reopen) | **121 passed** (522 assertions) |
| Vitest (`npm run test -- --run`) | **134 passed** (14 files) |
| `php artisan test` (global) | **Échec fatal hors RBAC** — voir §20 |

---

## 15. Build

`npm run build` : **OK** (~1m 19s).

---

## 16. Stock consistency

`php artisan stock:check-consistency` : **Cohérence stock OK.**

---

## 17. Recherches finales (synthèse)

| Motif | Classification dominante |
|-------|--------------------------|
| `*.edit` / `inventory.review` | LEGACY COMPAT / DOC / TEST / définitions DB |
| `checkPermission` | ACTIVE façades → AuthZ |
| `CheckPermission` middleware | ABSENT (supprimé N1) |
| `permissions.includes` | UI matrice RBAC (`rbacUi.ts`) |
| `role ===` | UI / attribution presets / last-admin / EnsureUserIsAdmin |
| `hasPermission` / `sync` / `attach` | ACTIVE façades / UserController canonique |
| Écriture directe legacy | **0** |

Aucune occurrence **ACTIVE** ne réintroduit une autorisation legacy à l’écriture.

---

## 18. Corrections effectuées

**Aucune.** Aucun problème de sécurité RBAC réel n’a nécessité de correctif durant ce sign-off.

---

## 19. Risques résiduels (non bloquants)

| Risque | Niveau | Mitigation |
|--------|--------|------------|
| Bases clientes avec pivots legacy importés | Faible | Lecture AuthZ + commande migrate + canonicalize à l’écriture admin |
| Définitions legacy encore en DB | Accepté | Suppression physique = décision ultérieure |
| `user:set-role` ne resync pas les pivots | Moyen / doc | Comportement volontaire ; pas d’intro legacy |
| Confusion routes Laravel `*.edit` | Faible | Documenté (CRUD Laravel ≠ permission) |

---

## 20. Limites hors périmètre

```text
Global PHP suite blocked by pre-existing test helper redeclaration.
Cannot redeclare function createTestProduct()
(previously declared in AttachmentTest.php:59)
in EditUpdateCompatibilityTest.php:104
```

Hors périmètre RBAC sign-off — **ne pas** corriger automatiquement ici.

Autres hors scope : Spatie, Redis RBAC, force-sync global, suppression physique legacy, refactor inventaire/stock/barcode.

---

## 21. Recommandations post-sign-off (non bloquantes)

1. Corriger la redéclaration `createTestProduct()` pour débloquer `php artisan test` global.
2. Décider ultérieurement (GO produit) de la suppression physique des lignes `permissions` legacy + réduction progressive des aliases.
3. Sur environnements clients : exécuter `rbac:migrate-legacy-permissions --status` puis migrate si besoin.
4. Clarifier éventuellement le comportement de `user:set-role` (doc ops / sync optionnel explicite).

---

## 22. Conclusion

Le RBAC MKD-Pro (C → N4) est **techniquement finalisé** pour la production :

- source unique `AuthorizationService` ;
- écritures canoniques ;
- legacy en lecture seule ;
- rôles métier conformes (gestionnaire sans ventes) ;
- last-admin, audit, cache intra-requête, routes sensibles, isolation inventaire validés ;
- build et stock OK ;
- suites RBAC / inventaire / Vitest vertes.

```text
RBAC FINAL SIGN-OFF — READY FOR PRODUCTION
```
