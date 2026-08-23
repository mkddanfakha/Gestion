# RBAC Frontend — Phase L

**Date :** 2026-08-23  
**Statut :** Implémenté  
**Référence :** [`docs/rbac-architecture.md`](./rbac-architecture.md)

---

## 1. Architecture

```
AuthorizationService::forUser($user)
        ↓
HandleInertiaRequests (auth.user.permissions)
        ↓
usePermissions()
        ↓
Pages / composants Vue
```

Le frontend **n’est pas** une protection de sécurité. Toute action sensible reste contrôlée par le backend (`checkPermission` / `AuthorizationService::allows`).

---

## 2. Exposition Inertia

Dans `HandleInertiaRequests` :

| Utilisateur | `auth.user.permissions` |
|-------------|-------------------------|
| Guest | `auth.user = null` |
| Admin | `[]` (bypass via `role === 'admin'`) |
| Autres | liste des noms issus de `AuthorizationService::forUser()` |

Pas d’exposition des pivots, des permissions d’autres users, ni de secrets.

---

## 3. `usePermissions()`

| Méthode | Rôle |
|---------|------|
| `can(name)` | Permission complète (`products.update`) + aliases legacy |
| `canAny([...])` | Au moins une (noms complets) |
| `canAny(resource, actions[])` | API historique |
| `canAll([...])` | Toutes |
| `canUpdate(module)` | `*.update` (+ `*.edit`) |
| `canEdit(module)` | **Alias legacy** de `canUpdate` |
| `canReopenInventory()` | `inventory.reopen` (+ `inventory.review`) |
| `canCreate` / `canView` / `canDelete` / … | Raccourcis resource.action |
| `isAdmin` / `isVendeur` / `isGestionnaire` | Lecture du rôle (affichage / UX) |

Aucune requête HTTP par permission.

---

## 4. Legacy

Compatibilité dans `resources/js/utils/rbacPermissions.ts` :

- `*.edit` ↔ `*.update`
- `inventory.review` ↔ `inventory.reopen`

**Nouveau code :** utiliser les formes canoniques uniquement.

---

## 5. Admin Users (préparation Phase M)

Grille Create/Edit :

- masque les permissions legacy si le canonique est présent ;
- presets UI alignés sur `RolePresets` (vendeur + `dashboard.view`, gestionnaire + inventaire, **sans** `sales.*`) ;
- cases désactivées pour vendeur/gestionnaire (sync réelle au backend).

---

## 6. Rôle vs permission

| OK | Éviter |
|----|--------|
| `can('sales.create')` pour masquer « Nouvelle vente » | `role === 'gestionnaire'` pour cacher les ventes |
| `isAdmin` pour liens admin / activité | reconstruire un preset dans Vue |

Occurrences `role` restantes justifiées : badges Admin Users, UX prix d’achat vendeur (`Products/Edit`), URL notifications admin.

---

## 7. Inventaire

Décisions UI session : `session.permissions.*` (payload backend déjà basé sur `hasPermission`).  
`session.permissions.reopen` (plus `review`).  
`canReopenInventory()` disponible pour toute UI hors session.

---

## 8. 403

Page `resources/js/pages/errors/403.vue` existante. Ne pas transformer 403 en 404.

---

## 9. Règles pour les développeurs

1. Toujours `usePermissions()` — pas de `permissions.includes` ad hoc.  
2. Canonique : `can('….update')`, `can('inventory.reopen')`.  
3. Ne jamais faire confiance à l’UI pour la sécurité.  
4. Ne pas appeler d’API `/permissions/check`.  
5. Ne pas recopier `RolePresets` hors des écrans admin d’aperçu.
