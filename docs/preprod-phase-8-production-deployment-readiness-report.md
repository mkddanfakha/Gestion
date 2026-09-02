# PRE-PROD 8 — PRODUCTION DEPLOYMENT READINESS REPORT

**Cible production :** `https://niane.mkd-pro.com`  
**Date audit :** 2026-09-01  
**Mode :** READ ONLY — aucun déploiement, aucune modification applicative

---

## 1. EXECUTIVE SUMMARY

Audit de préparation au premier déploiement MKD-Pro sur OVH. La version auditée (`122ac9a` sur `main`) présente une **architecture backup/restore mature et testée** (188 tests infrastructure + validations PRE-PROD 7.1 / 7.1.1), un **build frontend réussi**, et des **160 tests Vitest passants**.

Deux **bloqueurs** empêchent un déploiement serein immédiat :

1. **Secret Pusher réel versionné dans Git** (`DEBUG_NOTIFICATIONS.md`) — échec explicite de la politique secrets.
2. **Suite backend complète non exécutable** — erreur fatale `createTestProduct()` redéclarée entre fichiers Feature.

De plus, de nombreux paramètres **OVH production restent non vérifiables localement** (PHP version hébergée, cron, worker queue, R2, `.env` production, migrations déjà exécutées ou non).

**Verdict final :** `NOT READY FOR PRODUCTION`

---

## 2. APPLICATION VERSION

```text
PROJECT PATH:     C:\Users\dmoha\Documents\laravelia\gestion
GIT BRANCH:       main
GIT COMMIT:       122ac9abce24aac19a09f557911be8e26223304a
GIT MESSAGE:      feat(preprod): restore privilégié, backup async et protocole recovery
LARAVEL VERSION:  12.40.2
PHP VERSION:      8.4.0 (local WAMP)
NODE VERSION:     v22.20.0
NPM VERSION:      10.9.3
VUE VERSION:      ^3.5.13 (package.json)
INERTIA VERSION:  ^2.1.0 (@inertiajs/vue3) / inertia-laravel ^2.0
PINIA VERSION:    ^4.0.2
VITE VERSION:     ^7.0.4 (build: 7.1.9)
TAILWIND VERSION: ^4.1.1
```

### Variables d'environnement locales (sans valeurs sensibles)

| Variable | Statut local |
|----------|--------------|
| APP_NAME | SET |
| APP_ENV | `local` |
| APP_DEBUG | `true` (ENABLED) |
| APP_URL | SET (`192.168.1.24` — LAN dev) |
| APP_KEY | SET |
| DB_PASSWORD | SET (gitignored) |
| AWS_ACCESS_KEY_ID | UNKNOWN / NOT VERIFIED prod |
| AWS_SECRET_ACCESS_KEY | SET or NOT SET (gitignored) |
| MAIL_PASSWORD | NOT SET or placeholder |
| PUSHER_APP_SECRET | SET (gitignored .env) |

> L'environnement local n'est **pas** représentatif de la production. La production exigera `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://niane.mkd-pro.com`.

---

## 3. GIT STATUS

```text
STATUS: MODIFIED (fichiers non suivis uniquement)
```

| Élément | Résultat |
|---------|----------|
| Branche | `main` — synchronisée avec `origin/main` @ `122ac9a` |
| Fichiers modifiés trackés | **Aucun** |
| Fichiers non suivis | `.env.testing`, `tmp_9_4_1_scan.php` |
| `git diff --check` | PASS (aucun conflit whitespace) |
| `.env` | **IGNORED** — non tracké |
| `.mysql-gestion-backup.local` | **IGNORED** |
| `.mysql-gestion-restore.local` | **IGNORED** |
| `*.sql` / `backups/` | **IGNORED** |

**Verdict section :** `CLEAN` (code commité) avec fichiers locaux non suivis acceptables.

---

## 4. SECRETS

```text
PASS / FAIL → FAIL
```

### Fichiers credentials — protection Git

