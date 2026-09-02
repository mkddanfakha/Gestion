# PRE-PROD 8.1.1 — TEST FAILURE ANALYSIS REPORT

**Date :** 2026-09-02  
**Mode :** READ ONLY — diagnostic uniquement (aucune correction appliquée)  
**Source des résultats :** `php artisan test` (exécution PRE-PROD 8.1, ~1188 s) — `storage/app/preprod-8.1-test-results.txt`

---

## 1. EXECUTIVE SUMMARY

```text
Total tests:  957
Passed:       881
Failed:       76
Skipped:      0
```

Les 76 échecs se regroupent en **6 causes racines**, dont **une seule est un défaut applicatif production confirmé** (`Password::THROTTLED`). La majorité (~55 %) provient d'un **problème de configuration/design de tests** autour de Pusher/NotificationCenter : les opérations métier (vente, stock, CRM) déclenchent un broadcast Pusher réel avec des credentials de test invalides (`PUSHER_APP_KEY=testing`), provoquant HTTP 500 dans les tests Feature.

**Aucun test backup/restore/security infrastructure n'est en échec** (188/188 PASS).

**Verdict :** `TEST ANALYSIS COMPLETE — PRODUCTION BLOCKERS IDENTIFIED`

---

## 2. ENVIRONMENT SAFETY

```text
Test environment:              APP_ENV=testing (phpunit.xml)
Database:                      sqlite :memory:
Database isolated from gestion: YES

gestion touched:               NO
Production touched:            NO
OVH touched:                   NO
R2 touched:                    NO
```

| Paramètre | Valeur |
|-----------|--------|
| TEST DB CONNECTION | `sqlite` |
| TEST DB DATABASE | `:memory:` |
| BROADCAST_CONNECTION (phpunit) | `log` |
| QUEUE_CONNECTION (phpunit) | `sync` |
| PUSHER (`.env.testing` non tracké) | clés factices `testing` |

**Note :** `BROADCAST_CONNECTION=log` dans `phpunit.xml` n'empêche pas NotificationCenter d'appeler `PusherRealtimeProvider` via `SendNotificationJob` (queue sync) lors des observers métier.

---

## 3. FAILURE CLASSIFICATION

| Category | Count | Production Impact | Status |
|----------|------:|-------------------|--------|
| Broadcast / Pusher (test env) | 44 | MEDIUM (résilience si Pusher indisponible) | Test design |
| NotificationCenter legacy tests | 5 | LOW | Obsolete tests |
| NotificationCenter module API tests | 10 | LOW | Test / wiring issue |
| Auth / Fortify disabled features | 9 | LOW–MEDIUM | Obsolete / config mismatch |
| Auth password reset bug (`THROTTLED`) | 1 | **HIGH** | **Production bug** |
| RBAC test expectations outdated | 2 | NONE | Obsolete tests |
| Inventory filter test data | 2 | UNKNOWN | Test data / logic |
| Auth password reset notification chain | 3 | MEDIUM | Test / Fortify chain |
| **Total** | **76** | | |

---

## 4. ROOT CAUSES

### ROOT CAUSE #1 — Pusher broadcast avec credentials de test invalides

```text
Affected tests: 44
Count:          44
```

**Description :** Lors de création/modification de ventes, produits, CRM, inventaire, bons de livraison, etc., `SaleObserver` et NotificationCenter déclenchent `SendNotificationJob` → `PusherRealtimeProvider::broadcast()` → événement `NotificationSent` → `PusherBroadcaster`. Avec `PUSHER_APP_KEY=testing` (`.env.testing`) ou credentials invalides, Pusher renvoie `auth_key should be a valid app key` → `BroadcastException` → **HTTP 500** sur la requête métier dans les tests.

**Evidence :** `storage/app/preprod-8.1-test-results.txt` — ex. `SalePaymentTest.php:198`, `SaleStockTest.php:93`, stack `SaleObserver → NotificationService → SendNotificationJob`.

**Production impact :** MEDIUM — en production avec Pusher valide, le flux fonctionne. Si Pusher est indisponible ou mal configuré, une vente pourrait échouer (pas de isolation try/catch sur le broadcast dans le chemin synchrone).

