# RBAC — Role Presets (Phase D)

**Date :** 2026-08-23  
**Fichier source :** `app/Auth/RolePresets.php`  
**Références :** [`rbac-permission-catalog.md`](./rbac-permission-catalog.md), [`rbac-architecture.md`](./rbac-architecture.md)

---

## 1. Rôle de RolePresets

`RolePresets` centralise la **configuration standard** des permissions par rôle métier.

Ce n'est **pas** le moteur d'autorisation :

- `User::hasPermission()` et le bypass admin restent inchangés
- Les presets ne déclenchent **aucune** synchronisation globale des utilisateurs existants
- La sync pivot n'a lieu que via `Admin\UserController` (création ou mise à jour explicite)

---

## 2. Rôles disponibles

| Rôle | Constante | Preset permissions |
|------|-----------|---------------------|
| Administrateur | `User::ROLE_ADMIN` | Aucune (bypass code) |
| Gestionnaire | `User::ROLE_GESTIONNAIRE` | Catalogue opérationnel + inventaire |
| Vendeur | `User::ROLE_VENDEUR` | Ventes + devis + clients (lecture/vente) |
| Utilisateur | `User::ROLE_USER` | Personnalisé (vide par défaut) |

---

## 3. API

| Méthode | Description |
|---------|-------------|
| `roles()` | Liste des rôles connus |
| `has($role)` | Rôle supporté |
| `usesBypass($role)` | true pour admin |
| `for($role)` | Liste `PermissionName` |
| `permissionNames($role)` / `permissions($role)` | Noms string |
| `permissionIds($role)` | IDs DB (sync UserController) |
| `validate()` | Contrôle catalogue + doublons + règle gestionnaire |

---

## 4. Preset Gestionnaire

**Règle métier : pas de ventes.**

Modules couverts :

- dashboard
- products, categories
- quotes, expenses, suppliers
- purchase-orders, delivery-notes
- inventory (10 permissions, dont `inventory.review` legacy)

**Exclus :** `sales.*`, `customers.*`, `company.*`, `backups.*`

---

## 5. Preset Vendeur

- `dashboard.view` (ajout Phase D vs ancien preset)
- `sales.*` (view, create, update, delete, invoice) — canonique `update`, pas `edit`
- `quotes.*` complet
- `products.view`
- `customers.view`, `customers.create`, `customers.update`

**Exclus :** inventaire apply/validate/close, backups, permissions admin

---

## 6. Preset Admin

- `RolePresets::for('admin')` → `[]`
- `usesBypass('admin')` → true
- Pivot `user_permissions` vidé à la création/mise à jour admin
- Accès total via `User::isAdmin()` / `hasPermission()` bypass

---

## 7. Preset User

- Preset vide → permissions choisies manuellement (checkboxes admin)
- Création : `attach` des IDs fournis
- Mise à jour : `sync` des IDs fournis

---

## 8. edit / update

Les presets Phase D utilisent **`*.update`** (canonique), pas **`*.edit`** (legacy).

Les permissions `*.edit` restent en base pour compatibilité (Phase G).

---

## 9. Inventaire

Gestionnaire : toutes les permissions inventaire seedées, y compris **`inventory.review`** (legacy reopen).

**Non inclus :** `inventory.reopen` (Phase H).

---

## 10. Matrice synthétique

| Module | Admin | Gestionnaire | Vendeur | User |
|--------|:-----:|:------------:|:-------:|:----:|
| Bypass total | ✓ | — | — | — |
| dashboard | bypass | ✓ | ✓ | ○ |
| products | bypass | CRUD | view | ○ |
| categories | bypass | CRUD | — | ○ |
| customers | bypass | — | view/create/update | ○ |
| sales | bypass | **—** | ✓ | ○ |
| quotes | bypass | ✓ | ✓ | ○ |
| expenses | bypass | ✓ | — | ○ |
| suppliers | bypass | ✓ | — | ○ |
| purchase-orders | bypass | ✓ | — | ○ |
| delivery-notes | bypass | ✓ | — | ○ |
| inventory | bypass | ✓ | — | ○ |
| company / backups | bypass | — | — | ○ |

Légende : ✓ = preset ; ○ = personnalisable ; **—** = exclu du preset.

---

## 11. Divergences vs ancien UserController (documentées)

| Changement | Impact utilisateurs existants |
|------------|------------------------------|
| Vendeur + `dashboard.view` | Nouveaux vendeurs uniquement (ou resync à la prochaine MAJ admin) |
| Presets sans `*.edit` | Idem — canonique `update` |
| Gestionnaire + inventaire complet | Idem — resync si admin enregistre le profil |
| Pas de force-sync global | Utilisateurs non touchés tant qu'aucune action admin |

---

*Phase D — extraction presets. AuthorizationService → Phase E.*
