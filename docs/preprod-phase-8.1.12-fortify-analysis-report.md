# PRE-PROD 8.1.12 — FORTIFY PRODUCT & ARCHITECTURE VALIDATION REPORT

**Date :** 2026-09-02  
**Mode :** READ ONLY — diagnostic produit et architecture uniquement  
**Baseline :** PRE-PROD 8.1.11 (`950 PASS / 7 FAIL`)

---

## 1. OBJECTIVE

Analyser rigoureusement les **7 tests Fortify encore en échec** après PRE-PROD 8.1.11, vérifier l'hypothèse « configuration intentionnelle + implémentation MKD-Pro custom », et déterminer s'il existe un risque production réel.

Aucune modification de code, test, configuration ou base de données n'a été effectuée.

---

## 2. BASELINE

```text
957 TOTAL
950 PASS
  7 FAIL
  0 SKIP
```

---

## 3. TEST ENVIRONMENT

```text
APP_ENV:          testing
DB_CONNECTION:    sqlite
DB_DATABASE:      :memory:
BROADCAST_DRIVER:   log
```

Isolation confirmée — `gestion`, `gestion_recovery`, `gestion_test` non utilisés.

---

## 4. DATABASE ISOLATION

| Base | Utilisée ? |
| ---- | ---------- |
| `gestion` | NON |
| `gestion_recovery` | NON |
| `gestion_test` | NON |

---

## 5. GIT BASELINE

```text
GIT BASELINE BEFORE PRE-PROD 8.1.12
```

Fichiers modifiés avant cette phase (PRE-EXISTING, dont 8.1.11) — aucune modification applicative durant 8.1.12.

---

## 6. FORTIFY CONFIGURATION

Source : `config/fortify.php` (lecture seule)

| Feature Fortify | État |
| --------------- | ---- |
| `Features::registration()` | **DÉSACTIVÉ** (commenté) |
| `Features::resetPasswords()` | **ACTIF** |
| `Features::emailVerification()` | **DÉSACTIVÉ** (commenté) |
| `Features::updateProfileInformation()` | **DÉSACTIVÉ** (commenté) |
| `Features::updatePasswords()` | **DÉSACTIVÉ** (commenté) |
| `Features::twoFactorAuthentication()` | **ACTIF** (`confirm` + `confirmPassword`) |

Autres paramètres :

```text
home: /dashboard
views: true
guard: web
passwords: users
```

`FortifyServiceProvider` configure :

- `loginView` → Inertia `auth/Login`
- `twoFactorChallengeView` → Inertia `auth/TwoFactorChallenge`
- `confirmPasswordView` → Inertia `auth/ConfirmPassword`
- `authenticateUsing` custom (vérif `is_active`, messages FR)
- `LoginResponse` custom (`App\Http\Responses\LoginResponse`)

**Aucune vue Fortify** pour registration, reset password views (routes custom dans `routes/auth.php`).

---

## 7. AUTHENTICATION ARCHITECTURE

### Cartographie par fonctionnalité

| Fonctionnalité | Implémentation MKD-Pro | Preuve |
| -------------- | ---------------------- | ------ |
| **Login** | Fortify + custom auth | `FortifyServiceProvider::authenticateUsing`, `Login.vue`, tests `AuthenticationTest` PASS |
| **Logout** | Fortify | Standard Fortify |
| **Registration** | Controller custom **non routé** | `RegisteredUserController` existe ; `Features::registration()` off ; pas de route `register` |
| **Password reset (forgot)** | Custom | `PasswordResetLinkController`, `routes/auth.php`, `ForgotPassword.vue` |
| **Password reset (store)** | Custom | `NewPasswordController` (fix 8.1.2 `RESET_THROTTLED`) |
| **Email verification** | Custom partiel | Routes `verification.*`, `VerifyEmailController`, `EmailVerificationNotificationController` ; `User` **n'implémente pas** `MustVerifyEmail` |
| **Password update (settings)** | Custom | `PasswordController`, `routes/settings.php` — tests `PasswordUpdateTest` **PASS** |
| **Profile update** | Custom | `ProfileController::update`, `ProfileUpdateRequest` — tests **PASS** (3/5) |
| **Profile delete** | Custom **bloqué** | `ProfileController::destroy` refuse toute auto-suppression |
| **2FA** | Fortify | `TwoFactorAuthenticationController`, tests `TwoFactorAuthenticationTest` **PASS** |
| **User creation** | Admin only | `admin.users.store`, `RolePresetUserTest` |

