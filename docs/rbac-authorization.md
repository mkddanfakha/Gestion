# RBAC — AuthorizationService (Phase E)

**Fichier :** `app/Auth/AuthorizationService.php`  
**Date :** 2026-08-23

---

## 1. Responsabilité

Répondre à une seule question :

> **Cet utilisateur peut-il effectuer cette action (permission) ?**

Ce n'est **pas** l'isolation des données (company, IDOR, policies métier).

---

## 2. API

| Méthode | Description |
|---------|-------------|
| `allows(User, string\|PermissionName)` | Décision principale |
| `denies(User, …)` | Négation de `allows` |
| `any(User, array)` | Au moins une permission |
| `all(User, array)` | Toutes les permissions |
| `forUser(User)` | Liste pivot pour frontend (admin → `[]`) |
| `allowsResourceAction(User, resource, action)` | Compat `hasPermission(resource, action)` |

---

## 3. Flux de décision

```
allows(user, permission)
  ├─ admin ? → true
  ├─ permission inconnue / vide ? → false
  ├─ resolveAliases(permission)  // legacy ↔ canonique
  └─ exists in user_permissions pivot ?
```

---

## 4. Admin bypass

Centralisé dans `AuthorizationService::allows()` :

```php
if ($user->isAdmin()) {
    return true;
}
```

`User::hasPermission()` et `Controller::checkPermission()` délèguent au service.

---

## 5. Permissions legacy

Résolution bidirectionnelle via `PermissionCatalog::legacyMappings()` :

| Vérification | Accepte en pivot |
|--------------|------------------|
| `products.edit` | `products.edit` ou `products.update` |
| `products.update` | `products.update` ou `products.edit` |
| `inventory.review` | `inventory.review` uniquement |

`inventory.reopen` n'est **pas** en base — vérification `reopen` → false sauf si review présente (mapping catalogue uniquement pour review→reopen côté legacy, pas l'inverse pour reopen inexistant).

---

## 6. Relations

```
PermissionName
      ↓
PermissionCatalog (métadonnées, legacy)
      ↓
AuthorizationService (décision)
      ↑
User::hasPermission() — façade
Controller::checkPermission() — HTTP 403
CheckPermission middleware — prêt, non branché routes
RolePresets — configuration, pas décision runtime
```

**Pas de dépendance circulaire** : le service interroge directement `user->permissions()`, jamais `User::hasPermission()`.

---

## 7. Frontend

`HandleInertiaRequests` inchangé en Phase E.

`forUser()` prépare une API propre pour Phase L (`usePermissions`).

---

## 8. Futur

| Sujet | Phase |
|-------|-------|
| Cache request-level | J |
| Audit sync permissions | K |
| Routes sensibles | F |
| Migration edit/update | G |
| review → reopen | H |

---

*Phase E — stabilisation. Ne pas confondre RBAC et isolation entreprise.*
