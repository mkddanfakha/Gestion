# PRE-PROD 12.2 — Backup Scheduler Process-Only Wiring Audit

**Date :** 2026-08-27  
**Mode :** **AUDIT ONLY** — aucune modification code / `.env` / MySQL / cron / backup exécuté

---

```text
========================================
MKD-PRO PRE-PROD 12.2
BACKUP SCHEDULER PROCESS-ONLY WIRING
========================================

AUDIT ONLY: YES

DATABASE MODIFIED: NO
GESTION MODIFIED: NO
GESTION_RECOVERY MODIFIED: NO
GESTION_TEST MODIFIED: NO

ENV MODIFIED: NO
GRANTS MODIFIED: NO
BACKUP EXECUTED: NO
RESTORE EXECUTED: NO
MIGRATE EXECUTED: NO
SEED EXECUTED: NO

RUNTIME ACCOUNT:
gestion_app

BACKUP ACCOUNT:
gestion_backup

RESTORE ACCOUNT:
gestion_restore

MIGRATION ACCOUNT:
gestion_migration

SCHEDULER:
FAIL

PROCESS-ONLY BACKUP:
WARNING

CACHE FILE LOCK:
PASS

R2:
PASS

SSL:
PASS

CA:
UNKNOWN

SECRET HYGIENE:
WARNING

OVH COMPATIBILITY:
PASS

WINDOWS TASK SCHEDULER:
NOT RELEVANT

PRODUCTION STATUS:
NOT READY
```

---

## 1. Résumé exécutif

Le scheduler Laravel (`routes/console.php`) planifie `backup:run` à 02:00 (Africa/Dakar). Ce flux charge le `.env` runtime (`DB_USERNAME=gestion_app`). `PrivilegedCommandGuard` **refuse volontairement** `backup:run` hors compte `gestion_backup`.

Un cron OVH standard `* * * * * php artisan schedule:run` **échouera sur le backup quotidien** tant qu'un mécanisme process-only n'est pas câblé.

Le projet possède déjà :
- la policy comptes (`DatabaseAccountGuard`, `config/database-accounts.php`) ;
- le guard fail-closed (`PrivilegedCommandGuard`) ;
- le lock fichier backup (`BackupConcurrencyGuard` + `BACKUP_LOCK_CACHE_STORE=file`) ;
- un **pattern process-only documenté** (PRE-PROD 10 / 11) via fichiers `.mysql-*.local` gitignorés ;
- **aucun wrapper permanent versionné** pour le scheduler.

**Recommandation :** Option **B** — commande Artisan dédiée + runner process-only (subprocess Symfony Process avec tableau `$env`, mot de passe **hors argv**), réutilisée par scheduler, UI et job queue.

---

## 2. Flux actuel du scheduler (audit statique)

### 2.1 Enregistrement scheduler

| Élément | Valeur |
|---------|--------|
| Fichier | `routes/console.php` |
| `app/Console/Kernel.php` | **ABSENT** (Laravel 11+) |
| Bootstrap | `bootstrap/app.php` → `commands: routes/console.php` |
| Timezone | `Africa/Dakar` (`config/app.php` + `@date_default_timezone_set` dans `AppServiceProvider`) |

### 2.2 Chaîne planifiée

```text
OVH cron (futur)
    → php artisan schedule:run
    → Laravel Scheduler (routes/console.php)
    → commande planifiée
    → service / Spatie
    → connexion MySQL effective
    → cache / lock
    → destination backup
    → credentials R2
```

| Heure | Commande | Guard privilégié | Compte MySQL attendu | Verdict actuel |
|-------|----------|------------------|----------------------|----------------|
| 02:00 | `backup:run` | **OUI** (`OPERATION_BACKUP`) | `gestion_backup` | **FAIL** (runtime = `gestion_app`) |
| 03:00 | `backup:clean` | NON | `gestion_app` (filesystem) | PASS |
| 04:00 | `backup:monitor` | NON | `gestion_app` (lecture status) | PASS |
| 03:00–05:00 | `notifications:*` | NON | `gestion_app` | PASS (hors scope backup) |

### 2.3 Détail `backup:run` (chemin actuel — bloqué)