| Fichier | Git status |
|---------|------------|
| `.env` | IGNORED |
| `.env.production` | IGNORED |
| `.mysql-gestion-backup.local` | IGNORED |
| `.mysql-gestion-restore.local` | IGNORED |
| `.mysql-gestion-migration.local` | IGNORED |
| `.mysql-gestion-app.local` | IGNORED |

### Secret détecté dans fichier Git-tracked

| SECRET LOCATION | SECRET TYPE | TRACKED / IGNORED | STATUS |
|-----------------|-------------|-------------------|--------|
| `DEBUG_NOTIFICATIONS.md` | `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET` (valeurs réelles) | **TRACKED** | **FAIL** |

Les fichiers `PUSHER_CONFIG.md`, `FIX_NOTIFICATIONS_PRODUCTION.md`, `CONFIGURATION_MAIL.md` utilisent des placeholders ou des références `${PUSHER_APP_KEY}` — **PASS**.

`.env.example` ne contient que des clés vides — **PASS**.

**Action requise avant production :** retirer les secrets de l'historique Git, faire tourner les credentials Pusher, ne jamais committer de secrets dans la documentation.

---

## 5. ENVIRONMENT

```text
PASS / WARNING / FAIL → WARNING
```

| Contrôle | Résultat |
|----------|----------|
| `config/app.php` utilise `env('APP_DEBUG')` | OK |
| Comportement `APP_DEBUG=false` | Code gère erreurs 403 Inertia, pas de dépendance debugbar en prod (`require-dev`) |
| `bootstrap/app.php` trusted proxies | Configurable via `TRUSTED_PROXIES` — **requis** derrière HTTPS OVH/Cloudflare |
| Session driver | `database` (tables requises) |
| Cache store | `database` |
| Queue | `database` (worker requis) |

Production `.env` non présent localement — configuration production **non validée**.

---

## 6. DATABASE

```text
PASS / WARNING / FAIL → WARNING
```

| Élément | Statut |
|---------|--------|
| Runtime attendu | `gestion_app` → base `gestion` |
| Comptes séparés documentés | `config/database-accounts.php` |
| Restore allow-list | `gestion_recovery`, `gestion_test` via `DB_RESTORE_ALLOWED_DATABASES` |
| Base protégée | `gestion` via `DB_PROTECTED_DATABASES` |
| Schéma local | 38 tables métier (validé PRE-PROD 7.1) |
| FK physiques | 0 (MyISAM — connu, non bloquant restore) |
| Base production OVH | **UNKNOWN — MANUAL OVH VERIFICATION REQUIRED** |

---

## 7. MIGRATIONS

```text
PASS / WARNING / FAIL → WARNING
```

```text
TOTAL MIGRATIONS:        75 (+ migrations module NotificationCenter)
LATEST MIGRATION:        2026_08_22_000002_add_application_summary_to_inventory_sessions_table.php
ORDER:                   Chronologique Laravel standard
POTENTIAL PRODUCTION IMPACT: ÉLEVÉ si base production vide ou partielle
```

### Points d'attention

| Type | Exemples | Risque |
|------|----------|--------|
| Migrations de données | `convert_existing_sale_numbers`, `convert_existing_skus` | Moyen — idempotence à vérifier sur données réelles |
| `dropColumn` | `drop_credit_limit_from_customers` | Moyen — irréversible |
| `renameColumn` | `companies.phone` → `phone1` | Moyen |
| `dropIfExists` en `down()` | Standard Laravel | Faible en `up()` |

```text
MIGRATION FILES VALID:           OUI (inspection statique)
MIGRATIONS EXECUTED IN PRODUCTION: UNKNOWN
```

**Aucune migration exécutée pendant cet audit.**

---

## 8. BACKUP SYSTEM

```text
PASS / WARNING / FAIL → PASS
```

