# RBAC — Protection du dernier administrateur (Phase I)

**Date :** 2026-08-23  
**Statut :** Implémenté  
**Référence architecture :** [`docs/rbac-architecture.md`](./rbac-architecture.md)

---

## 1. Problème initial

Sans contrainte métier, un administrateur pouvait :

- rétrograder le dernier admin (rôle → vendeur / gestionnaire / user) ;
- désactiver le dernier admin (`is_active = false`) ;
- supprimer le dernier admin (via auto-suppression ou concurrente).

Résultat possible : **MKD-Pro sans aucun administrateur fonctionnel**, donc plus de capacité d’administration.

---

## 2. Règle métier

**Il doit toujours rester au moins un administrateur fonctionnel.**

Cette règle est une contrainte de sécurité/métier. Ce n’est **pas** une permission RBAC (`admin.delete-last-admin` n’existe pas et ne doit pas être créé).

Elle s’applique même à un admin bénéficiant du bypass `AuthorizationService`.

---

## 3. Définition d’un administrateur fonctionnel

```
role === User::ROLE_ADMIN  AND  is_active === true
```

Le champ `is_active` existe déjà sur `users` ; il est pris en compte. Aucun nouveau champ n’a été inventé.

Un admin avec `is_active = false` n’est **pas** compté comme administrateur fonctionnel.

---

## 4. Opérations protégées

| Opération | Route / commande | Protection |
|-----------|------------------|------------|
| Changement de rôle | `PUT admin/users/{user}` | `assertCanChangeRole` |
| Désactivation | `PUT admin/users/{user}` (`is_active=false`) | `assertCanDeactivate` |
| Suppression | `DELETE admin/users/{user}` | `assertCanRemoveAdmin` (avant le garde-fou auto-suppression) |
| CLI `user:set-role` | Artisan | `canChangeRole` + verrou |

**Non concernés (pas de contournement réel) :**

- `ProfileController::destroy` — ne supprime jamais le compte ;
- `store` (création) — ne peut pas retirer un admin existant ;
- Soft-delete / restore — absents sur User.

---

## 5. Comportement avec 1 admin actif

| Action | Résultat |
|--------|----------|
| Rétrograder en vendeur / gestionnaire / user | **403** |
| Désactiver (`is_active=false`) | **403** |
| Supprimer (y compris soi-même) | **403** |
| Conserver le rôle admin + rester actif | OK |

Messages (exemples) :

- `Impossible de retirer le rôle administrateur au dernier administrateur du système.`
- `Impossible de désactiver le dernier administrateur du système.`
- `Impossible de supprimer le dernier administrateur du système.`

---

## 6. Comportement avec plusieurs admins actifs

| Action | Résultat |
|--------|----------|
| Rétrograder un admin | OK (tant qu’il en reste ≥ 1) |
| Désactiver un admin | OK |
| Supprimer un admin (par un autre admin) | OK |
| Auto-suppression | Toujours refusée (garde-fou distinct), sauf si dernier admin → 403 métier d’abord |

---

## 7. Protection backend

Classe centrale : `App\Auth\AdminProtectionService`

| Méthode | Rôle |
|---------|------|
| `countActiveAdmins()` | Compte `role=admin` + `is_active=true` |
| `isLastAdmin(User)` | Seul admin fonctionnel ? |
| `canChangeRole` / `assertCanChangeRole` | Démotion |
| `canDeactivate` / `assertCanDeactivate` | Désactivation |
| `canRemoveAdmin` / `assertCanRemoveAdmin` | Suppression |
| `withAdminLock(User, callable)` | Transaction + `lockForUpdate` |

`Admin\UserController` **appelle** le service ; la logique SQL n’est pas dupliquée dans le contrôleur.

`AuthorizationService` **n’est pas modifié** (bypass admin inchangé).

---

## 8. Comportement frontend

UX uniquement (non authoritative) :

| Page | Comportement |
|------|--------------|
| `Edit.vue` | Si `isLastActiveAdmin` : rôle et `is_active` désactivés + texte d’aide |
| `Index.vue` | Si `lastActiveAdminId` : bouton supprimer masqué / désactivé pour ce user |

Props Inertia minimales exposées par le backend. La sécurité ne dépend **pas** du frontend.

---

## 9. Concurrence / transactions

`withAdminLock` :

1. Ouvre une transaction DB ;
2. `lockForUpdate` sur l’utilisateur cible ;
3. `lockForUpdate` sur **tous** les admins actifs ;
4. Exécute asserts + mutation.

Cela réduit le risque « 2 admins, 2 suppressions simultanées → 0 admin ».

### Limites

- Dépend du moteur (InnoDB / SQLite en tests). Les verrous sont transactionnels, pas une contrainte SQL CHECK.
- Un accès **direct** à la DB (SQL manuel, seed, tinker sans service) peut encore créer un état à 0 admin.
- La commande Artisan est protégée ; les scripts hors application ne le sont pas.

---

## 10. Limites éventuelles

| Limite | Commentaire |
|--------|-------------|
| État 0 admin possible hors HTTP | Seed / SQL manuel / restauration backup |
| `isAdmin()` ignore `is_active` | Un admin inactif reste « admin » pour le bypass *si* authentifié — mais `EnsureUserIsActive` le déconnecte sur le web |
| Pas de contrainte DB native | Protection applicative + verrous |

---

## 11. Tests

| Fichier | Couverture |
|---------|------------|
| `tests/Unit/Rbac/AdminProtectionServiceTest.php` | Compteurs, last admin, demote/delete/deactivate, non-admin |
| `tests/Feature/Rbac/LastAdminProtectionTest.php` | Routes réelles update/destroy, auto-modif, multi-admins |

Régression RBAC + inventaire exécutée dans la Phase I (voir rapport final).

---

## 12. Impact sécurité

- Empêche la perte totale d’administration via l’UI admin et la CLI `user:set-role`.
- Ne crée aucune permission RBAC parallèle.
- Ne force-sync aucun utilisateur.
- Ne modifie pas le catalogue / presets / inventaire / stock.