```text
schedule:run
    → Schedule::command('backup:run')->dailyAt('02:00')->withoutOverlapping(180)
    → CommandStarting event
    → PrivilegedCommandGuard::handle()
        → DatabaseAccountGuard::assertAccountForOperation('backup')
        → DB_USERNAME via config('database.connections.mysql.username')
        → gestion_app ≠ gestion_backup
    → ProtectedDatabaseException (FAIL-CLOSED)
```

**Services non atteints** tant que le guard bloque :
- Spatie `backup:run` → `DbDumperFactory` / `MySqlForcedTcp` (`BackupServiceProvider`)
- `BackupConcurrencyGuard::runBackup()` (lock store `file`)
- Disques `BACKUP_DISKS` → `local` + `s3` (R2)
- Notifications Spatie (`BackupHasFailedNotification`, etc.)

### 2.4 Mutex `withoutOverlapping`

- Utilise le **cache Laravel par défaut** du processus `schedule:run` → `CACHE_STORE=database` + `gestion_app` → **INSERT cache OK**.
- TTL backup : **180 minutes** (3 h).
- Indépendant de `BackupConcurrencyGuard` (clé `mkdpro:lock:backup`, store **file**).
- Deux couches de protection : mutex scheduler + lock applicatif backup.

### 2.5 Backup manuel UI / queue (même blocage)

| Entrée | Fichier | Comportement |
|--------|---------|--------------|
| Admin UI | `BackupController::store` | `Artisan::call('backup:run')` ou `proc_open('php artisan backup:run')` — hérite `.env` runtime → **bloqué** |
| Queue job | `CreateBackupJob` | `assertAccountForOperation(BACKUP)` puis `Artisan::call('backup:run')` — même processus `gestion_app` → **bloqué** |

---

## 3. Mécanismes process-only existants

### 3.1 Recherche codebase

| Pattern | Résultat |
|---------|----------|
| `gestion_backup` | Policy + guards + docs ; **pas** de service runner permanent |
| `process-only` | Documenté dans rapports PRE-PROD 9.4 / 10 / 11 |
| `backup:production` / `RunPrivileged` | **ABSENT** |
| Scripts `.sh` backup cron | **ABSENT** (seulement `check-backup-code.bat` = vérif marqueurs PHP, Windows dev) |
| `.mysql-gestion-backup.local` | **PRESENT GITIGNORED** |

### 3.2 Pattern validé historiquement (PRE-PROD 10.2)

Processus **ad hoc** (scripts PHP temporaires, supprimés après usage) :

1. Lire secret depuis `.mysql-gestion-backup.local` (hors Git)
2. **Avant bootstrap Laravel** : variables process-only :
   - `DB_USERNAME=gestion_backup`
   - `DB_PASSWORD=<secret file>`
   - `DB_DATABASE=gestion`
   - `CACHE_STORE=file`
   - `BACKUP_LOCK_CACHE_STORE=file`
   - CA bundle process-only si nécessaire (`storage/app/private/cacert.pem`)
3. Exécuter `php artisan backup:run`
4. `.env` disque inchangé (`DB_USERNAME=gestion_app`)

Backup vérifié : `2026-08-26-21-30-29.zip` (local + R2, SHA-256 OK).

### 3.3 Option D — mécanisme existant à réutiliser

**Conclusion :** le pattern process-only est **éprouvé en ops** mais **non industrialisé** dans le dépôt. Ne pas créer un second mécanisme parallèle ; **factoriser** le pattern PRE-PROD 10 en un composant unique.

---

## 4. Audit risque `.env`

| Exigence | Statut |
|----------|--------|
| Runtime permanent `DB_USERNAME=gestion_app` | **PASS** (policy + cutover flag) |
| `gestion_backup` ne devient jamais runtime global | **WARNING** — voir §10 |
| Secrets backup hors Git | **PASS** (`.mysql-gestion-backup.local` gitignored) |
| Secrets R2 dans `.env` runtime | **SAFE** (`.env` gitignored ; utilisés par Flysystem S3, pas par mysqldump) |
| Mot de passe dans argv CLI | **RISQUE** si wrapper shell naïf — à interdire en implémentation |
| Secrets dans ZIP backup | **PASS** (`.env` exclu `config/backup.php`) |
| Secrets dans logs | **WARNING** — `BackupController` loggue `db_host`, pas de password ; rotation historique recommandée (PRE-PROD 9.3.1) |

