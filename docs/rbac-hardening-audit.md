# RBAC Hardening — Audit Phase N0

**Date :** 2026-08-23  
**Statut :** Audit terminé — **aucune modification applicative**  
**Objectif Phase N :** hardening + nettoyage + compatibilité + audit final (pas de refonte)

---

## 1. Architecture actuelle (validée A–M)

```text
PermissionCatalog → RolePresets → AuthorizationService → User / Controllers
                              ↓
                    HandleInertiaRequests → usePermissions → Vue
                              ↓
              AdminProtectionService | RbacAuditService | RbacUiPresenter
```

**Décision d’autorisation unique :** `AuthorizationService::allows()`  
**Facades OK (délèguent déjà) :** `User::hasPermission*`, `Controller::checkPermission`  
**UI admin :** `/admin/roles-permissions` (Phase M)

---

## 2. Cartographie des mécanismes

| Élément | Utilisation | Source de vérité ? | Legacy ? | Risque | Action recommandée (N1+) |
|---------|-------------|--------------------|----------|--------|---------------------------|
| `AuthorizationService` | Décision RBAC centrale | **Oui** | Non | Faible | Conserver ; tests matrice |
| `PermissionCatalog` | Catalogue + labels + legacy | **Oui** | Non | Faible | Conserver |
| `RolePresets` | Presets vendeur/gestionnaire | **Oui** | Non | Faible | Conserver ; pas de force-sync auto |
| `AdminProtectionService` | Last-admin | **Oui** | Non | Critique si affaibli | Ne pas affaiblir ; tests concurrence |
| `RbacAuditService` | Événements `rbac.*` | **Oui** | Non | Faible | Documenter doublon `logUpdate` |
| `User::hasPermission*` | Façades contrôleurs/services | Délègue AuthZ | Non | Faible | Conserver |
| `Controller::checkPermission` | Garde routes métier | Délègue AuthZ | Non | Faible | Conserver (pattern dominant) |
| `Controller::checkAnyPermission` | upload-image / generate-sku | Délègue AuthZ | Non | Faible | Conserver |
| `CheckPermission` middleware | **Aucune route** | Délègue AuthZ | **Oui (mort)** | Confusion maintenance | **Supprimer** ou documenter+alias alias middleware — décision N1 |
| `EnsureUserIsAdmin` | Admin routes, notifications, `/dev/*` | Rôle admin | Non | Log bruit prod (`Log::info` chaque hit) | Garder ; retirer/réduire logging en N |
| `EnsureUserIsActive` | Middleware web global | `is_active` | Non | Faible | Conserver ; doc sémantique |
| `isAdmin()` User | Bypass AuthZ + admin UI + policies | Rôle | Non | Moyen si utilisé hors AuthZ pour sécurité métier | Centraliser décisions métier via AuthZ ; `isAdmin` OK pour admin-only features |
| `DashboardService` `isAdmin() \|\| hasPermission` | Widgets dashboard | Redondant (admin déjà bypass dans hasPermission) | Style | Faible | Simplifier optionnel (N2/N3) |
| Presets hardcodés Vue (`VENDEUR_PRESET_NAMES` Create/Edit) | Aperçu UI cases | **Non** (duplique RolePresets) | Drift | Moyen drift presets | Remplacer par payload backend (N6/N15) |
| `*.edit` (PermissionName + DB) | Alias AuthZ + seeder + tests | Catalog legacy | **Oui** | Faible si alias conserve | Conserver ; migration pivots optionnelle (env local : **0 pivots edit**) |
| `inventory.review` | Alias → reopen | Catalog legacy | **Oui** | Faible | Conserver ; env local : **0 pivots review** |
| `inventory.reopen` | Canonique | Oui | Non | — | Conserver |
| `PermissionSeeder` | Sync catalogue → DB | Aligné catalog | Non | Faible | Conserver ; health check N7 |
| `user:set-role` | CLI admin/user seulement | AdminProtection + audit | Limité | Moyen (pas vendeur/gestionnaire, pas sync preset) | Documenter ; ne pas changer silencieusement |
| Cache inter-requête | Absent | Intra-requête relation | Non | — | **Ne pas ajouter Redis** |
| Spatie / table `roles` | Absent | — | — | — | Ne pas introduire |
| Double audit `logUpdate` + `rbac.*` | UserController update | Deux canaux | Bruit | Faible lisibilité | Documenter ; ne pas supprimer `logUpdate` auto |
| Tests `createTestProduct()` redeclare | AttachmentTest + EditUpdateCompatibilityTest + StoreStockFoundationTest | — | Bug suite | Bloque `php artisan test` full | Corriger en N19 (hors métier RBAC mais bloque validation) |

---

## 3. CheckPermission (N1 — pré-décision)

**État :** classe présente, injecte `AuthorizationService`, **zéro référence** dans `routes/`, `bootstrap/`, alias middleware.

**Valeur actuelle :** nulle (redondant avec `Controller::checkPermission`).

