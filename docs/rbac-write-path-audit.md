# RBAC — Audit des chemins d’écriture (Phase N4.1)

**Date :** 2026-08-23  
**Statut :** Audit sans modification applicative (sous-phase 1)  
**Règle cible :** Legacy = lecture/compat uniquement ; Canonical = seule forme autorisée à l’écriture

---

## Cartographie

| Chemin | Fichier | Méthode | Opération | Source | Canonicalisation | Risque | Statut |
|--------|---------|---------|-----------|--------|------------------|--------|--------|
| Admin Users store (admin) | `UserController` | `store` | `sync([])` | rôle admin | N/A (pivot vide) | Faible | OK |
| Admin Users store (vendeur) | `UserController` | `store` | `sync(RolePresets::permissionIds)` | RolePresets | Presets déjà canoniques | Faible | OK |
| Admin Users store (gestionnaire) | `UserController` | `store` | `sync(RolePresets::permissionIds)` | RolePresets | Presets déjà canoniques | Faible | OK |
| Admin Users store (user) | `UserController` | `store` | `attach(canonicalizeIds)` | formulaire | **Oui** (N3) | Faible | OK |
| Admin Users update | `UserController` | `update` | `sync` / presets / canonicalize | formulaire / RolePresets | **Oui** pour custom | Faible | OK |
| Admin Users edit (affichage) | `UserController` | `edit` | lecture + canonicalize IDs UI | pivot user | Affiche canonique | Faible | OK |
| CLI set-role | `SetUserRole` | `handle` | change `role` only | CLI admin\|user | **Ne sync pas** les pivots | Moyen (doc) | Documenté — pas de force-sync |
| Legacy migrator | `LegacyPermissionMigrator` | `migrate` | insert canonical + delete legacy | pivots legacy | Oui | Faible | OK (outil explicite) |
| PermissionSeeder | `PermissionSeeder` | run | upsert définitions | PermissionCatalog | N/A (définitions, pas pivots user) | Faible | OK |
| Tests Feature | divers | sync/attach | fixtures | noms de test | Hors prod | — | OK tests |
| RolePresets | `RolePresets` | `permissionIds` | lecture IDs | enum catalogue | Pas d’écriture directe | Faible | OK |
| AssignablePermissionResolver | resolver | canonicalize / grid | pré-écriture / UI | IDs formulaire | Oui | Orphelines ignorées (N4.2) | **OK** |

---

## Règle formelle

```text
Legacy permissions :
  READ / COMPATIBILITY = autorisé (AuthorizationService aliases)
  WRITE / ASSIGNMENT   = interdit (canonicaliser ou ignorer)
```

---

## Points verrouillés en N4.2

1. `canonicalizeIds` : **ignore** les permissions absentes du `PermissionCatalog`.
2. `adminGridByResource` : n’expose que des permissions **catalogue + non-legacy**.
3. `isWritableName()` : garde explicite write = canonique.
4. Tests de garde : `LegacyPermissionAssignmentGuardTest`, intégrité presets / architecture, `RbacFinalHardeningTest`.

---

## Non-écritures (lecture seule)

| Chemin | Usage |
|--------|--------|
| `AuthorizationService::permissionNameSet` | décision |
| `RbacAuditService::currentPermissionNames` | audit |
| `HandleInertiaRequests` | exposition frontend |
| `User::hasPermission*` | façade AuthZ |

---

## Hors périmètre écriture

- Spatie / table roles : absent
- Force-sync global : absent
- Cache inter-requêtes : absent

*Fin audit N4.1 — GO implémentation N4.2.*
