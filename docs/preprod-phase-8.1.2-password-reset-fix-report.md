# PRE-PROD 8.1.2 — PASSWORD RESET FIX REPORT

**Date :** 2026-09-02  
**Mode :** Correctif minimal — bloqueur password reset uniquement

---

## 1. OBJECTIVE

Corriger le bloqueur de production identifié en PRE-PROD 8.1.1 :

```text
NewPasswordController.php:79
Password::THROTTLED  →  constante inexistante (Laravel 12)
```

Lors d'une tentative de réinitialisation avec token invalide ou en cas de throttling, PHP levait `Error: Undefined constant Illuminate\Support\Facades\Password::THROTTLED`, provoquant HTTP 500 au lieu d'une erreur de validation utilisateur.

**Correctif appliqué :** `Password::RESET_THROTTLED`

---

## 2. INITIAL STATE

```text
Branch:          main
HEAD:            122ac9abce24aac19a09f557911be8e26223304a
Working tree:    modifications PRE-PROD 8.1 préexistantes (6 fichiers M) + rapports untracked
Laravel version: Laravel Framework 12.40.2
PHP version:     PHP 8.4.0
```

---

## 3. ROOT CAUSE

```text
Root cause:
Password::THROTTLED is unavailable in the installed Laravel version (12.40.2).
```

**Vérification runtime :**

```text
Password::RESET_THROTTLED = 'passwords.throttled'  ✓ EXISTS
Password::THROTTLED                                ✗ MISSING
```

**Source Laravel :** `vendor/laravel/framework/src/Illuminate/Support\Facades/Password.php` — constantes disponibles : `RESET_THROTTLED`, `ResetThrottled`, `INVALID_TOKEN`, `INVALID_USER`, `PASSWORD_RESET`, etc. Aucune constante `THROTTLED`.

Le tableau `$errorMessages` dans `NewPasswordController::store()` référençait une clé inexistante, provoquant une erreur fatale PHP dès l'évaluation de la constante.

---

## 4. FIX

```text
Before:
Password::THROTTLED

After:
Password::RESET_THROTTLED
```

**Fichier modifié :** `app/Http/Controllers/Auth/NewPasswordController.php` (ligne 79 uniquement)

Aucune autre modification de logique, style ou refactoring.

---

## 5. TEST ADDED / UPDATED

```text
Test file:       tests/Feature/Auth/PasswordResetTest.php (existant — non modifié)
Test name:       password cannot be reset with invalid token
Scenario:        POST password.store avec token invalide
Expected result: assertSessionHasErrors('email') — pas de HTTP 500
Actual result:   PASS (2 assertions)
```

Aucun test supplémentaire requis : le test existant couvrait déjà le chemin d'erreur `INVALID_TOKEN` et déclenchait l'évaluation du tableau `$errorMessages` contenant la constante incorrecte.

---

## 6. TARGETED TEST

```text
Command: php artisan test --filter="password cannot be reset with invalid token"
Result:  PASS (1 test, 2 assertions, ~16 s)
```

---

## 7. AUTHENTICATION TESTS

```text
Command: php artisan test tests/Feature/Auth tests/Feature/Settings/ProfileUpdateTest.php
Result:  20 PASS / 10 FAIL

Échecs restants (hors scope 8.1.2, identiques PRE-PROD 8.1.1) :
  - EmailVerificationTest (2) — feature Fortify désactivée
  - PasswordResetTest (3) — notification ResetPassword non envoyée
  - RegistrationTest (2) — route register désactivée
  - VerificationNotificationTest (1) — feature désactivée
  - ProfileUpdateTest (2) — suppression compte désactivée

Le test bloqueur « password cannot be reset with invalid token » : PASS
```

---

## 8. FULL BACKEND TESTS

```text
PRE-PROD 8.1.1:
881 PASS / 76 FAIL

PRE-PROD 8.1.2:
882 PASS / 75 FAIL (4837 assertions, ~877 s)
```