| Composant | Statut |
|-----------|--------|
| `BackupCreationService` + `CreateBackupJob` | Implémenté, async |
| `backup:production` via subprocess `gestion_backup` | Testé (22 tests critical fixes) |
| `BackupManifest` + SHA-256 | Testé |
| `BackupPathGuard` | Path traversal rejeté |
| ZIP quarantine / inspection | Testé |
| Stockage hors `public/` | `storage/app/backups` (via BackupPathGuard) |
| Scheduler | `backup:production` daily 02:00 |
| R2 offsite | Optionnel — `BACKUP_DISKS=local` par défaut |

**Risque résiduel :** `MYSQL_DUMP_PATH` peut être requis sur Linux OVH si `mysqldump` absent du PATH.

---

## 9. RESTORE SYSTEM

```text
PASS / WARNING / FAIL → PASS
```

Architecture validée PRE-PROD 7.1 / 12.7.17 :

```text
gestion_app → DatabaseRestoreService → PrivilegedRestoreProcessRunner
→ subprocess db:restore (gestion_restore) → gestion_recovery / gestion_test
```

18 tests `ControlledRestoreVerificationService` — **PASS**.

---

## 10. RESTORE TARGET PROTECTION

```text
gestion:           BLOCKED ✓
gestion_recovery:  ALLOWED ✓
gestion_test:      ALLOWED ✓
other:             BLOCKED ✓
```

Protection multi-couches vérifiée par tests :

- `DatabaseSafetyGuard`
- `PrivilegedRestoreProcessRunner::assertRestoreTargetAllowed`
- `db:restore` command guard
- `BackupController` validation explicite
- Pas d'inférence depuis `DB_DATABASE`

---

## 11. MYSQL ACCOUNT SEPARATION

```text
gestion_app      → runtime CRUD sur gestion
gestion_backup   → backup privilégié (subprocess)
gestion_restore  → restore privilégié (subprocess, allow-list DB)
gestion_migration → migrations (hors runtime Laravel)
```

188 tests infrastructure incluant `AccountSeparationTest`, `MysqlPrivilegeBoundaryTest` — **PASS**.

Credentials dans fichiers `.mysql-gestion-*.local` — **gitignored**.

---

## 12. STORAGE / R2

```text
PASS / WARNING / FAIL → WARNING
```

| Élément | Statut |
|---------|--------|
| Disque `local` | `storage/app/private`, `serve: false` |
| Disque `s3` (R2-compatible) | Configuré via `AWS_*` env vars |
| Visibilité backups S3 | `private` |
| `BACKUP_DISKS` default | `local` |
| CA bundle Windows (`extras/ssl`) | **Non référencé dans le code applicatif** |
| R2 production testé | **NON** (interdit pendant audit) |

`.env.example` documente le risque cURL 60 sur Windows et la nécessité d'un CA bundle — à valider sur PHP CLI OVH.

---

## 13. QUEUE

```text
PASS / WARNING / FAIL → WARNING
```

| Élément | Valeur |
|---------|--------|
| `QUEUE_CONNECTION` default | `database` |
| Jobs critiques | `CreateBackupJob` |
| Worker requis | **OUI** — `php artisan queue:work` ou supervisor |
| OVH worker support | **UNKNOWN — MANUAL OVH VERIFICATION REQUIRED** |

Sans worker, les backups async UI resteront en file `jobs`.

---

## 14. SCHEDULER

```text
PASS / WARNING / FAIL → WARNING
```

| COMMAND | FREQUENCY | PRODUCTION REQUIREMENT |
|---------|-----------|------------------------|
| `backup:production` | Daily 02:00 | Cron `* * * * * php artisan schedule:run` |
| `backup:clean` | Daily 03:00 | Cron |
| `backup:monitor` | Daily 04:00 | Cron |
| `notifications:*` | Daily/monthly | Cron |

**OVH cron :** UNKNOWN — configuration manuelle requise.

---

## 15. MAIL

```text
PASS / WARNING / FAIL → WARNING
```

| Variable | Default `.env.example` |
|----------|---------------------|
| MAIL_MAILER | `log` |
| BACKUP_ALERT_MAIL_ENABLED | `false` |

