# RBAC — Rapport Phase N4 (Final Hardening)

**Date :** 2026-08-23  
**Verdict :** `PHASE N4 READY FOR FINAL RBAC SIGN-OFF`

---

## 1. Audit initial

Audits réalisés **sans modification applicative** en sous-phase 1 :

- [`docs/rbac-write-path-audit.md`](./rbac-write-path-audit.md) — cartographie des écritures
- [`docs/rbac-final-authorization-audit.md`](./rbac-final-authorization-audit.md) — classification A/B/C des contrôles

Constat : écritures utilisateur concentrées dans `Admin\UserController` via `RolePresets` / `AssignablePermissionResolver` ; pas de deuxième moteur AuthZ.

---

## 2. Chemins d’écriture RBAC

| Chemin | Canonicalisation | Statut |
|--------|------------------|--------|
| UserController store/update (custom) | `AssignablePermissionResolver::canonicalizeIds` | OK |
| UserController presets vendeur/gestionnaire | `RolePresets::permissionIds` (déjà canoniques) | OK |
| Admin grille | `adminGridByResource` (non-legacy catalogue) | OK |
| Legacy migrator | outil explicite | OK |
| `user:set-role` | change rôle uniquement (pas de sync pivots) | Documenté |

---

## 3. Protection contre la réintroduction legacy

Règle verrouillée :

```text
Legacy = READ / COMPATIBILITY
Canonical = WRITE / ASSIGNMENT uniquement
```

`AssignablePermissionResolver` (N4) :

- `products.edit` → `products.update`
- `inventory.review` → `inventory.reopen`
- IDs hors `PermissionCatalog` **ignorés** (jamais écrits)
- `isWritableName()` pour garde explicite

Définitions legacy en table `permissions` : **conservées** (pas de suppression physique).

---

## 4. Intégrité RolePresets

Tests `RolePresetIntegrityTest` + `RbacArchitectureIntegrityTest` :

- Admin : bypass, preset vide, pas de legacy
- Vendeur : `dashboard.view` + commercial canonique ; pas d’inventaire apply/validate/close/reopen
- Gestionnaire : `inventory.reopen` ; **aucune** `sales.*`
- User : preset vide (permissions personnalisées)

---

## 5. AuthorizationService

Reste la source unique de décision. Aliases lecture (`*.edit` ↔ `*.update`, `inventory.review` ↔ `inventory.reopen`) et `canEdit()` deprecated **conservés**.

---

## 6. Gestionnaire sans sales

`RbacFinalHardeningTest` : `sales.view|create|update|delete|invoice` refusés ; HTTP **403** sur `sales.index` / `sales.create`.

---

## 7. Vendeur

Dashboard + sales OK ; admin users / roles-permissions redirigés ; pas de `inventory.reopen` / `backups.view`.

---

## 8. Admin / bypass

`allows()` true avec pivot vide ; rétrogradation admin → vendeur retire le bypass immédiatement (pas de cache global).

---

## 9. Last Admin Protection

Suites existantes `AdminProtectionServiceTest` + `LastAdminProtectionTest` : **vertes** (pas de réimplémentation).

---

## 10. Audit RBAC

`RbacAuditTest` + `RbacAuditIntegrationTest` : événements `rbac.*` inchangés ; pas de second système d’audit.

---

## 11. Cache RBAC

`AuthorizationCacheTest` + intégration : **intra-requête uniquement** ; `forgetCachedPermissions` obligatoire après sync.

---

## 12. Isolation / IDOR

`InventoryIdorTest` + reopen IDOR : **404** cross-company ; **403** sans permission.

---

## 13. Routes sensibles

`SensitiveRoutesTest` : upload-image, generate-sku, BL validate, notifications/test, `/dev/*`, company — **OK**.

---

## 14. Tests

| Suite | Résultat |
|-------|----------|
| `tests/Unit/Rbac` + `tests/Feature/Rbac` | **212 passed** (2516 assertions) |
| Inventaire ciblé (Workflow, Review, History, Export, Search, ListFilters, CreateSession, IDOR, Reopen) | **121 passed** (522 assertions) |
| N4 dédiés (AssignmentGuard + RolePresetIntegrity + ArchitectureIntegrity + FinalHardening) | **21 passed** (530 assertions) |
| Vitest (`npm run test -- --run`) | **134 passed** (14 files) |
| `php artisan test` (global) | **Bloqué** — `Cannot redeclare createTestProduct()` (préexistant : AttachmentTest / EditUpdateCompatibilityTest / …) |

---

## 15. Build

`npm run build` : **OK** (~1m 30s).

---

## 16. Stock consistency

`php artisan stock:check-consistency` : **Cohérence stock OK.**

---

## 17. Recherche finale legacy

| Contrôle | Résultat |
|----------|----------|
| `checkPermission(..., 'edit'|'review')` dans `app/` | **0** |
| Écriture directe `products.edit` / `inventory.review` dans Http | **0** |
| Pivots user legacy (`rbac:migrate-legacy-permissions --status`) | **NO LEGACY USER ASSIGNMENTS** (0) |
| Définitions legacy en DB | **PRESENT** (volontaire) |

Occurrences restantes légitimes : catalogue, mappings, AuthZ aliases, tests, docs, migrator, routes Laravel `*.edit`.

---

## 18. Git diff (périmètre N4)

Fichiers **créés / touchés pour N4** :

- Docs audit + rapport N4
- Durcissement `AssignablePermissionResolver`
- 4 fichiers de tests + `tests/Pest.php`
- Mises à jour architecture / legacy / hardening

Pas de modification StockService / ProductStock / StockMovement / BarcodeInput.

*(Le working tree contient aussi l’historique C→N3 non commité — hors périmètre de ce rapport.)*

---

## 19. Risques résiduels

| Risque | Niveau | Note |
|--------|--------|------|
| Bases clientes encore avec pivots legacy | Faible | Lecture OK ; écriture admin canonise ; commande migrate disponible |
| Définitions legacy encore en DB | Accepté | Suppression physique **hors N4** |
| Suite PHP globale cassée (`createTestProduct`) | Hors N4 | Documenté, non corrigé |
| `user:set-role` ne sync pas les pivots | Moyen / doc | Comportement volontaire, pas d’intro legacy |

---

## 20. Verdict

```text
PHASE N4 READY FOR FINAL RBAC SIGN-OFF
```

Critères N4 satisfaits : écritures canoniques, legacy lecture seule, presets cohérents, AuthZ centralisée, gestionnaire sans sales, vendeur/admin/last-admin/audit/cache/inventaire/stock/build/Vitest OK, **0** pivot legacy local, **pas** de suppression physique des permissions legacy.

**Aucune Phase N5 démarrée.**