```text
New regressions:          NO
Password reset blocker:   RESOLVED
```

**Variation :** +1 PASS, -1 FAIL — exactement le test `password cannot be reset with invalid token` corrigé. Aucune régression introduite.

---

## 9. INFRASTRUCTURE TESTS

```text
Baseline: 188 PASS
Current:  188 PASS / 0 FAIL (519 assertions, ~108 s)
```

```text
BACKUP/RESTORE INFRASTRUCTURE:
UNCHANGED
```

---

## 10. FRONTEND

```text
Not rerun — PRE-PROD 8.1 baseline:
160 PASS
```

Le correctif ne touche pas le frontend.

---

## 11. BUILD

```text
NOT RUN — PRE-PROD 8.1 baseline: PASS
```

Aucune modification frontend ou assets.

---

## 12. DATABASE SAFETY

```text
gestion:           NOT TOUCHED
Database writes:   NONE (tests sqlite :memory: uniquement)
Migration:         NONE
Restore:           NONE
Destructive SQL:   NONE
```

**Environnement de test :**

```text
TEST ENVIRONMENT:     APP_ENV=testing
TEST DB CONNECTION:   sqlite
TEST DB DATABASE:     :memory:
Isolated from gestion: YES
```

---

## 13. EXTERNAL SYSTEM SAFETY

```text
OVH:         NOT TOUCHED
Production:  NOT TOUCHED
R2:          NOT TOUCHED
```

---

## 14. GIT SAFETY

```text
Commit:          NONE
Push:            NONE
History rewrite: NONE
```

---

## 15. FILES MODIFIED

**Modifiés dans PRE-PROD 8.1.2 :**

```text
app/Http/Controllers/Auth/NewPasswordController.php
docs/preprod-phase-8.1.2-password-reset-fix-report.md
```

**Préexistants (PRE-PROD 8.1, non modifiés en 8.1.2) :**

```text
DEBUG_NOTIFICATIONS.md
phpunit.xml
tests/Pest.php
tests/Feature/AttachmentTest.php
tests/Feature/Rbac/EditUpdateCompatibilityTest.php
tests/Feature/StoreStockFoundationTest.php
```

---

## 16. REMAINING TEST FAILURES

```text
The remaining failures identified in PRE-PROD 8.1.1 remain outside the scope of this phase unless their count or nature changed due to this fix.
```

**Changement dû à ce correctif :**

| Test | Avant | Après |
|------|-------|-------|
| `password cannot be reset with invalid token` | FAIL (Undefined constant) | PASS |

**75 échecs restants** — causes inchangées (Pusher test env ~44, Fortify disabled ~9, legacy notifications ~5, etc.) — voir `docs/preprod-phase-8.1.1-test-failure-analysis-report.md`.

---

## 17. PUSHER

```text
Pusher credential rotation remains an external/manual action.
```

Aucune modification Pusher dans cette phase.

---

## 18. FINAL VERDICT

```text
PASSWORD RESET BLOCKER RESOLVED — READY FOR HUMAN REVIEW
```

**Critères validés :**

- [x] `Password::RESET_THROTTLED` confirmé dans Laravel 12.40.2
- [x] Correctif appliqué (1 ligne)
- [x] Test ciblé PASS
- [x] Aucune régression (+1 PASS, -1 FAIL uniquement)
- [x] Infrastructure 188/188 PASS
- [x] `gestion` non touchée
- [x] OVH / production / R2 non touchés
- [x] Aucun commit/push

---

## MESSAGE FINAL

```text
=== PRE-PROD 8.1.2 COMPLETE ===

REPORT:
docs/preprod-phase-8.1.2-password-reset-fix-report.md

PASSWORD RESET BLOCKER:
RESOLVED

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

GIT COMMIT:
NONE

GIT PUSH:
NONE

FINAL VERDICT:
PASSWORD RESET BLOCKER RESOLVED — READY FOR HUMAN REVIEW

NEXT STEP:
WAIT FOR HUMAN REVIEW
```