SMTP production non configuré localement. Password reset et notifications dépendent d'une config SMTP réelle en production.

---

## 16. HTTPS / DOMAIN

```text
PASS / WARNING / FAIL → WARNING
```

| Contrôle | Résultat |
|----------|----------|
| `APP_URL` production attendu | `https://niane.mkd-pro.com` |
| URLs codées en dur problématiques | Aucune URL prod codée ; `localhost`/`127.0.0.1` limités aux configs/tests |
| `config/inertia.php` SSR url | `http://127.0.0.1:13714` — SSR non utilisé en prod standard |
| `TRUSTED_PROXIES` | Non défini hors `local` — **à configurer** pour HTTPS derrière proxy |
| Cookies secure | Dépend de `SESSION_SECURE_COOKIE` / proxy HTTPS |
| Barcode camera | Nécessite HTTPS (documenté dans `barcodeCamera.ts`) |

---

## 17. AUTHORIZATION

```text
PASS / WARNING / FAIL → PASS
```

Routes backup sous :

```text
middleware: auth, verified, EnsureUserIsAdmin
+ checkPermission(request, 'backups', action)
```

Permissions : `backups.view`, `backups.create`, `backups.delete`, `backups.restore`.

Double barrière admin + RBAC granulaire. Tests RBAC infrastructure présents.

---

## 18. UPLOAD SECURITY

```text
PASS / WARNING / FAIL → PASS
```

| Upload | Protections |
|--------|-------------|
| Backup ZIP import | Extension, magic bytes, traversal, quarantine, manifest |
| Company logo/stamp | Controllers dédiés, auth |
| Attachments | Modèle + storage privé |

Chaîne ZIP backup testée dans `BackupCriticalFixesTest`.

---

## 19. ZIP / BACKUP SECURITY

```text
PASS / WARNING / FAIL → PASS
```

Rejet vérifié pour : `../`, chemins absolus, ZIP sans SQL, ZIP corrompu, entrées traversal, secrets dans manifest.

---

## 20. SHELL INJECTION PROTECTION

```text
PASS / WARNING / FAIL → PASS
```

`PrivilegedProcessRunner` / `PrivilegedRestoreProcessRunner` :

- Arguments via tableau `Process` (pas de shell)
- Mot de passe en **environment**, jamais en argv
- `assertCommandLineContainsNoSecret()` testé
- Targets validés avant subprocess
- Tests shell injection dans `PrivilegedRestoreProcessRunnerTest`

Commandes dev `DiagnoseMySQL` / `TestMySQLConnection` utilisent `exec()` — outils CLI locaux uniquement, non exposés HTTP.

---

## 21. PDF

```text
PASS / WARNING / FAIL → PASS
```

Génération via Dompdf, réponses `Content-Disposition: attachment`. Routes protégées par `auth` + permissions métier. Aucune génération PDF production pendant l'audit.

---

## 22. INVENTORY

```text
PASS / WARNING / FAIL → WARNING
```

Modules `inventory_sessions`, `inventory_items`, `product_stocks`, `stock_movements` présents. Cohérence stock documentée dans migrations. Tests Vitest inventaire passants. Validation concurrence production : **non testée** sur OVH.

---

## 23. MULTI-STORE / MULTI-COMPANY

```text
PASS / WARNING / FAIL / NOT APPLICABLE → PASS
```

Tables `companies`, `stores`, `permissions`, `user_permissions`. `AuthorizationService` centralisé. Tests RBAC Feature présents (mais suite complète bloquée — voir §26).

---

## 24. PERFORMANCE

```text
PASS / WARNING / FAIL → PASS
```

Polling backup (`backupCreatePolling.ts`) :

- Idempotent, séquentiel
- `preserveState: true` pour éviter remount
- 12 tests Vitest dédiés
- Correctif 12.7.13/15 documenté dans le code

Risque N+1 : non profilé — INFO seulement.

---

## 25. PRODUCTION COMPATIBILITY