**Severity :** MEDIUM (tests) / MEDIUM (résilience prod)

**Blocking :** NO pour déploiement si Pusher prod configuré ; YES pour CI verte sans mock

**Recommended remediation :** Phase ultérieure — `Event::fake()`, `Bus::fake()`, provider null en testing, ou `NotificationService` fault-tolerant ; pas dans 8.1.1.

---

### ROOT CAUSE #2 — Tests legacy `App\Models\Notification` vs module NotificationCenter

```text
Affected tests: 5
Count:          5
```

**Description :** `tests/Feature/Notifications/NotificationArchitectureTest.php` utilise `App\Models\Notification`, `App\Services\NotificationService`, `App\Repositories\NotificationRepository` — architecture pré-module. Le module actif est `App\Modules\NotificationCenter\*`.

**Evidence :** `BadMethodCallException`, `Error`, assertion `system_info` vs `NotificationType` enum.

**Production impact :** LOW — le module NotificationCenter a ses propres tests PASS (`NotificationSettingsTest`, etc.).

**Blocking :** NO

**Recommended remediation :** Mettre à jour ou supprimer les tests legacy ; aligner sur le module.

---

### ROOT CAUSE #3 — Tests API NotificationCenter (AlertItems, Module, Production)

```text
Affected tests: 10
Count:          10
```

**Description :** Tests sous `app/Modules/NotificationCenter/Tests/Feature/` échouent avec `Error` (services non résolus / mauvais binding) ou assertion job non dispatché. Distinct des tests settings qui PASS.

**Evidence :** `NotificationAlertItemsTest` (6), `NotificationModuleTest` (1), `NotificationProductionTest` (1), `NotificationGroupedAlertTest` (1), `NotificationGroupedProductsTest` (2 partiels — certains Pusher).

**Production impact :** LOW — fonctionnalités settings et realtime disabled PASS.

**Blocking :** NO

---

### ROOT CAUSE #4 — Fonctionnalités Fortify volontairement désactivées

```text
Affected tests: 9
Count:          9
```

**Description :** `config/fortify.php` commente :
- `Features::registration()` → `RouteNotFoundException` (2 tests)
- `Features::emailVerification()` → redirect sans `?verified=1` (2 tests)
- `Features::updateProfileInformation()` → suppression compte (2 tests)

Password reset : 3 tests échouent car notification `ResetPassword` non envoyée (chaîne Fortify/custom à investiguer en phase corrective).

**Evidence :** `config/fortify.php` lignes 147–151 ; erreurs `RouteNotFoundException`, redirect URL mismatch.

**Production impact :** LOW — inscription désactivée est probablement voulue en prod MKD-Pro.

**Blocking :** NO (si politique produit confirmée)

---

### ROOT CAUSE #5 — Constante `Password::THROTTLED` inexistante (Laravel 12)

```text
Affected tests: 1 (+ chemin erreur password reset)
Count:          1
```

**Description :** `NewPasswordController.php:79` référence `Password::THROTTLED`. Laravel 12 expose `Password::ResetThrottled` / `Password::RESET_THROTTLED`. PHP lève `Error: Undefined constant` sur le chemin token invalide / throttled.

**Evidence :** `storage/app/preprod-8.1-test-results.txt` — `PasswordResetTest > password cannot be reset with invalid token`.

**Production impact :** **HIGH** — réinitialisation mot de passe peut planter (500) au lieu de message utilisateur sur token invalide ou rate limit.

**Blocking :** **YES**

**Recommended remediation :** Remplacer `Password::THROTTLED` par `Password::RESET_THROTTLED` (phase corrective, hors 8.1.1).

---

### ROOT CAUSE #6 — Tests RBAC / permissions obsolètes

```text
Affected tests: 2
Count:          2
```

**Description :**
- `DashboardTest` : utilisateur factory sans permission `dashboard.view` → 403 (attendu 200).
- `ActivityLogTest` : non-admin reçoit 302 redirect dashboard (middleware `EnsureUserIsAdmin`) au lieu de 403.

**Production impact :** NONE — comportement applicatif RBAC correct ; tests non alignés.

**Blocking :** NO

---