**Recommandation N1 :** suppression propre **après** recherche exhaustive + test compile/route:list, **ou** conservation documentée « non branché / ne pas utiliser ».  
Préférence audit : **supprimer** pour réduire la surface mentale — le pattern contrôleur est la norme du projet.

---

## 4. Source unique d’autorisation (N2)

### Décision

Toutes les façades métier pointent vers `AuthorizationService` :

- `User::hasPermission` → `allowsResourceAction`
- `User::hasPermissionByName` → `allows`
- `Controller::checkPermission` / `checkAnyPermission` → `allows` / `any`
- Middleware mort → aussi `allows`

### Accès `$user->permissions()`

| Contexte | Classification |
|----------|----------------|
| `UserController` sync/attach | **Autorisé** (admin) |
| `RbacAuditService::currentPermissionNames` | **Autorisé** |
| `AuthorizationService::permissionNameSet` | **Autorisé** |
| Tests Feature/Unit | **Autorisé** |
| Contrôleurs métier (décision) | **Non trouvé** — OK |

### Bypass admin

Unique dans `AuthorizationService::allows` / `forUser` via `$user->isAdmin()`.

Autres `isAdmin()` : admin-only features (ActivityLog policy, EnsureUserIsAdmin, NotificationSettings, Profile admin check, Dashboard activity) — **justifiés** (pas une permission catalogue).

---

## 5. Legacy `*.edit` (N4)

**Modules concernés (enum) :** products, categories, customers, sales, quotes, expenses, suppliers, purchase-orders, delivery-notes, company.

**Compatibilité :** `AuthorizationService::resolveAliases`, `rbacPermissions.ts`, tests EditUpdate + SensitiveRoutes.

**Écriture (N4) :** `AssignablePermissionResolver` — `*.edit` → `*.update` ; jamais réattribué tel quel.

**Snapshot DB locale (2026-08-23) :**

| Métrique | Valeur |
|----------|--------|
| Pivots `action=edit` | **0** |
| Permissions en table | **75** (définitions legacy **conservées**) |
| Admins actifs | **1** |

**Recommandation N4 :** ne pas supprimer les permissions `*.edit` du catalogue/DB.  
Suppression physique : **Non effectuée en N4.**

---

## 6. Legacy `inventory.review` (N5)

- Canonique : `inventory.reopen` (preset gestionnaire + UI).
- Alias AuthZ + frontend `canReopenInventory`.
- Snapshot local : **0 pivots review**, **0 pivots reopen** (aucun user inventaire custom en local).

**Recommandation :** conserver alias ; migration pivots optionnelle ; ne jamais retirer review sans période de compat.

---

## 7. Force-sync presets (N6)

**Comportement actuel :**

- Create/Update user vendeur/gestionnaire → `RolePresets::permissionIds` sync **explicite**.
- Rôle `user` → permissions custom / attach — **jamais** sync preset vide forcé sur update partiel sauf chemins prévus.
- **Aucun** force-sync global au login / seeder / page RolesPermissions.
- CLI `user:set-role` : change rôle admin↔user **sans** sync preset vendeur/gestionnaire.

**Recommandation N6 :**

- **Ne pas** auto force-sync.
- Option future : action admin « Synchroniser les utilisateurs du rôle » avec confirmation + audit + résumé — **hors N0**, à proposer seulement si besoin métier.
- Remplacer presets hardcodés Vue par données Inertia (réduit drift).

---

## 8. Permissions orphelines / santé (N7–N8)

À produire en N7 via comparaison `PermissionCatalog::names()` ↔ table `permissions` (et pivots inutilisés).

**Hypothèse locale :** seeder aligné (75 permissions) — à confirmer par script/commande diagnostic.

Classification cible N8 : ACTIVE / LEGACY / PLANNED / UNUSED / UI_ONLY — **documentaire**, pas de suppression auto.

---

## 9. Routes (N9)

| Zone | Auth | AuthZ | Notes |
|------|------|-------|-------|
| Métier CRUD | auth+verified | `checkPermission` | OK |
| upload-image / generate-sku | auth | `checkAnyPermission` create\|update | Phase F OK |
| delivery-notes.validate | auth | `delivery-notes.validate` | Pas admin-only |
| Admin users/backups/logs/RBAC UI | EnsureUserIsAdmin | + backups permissions | OK |
| `/dev/*` | `environment('local')` + auth + verified + **EnsureUserIsAdmin** | — | Routes **non enregistrées** hors local |
| notifications test | EnsureUserIsAdmin | — | Phase F |

**Point N9 :** `if (app()->environment('local'))` empêche l’enregistrement en production — bonne pratique. Tester explicitement en N que `route:list` prod n’inclut pas `/dev`.

**Bruit :** `EnsureUserIsAdmin` loggue chaque requête admin — à réduire en hardening.

---

## 10. IDOR / isolation (N10)

RBAC ≠ isolation données. Audits inventaire existants (404 autre company).  
Phase N : ré-échantillonner contrôleurs clés ; **pas** d’ACL multi-magasin.

---

## 11. Last-admin (N11)

Couverture : UserController, SetUserRole, AdminProtectionService, tests LastAdmin*.  
Snapshot : **1 admin actif** — protection critique en prod-like.