```text
PASS / WARNING / FAIL → WARNING
```

| Élément | Statut | Risque |
|---------|--------|--------|
| PHP version OVH | UNKNOWN | Local 8.4.0 — composer `^8.2` |
| PHP extensions | UNKNOWN | pdo_mysql, curl, zip, mbstring, gd, openssl requis |
| Document root `/public` | KNOWN | Standard Laravel + `.htaccess` |
| Cron | UNKNOWN | Requis pour scheduler |
| Queue worker | UNKNOWN | Requis pour jobs async |
| MySQL version OVH | UNKNOWN | |
| HTTPS | KNOWN requirement | |
| Build strategy | KNOWN | `npm run build` avant deploy ou CI |
| Windows paths en prod | LOW RISK | Uniquement commandes diagnostic dev |
| `mysqldump` Linux PATH | ACTION REQUIRED | Configurer `MYSQL_DUMP_PATH` si besoin |

---

## 26. TESTS

```text
Backend:  PARTIAL PASS / FULL SUITE FAIL
Frontend: PASS (160/160)
Build:    PASS (exit 0, 2m29s)
```

### Backend

| Suite | Résultat |
|-------|----------|
| `tests/Unit/Infrastructure` | **188 passed** (519 assertions) |
| `ControlledRestoreVerificationServiceTest` | **18 passed** |
| `php artisan test` (suite complète) | **FAIL** — `Cannot redeclare function createTestProduct()` (`AttachmentTest.php` vs `EditUpdateCompatibilityTest.php`) |

Tests destructifs (`migrate:fresh` sur gestion) : **non exécutés** — bloqués par design.

### Frontend (Vitest)

```text
Test Files:  16 passed
Tests:       160 passed
```

Inclut `backupCreatePolling.test.ts`, `backupRestoreResponse.test.ts`.

### Build

```text
npm run build → exit 0
manifest.json généré (84.89 kB)
3705 modules transformés
```

---

## 27. PREVIOUS RESTORE VALIDATION

### PRE-PROD 7.1 — RECOVERY VERIFIED WITH WARNINGS

- 38/38 tables restaurées dans `gestion_recovery`
- Business row counts cohérents avec `gestion`
- SHA-256 valide (`fc8ba394…`)
- `gestion` inchangée
- `gestion_app` bloqué pour restore direct
- `gestion_restore` utilisé via subprocess

### PRE-PROD 7.1.1 — WARNING ANALYSIS VERIFIED

- FK=0 : MyISAM, pas de FK physiques — pas un défaut restore
- 2 orphelins `quote_items` : préexistants, dump-consistent
- 2 `restore.audit attempt` : parent + subprocess, 1 seul import SQL
- `activity_logs.metadata` : colonne inexistante — défaut protocole 7.1

**Aucun restore relancé pendant PRE-PROD 8.**

---

## 28. OPEN ITEMS

| Severity | Problem | Evidence | Recommended Action | Blocking? |
|----------|---------|----------|-------------------|-----------|
| **CRITICAL** | Secret Pusher réel dans Git | `DEBUG_NOTIFICATIONS.md` tracké, `PUSHER_APP_SECRET` valeur réelle | Retirer du repo, rotation Pusher, audit historique Git | **YES** |
| **HIGH** | Suite `php artisan test` non exécutable | Fatal `createTestProduct()` redeclared | Renommer/isoler helpers de test | **YES** |
| **HIGH** | Environnement production OVH non configuré | Aucun `.env` prod local | Préparer `.env` prod, valider sur staging OVH | **YES** |
| **HIGH** | Queue worker non vérifié OVH | `QUEUE_CONNECTION=database` | Configurer worker/supervisor OVH | **YES** |
| **HIGH** | Cron scheduler non vérifié OVH | `routes/console.php` daily backups | Configurer cron `schedule:run` | **YES** |
| **MEDIUM** | Migrations production inconnues | État DB OVH inconnu | Inventaire `migrations` table avant deploy | **YES** (si DB existante) |
| **MEDIUM** | `TRUSTED_PROXIES` non documenté prod | `bootstrap/app.php` | Configurer pour HTTPS OVH | Partial |
| **MEDIUM** | R2 offsite non testé | `BACKUP_DISKS=local` | Tester upload R2 post-deploy autorisé | No |
| **MEDIUM** | PHP 8.4 local vs OVH | PHP 8.4.0 WAMP | Vérifier version PHP OVH ≥ 8.2 | Partial |
| **LOW** | `SESSION_ENCRYPT=false` default | `config/session.php` | Évaluer `true` en production | No |
| **LOW** | Protocole 7.1 `activity_logs.metadata` | Rapport 7.1.1 | Corriger protocole (phase ultérieure) | No |
| **LOW** | 2 orphelins `quote_items` | Rapport 7.1.1 | Audit métier séparé | No |
| **INFO** | Fichiers non suivis | `.env.testing`, `tmp_9_4_1_scan.php` | Ajouter au `.gitignore` ou supprimer | No |