---

## 5. Comparaison des options

### Option A — Wrapper shell dédié (hors Laravel)

```text
cron → /opt/mkdpro/run-backup.sh → env process-only → php artisan backup:run
```

| Avantages | Inconvénients |
|-----------|---------------|
| Simple sur OVH | Duplication logique ; risque mot de passe dans `ps`/historique si mal écrit |
| Isole clairement le processus | UI admin / queue non corrigées sans second wrapper |
| | Tests Pest plus difficiles |

### Option B — Commande Artisan dédiée (RECOMMANDÉE)

```text
schedule:run → backup:production → PrivilegedProcessRunner
    → subprocess (env: gestion_backup, CACHE_STORE=file)
    → backup:run
```

| Avantages | Inconvénients |
|-----------|---------------|
| Un seul point d'entrée testable | Nécessite implémentation soignée du runner |
| Réutilisable scheduler + UI + job | Subprocess = overhead acceptable (1×/jour) |
| Mot de passe via `$env` Process, pas argv | |
| Garde-fous centralisés | |

### Option C — Variables process-only au niveau cron uniquement

```text
cron avec DB_USERNAME=gestion_backup ... php artisan schedule:run
```

| Avantages | Inconvénients |
|-----------|---------------|
| Minimal code | **DANGEREUX** : tout `schedule:run` (notifications, etc.) tournerait en `gestion_backup` |
| | Violation séparation comptes runtime / backup |
| | **REJETÉ** |

### Option D — Réutiliser pattern PRE-PROD 10

Identique à factoriser en Option B (service runner + fichier secret local).

---

## 6. Exigences de sécurité (état actuel)

| Compte | Rôle | Enforcement actuel |
|--------|------|---------------------|
| `gestion_app` | Runtime CRUD | `.env` + policy ; `backup:run` **refusé** |
| `gestion_backup` | Backup mysqldump | `PrivilegedCommandGuard` sur `backup:run` uniquement |
| `gestion_restore` | Restore allow-list | `DatabaseRestoreService` + `db:restore` |
| `gestion_migration` | Migrations | `PrivilegedCommandGuard` sur `migrate` (mysql) |
| `root` | DBA | Refusé comme runtime (`assertRuntimeUsernameAllowed`) |

**Gap :** `assertRuntimeUsernameAllowed()` n'est **pas** appelé au boot HTTP/CLI — seulement testé unitairement. Si `.env` était changé en `gestion_backup`, l'app **démarrerait** (fail-open runtime). À corriger en implémentation (boot guard).

**Impossible aujourd'hui** d'exécuter `backup:run` avec `gestion_app` (PASS).  
**Possible** de lancer `backup:clean` / `backup:monitor` avec `gestion_app` (PASS — pas de dump DB).

---

## 7. Cache et lock

| Composant | Store | Compte | Statut |
|-----------|-------|--------|--------|
| Runtime cache | `CACHE_STORE=database` (`.env.example`) | `gestion_app` | PASS |
| Scheduler mutex | Cache default (database) | `gestion_app` | PASS |
| `BackupConcurrencyGuard` | `BACKUP_LOCK_CACHE_STORE=file` | N/A (filesystem) | PASS |
| Backup subprocess (futur) | Doit forcer `CACHE_STORE=file` | `gestion_backup` | **À câbler** |

**Risques lock :**
- Crash pendant backup : TTL `BackupConcurrencyGuard` = **7200 s** ; mutex scheduler = **180 min** — expiration automatique.
- Lock permanent : faible si TTL respectés ; `backup:status` expose `backup_lock: LOCKED|FREE`.
- Deux backups simultanés : refusés (`Concurrent backup/restore is forbidden`).

---

## 8. R2 / SSL / CA

