# RBAC — Cache & Invalidation (Phase J)

**Date :** 2026-08-23  
**Statut :** Implémenté (cache intra-requête uniquement)  
**Référence :** [`docs/rbac-architecture.md`](./rbac-architecture.md)

---

## 1. Problème initial

`AuthorizationService::allows()` exécutait pour **chaque** décision :

```php
$user->permissions()->whereIn('name', $namesToCheck)->exists();
```

Conséquences :

- N appels `allows()` → N requêtes SQL ;
- `any()` / `all()` → une requête par permission évaluée ;
- même avec `load('permissions')`, Eloquent `exists()` **ne réutilisait pas** la relation ;
- pages inventaire / recherche produits / dashboard multipliaient les hits `user_permissions`.

---

## 2. Audit des requêtes

| Point | Comportement avant |
|-------|-------------------|
| `allows()` | 1 `EXISTS` SQL par appel |
| `any()` / `all()` | Boucle sur `allows()` → N requêtes |
| Admin | Bypass immédiat (0 requête) — inchangé |
| `forUser()` / `getPermissionsArray()` | `pluck` SQL distinct |
| Mutations | `UserController` sync/attach ; `SetUserRole` (rôle) |

**Mesure scénario standard** (4× `allows` + `any` + `all`, vendeur, relation non chargée) :

| Métrique | Avant |
|----------|-------|
| Requêtes permissions | **8** |
| Avec relation déjà eager-loaded | **3** (toujours des `exists`) |
| Admin (3 décisions) | **0** |

---

## 3. Stratégie retenue

Priorité sécurité > exactitude > perf.

| Niveau | Décision |
|--------|----------|
| 1 — Intra-requête | **Implémenté** |
| 2 — Réutilisation Eloquent | **Implémenté** (`relationLoaded` + `load`) |
| 3 — Inter-requêtes (Redis/file) | **Volontairement non implémenté** |
| 4 — Versioning colonne / clé cache | Non nécessaire |

Raison du refus du cache inter-requêtes : le gain mesuré (8 → 1 requête par requête HTTP) suffit pour les hotspots actuels ; un cache partagé ajouterait un risque de droits obsolètes sans besoin prouvé.

---

## 4. Cache intra-requête

Implémentation dans `AuthorizationService` :

1. Bypass admin inchangé (`isAdmin()` → true, sans charger le pivot).
2. Sinon : charger `permissions` **une fois** sur l’instance `User`.
3. Décisions suivantes : lookup en mémoire (set de noms).
4. Alias legacy (`edit`/`update`, `review`/`reopen`) inchangés via `resolveAliases()`.

```
Request → AuthorizationService::allows() × N
              ↓
         load('permissions') une fois
              ↓
         décisions O(1) en mémoire
```

`User::getPermissionsArray()` délègue à `AuthorizationService::forUser()` (même chemin).

---

## 5. Cache inter-requêtes

**Volontairement non implémenté.**

Motifs :

- gain intra-requête déjà majeur (8 → 1) ;
- pas de preuve de pression multi-requêtes nécessitant Redis ;
- règle absolue : mieux une requête SQL de plus qu’un droit retiré encore accordé ;
- éviter `Cache::remember` / TTL sans versionnement.

---

## 6. Invalidation

Méthode : `AuthorizationService::forgetCachedPermissions(User $user)`  
→ `$user->unsetRelation('permissions')`.

Branchée après :

- sync/attach dans `Admin\UserController::store` / `update` ;
- changement de rôle CLI `user:set-role`.

Sans `forget` après sync sur la **même** instance, la relation Eloquent peut rester stale (test unitaire explicite).  
Entre deux requêtes HTTP : nouvelle instance User → rechargement naturel (pas de cache partagé).

---

## 7. Changement de rôle

Dans `update` (déjà sous `withAdminLock` / transaction) :

1. asserts dernier admin ;
2. save rôle ;
3. sync preset / permissions ;
4. `forgetCachedPermissions` ;
5. commit.

Nouvelle requête (ou même instance après forget) → permissions du nouveau preset.

---

## 8. Ajout / suppression permission

Après `sync` admin + `forget` : `allows()` reflète immédiatement le pivot DB.

---

## 9. Admin bypass

Toujours basé sur `$user->role` courant. Aucune permission en cache pour l’admin.  
Rétrogradation → `isAdmin()` false immédiatement ; preset vendeur syncé.

---

## 10. Dernier administrateur

`AdminProtectionService` non modifié (sauf usage inchangé). Tests Phase I verts.

---

## 11. Concurrence

Sans cache inter-requêtes :

- Requête A retire une permission (commit) ;
- Requête B charge ensuite le pivot → voit le retrait.

Fenêtre résiduelle : B a déjà chargé la relation **avant** le commit de A (même process rare) — classique sans verrou de lecture ; acceptable. Priorité : pas de TTL servant un droit déjà retiré après commit.

---

## 12. Limites

| Limite | Détail |
|--------|--------|
| Cache = instance User | Deux instances du même user dans une requête peuvent chacune charger le pivot |
| Oubli de `forget` après sync manuel | Droits stale possibles sur cette instance |
| Pas de cache cross-request | Chaque requête HTTP recharge 1 fois (choix volontaire) |
| `SetUserRole` | Change le rôle ; ne sync pas les presets (comportement préexistant) |

---

## 13. Mesures avant / après

Scénario : 4× `allows` + `any` + `all` (vendeur).

| | Avant | Après | Gain |
|--|-------|-------|------|
| Cold (relation non chargée) | **8** | **1** | −7 requêtes (−87 %) |
| Relation déjà eager-loaded | **3** | **0** | −3 requêtes |
| Admin | **0** | **0** | inchangé |

Coût invalidation : `unsetRelation` (négligeable).  
Coût load unique : 1 jointure `permissions` / `user_permissions` par utilisateur non-admin et par requête HTTP qui appelle le RBAC.

---

## 14. Tests

- `tests/Unit/Rbac/AuthorizationCacheTest.php`
- `tests/Feature/Rbac/AuthorizationCacheIntegrationTest.php`
