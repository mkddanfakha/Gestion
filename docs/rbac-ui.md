# RBAC UI — Phase M

**Date :** 2026-08-23  
**Verdict :** Interface admin premium « Rôles & permissions » — backend reste source de vérité.

---

## Architecture UI

```text
PermissionCatalog / RolePresets
        ↓
RbacUiPresenter (lecture seule)
        ↓
RolesPermissionsController
        ↓
Admin/RolesPermissions/Index.vue
        ↓
composants rbac/* + rbacUi.ts
```

Les mutations restent dans `Admin\UserController` + `AuthorizationService` + `AdminProtectionService` + `RbacAuditService`.

---

## Source de vérité

| Donnée | Source |
|--------|--------|
| Catalogue / labels | `PermissionCatalog` |
| Presets rôles | `RolePresets` |
| Autorisation | `AuthorizationService` |
| Compteurs users | `User::groupBy('role')` (1 requête) |
| Journal | `ActivityLog` module `RBAC` |
| Checks Vue métier | `usePermissions()` |

---

## Rôles (affichage)

| Rôle | UI |
|------|-----|
| Administrateur | Accès complet / bypass — **pas** « N permissions cochées » |
| Gestionnaire | Preset sans `sales.*` |
| Vendeur | Inclut `sales.*` + `dashboard.view` |
| Utilisateur | Permissions personnalisées (Users Create/Edit) |

---

## Permissions

- Affichage principal : permissions **non legacy** du catalogue.
- Canonique : `*.update`, `inventory.reopen`.
- Legacy (`*.edit`, `inventory.review`) : section « Compatibilité legacy » uniquement.
- Classification « Sensible » : **UI only** (delete, validate, apply, cancel, close, export, reopen, backups).

---

## Comportement admin

- `isBypass: true`, `permissions: []`, `permissionCount: null`.
- Libellé : « Accès complet ».
- Détail : explique le bypass sans simuler un pivot rempli.

---

## Gestionnaire sans ventes

La carte et la matrice montrent l’absence de `sales.*` (données `RolePresets`, non hardcodées pour la matrice).

---

## Inventaire

- Affiché : `inventory.reopen` (« Réouvrir un inventaire »).
- `inventory.review` : legacy uniquement.

---

## Page & route

- Route : `GET /admin/roles-permissions` → `admin.roles-permissions.index`
- Middleware : `EnsureUserIsAdmin`
- Nav : Administration → **Rôles & permissions**

Sections :

1. Stats (rôles / permissions / users)
2. Cartes rôles + drawer détail
3. Matrice de comparaison (2–3 rôles, différences seules)
4. Journal RBAC récent + filtres
5. Legacy technique

---

## Users Create/Edit

- Composant `RbacPermissionPicker` : recherche, filtres, groupes premium.
- Sync / audit / last-admin inchangés côté backend.

---

## Responsive / dark / a11y

- Mobile : cartes → drawer plein écran ; matrice en scroll horizontal contrôlé.
- Tokens `--color-*` / Bootstrap theme (light/dark).
- Labels associés, Escape pour fermer le drawer, états disabled, texte + icônes (pas couleur seule).

---

## Sécurité

- Frontend = UX uniquement.
- Pas de nouvelle mutation RBAC hors UserController.
- Last-admin et audit intacts.

---

## Tests

| Suite | Couverture |
|-------|------------|
| `RolesPermissionsPageTest` | Page admin, payload, filtre users |
| `rbac-ui.test.ts` | Admin bypass, vendeur, gestionnaire, recherche, matrice, audit filters |
| Suites RBAC existantes | Non régressées (échantillon Phase M) |

---

## Fichiers clés

- `app/Auth/RbacUiPresenter.php`
- `app/Http/Controllers/Admin/RolesPermissionsController.php`
- `resources/js/pages/Admin/RolesPermissions/Index.vue`
- `resources/js/components/rbac/*`
- `resources/js/utils/rbacUi.ts`
- `docs/rbac-ui-audit.md` (M0)
