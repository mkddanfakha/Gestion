# RBAC — Permission Catalog (Phase C)

**Date :** 2026-08-23  
**Statut :** Implémenté — fondation catalogue, sans changement comportemental  
**Références :** [`rbac-audit.md`](./rbac-audit.md), [`rbac-architecture.md`](./rbac-architecture.md)

---

## 1. Objectif

Centraliser les **74 permissions actives** MKD-Pro dans une source PHP unique :

- `App\Enums\PermissionName` — identifiants typés
- `App\Auth\PermissionCatalog` — métadonnées, libellés FR, legacy/canonique
- `Database\Seeders\PermissionSeeder` — alimentation idempotente de la table `permissions`

Aucune modification des attributions `user_permissions`, des contrôleurs, du frontend ou des presets rôles.

---

## 2. Audit pré-implémentation (écarts confirmés)

### Permissions utilisées via `checkPermission` (backend)

| Module | Actions |
|--------|---------|
| products | view, create, edit, update, delete |
| categories | view, create, edit, update, delete |
| customers | view, create, edit, update, delete, export |
| sales | view, create, edit, update, delete, invoice |
| quotes | view, create, edit, update, delete, download, print |
| expenses | view, create, edit, update, delete |
| suppliers | view, create, edit, update, delete, export |
| purchase-orders | view, create, edit, update, delete, download, print |
| delivery-notes | view, create, edit, update, delete, validate, download, print |
| company | view, update (pas `edit` en checkPermission) |
| dashboard | view |
| inventory | view, create, count, submit, **review** (route reopen), validate, apply, cancel, close, export |
| backups | view, create, download, delete, restore |

### Écarts catalogue vs usage réel

| Écart | Détail | Phase C |
|-------|--------|---------|
| `*.edit` + `*.update` | Doublons CRUD | Conservés ; `edit` = legacy |
| `company.edit` | Seedée, peu utilisée en check | Conservée legacy |
| `inventory.review` | Autorise `reopen` | Conservée legacy ; cible `inventory.reopen` |
| `inventory.reopen` | Route métier existante | **Non seedée** (planned canonical) |
| Admin / users | Pas de permissions seedées | Hors catalogue V1 (middleware admin) |

---

## 3. PermissionName

Fichier : `app/Enums/PermissionName.php`

- Backed enum `string`
- **74 cases** = permissions actives en base
- Méthodes : `values()`, `tryFromName()`

**Non inclus :** `inventory.reopen` (canonique future, Phase H).

---

## 4. PermissionCatalog

Fichier : `app/Auth/PermissionCatalog.php`

### API

| Méthode | Rôle |
|---------|------|
| `all()` | Toutes les définitions triées |
| `names()` | Liste des noms string |
| `find(PermissionName)` | Définition complète |
| `findByName(string)` | Définition ou null |
| `exists(string)` | Présence dans le catalogue actif |
| `forModule(string)` | Permissions d'un module |
| `label()` / `module()` / `description()` | Métadonnées |
| `isLegacy()` | Statut legacy |
| `canonicalName()` | Nom canonique cible (string) |
| `canonical()` | Enum canonique si existant |
| `plannedCanonicalName()` | Cible non encore en enum (ex. reopen) |
| `legacyMappings()` | Map legacy → canonique |
| `modules()` | Liste des modules |

Constante : `PermissionCatalog::PLANNED_INVENTORY_REOPEN = 'inventory.reopen'`

---

## 5. Permissions legacy

| Legacy | Canonique | En base Phase C |
|--------|-----------|-----------------|
| products.edit | products.update | Les deux |
| categories.edit | categories.update | Les deux |
| customers.edit | customers.update | Les deux |
| sales.edit | sales.update | Les deux |
| quotes.edit | quotes.update | Les deux |
| expenses.edit | expenses.update | Les deux |
| suppliers.edit | suppliers.update | Les deux |
| purchase-orders.edit | purchase-orders.update | Les deux |
| delivery-notes.edit | delivery-notes.update | Les deux |
| company.edit | company.update | Les deux |
| inventory.review | inventory.reopen | review oui ; reopen **non** |

---

## 6. PermissionSeeder

- Source : `PermissionCatalog::all()`
- `firstOrCreate` sur `(resource, action)` — idempotent
- Ne supprime rien, ne touche pas `user_permissions`
- Descriptions alignées sur le catalogue (à la création uniquement)

---

## 7. Volontairement reporté

| Sujet | Phase |
|-------|-------|
| RolePresets centralisés | D |
| AuthorizationService | E |
| Migration edit → update | G |
| inventory.review → reopen en base | H |
| Protection dernier admin | I |
| Cache / audit RBAC | J–K |
| UI admin premium | M |

---

## 8. Matrice complète

Voir [`rbac-permissions.md`](./rbac-permissions.md).

---

*Phase C — catalogue uniquement. Comportement applicatif inchangé.*