### Routes auth (`routes/auth.php`)

```text
GET/POST  forgot-password     → password.request / password.email
GET/POST  reset-password/*      → password.reset / password.store
GET       verify-email          → verification.notice
GET       verify-email/{id}/{hash} → verification.verify
POST      email/verification-notification → verification.send
```

**Absentes :** `register`, `register.store` (Fortify registration désactivée).

### User model (`app/Models/User.php`)

```php
// use Illuminate\Contracts\Auth\MustVerifyEmail;  ← COMMENTÉ
```

- Notifications custom : `ResetPasswordNotification`, `VerifyEmailNotification`
- Trait `TwoFactorAuthenticatable` (Fortify)
- Création utilisateurs : rôle assigné par admin, pas d'inscription publique

### Middleware `verified`

`routes/web.php` groupe principal : `middleware(['auth', 'verified'])`.

Comme `User` n'implémente **pas** `MustVerifyEmail`, le middleware `verified` de Laravel **ne bloque pas** les utilisateurs non vérifiés — la vérification email est **optionnelle**, non enforce globalement.

---

## 8. FEATURE MATRIX

| Fonctionnalité | Fortify | MKD-Pro custom | Laravel default | Utilisée par l'app | Test existant |
| -------------- | ------- | -------------- | --------------- | ------------------ | ------------- |
| Login | OUI | OUI (auth custom, `is_active`) | — | **OUI** | PASS |
| Logout | OUI | — | — | **OUI** | PASS |
| Registration | OFF | Controller existe, **non routé** | Breeze scaffold | **NON** (admin only) | **FAIL** (2) |
| Password reset | ON (feature) | Controllers custom | — | **OUI** | PASS (8.1.11) |
| Email verification | OFF | Routes + controllers custom | Partiel | **PARTIEL** (UI profil, non enforce) | **FAIL** (3) |
| Password update | OFF | `PasswordController` | — | **OUI** | PASS |
| Profile update | OFF | `ProfileController::update` | — | **OUI** | PASS |
| Profile delete | OFF | `ProfileController::destroy` **bloqué** | Breeze auto-delete | **NON** (policy MKD-Pro) | **FAIL** (2) |
| 2FA | ON | `TwoFactorAuthenticationController` | — | **OUI** | PASS |

---

## 9. ANALYSIS OF THE 7 FAILURES

### FORTIFY FAILURE BASELINE

```text
FORTIFY TESTS CONFIRMED: 7 FAIL / 8 PASS (dans les 4 fichiers ciblés)
```

| # | FILE | TEST | LINE | FAILURE | EXCEPTION |
| - | ---- | ---- | ---- | ------- | --------- |
| 1 | `RegistrationTest.php` | registration screen can be rendered | 4 | Route absente | `RouteNotFoundException` |
| 2 | `RegistrationTest.php` | new users can register | 10 | Route absente | `RouteNotFoundException` |
| 3 | `EmailVerificationTest.php` | email can be verified | 31 | Redirect mismatch | Assertion redirect |
| 4 | `EmailVerificationTest.php` | already verified user visiting… | 91 | Redirect mismatch | Assertion redirect |
| 5 | `VerificationNotificationTest.php` | sends verification notification | 16 | Notification non envoyée | `NotificationFake` |
| 6 | `ProfileUpdateTest.php` | user can delete their account | 64 | Redirect + compte non supprimé | Assertion redirect |
| 7 | `ProfileUpdateTest.php` | correct password must be provided… | 81 | Pas d'erreurs session | Assertion session |

---

### Test #1 — Registration screen

```text
TEST: registration screen can be rendered
FILE: tests/Feature/Auth/RegistrationTest.php
FEATURE: Registration
WHAT THE TEST EXPECTS: GET route('register') → HTTP 200
WHAT THE CURRENT APPLICATION DOES: Route [register] not defined — Features::registration() désactivé
FORTIFY INVOLVEMENT: Feature commentée dans config/fortify.php
CUSTOM MKD-PRO INVOLVEMENT: RegisteredUserController existe mais non câblé ; création via admin.users.store
USER-FACING IMPACT: NO — inscription publique volontairement absente ; Login.vue sans lien register ; Welcome.vue sans lien register
CLASSIFICATION: INTENTIONAL CONFIGURATION
```