---

## 29. DEPLOYMENT CHECKLIST

```text
[ ] CODE          — tag/commit 122ac9a+ ; corriger secrets Git d'abord
[ ] ENV           — APP_ENV=production, APP_DEBUG=false, APP_URL=https://niane.mkd-pro.com
[ ] DATABASE      — gestion_app sur gestion ; comptes backup/restore ; migrations contrôlées
[ ] STORAGE       — storage:link ; permissions storage/ ; R2 si offsite
[ ] QUEUE         — worker database queue
[ ] SCHEDULER     — cron schedule:run
[ ] HTTPS         — certificat ; TRUSTED_PROXIES
[ ] DOMAIN        — DNS niane.mkd-pro.com → OVH
[ ] HEALTH CHECK  — GET /up
[ ] SMOKE TEST    — voir §31
[ ] BACKUP        — backup manuel avant migration
[ ] ROLLBACK      — voir §30
```

### Ordre recommandé (§55)

```text
CODE → DEPENDENCIES (composer --no-dev) → ENV → ASSETS (npm build) → CACHE
→ DATABASE MIGRATIONS (avec backup préalable) → QUEUE → SCHEDULER
→ HEALTH CHECK → SMOKE TEST
```

---

## 30. ROLLBACK PLAN

Documentaire uniquement — **non exécuté**.

| Type | Procédure |
|------|-----------|
| CODE ROLLBACK | Redéployer commit précédent |
| DATABASE ROLLBACK | Restaurer backup SQL vers `gestion_recovery`, puis bascule contrôlée — **jamais restore direct vers `gestion` sans procédure humaine** |
| ASSET ROLLBACK | Restaurer `public/build` du commit précédent |
| ENV ROLLBACK | Conserver copie `.env` avant deploy |
| STORAGE ROLLBACK | R2 versioning / backups locaux |

**Migrations non réversibles identifiées :** `drop_credit_limit_from_customers`, conversions de données numériques, `renameColumn` companies phones.

---

## 31. POST-DEPLOYMENT SMOKE TEST

À exécuter **après** PRE-PROD 9 avec autorisation humaine :

```text
[ ] Homepage/login
[ ] Login admin
[ ] Dashboard
[ ] Customer read
[ ] Product read
[ ] Sale read
[ ] Quote read
[ ] PDF read/download
[ ] Backup page
[ ] Backup creation
[ ] R2 verification (si configuré)
[ ] Queue verification
[ ] Logout
```

Écriture (phase séparée) :

```text
[ ] création client test
[ ] création produit test
```

---

## 32. COMMANDES ARTISAN SENSIBLES (§35)

