# PHASE N2 — RAPPORT FINAL

## 1. Audit initial

Environnement local (commande `--status` + catalogue) :

```text
Legacy permission definitions : PRESENT (11 : 10× *.edit + inventory.review)
Legacy user pivots : 0
Canonical permission definitions : PRESENT
Canonical user pivots : présents selon usage (ex. sales.update, quotes.update, customers.update)
```

Code métier : aucun `checkPermission(..., 'edit')` / `hasPermission(..., 'edit')` restant dans les contrôleurs.

## 2. Mappings

| Legacy → Canonique | Pivots migrés (env. local) |
|--------------------|----------------------------|
| products.edit → products.update | 0 |
| categories.edit → categories.update | 0 |
| customers.edit → customers.update | 0 |
| sales.edit → sales.update | 0 |
| quotes.edit → quotes.update | 0 |
| expenses.edit → expenses.update | 0 |
| suppliers.edit → suppliers.update | 0 |
| purchase-orders.edit → purchase-orders.update | 0 |
| delivery-notes.edit → delivery-notes.update | 0 |
| company.edit → company.update | 0 |
| inventory.review → inventory.reopen | 0 |

(Migration démontrée sur pivots artificiels en tests.)

## 3. Dry-run

Sur base locale sans pivots legacy :

```text
No legacy user permission assignments found.
Nothing to migrate.
```

En tests Feature : dry-run affiche les mappings et **ne modifie pas** la DB.

## 4. Migration

Env. local :

```text
Users affected : 0
Pivots canonical added : 0
Pivots legacy removed : 0
```

Tests (données artificielles) : ajout canonique puis retrait legacy, sans force-sync.

## 5. Idempotence

Deuxième exécution → `Nothing to migrate.` / `legacy_removed = 0`.

## 6. Rollback

Test unitaire : exception après attach → transaction annulée, pivots inchangés.

## 7. Audit RBAC

Événement `rbac.legacy_permissions_migrated` (CLI, actor null) lorsque des pivots sont réellement migrés.

## 8. État final des pivots

```text
Legacy user pivots = 0
```

## 9. Permissions legacy

```text
Legacy permission rows in permissions table = KEPT TEMPORARILY
```

## 10. Tests

```text
Unit RBAC : 88 passed
Feature RBAC : 93 passed
Migration : 13 passed (10 unit + 3 feature)
Inventaire : 64 passed (reopen/review/apply)
Vitest : 20 passed
```

## 11. Stock

```text
stock:check-consistency : OK
```

## 12. Build

```text
npm run build : OK
```

## 13. Recherche finale

Occurrences `*.edit` / `inventory.review` restantes — **acceptables** :

- `PermissionCatalog` / enum / aliases AuthZ
- `rbacPermissions.ts` / `usePermissions` / tests compat
- commande + migrator + docs
- routes nommées Laravel `products.edit` (URL, pas permission)

**Non acceptables (absents) :** contrôleurs métier demandant `edit` / `review` comme action d’autorisation.

## 14. Régressions

```text
Aucune régression détectée
```

## 15. Hors périmètre

Confirmé : pas de suppression physique legacy, pas de force-sync, RolePresets inchangé, AuthorizationService inchangé (hors audit event), inventaire/stock/barcode/frontend métier inchangés, pas de migration Schema destructive.

## 16. Limites

- `php artisan test` global toujours bloqué par `createTestProduct()` préexistant (hors N2).
- Aliases de lecture legacy volontairement conservés jusqu’à une phase ultérieure de cleanup catalogue.

## 17. Verdict

```text
PHASE N2 READY FOR N3
```