Ne pas affaiblir. Ajouter tests concurrence si manquants (N24).

---

## 12. Audit RBAC (N12)

Événements présents. Mutations UserController : `ActivityLogger::logUpdate` **+** `rbac.*` via `recordUserMutation`.

**Décision proposée :** conserver les deux (journal général vs journal RBAC filtré) ; documenter comme **intentionnel / bruit acceptable**. Ne pas supprimer `logUpdate` sans alternative UI.

Vérifier secrets absents des payloads (déjà doc Phase K).

---

## 13. Cache (N13)

Intra-requête uniquement (`relationLoaded` + `forgetCachedPermissions` après sync UserController / SetUserRole).  
**Pas** de Redis. Conserver.

---

## 14. CLI (N14)

`user:set-role {email} {role=admin}` :

- Rôles acceptés : **admin | user seulement**
- Last-admin + audit + forget cache
- **Ne sync pas** presets vendeur/gestionnaire
- Documenter clairement ; ne pas élargir silencieusement

---

## 15. Frontend (N15)

| Pattern | État |
|---------|------|
| `usePermissions()` | API canonique Phase L |
| `isAdmin` nav / activity | UX admin — OK |
| `form.role ===` Users | UX formulaire — OK |
| Presets dupliqués Create/Edit | **Drift** — à corriger |
| `permissions.includes` hors rbacUi | Non trouvé en pages métier |
| BL validate | `can('delivery-notes.validate')` | Phase L |

Rappel : cacher bouton ≠ sécuriser route (backend obligatoire).

---

## 16. Permissions sensibles (N16 — doc)

Classification documentaire proposée (alignée UI Phase M) :

| Niveau | Exemples |
|--------|----------|
| Critique | `backups.*`, `inventory.apply`, `inventory.close` |
| Élevée | `inventory.validate`, `inventory.cancel`, `delivery-notes.validate`, `inventory.reopen` |
| Normale | `*.view`, `*.create`, `*.update` |

Ne pas modifier le catalogue pour cette classification.

---

## 17. Matrice sécurité (N17 — à générer depuis RolePresets)

À produire dans `docs/rbac-security-matrix.md` **après** vérification code (pas de copie aveugle).

Invariants attendus (présets) :

- Admin : bypass
- Gestionnaire : **pas** `sales.*` ; inventaire + reopen
- Vendeur : `sales.*` + `dashboard.view` ; **pas** inventaire reopen
- User : custom uniquement

---

## 18. Tests / build / stock (état connu)

| Check | État |
|-------|------|
| Suites RBAC ciblées (Phase M) | Vert |
| Vitest rbac / rbac-ui | Vert |
| Build | Vert (Phase M) |
| Stock consistency | OK |
| `php artisan test` **complet** | **Cassé** : redeclaration `createTestProduct()` (AttachmentTest, EditUpdateCompatibilityTest, StoreStockFoundationTest) — **préexistant**, à corriger en N19 |

---

## 19. Fichiers / zones hors périmètre (ne pas toucher en N)

- `StockService`, `ProductStock`, `StockMovement`
- `BarcodeInput.vue` / scanners
- Workflow inventaire métier (hors permissions)
- Spatie / table roles
- Redis RBAC

---

## 20. Plan d’exécution proposé (après validation N0)

| Étape | Action | Risque |
|-------|--------|--------|
| N1 | Décider suppression `CheckPermission` | Faible |
| N2–N3 | Doc + éventuel nettoyage `isAdmin \|\| hasPermission` redondant | Faible |
| N4–N5 | Doc procédure migration ; **pas** drop permissions | Faible |
| N6 | Doc force-sync ; option UI sync explicite = **non obligatoire** ; fix presets Vue | Moyen |
| N7–N8 | `rbac-permission-health.md` (+ `rbac:health` si utile) | Faible |
| N9 | Réaudit routes + test `/dev` absent hors local ; réduire logs EnsureUserIsAdmin | Faible |
| N10–N12 | Réaudit IDOR / last-admin / audit doublons | Moyen |
| N13–N14 | Doc cache + CLI | Faible |
| N15 | Frontend presets depuis backend | Moyen |
| N16–N18 | Matrice + tests invariants | Faible |
| N19 | Fix redeclaration tests + régression | Moyen |
| N20–N25 | Docs finales + rapport santé + verdict | — |

---

## 21. GO / NO-GO

| Critère | N0 |
|---------|-----|
| Audit sans modification applicative | **GO** |
| Architecture stable documentée | **GO** |
| Failes critiques découvertes bloquant la prod | **Aucune nouvelle** (suite PHP full cassée = dette tests) |
| Implémentation N1+ | **En attente** validation humaine du présent audit |

---

## 22. Verdict N0

```text
N0 COMPLETE — READY FOR N1 (après validation)
```

**Prochaine action autorisée :** N1 (CheckPermission) uniquement après validation de cet audit.  
**Interdit jusqu’alors :** modification de fichiers applicatifs hors docs d’audit.

---

*Fin audit Phase N0 — MKD-Pro RBAC.*