---

### Test #2 — New users can register

```text
TEST: new users can register
FILE: tests/Feature/Auth/RegistrationTest.php
FEATURE: Registration
WHAT THE TEST EXPECTS: POST register.store → auth + redirect dashboard
WHAT THE CURRENT APPLICATION DOES: Route [register.store] not defined
FORTIFY INVOLVEMENT: Feature désactivée
CUSTOM MKD-PRO INVOLVEMENT: Utilisateurs créés par admin avec rôle RBAC (RolePresetUserTest PASS)
USER-FACING IMPACT: NO — modèle B2B fermé, comptes provisionnés par administrateur
CLASSIFICATION: INTENTIONAL CONFIGURATION
```

---

### Test #3 — Email can be verified

```text
TEST: email can be verified
FILE: tests/Feature/Auth/EmailVerificationTest.php
FEATURE: Email verification
WHAT THE TEST EXPECTS: Redirect dashboard?verified=1 après vérification
WHAT THE CURRENT APPLICATION DOES: VerifyEmailController redirige vers dashboard avec flash success (sans ?verified=1) ; email effectivement vérifié (test confirme Verified event + hasVerifiedEmail)
FORTIFY INVOLVEMENT: Features::emailVerification() désactivé
CUSTOM MKD-PRO INVOLVEMENT: VerifyEmailController custom ; redirect vendeur → sales.index
USER-FACING IMPACT: NO — la vérification fonctionne ; seul le paramètre query Fortify/Breeze manque
CLASSIFICATION: TEST OBSOLETE + ARCHITECTURE DRIFT
```

| Champ | Valeur |
| ----- | ------ |
| EXPECTED | `http://localhost/dashboard?verified=1` |
| ACTUAL | `http://localhost/dashboard` |

---

### Test #4 — Already verified user visiting link

```text
TEST: already verified user visiting verification link is redirected without firing event again
FILE: tests/Feature/Auth/EmailVerificationTest.php
FEATURE: Email verification
WHAT THE TEST EXPECTS: Redirect dashboard?verified=1, pas d'event Verified
WHAT THE CURRENT APPLICATION DOES: Redirect dashboard sans query param ; Verified non dispatché (assertion PASS)
FORTIFY INVOLVEMENT: Feature désactivée
CUSTOM MKD-PRO INVOLVEMENT: VerifyEmailController lignes 18-23
USER-FACING IMPACT: NO
CLASSIFICATION: TEST OBSOLETE + ARCHITECTURE DRIFT
```

---

### Test #5 — Sends verification notification

```text
TEST: sends verification notification
FILE: tests/Feature/Auth/VerificationNotificationTest.php
FEATURE: Email verification resend
WHAT THE TEST EXPECTS: Notification::fake() détecte Illuminate\Auth\Notifications\VerifyEmail ; redirect home
WHAT THE CURRENT APPLICATION DOES: EmailVerificationNotificationController envoie via Mail::send() + VerifyEmailNotification custom ; vérifie config SMTP (host/username/password) ; en test MAIL_MAILER=array la chaîne diffère
FORTIFY INVOLVEMENT: Feature emailVerification désactivée
CUSTOM MKD-PRO INVOLVEMENT: Controller custom avec réflexion MailMessage ; frontend Profile.vue appelle verification.send via Inertia router
USER-FACING IMPACT: UNKNOWN en environnement test ; en production le flux Mail::send est intentionnel si SMTP configuré
CLASSIFICATION: TEST BUG + ARCHITECTURE DRIFT
```

**Note :** Le test `does not send verification notification if email is verified` **PASS** — le controller gère correctement les utilisateurs déjà vérifiés.

---

### Test #6 — User can delete their account

```text
TEST: user can delete their account
FILE: tests/Feature/Settings/ProfileUpdateTest.php
FEATURE: Profile deletion (Fortify updateProfileInformation / Breeze)
WHAT THE TEST EXPECTS: DELETE profile.destroy → redirect home, user supprimé, guest
WHAT THE CURRENT APPLICATION DOES: ProfileController::destroy bloque toute suppression (admin ou non) ; redirect profile.edit avec message erreur ; compte intact
FORTIFY INVOLVEMENT: Features::updateProfileInformation() désactivé
CUSTOM MKD-PRO INVOLVEMENT: Politique explicite lignes 52-73 ProfileController ; UI Profile.vue affiche message info (pas de bouton suppression actif)
USER-FACING IMPACT: NO — suppression volontairement désactivée depuis profil ; gestion via Administration
CLASSIFICATION: INTENTIONAL CONFIGURATION
```

