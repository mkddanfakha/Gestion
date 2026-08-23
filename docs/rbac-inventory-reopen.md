# RBAC — inventory.review → inventory.reopen (Phase H)

**Date :** 2026-08-23  
**Statut :** Implémenté  
**Références :** [`rbac-architecture.md`](./rbac-architecture.md), [`rbac-authorization.md`](./rbac-authorization.md)

---

## 1. Problème historique

La permission `inventory.review` autorisait en réalité l’action HTTP de **réouverture** :

`POST /inventory/{session}/reopen` → `InventorySessionController::reopen()`

Le statut métier de session `review` (revue des écarts) est une notion distincte et **n’a pas été renommée**.

---

## 2. Mapping

| Nom | Statut | Rôle |
|-----|--------|------|
| `inventory.reopen` | **Canonique** | Réouvrir un inventaire (reprendre le comptage depuis l’état revue) |
| `inventory.review` | **Legacy** | Ancien nom ; conserve l’accès pour les utilisateurs existants |

---

## 3. Compatibilité AuthorizationService

Bidirectionnelle via `PermissionCatalog::legacyMappings()` :

- Utilisateur avec uniquement `inventory.review` → `allows('inventory.reopen')` = true
- Utilisateur avec uniquement `inventory.reopen` → `allows('inventory.review')` = true
- Aucun des deux → false
- Admin → bypass

Le nouveau code applicatif demande uniquement `inventory.reopen`.

---

## 4. RolePresets

- **Gestionnaire :** `inventory.reopen` (plus `inventory.review`)
- **Vendeur :** pas de permission de réouverture
- **Admin :** bypass
- **User :** personnalisable

**Aucun force-sync** des utilisateurs existants.

---

## 5. Backend

| Fichier | Changement |
|---------|------------|
| `InventorySessionController::reopen` | `checkPermission(..., 'reopen')` |
| `InventorySessionService::resolveSessionPermissions` | clé `reopen` (+ `review` miroir pour compat payload) |

URL et nom de route `inventory.reopen` **inchangés**.

---

## 6. Frontend

- Bouton réouverture : `session.permissions?.reopen`
- `usePermissions.canReopenInventory()` : `inventory.reopen` OU `inventory.review`

---

## 7. Seeder

Crée `inventory.reopen` si absent.  
**Ne supprime pas** `inventory.review`.  
**Ne modifie pas** `user_permissions`.

---

## 8. Suppression future de `inventory.review`

Après période de transition :

1. Auditer les pivots encore sur `inventory.review`
2. Migrer explicitement vers `inventory.reopen` (script / admin)
3. Retirer `inventory.review` du catalogue et de la DB

**Non fait en Phase H.**