| Élément | Source | Statut |
|---------|--------|--------|
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | `.env` runtime | PRESENT (gitignored) — SAFE |
| `AWS_BUCKET`, `AWS_ENDPOINT` | `.env` | Configurés (PRE-PROD 10) |
| `AWS_USE_PATH_STYLE_ENDPOINT=true` | `.env` | Requis R2 — PASS |
| `BACKUP_DISKS=local,s3` | `.env` | Validé upload offsite |
| `verify=false` | Code | **ABSENT** — PASS |
| CA bundle process-only | `storage/app/private/cacert.pem` | Utilisé en PRE-PROD 10 (WAMP) |
| `php.ini` local `curl.cainfo` / `openssl.cafile` | OS | **VIDES** — WARNING dev |
| OVH production CA | Serveur | **UNKNOWN** — vérifier en déploiement |

Le futur processus scheduler héritera des credentials R2 du `.env` runtime (lecture seule S3) — **acceptable** : R2 n'est pas lié au compte MySQL backup.

---

## 9. Compatibilité OVH / Linux

### 9.1 Déclencheur production (futur — non installé)

```cron
* * * * * cd /chemin/vers/projet && /usr/bin/php artisan schedule:run >> /chemin/vers/projet/storage/logs/scheduler.log 2>&1
```

### 9.2 Prérequis identifiés

| Besoin | Détail |
|--------|--------|
| Chemin PHP absolu | `/usr/bin/php` ou `php8.x` — à confirmer sur OVH |
| Chemin projet | Ex. `/var/www/mkdpro/gestion` |
| Utilisateur Linux | Utilisateur web/deploy (pas root) |
| Permissions | Écriture `storage/`, `bootstrap/cache/`, `storage/app/private/Gestion/` |
| Secret backup | `/chemin/projet/.mysql-gestion-backup.local` (mode 600, propriétaire app) |
| Secret R2 | `.env` (mode 600) |
| Logs | `storage/logs/scheduler.log` + Laravel log |
| Exit codes | Non-zero si backup échoue → monitoring cron |
| Wrapper | Commande `backup:production` (Option B) — pas de Task Scheduler Windows |

---

## 10. Secret hygiene

| Secret | Emplacement | Git | Exposition |
|--------|-------------|-----|------------|
| `gestion_app` password | `.mysql-gestion-app.local` | GITIGNORED | SAFE |
| `gestion_backup` password | `.mysql-gestion-backup.local` | GITIGNORED | SAFE (rotation recommandée — historique CLI 9.3.1) |
| `gestion_restore` password | `.mysql-gestion-restore.local` | GITIGNORED | SAFE |
| `gestion_migration` password | `.mysql-gestion-migration.local` | GITIGNORED | SAFE (sync à vérifier avant migrate) |
| R2 keys | `.env` | GITIGNORED | SAFE |
| SMTP | `.env` | GITIGNORED | ABSENT si `MAIL_MAILER=log` |
| `.env.example` | Repo | SAFE | Placeholders vides |
| Rapports `docs/` | Repo | SAFE | Pas de secrets reproduits |
| ZIP backup | `storage/app/private/` | GITIGNORED | `.env` exclu |

**Risque futur wrapper :** mot de passe visible via `ps e` si passé en variable d'environnement — acceptable vs argv ; préférer fichier secret lu en PHP + `Process::run(['env' => ...])` sans logging. **Interdire** `-pPASSWORD` mysqldump.

---

## 11. Comportement des guards (tests non destructifs)

### 11.1 Tests exécutés ( cette phase )

```text
pest tests/Unit/Infrastructure/AccountSeparationTest.php
pest tests/Unit/Infrastructure/BypassAssuranceTest.php
pest tests/Unit/Infrastructure/MysqlPrivilegeBoundaryTest.php
pest tests/Unit/Infrastructure/BackupStrategyTest.php
```

**Résultat : 68 tests PASS** (AccountSeparation 12 + Bypass 18 + MysqlPrivilege 13 + BackupStrategy 25).

### 11.2 Confirmations statiques

| Scénario | Résultat attendu | Confirmé |
|----------|------------------|----------|
| `gestion_app` + `backup:run` | BLOCKED | OUI (test + guard) |
| `gestion_app` + `migrate` (mysql) | BLOCKED | OUI |
| `gestion_restore` + restore allow-list | ALLOWED (assert) | OUI |
| `gestion_app` + restore | BLOCKED | OUI |
| `backup:status` | Read-only, non gated | OUI |
| `backup:verify` | File-only, non gated | OUI (commentaire guard) |
| Target restore `gestion` | BLOCKED | OUI (BypassAssurance) |