| Champ | Valeur |
| ----- | ------ |
| EXPECTED | Redirect `home`, user deleted |
| ACTUAL | Redirect `settings/profile`, user exists |

---

### Test #7 — Correct password must be provided to delete account

```text
TEST: correct password must be provided to delete account
FILE: tests/Feature/Settings/ProfileUpdateTest.php
FEATURE: Profile deletion validation
WHAT THE TEST EXPECTS: Mauvais password → session errors password
WHAT THE CURRENT APPLICATION DOES: destroy() retourne immédiatement sans valider password (feature désactivée)
FORTIFY INVOLVEMENT: Feature désactivée
CUSTOM MKD-PRO INVOLVEMENT: Même politique que test #6
USER-FACING IMPACT: NO
CLASSIFICATION: INTENTIONAL CONFIGURATION + TEST OBSOLETE
```

---

## 10. USER IMPACT

| Test | Si échec persiste, perte fonctionnelle utilisateur ? | Justification |
| ---- | --------------------------------------------------- | ------------- |
| Registration ×2 | **NO** | Inscription publique non offerte ; comptes créés par admin |
| Email verification redirect ×2 | **NO** | Vérification fonctionne ; redirect custom sans `?verified=1` |
| Verification notification ×1 | **NO** | Flux resend existe via UI profil + Mail::send ; test mal instrumenté |
| Profile delete ×2 | **NO** | Suppression depuis profil volontairement interdite |

```text
AUCUN des 7 échecs ne représente une perte de fonctionnalité réellement attendue par MKD-Pro en production.
```

---

## 11. CLASSIFICATION TABLE

| # | Test | Feature | Échec | Impact utilisateur | Classification | Action recommandée |
| - | ---- | ------- | ----- | ------------------ | -------------- | ------------------ |
| 1 | registration screen can be rendered | Registration | Route absente | NO | INTENTIONAL CONFIGURATION | ADAPT TEST ou REMOVE OBSOLETE TEST |
| 2 | new users can register | Registration | Route absente | NO | INTENTIONAL CONFIGURATION | ADAPT TEST ou REMOVE OBSOLETE TEST |
| 3 | email can be verified | Email verification | Redirect sans `?verified=1` | NO | TEST OBSOLETE | ADAPT TEST |
| 4 | already verified user visiting link | Email verification | idem | NO | TEST OBSOLETE | ADAPT TEST |
| 5 | sends verification notification | Email verification | Fake notification class | NO | TEST BUG | ADAPT TEST (`Mail::fake()` ou classe custom) |
| 6 | user can delete their account | Profile delete | Suppression bloquée | NO | INTENTIONAL CONFIGURATION | ADAPT TEST ou REMOVE OBSOLETE TEST |
| 7 | correct password must be provided to delete account | Profile delete | Pas de validation password | NO | INTENTIONAL CONFIGURATION | ADAPT TEST ou REMOVE OBSOLETE TEST |

---

## 12. PRODUCTION IMPACT

```text
PRODUCTION BUGS FOUND:                    0
MISSING PRODUCTION FEATURES:              0 (sous réserve décision produit sur inscription publique)
PRODUCTION BLOCKERS:                      0
TEST BUGS:                                1  (VerificationNotificationTest)
TEST OBSOLETE:                            3  (EmailVerification redirect ×2, Profile delete password ×1)
INTENTIONAL CONFIGURATION:                5  (Registration ×2, Profile delete ×2, partiellement VerificationNotification)
ARCHITECTURE DRIFT:                       4  (chevauchements avec ci-dessus)
UNKNOWN:                                  0
```

**Note importante :** L'inscription publique est **absente par design** (preuve code + UI). Si le produit devait un jour ouvrir l'inscription, ce serait une **décision produit**, pas un bug découvert par ces tests.

---

## 13. REGRESSION CHECK

```text
Baseline confirmé par exécution ciblée :
  7 FAIL / 8 PASS (fichiers Fortify)
  950 PASS / 7 FAIL (global 8.1.11)

REGRESSION DETECTED: NO
```