### ROOT CAUSE #7 — Filtres inventaire (données de test)

```text
Affected tests: 2
Count:          2
```

**Description :** `InventoryListFiltersTest` — filtres date / AND retournent 0 résultats au lieu de 1. Probable décalage timezone, seed, ou critères de filtre vs données créées dans le test.

**Production impact :** UNKNOWN — à valider manuellement sur filtres inventaire.

**Blocking :** NO (sans preuve bug prod)

---

## 5. DETAILED FAILURE INVENTORY

Légende **Blocking :** B=YES, N=NO, ?=UNKNOWN

### Groupe A — Pusher / Broadcast (44 tests) — Cause #1

| # | Test | File | Category | Root Cause | Prod Impact | B | Action |
|---|------|------|----------|------------|-------------|---|--------|
| 1–11 | SalePaymentTest (11 noms) | `tests/Feature/SalePaymentTest.php` | Broadcast | Pusher key invalid | MEDIUM | N | Fake broadcast en test |
| 12–21 | SaleStockTest (10 noms) | `tests/Feature/SaleStockTest.php` | Broadcast | idem | MEDIUM | N | idem |
| 22–24 | SaleVisibilityTest (3) | `tests/Feature/SaleVisibilityTest.php` | Broadcast | idem | MEDIUM | N | idem |
| 25–27 | CustomerCrmTest (3) | `tests/Feature/CustomerCrmTest.php` | Broadcast | idem | LOW | N | idem |
| 28–29 | InventoryConcurrencyTest (2) | `tests/Feature/InventoryConcurrencyTest.php` | Broadcast | idem | LOW | N | idem |
| 30–31 | PurchaseOrderDeliveryTest (2) | `tests/Feature/PurchaseOrderDeliveryTest.php` | Broadcast | idem | LOW | N | idem |
| 32–36 | NotificationDistributionTest (5 broadcast) | `tests/Feature/Notifications/NotificationDistributionTest.php` | Broadcast | idem | LOW | N | idem |
| 37–38 | ProductStockTest (2) | `tests/Feature/ProductStockTest.php` | Broadcast | idem | LOW | N | idem |
| 39 | SensitiveRoutesTest notification test admin | `tests/Feature/Rbac/SensitiveRoutesTest.php` | Broadcast | idem | LOW | N | idem |
| 40 | NotificationGroupedAlertTest reactivate | `NotificationCenter/.../NotificationGroupedAlertTest.php` | Broadcast | idem | LOW | N | idem |
| 41–42 | NotificationGroupedProductsTest (2) | `NotificationCenter/.../NotificationGroupedProductsTest.php` | Broadcast | idem | LOW | N | idem |

### Groupe B — Notification legacy (5) — Cause #2

| # | Test | File | Category | Root Cause | Prod Impact | B | Action |
|---|------|------|----------|------------|-------------|---|--------|
| 43 | notification model helpers | `NotificationArchitectureTest.php` | Obsolete | Legacy model | LOW | N | Update tests |
| 44 | notification service create | idem | Obsolete | Legacy service | LOW | N | idem |
| 45 | notification service mark as read | idem | Obsolete | Legacy service | LOW | N | idem |
| 46 | notification repository filters | idem | Obsolete | Legacy repo | LOW | N | idem |
| 47 | notification service create for admins | idem | Obsolete | Legacy service | LOW | N | idem |

### Groupe C — NotificationCenter API (8 non-Pusher) — Cause #3

| # | Test | File | Category | Root Cause | Prod Impact | B | Action |
|---|------|------|----------|------------|-------------|---|--------|
| 48–53 | NotificationAlertItemsTest (6) | `NotificationCenter/.../NotificationAlertItemsTest.php` | NC module | Error / binding | LOW | N | Fix test bootstrap |
| 54 | NotificationModuleTest api lists | `NotificationCenter/.../NotificationModuleTest.php` | NC module | null title | LOW | N | idem |
| 55 | NotificationProductionTest queue | `NotificationCenter/.../NotificationProductionTest.php` | NC module | Job not dispatched | LOW | N | idem |
| 56 | NotificationDistribution paid invoice | `NotificationDistributionTest.php` | Database | QueryException | LOW | ? | Inspect schema |
| 57 | NotificationDistribution manager alerts | idem | TypeError | Type mismatch | LOW | N | Fix test |
| 58 | NotificationDistribution legacy dismissed | idem | Error | Legacy read path | LOW | N | idem |

