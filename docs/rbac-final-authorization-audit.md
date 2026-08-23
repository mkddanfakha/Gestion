# RBAC — Audit final des contrôles d’autorisation (Phase N4)

**Date :** 2026-08-23

## Classification

### A — Sécurité backend (doit déléguer à AuthorizationService)

| Occurrence | Classification |
|------------|----------------|
| `Controller::checkPermission` / `checkAnyPermission` | **A** → AuthZ |
| `User::hasPermission` / `hasPermissionByName` | **A** → AuthZ |
| Contrôleurs métier (sales, inventory, products, …) | **A** via checkPermission |
| `AttachmentAuthorizer` | **A** via hasPermission |
| `EnsureUserIsAdmin` | **A** admin-only (rôle, pas permission catalogue) — justifié |
| `ActivityLogPolicy` / NotificationSettings admin | **A** admin-only — justifié |
| `UserController::destroy` `isAdmin()` | **A** admin-only — justifié |

### B — Affichage / UX

| Occurrence | Classification |
|------------|----------------|
| `usePermissions().isAdmin` nav admin | **B** |
| `canView` / `canUpdate` / `canReopenInventory` UI | **B** |
| Badges rôle Users Index | **B** |
| `form.role ===` Create/Edit Users | **B** |
| DashboardService `isAdmin() \|\| hasPermission` | **B/A** redondant (admin déjà bypass dans hasPermission) — non bloquant |

### C — Non-RBAC / conventions Laravel

| Occurrence | Classification |
|------------|----------------|
| `route('*.edit')`, `edit()` CRUD, `Edit.vue` | **C** |
| Notification prefs par rôle | **C** (préférences, pas AuthZ catalogue) |

## Exceptions documentées

1. **Admin-only features** (journal, backups UI gate via EnsureUserIsAdmin + backups.*) : rôle admin comme porte d’entrée admin, permissions backups pour actions.
2. **CLI `user:set-role`** : change uniquement le rôle ; ne réécrit pas les pivots (pas d’introduction legacy).

## Source unique de décision

```text
AuthorizationService::allows()
```

Aucune deuxième logique RBAC métier trouvée hors façades.

*Fin audit autorisation N4.*