---

## 12. Audit bypass

| Vecteur | Fichier / zone | Risque |
|---------|----------------|--------|
| `config(['database.connections.mysql'])` | `BackupController`, `BackupServiceProvider` | **LOW** — retire `unix_socket` seulement, pas username |
| `Artisan::call('backup:run')` | Controller, Job | Bloqué si `gestion_app` — pas un bypass |
| `proc_open('php artisan backup:run')` | `BackupController` (Windows web) | Hérite `.env` — bloqué |
| `putenv` / `$_ENV` | App code | **ABSENT** (hors tests Pest) |
| `DB::connection()` override credentials | App | **ABSENT** pour backup |
| `shell_exec` / `exec` | `DiagnoseMySQL`, `TestMySQLConnection` | Diagnostics dev — hors backup |
| Schedule cron env global `DB_USERNAME=gestion_backup` | Ops | **HIGH** — à ne pas faire (Option C rejetée) |
| `.env` permanent `gestion_backup` | Ops | **HIGH** — pas bloqué au boot aujourd'hui |

---

## 13. Architecture cible proposée

```text
                  ┌───────────────────┐
                  │    OVH CRON       │
                  │ schedule:run      │
                  │ (gestion_app .env)│
                  └─────────┬─────────┘
                            │
                            ▼
                  ┌───────────────────┐
                  │ Laravel Scheduler │
                  │ backup:production │
                  │ clean / monitor   │
                  └─────────┬─────────┘
                            │
              ┌─────────────┴─────────────┐
              ▼                           ▼
   ┌────────────────────┐    ┌────────────────────┐
   │ backup:production  │    │ backup:clean       │
   │ subprocess isolé   │    │ backup:monitor     │
   └─────────┬──────────┘    │ (gestion_app OK)   │
             │               └────────────────────┘
             ▼
   ┌────────────────────┐
   │ env process-only:  │
   │ gestion_backup     │
   │ CACHE_STORE=file   │
   │ BACKUP_LOCK=file   │
   └─────────┬──────────┘
             ▼
   ┌────────────────────┐
   │ backup:run         │
   │ PrivilegedGuard OK │
   └─────────┬──────────┘
             │
    ┌────────┴────────┐
    ▼                 ▼
 Local ZIP          R2 (s3)
```

**Parallèle runtime (inchangé) :**

```text
Web request → Laravel → gestion_app → gestion
```

### 13.1 Composants à créer (phase IMPLEMENTATION)

1. **`App\Database\PrivilegedCredentialLoader`** — lit `.mysql-gestion-backup.local` (format clé=valeur, jamais loggé)
2. **`App\Database\PrivilegedProcessRunner`** — lance subprocess `php artisan backup:run` avec `$env` process-only
3. **`App\Console\Commands\RunProductionBackupCommand`** — signature `backup:production` ; appelle le runner
4. **Modifier `routes/console.php`** — `Schedule::command('backup:production')` au lieu de `backup:run`
5. **Modifier `BackupController` / `CreateBackupJob`** — déléguer au même runner
6. **Optionnel mais recommandé** — `AppServiceProvider::boot()` → `DatabaseAccountGuard::assertRuntimeUsernameAllowed()` en non-testing

### 13.2 Fichier secret backup (format attendu — pas de valeurs ici)

```ini
DB_USERNAME=gestion_backup
DB_PASSWORD=<PRESENT>
DB_HOST=127.0.0.1
DB_DATABASE=gestion
```

---

## CRITICAL FINDINGS

1. **P0 — Scheduler appelle `backup:run` directement** → échec garanti avec `gestion_app` sur OVH.
2. **P0 — Aucun wrapper process-only versionné** — pattern ops seulement, non relié au scheduler.
3. **P1 — `BackupController` et `CreateBackupJob` même défaut** — backup UI/queue non fonctionnels en runtime durci.
4. **P1 — `assertRuntimeUsernameAllowed()` non appelé au boot** — `.env` pourrait théoriquement pointer un compte privilégié comme runtime sans blocage immédiat.
5. **P2 — CA PHP OVH non vérifié** — risque cURL 60 sur upload R2 si bundle absent.
6. **P2 — Rotation secrets recommandée** — exposition historique CLI (doc 9.3.1), non exécutée dans cette phase.