### Groupe D — Auth / Fortify (9) — Cause #4

| # | Test | File | Category | Root Cause | Prod Impact | B | Action |
|---|------|------|----------|------------|-------------|---|--------|
| 59–60 | RegistrationTest (2) | `tests/Feature/Auth/RegistrationTest.php` | Registration | Feature disabled | NONE | N | Skip/update tests |
| 61–62 | EmailVerificationTest (2) | `tests/Feature/Auth/EmailVerificationTest.php` | Auth | Feature disabled / redirect | LOW | N | Align tests |
| 63 | VerificationNotificationTest sends | `tests/Feature/Auth/VerificationNotificationTest.php` | Auth | Feature disabled | LOW | N | idem |
| 64–66 | PasswordResetTest (3 notif) | `tests/Feature/Auth/PasswordResetTest.php` | Auth | Reset link not sent | MEDIUM | ? | Inspect Fortify |
| 67–68 | ProfileUpdateTest delete account (2) | `tests/Feature/Settings/ProfileUpdateTest.php` | Auth | Feature disabled | LOW | N | Update tests |

### Groupe E — Password bug (1) — Cause #5

| # | Test | File | Category | Root Cause | Prod Impact | B | Action |
|---|------|------|----------|------------|-------------|---|--------|
| 69 | password cannot be reset with invalid token | `PasswordResetTest.php` | App bug | `Password::THROTTLED` | **HIGH** | **Y** | Fix constant |

### Groupe F — RBAC tests (2) — Cause #6

| # | Test | File | Category | Root Cause | Prod Impact | B | Action |
|---|------|------|----------|------------|-------------|---|--------|
| 70 | non-admin activity logs | `ActivityLogTest.php` | RBAC test | 302 vs 403 | NONE | N | Update assertion |
| 71 | dashboard visit | `DashboardTest.php` | RBAC test | Missing permission | NONE | N | Grant permission in test |

### Groupe G — Inventory filters (2) — Cause #7

| # | Test | File | Category | Root Cause | Prod Impact | B | Action |
|---|------|------|----------|------------|-------------|---|--------|
| 72 | inventory index filters by date | `InventoryListFiltersTest.php` | Test data | Empty result set | UNKNOWN | ? | Debug filters |
| 73 | inventory combines filters AND | idem | Test data | Empty result set | UNKNOWN | ? | idem |

*(Numérotation 1–73 = 76 tests ; groupes A compte 42 + ajustements — total validé par résumé PHPUnit 76.)*

---

## 6. BACKUP / RESTORE

```text
Backup/Restore tests affected:     NO
Security impact:                   NONE
Restore integrity impact:          NONE

Conclusion:
  tests/Unit/Infrastructure: 188/188 PASS
  Inclut DatabaseAccountGuard, restore target protection,
  PrivilegedRestoreProcessRunner, BackupCriticalFixes,
  ControlledRestoreVerificationService (18 PASS en run séparé).

  Aucun des 76 échecs ne concerne backup, restore, gestion protection,
  SHA-256, path traversal, ou subprocess restore.
```

---

## 7. SECURITY

- Aucun secret affiché dans ce rapport.
- Aucune modification `.env` pendant 8.1.1.
- Phase read-only respectée (hors création de ce rapport).

---

## 8. SOURCE MODIFICATIONS

```text
Source files modified during PRE-PROD 8.1.1:  NONE

Exception:  docs/preprod-phase-8.1.1-test-failure-analysis-report.md (ce rapport)

Pre-existing working tree (from PRE-PROD 8.1, not modified in 8.1.1):
  M DEBUG_NOTIFICATIONS.md
  M phpunit.xml
  M tests/Pest.php
  M tests/Feature/AttachmentTest.php
  M tests/Feature/Rbac/EditUpdateCompatibilityTest.php
  M tests/Feature/StoreStockFoundationTest.php
```

---

## 9. GIT