| COMMAND | PURPOSE | DESTRUCTIVE? | PRODUCTION SAFE? | PROTECTION |
|---------|---------|:------------:|:----------------:|------------|
| `migrate` | Schéma | Partiel | Avec backup + compte migration | Account guard |
| `migrate:fresh` | Reset DB | **OUI** | **NON** | `DatabaseSafetyGuard` bloque `gestion` |
| `migrate:refresh` | Reset+migrate | **OUI** | **NON** | Idem |
| `db:wipe` | Vide tables | **OUI** | **NON** | Idem |
| `db:restore` | Restore SQL | **OUI** | Allow-list only | Guards + subprocess |
| `backup:production` | Backup | Non | Oui (subprocess) | `gestion_backup` only |
| `restore:verify-protocol` | Audit dry-run | Non | Oui | Read-only protocol |

---

## 33. VARIABLES D'ENVIRONNEMENT ATTENDUES (§36)

| Variable | Classification |
|----------|----------------|
| APP_NAME, APP_URL | PUBLIC / REQUIRED |
| APP_ENV, APP_DEBUG | PRODUCTION-SPECIFIC / REQUIRED |
| APP_KEY | SECRET / REQUIRED |
| DB_* | SECRET (password) / REQUIRED |
| DB_PROTECTED_DATABASES | PRODUCTION-SPECIFIC / REQUIRED |
| DB_RESTORE_ALLOWED_DATABASES | PRODUCTION-SPECIFIC / REQUIRED |
| QUEUE_CONNECTION | REQUIRED |
| FILESYSTEM_DISK | REQUIRED |
| AWS_* / R2 | SECRET / OPTIONAL (offsite) |
| BACKUP_DISKS | PRODUCTION-SPECIFIC |
| MAIL_* | SECRET (password) / REQUIRED for alerts |
| PUSHER_* | SECRET (server) / OPTIONAL |
| VITE_PUSHER_APP_KEY | PUBLIC (client) / OPTIONAL |
| MYSQL_DUMP_PATH | PRODUCTION-SPECIFIC / OPTIONAL |
| TRUSTED_PROXIES | PRODUCTION-SPECIFIC / RECOMMENDED |
| SESSION_* | PRODUCTION-SPECIFIC |

---

## 34. DOCUMENT ROOT (§5)

```text
DOCUMENT ROOT EXPECTED:          /public (index.php)
DOCUMENT ROOT CURRENT ASSUMPTION: public/ avec .htaccess rewrite standard
RISQUE EXPOSITION .env/storage:  FAIBLE si document root correct sur OVH
```

---

## 35. PROTECTION CONTRE MODIFICATIONS NON AUTORISÉES (§63)

```text
DEPLOYMENT EXECUTED:           NO
OVH MODIFIED:                  NO
PRODUCTION DATABASE MODIFIED:  NO
R2 MODIFIED:                   NO
SCHEDULER MODIFIED:            NO
CRON MODIFIED:                 NO
WRITE SQL EXECUTED:            NO
MIGRATIONS EXECUTED:           NO
GIT MODIFIED:                  NO
.env MODIFIED:                 NO
```

**Opérations autorisées exécutées :** `git status/log`, `npm run build`, `php artisan test` (infrastructure), `npm run test`, inspection fichiers.

---

## 36. FINAL VERDICT

```text
NOT READY FOR PRODUCTION
```

### Justification

La base technique backup/restore et la séparation des comptes MySQL sont **solides et validées**. Cependant :

1. **Secret versionné** — bloqueur sécurité immédiat.
2. **Suite de tests backend complète cassée** — régression non détectable en CI.
3. **Infrastructure OVH non vérifiée** — cron, queue, PHP, migrations prod, R2, `.env` production.

### Prochaine phase

```text
PRE-PROD 9 — CONTROLLED PRODUCTION DEPLOYMENT
```

**Ne pas exécuter automatiquement.** Prérequis :

1. Corriger `DEBUG_NOTIFICATIONS.md` + rotation Pusher
2. Réparer la suite `php artisan test`
3. Préparer et valider `.env` production OVH
4. Confirmer cron + queue worker sur OVH

---

*Rapport généré en mode READ ONLY — PRE-PROD 8 — MKD-Pro / gestion*