---

## PROPOSED SOLUTION

**Option B** : commande `backup:production` + `PrivilegedProcessRunner` + secret `.mysql-gestion-backup.local`.

Le parent `schedule:run` reste en `gestion_app`. Seul le subprocess enfant charge `gestion_backup` + `CACHE_STORE=file` avant bootstrap.

---

## FILES THAT WOULD NEED MODIFICATION

| Fichier | Modification envisagée |
|---------|------------------------|
| `routes/console.php` | `backup:run` → `backup:production` |
| `app/Console/Commands/RunProductionBackupCommand.php` | **NOUVEAU** |
| `app/Database/PrivilegedCredentialLoader.php` | **NOUVEAU** |
| `app/Database/PrivilegedProcessRunner.php` | **NOUVEAU** |
| `app/Http/Controllers/Admin/BackupController.php` | Déléguer au runner |
| `app/Jobs/CreateBackupJob.php` | Déléguer au runner |
| `app/Providers/AppServiceProvider.php` | Boot guard runtime (recommandé) |
| `tests/Unit/Infrastructure/ProductionBackupRunnerTest.php` | **NOUVEAU** — mocks Process, pas de backup réel |
| `docs/preprod-recovery-runbook.md` | Documenter flux scheduler (optionnel) |

---

## FILES THAT MUST NOT BE MODIFIED

| Fichier / ressource | Raison |
|---------------------|--------|
| `.env` | Approbation humaine séparée |
| `.mysql-*.local` | Secrets — pas dans Git |
| `gestion` / `gestion_recovery` | Données / preuves |
| GRANT MySQL | Approbation DBA |
| `config/database-accounts.php` (policy) | Stable sauf décision humaine |
| `PrivilegedCommandGuard.php` | Comportement voulu — ne pas assouplir |
| Cron OVH | Phase déploiement post-implémentation |
| `php.ini` production | Phase OVH séparée |

---

## REQUIRED HUMAN APPROVALS

Avant **PRE-PROD 12.2 IMPLEMENTATION** :

```text
OUI — IMPLEMENT PRE-PROD 12.2 BACKUP SCHEDULER PROCESS-ONLY WIRING
```

Avant **premier backup scheduler sur OVH** (post-implémentation) :

```text
OUI — DEPLOY BACKUP PRODUCTION WRAPPER ON OVH
OUI — CONFIGURE OVH CRON schedule:run
```

Optionnel recommandé :

```text
OUI — ROTATE gestion_backup PASSWORD
OUI — CONFIGURE PHP CA BUNDLE ON OVH
OUI — ENABLE BACKUP_ALERT_MAIL
```

---

## NEXT PHASE — Commandes envisagées (NON EXÉCUTÉES)

```bash
# Après implémentation + déploiement secret sur OVH
php artisan backup:production --dry-run    # si implémenté
php artisan schedule:list
php artisan schedule:run --verbose         # test manuel fenêtre horaire
php artisan backup:status
php artisan db:safety-check
./vendor/bin/pest tests/Unit/Infrastructure/
```

**Interdit sans approbation :** `backup:run` direct, `backup:clean` destructif sur prod sans fenêtre, toute commande migrate/restore.

---

## TESTS PRÉVUS (implémentation)

1. Unit : runner charge secret sans l'exposer ; env subprocess contient `gestion_backup`
2. Unit : `backup:production` refuse si secret absent
3. Unit : subprocess command line ne contient pas `DB_PASSWORD`
4. Feature mock : scheduler enregistre `backup:production`
5. Regression : `AccountSeparationTest`, `BypassAssuranceTest` — 100% PASS
6. Manuel OVH : un `schedule:run` dans fenêtre 02:00 ou commande forcée avec approbation

---

```text
STATUS: WAITING FOR HUMAN APPROVAL
NEXT STEP: PRE-PROD 12.2 IMPLEMENTATION
```
