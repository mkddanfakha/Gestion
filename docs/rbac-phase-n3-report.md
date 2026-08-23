# PHASE N3 — RAPPORT FINAL

## 1. Audit initial

- Contrôleurs métier : déjà sur `update` / `reopen` (aucune garde `edit`/`review`)
- RolePresets : déjà canoniques
- Frontend métier : routes Laravel `*.edit` (CRUD) ≠ permissions RBAC
- Pivots legacy locaux : 0
- Définitions legacy DB : 11 présentes
- Grille Users : filtrage surtout frontend avant N3

## 2. Références legacy trouvées

| Type | Exemples | Classification |
|------|----------|----------------|
| A — Permission RBAC | `products.edit`, `inventory.review` | Compat catalogue / aliases / tests |
| B — Route Laravel | `route('products.edit')` | Conservé |
| C — Méthode CRUD | `edit()` | Conservé |
| D — Page Vue | `Edit.vue` | Conservé |

## 3. Références legacy « nettoyées » côté attribution

- Grille admin Users : plus d’exposition des IDs legacy (backend)
- Store/Update custom : IDs legacy soumis → canoniques (`AssignablePermissionResolver`)
- Aucune autorisation métier à corriger (déjà propre)

## 4. Références conservées volontairement

- `PermissionName::*Edit`, `InventoryReview`
- `PermissionCatalog::legacyMappings` / `isLegacy` / `canonicalName`
- Aliases `AuthorizationService` + `usePermissions` / `rbacPermissions.ts`
- `canEdit()` (alias deprecated de `canUpdate`)
- Lignes table `permissions` legacy
- Commande `rbac:migrate-legacy-permissions`
- Tests / docs de transition

## 5. Presets

Exclusivement canoniques (`*.update`, `inventory.reopen`). Aucun changement métier des presets.

## 6. Frontend

- `canUpdate` / `canReopenInventory` privilégiés
- `canEdit` conservé + `@deprecated`
- Aucun `can('*.edit')` métier trouvé hors tests

## 7. UI RBAC

- RolesPermissions : section legacy clarifiée (lecture seule / pas de nouvelle attribution)
- Users Create/Edit : grille via `AssignablePermissionResolver::adminGridByResource()`

## 8. Migration legacy

Commande N2 inchangée (`--dry-run`, `--force`, `--status`). Toujours disponible.

## 9. Tests de compatibilité

`LegacyPermissionCompatibilityTest` : update, edit legacy, reopen, review legacy, presets/grille, canonicalize à la création, migrate + idempotence, rollback.

## 10. Régression RBAC

```text
Unit RBAC : 90 passed
Feature RBAC : 101 passed
```

## 11. Tests inventaire

```text
64 passed (reopen / review / apply)
```

## 12. Stock consistency

```text
Cohérence stock OK
```

## 13. Build

```text
npm run build : OK
Vitest RBAC : 20 passed
```

## 14. Fichiers créés

- `app/Auth/AssignablePermissionResolver.php`
- `tests/Feature/Rbac/LegacyPermissionCompatibilityTest.php`
- `docs/rbac-legacy-cleanup.md`

## 15. Fichiers modifiés

- `app/Http/Controllers/Admin/UserController.php`
- `resources/js/composables/usePermissions.ts` (doc deprecated)
- `resources/js/pages/Admin/RolesPermissions/Index.vue` (texte legacy)
- `tests/Unit/Rbac/PermissionCatalogTest.php`
- `docs/rbac-architecture.md`

## 16. Fichiers volontairement non modifiés

AuthorizationService (règles), RolePresets, PermissionCatalog (mappings), AdminProtection, inventaire/stock/BarcodeInput, suppression DB legacy, Spatie/Redis.

## 17. Sécurité

- Pas de nouveau privilège
- Lecture legacy toujours honorée
- Nouvelles attributions custom = canoniques uniquement
- Last-admin inchangé

## 18. Régressions

```text
Aucune régression détectée
```

## 19. Risques résiduels

- Pivots legacy encore possibles sur d’autres environnements → commande migrate
- Suppression physique des définitions legacy reportée
- Suite PHP globale toujours cassée par `createTestProduct()` préexistant

## 20. État des permissions legacy en DB

```text
Définitions : KEPT (11)
Pivots utilisateurs (local) : 0
Status commande : NO LEGACY USER ASSIGNMENTS
```

## 21. Documentation

- `docs/rbac-legacy-cleanup.md`
- Section Phase N3 dans `docs/rbac-architecture.md`

## 22. Verdict

```text
PHASE N3 READY FOR N4
```

Ne pas démarrer N4 automatiquement.