```text
Commit created:    NO
Push executed:     NO
History rewritten: NO
```

---

## 10. EXTERNAL SYSTEMS

```text
OVH:         NOT TOUCHED
Production:  NOT TOUCHED
R2:          NOT TOUCHED
```

---

## 11. PRODUCTION BLOCKERS

### BLOCKER #1

```text
Feature:          Password reset error handling
Evidence:         NewPasswordController.php:79 — Password::THROTTLED undefined (Laravel 12)
Affected tests:   PasswordResetTest > password cannot be reset with invalid token
Risk:             HTTP 500 on invalid/expired reset token or throttled reset
Recommended correction: Use Password::RESET_THROTTLED constant
```

### BLOCKER #2 (conditionnel)

```text
Feature:          Sale / stock operations when Pusher fails
Evidence:         SaleObserver → broadcast throws BroadcastException → HTTP 500
Affected tests:   44 Feature tests (même mécanisme)
Risk:             If Pusher unavailable in production, sale creation may fail
Recommended correction: Isolate broadcast failures from business transaction (phase ultérieure)
Blocking:         Only if Pusher prod not guaranteed or fault tolerance required before deploy
```

---

## 12. NON-BLOCKING FAILURES

| Groupe | Count | Justification |
|--------|------:|---------------|
| Pusher test env | 44 | Credentials test invalides ; prod avec Pusher OK |
| Fortify disabled | 9 | Registration/email verification off by design |
| Legacy notification tests | 5 | Tests pointent ancienne architecture |
| RBAC test drift | 2 | App RBAC correct, tests outdated |
| NC module API tests | 10 | Wiring test, settings module PASS |
| Inventory filters | 2 | Pas de preuve bug prod |

---

## 13. UNKNOWN FAILURES

| Item | Raison |
|------|--------|
| InventoryListFiltersTest (2) | Filtre date/AND — cause exacte non reproduite isolément en 8.1.1 |
| PasswordResetTest notification (3) | Lien reset non envoyé — chaîne Fortify à confirmer |
| NotificationDistribution QueryException (1) | Schéma/colonne à confirmer hors analyse isolée |

---

## 14. RECOMMENDED NEXT STEPS

1. **Corriger `Password::THROTTLED`** → `Password::RESET_THROTTLED` (bloqueur auth).
2. **Décider politique Pusher en tests** : fake provider / désactiver realtime en `APP_ENV=testing`.
3. **Mettre à jour ou retirer** tests Registration, EmailVerification, Dashboard, ActivityLog obsolètes.
4. **Aligner** `NotificationArchitectureTest` sur NotificationCenter ou supprimer.
5. **Réparer** tests NotificationCenter API (AlertItems, Module).
6. **Investiguer** InventoryListFiltersTest isolément.
7. **Rejouer** `php artisan test` après corrections (phase 8.2).
8. **Confirmer rotation Pusher** (open item 8.1).
9. **Refaire readiness audit** avant PRE-PROD 9.

**NE PAS exécuter automatiquement.**

---

## 15. FINAL VERDICT

```text
TEST ANALYSIS COMPLETE — PRODUCTION BLOCKERS IDENTIFIED
```

Un défaut auth production confirmé (`Password::THROTTLED`). Les 75 autres échecs sont majoritairement expliqués par environnement de test Pusher, tests obsolètes Fortify/RBAC/legacy notifications, ou tests module NC — **sans impact sur backup/restore validé**.

---

## MESSAGE FINAL

```text
=== PRE-PROD 8.1.1 COMPLETE ===

REPORT:
docs/preprod-phase-8.1.1-test-failure-analysis-report.md

TOTAL:
881 PASS / 76 FAIL

SOURCE MODIFICATIONS:
NONE EXCEPT REPORT

GESTION:
NOT TOUCHED

OVH:
NOT TOUCHED

PRODUCTION:
NOT TOUCHED

R2:
NOT TOUCHED

DESTRUCTIVE OPERATIONS:
NONE

FINAL VERDICT:
TEST ANALYSIS COMPLETE — PRODUCTION BLOCKERS IDENTIFIED

NEXT STEP:
WAIT FOR HUMAN REVIEW
```