Aucune modification n'a été effectuée durant cette analyse.

---

## 14. DATABASE SAFETY

```text
GESTION BEFORE:
  users: 3, customers: 8, products: 26, sales: 10, quotes: 4, expenses: 2

GESTION AFTER:
  users: 3, customers: 8, products: 26, sales: 10, quotes: 4, expenses: 2

GESTION UNCHANGED: YES

gestion_recovery:  UNTOUCHED
gestion_test:      NON UTILISÉ
production:        UNTOUCHED
OVH:               UNTOUCHED
R2:                UNTOUCHED
```

---

## 15. FILE INTEGRITY

```text
APPLICATION FILES MODIFIED BY ANALYSIS: 0
TEST FILES MODIFIED BY ANALYSIS:        0
CONFIG FILES MODIFIED BY ANALYSIS:      0
```

Seul fichier créé : `docs/preprod-phase-8.1.12-fortify-analysis-report.md`

---

## 16. RECOMMENDED ACTIONS

> **Aucune action appliquée.** Recommandations pour phase ultérieure après validation humaine.

### Option A — Adapter les tests (recommandé si politique confirmée)

| Test | Action proposée |
| ---- | --------------- |
| Registration ×2 | `@group intentional` + skip, ou supprimer fichier ; documenter politique admin-only |
| EmailVerification ×2 | Assert redirect `dashboard` sans `?verified=1` ; vérifier flash `success` |
| VerificationNotification ×1 | `Mail::fake()` au lieu de `Notification::fake()` ; assert `VerifyEmailNotification` ou contenu mail |
| ProfileUpdate delete ×2 | Réécrire pour assert redirect `profile.edit` + message erreur + user non supprimé |

### Option B — Décision produit requise (si changement de politique)

| Sujet | Question produit |
| ----- | ---------------- |
| Inscription publique | MKD-Pro doit-il autoriser `register` ? |
| Suppression compte profil | Les utilisateurs doivent-ils pouvoir s'auto-supprimer ? |
| Email verification enforce | `MustVerifyEmail` doit-il être activé globalement ? |

### Option C — Ne rien faire

Les 7 FAIL n'impactent pas la production. La suite peut rester à `950/7` avec documentation explicite.

---

## 17. PRODUCT DECISION REQUIRED

**Décision produit optionnelle** (non bloquante pour déploiement) :

1. **Inscription publique** — actuellement **fermée** (admin provisionne les comptes). Confirmer que c'est la politique définitive MKD-Pro.
2. **Auto-suppression compte** — actuellement **interdite** depuis le profil. Confirmer que la gestion via Administration est le seul canal voulu.
3. **Vérification email obligatoire** — actuellement **non enforce** (`MustVerifyEmail` commenté). Décider si le middleware `verified` doit devenir effectif à l'avenir.

**Aucune de ces décisions n'est un blocker production identifié par cette analyse.**

---

## 18. FINAL VERDICT

**Scénario global : SCÉNARIO A**

Les 7 tests correspondent à des fonctionnalités **volontairement désactivées**, **remplacées par une implémentation MKD-Pro custom**, ou à des **tests Breeze/Fortify obsolètes** qui n'ont pas été alignés sur l'architecture réelle.

```text
FORTIFY VERIFIED — NO PRODUCTION BLOCKER
```

---

## STOP

```text
PRE-PROD 8.1.12 COMPLETE

FORTIFY TESTS ANALYZED: 7

BASELINE:
950 PASS / 7 FAIL

PRODUCTION BUGS: 0
MISSING PRODUCTION FEATURES: 0 (décision produit optionnelle sur inscription)
PRODUCTION BLOCKERS: 0

TEST OBSOLETE: 3
INTENTIONAL CONFIGURATION: 5
TEST BUG: 1
ARCHITECTURE DRIFT: 4 (chevauchements)

HUMAN REVIEW REQUIRED: YES (décision produit sur adaptation tests vs politique)

FILES MODIFIED BY ANALYSIS: 0

GESTION: UNCHANGED
GESTION_RECOVERY: UNCHANGED
PRODUCTION: UNTOUCHED
OVH: UNTOUCHED
R2: UNTOUCHED

REPORT:
docs/preprod-phase-8.1.12-fortify-analysis-report.md

STOP — WAITING FOR HUMAN VALIDATION
```
