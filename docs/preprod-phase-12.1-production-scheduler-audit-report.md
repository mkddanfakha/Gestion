# PRE-PROD 12.1 — Production Server Scheduler Audit

**Date :** 2026-08-27  
**Mode :** **AUDIT ONLY** — aucune modification serveur / `.env` / MySQL / cron / backup  
**Périmètre production visé :** serveur **OVH Linux** (SSH)  
**Périmètre local :** WAMP Windows = **développement uniquement — NOT RELEVANT TO PRODUCTION**

---

## Résumé exécutif

Le **code Laravel** du scheduler est **prêt** (horaires Africa/Dakar, `withoutOverlapping`, tâches backup/clean/monitor + notifications). L'**audit serveur OVH production** n'a **pas pu être exécuté** depuis cet environnement : **aucun accès SSH** au serveur OVH n'est disponible (pas d'hôte/credentials de production dans le dépôt — placeholders `user@votre-serveur` uniquement).

**Point critique code/ops :** `Schedule::command('backup:run')` s'exécute avec le `.env` runtime (`gestion_app`). `PrivilegedCommandGuard` **bloque** `backup:run` pour `gestion_app`. Un cron standard `* * * * * php artisan schedule:run` **échouera sur les backups** sans wrapper process-only `gestion_backup` (comme validé manuellement en PRE-PROD 10).

**Verdict :** `PRODUCTION SCHEDULER NOT READY` (serveur OVH non audité + wiring backup cron incomplet)

---

```text
========================================
MKD-PRO PRE-PROD 12.1
PRODUCTION SERVER SCHEDULER AUDIT
=================================

ENVIRONMENT (OVH PRODUCTION):
OS: UNKNOWN (SSH not available from audit environment)
PHP: UNKNOWN on production server
PHP PATH: UNKNOWN on production server
PROJECT PATH: UNKNOWN on production server
LARAVEL: codebase present locally; production path UNKNOWN

TIMEZONE SERVER: UNKNOWN (production)
TIMEZONE PHP: UNKNOWN (production)
TIMEZONE LARAVEL: Africa/Dakar (config/app.php — applies when app boots)

SSH:
NO (not connected — no production host/credentials configured for audit)

SCHEDULER TYPE:
NONE / UNKNOWN on OVH (not inspectable from here)

SCHEDULE:RUN CONFIGURED (OVH):
NO / UNKNOWN

SCHEDULE:RUN EXECUTION:
NOT EXECUTED

WINDOWS TASK SCHEDULER:
NOT RELEVANT TO PRODUCTION

--- LARAVEL CODE READINESS (repository audit) ---

BACKUP SCHEDULE:
WARNING (02:00 defined; requires gestion_backup process-only at runtime)

OFFSITE SCHEDULE:
WARNING (same backup:run path; BACKUP_DISKS=local,s3 in local .env)

CLEANUP SCHEDULE:
PASS (03:00 backup:clean + withoutOverlapping 120)

MONITORING:
PASS (04:00 backup:monitor + withoutOverlapping 60)

ALERTING:
FAIL (BACKUP_ALERT_* NOT_SET in local .env — production UNKNOWN)

WITHOUT OVERLAPPING:
PASS (backup/clean/monitor)

PHP CLI (local dev reference only):
PHP 8.4.0 — NOT production evidence

PHP EXTENSIONS (local dev reference):
PASS (pdo_mysql, zip, openssl, curl, mbstring, json, fileinfo)

CA (local dev reference):
FAIL (curl.cainfo / openssl.cafile empty in php.ini)
NOTE: production OVH CA — UNKNOWN

STORAGE PERMISSIONS (OVH):
UNKNOWN

SECRET HYGIENE:
WARNING (Git ignores OK; log pattern scan found potential secret markers in local laravel.log — ROTATION REQUIRED)

DATABASE ACCOUNT SEPARATION:
PASS (policy + guards verified in code)

GESTION:
UNCHANGED (38 tables, 75 migrations, 0 métier rows)

GESTION_RECOVERY:
UNCHANGED (27 tables)

GESTION_TEST:
ABSENT

MYSQL:
UNCHANGED

ENV:
UNCHANGED (audit did not modify .env)

BACKUP:
NOT EXECUTED

RESTORE:
NOT EXECUTED

MIGRATE:
NOT EXECUTED

SEED:
NOT EXECUTED

FILES MODIFIED:
REPORT ONLY

GIT:
REPORT ONLY (not committed)

FINAL VERDICT:
PRODUCTION SCHEDULER NOT READY

========================================
```

---

## Étape 1 — Environnement serveur OVH

| Item | Résultat |
|------|----------|
| SSH vers OVH | **NO** — audit exécuté depuis poste dev Windows ; aucune connexion SSH établie |
| `uname -a` / `/etc/os-release` | **NON EXÉCUTÉ** (pas d'accès serveur) |
| Chemin projet production | **UNKNOWN** — `DEPLOYMENT_CHECKLIST.md` utilise placeholder `/path/to/your/app` |
| PHP production | **UNKNOWN** |

**Documentation existante :** guides déploiement (`DEPLOYMENT_CHECKLIST.md`, `app/Modules/NotificationCenter/PRODUCTION.md`) mentionnent cron `* * * * * php artisan schedule:run` mais **aucun crontab versionné** ni script `deploy.sh` avec cron installé.

---

## Étape 2 — Scheduler Laravel (code — PASS WITH WARNING)

**Fichier :** `routes/console.php`

| Commande | Horaire | Overlap | Timezone |
|----------|---------|---------|----------|
| `backup:run` | 02:00 daily | withoutOverlapping(180) | Africa/Dakar (app) |
| `backup:clean` | 03:00 daily | withoutOverlapping(120) | Africa/Dakar |
| `backup:monitor` | 04:00 daily | withoutOverlapping(60) | Africa/Dakar |
| `notifications:optimize-tables` | 03:00 | — | Africa/Dakar |
| `notifications:archive-resolved` | 03:30 | — | Africa/Dakar |
| `notifications:delete-archived` | monthly 1st 04:00 | — | Africa/Dakar |
| `notifications:cleanup-orphans` | 04:30 | — | Africa/Dakar |
| `notifications:cleanup` | 05:00 | — | Africa/Dakar |

**Horaires Sénégal :** `config/app.php` → `Africa/Dakar` — les jobs 02:00/03:00/04:00 correspondent bien à l'intention documentée.

**Compte backup :** `Schedule::command('backup:run')` n'embarque **pas** de switch vers `gestion_backup`. `PrivilegedCommandGuard` mappe `backup:run` → `OPERATION_BACKUP` → refus si `DB_USERNAME=gestion_app`.

**Lock backup :** `BackupConcurrencyGuard` → store `file` par défaut (`config/backup.php` `lock_cache_store`). Indépendant du cache DB — **PASS** pour `gestion_backup` une fois le compte activé.

**Commandes dangereuses planifiées :** aucune (`migrate:fresh`, `db:wipe`, `restore` absents du scheduler).

---

## Étape 3–4 — Planification OS OVH (Cron / systemd)

| Inspection | Résultat |
|------------|----------|
| `crontab -l` (utilisateur deploy) | **UNKNOWN** — SSH indisponible |
| `/etc/cron.d/` | **UNKNOWN** |
| `systemctl list-timers` | **UNKNOWN** |
| Références repo `schedule:run` | Documentation seulement (`PRODUCTION.md`, checklists) — **NOT CONFIGURED** dans le dépôt |

```text
SCHEDULE:RUN CONFIGURED (production OVH): NO / UNKNOWN
```

---

## Étape 5 — PHP CLI production

**Non vérifiable** sans SSH.

Référence locale (dev uniquement) :
- `composer.json` exige `php: ^8.2`
- Extensions présentes localement : pdo_mysql, zip, openssl, curl, mbstring, json, fileinfo

---

## Étape 6 — Contexte d'exécution OVH

| Item | Statut |
|------|--------|
| Utilisateur cron prévu | **UNKNOWN** (recommandation : utilisateur deploy non-root) |
| Permissions `storage/` / `bootstrap/cache/` | **UNKNOWN** |
| `schedule:run` sur production | **NOT EXECUTED** (interdit par cette phase) |

---

## Étape 7 — Timezone

| Couche | Valeur auditée |
|--------|----------------|
| Laravel | **Africa/Dakar** (config) |
| Serveur OVH | **UNKNOWN** |
| PHP CLI OVH | **UNKNOWN** |

**Risque :** si le timezone OS/PHP du serveur OVH ≠ Africa/Dakar et que Laravel n'est pas bootstrappé correctement, décalage possible. Laravel Schedule utilise la timezone de l'application — **PASS côté code** si `config/app.php` inchangé en prod.

---

## Étape 8 — Sécurité scheduler

| Contrôle | Résultat |
|----------|----------|
| Runtime ≠ root | **PASS** (policy `gestion_app`; local safety check Uses root: NO) |
| backup:run ≠ gestion_app | **PASS** (guard bloque — mais implique wrapper requis pour cron) |
| Secrets en arguments CLI | **PASS** (aucun mot de passe dans `routes/console.php`) |
| PrivilegedCommandGuard | **PASS** (actif sur backup:run, migrate mysql) |
| DatabaseAccountGuard | **PASS** |

---

## Étape 9 — Backups planifiés (config locale RO)

| Variable | État (local .env, secrets non affichés) |
|----------|----------------------------------------|
| `BACKUP_DISKS` | CONFIGURED (`local,s3`) |
| `AWS_USE_PATH_STYLE_ENDPOINT` | CONFIGURED (`true`) |
| `BACKUP_LOCK_CACHE_STORE` | NOT_SET → default `file` OK |

Production OVH `.env` : **UNKNOWN** (non audité sur serveur).

---

## Étape 10 — CA PHP

| Environnement | curl.cainfo | openssl.cafile |
|---------------|-------------|----------------|
| Local WAMP (dev) | empty | empty |
| OVH production | **UNKNOWN** |

`storage/app/private/cacert.pem` existe localement (gitignored) — **non preuve production**.

```text
CA STATUS (production): UNKNOWN
HUMAN ACTION REQUIRED if absent on OVH PHP CLI
```

---

## Étape 11 — Alerting

| Item | État (local) |
|------|--------------|
| `BACKUP_ALERT_MAIL_ENABLED` | NOT_SET |
| `BACKUP_ALERT_EMAIL` | NOT_SET |
| `BackupAlertChannels` | gate mail inactive |

Production OVH : **UNKNOWN**. Comportement si échec backup/R2 : logs + event Spatie ; mail **non envoyé** sans config.

---

## Étape 12 — Logs

| Item | Résultat |
|------|----------|
| `storage/logs/laravel.log` | présent (~16 Mo local) |
| Scan patterns secrets | matches détectés (count only — **ROTATION REQUIRED**) |
| Rotation | non auditée sur OVH |

Aucun log supprimé. Aucun secret affiché.

---

## Étape 13 — Déploiement production (local .env RO)

| Variable | Valeur observée |
|----------|-----------------|
| `APP_ENV` | local (≠ production) |
| `APP_DEBUG` | true (≠ production recommandé) |
| `APP_URL` | https://192.168.1.24 (LAN dev) |
| `vendor/` | present |
| `public/build/` | present (build récent) |

Écarts production attendus — **HUMAN ACTION** avant cutover.

---

## Étape 14 — Bases (local RO)

| Base | Statut |
|------|--------|
| `gestion` | PRESENT · 38 tables · 75 migrations · 0 métier |
| `gestion_recovery` | PRESENT · 27 tables · archive nov. 2025 |
| `gestion_test` | **ABSENT** |

Comptes : `gestion_app` runtime · `gestion_backup` backup · `gestion_restore` restore · `gestion_migration` migration · root DBA.

---

## Étape 15 — Tests

Aucun test destructif exécuté. Aucun `schedule:run` / `backup:run` lancé.

---

## Critical findings

| # | Finding | Impact |
|---|---------|--------|
| 1 | **SSH OVH non disponible** | Impossible de confirmer cron/systemd/PHP/CA/permissions production | P0 |
| 2 | **Aucun cron `schedule:run` prouvé sur OVH** | Backups/monitoring ne tourneront pas seuls | P0 |
| 3 | **`backup:run` bloqué pour `gestion_app`** | Cron standard échoue sur backup sans wrapper `gestion_backup` | P0 |
| 4 | **Alerting backup non configuré** (local) | Échecs silencieux côté mail | P1 |
| 5 | **CA PHP production UNKNOWN** | Risque échec upload R2 depuis cron | P1 |

---

## HUMAN APPROVAL REQUIRED (actions — aucune exécutée)

### A — Accès audit serveur OVH

```text
ACTION: Fournir accès SSH read-only (ou exécuter les commandes d'inspection sur OVH)
REASON: Valider crontab, PHP CLI, CA, permissions, chemin projet
IMPACT: Aucune modification
NO ACTION EXECUTED
```

### B — Installer cron production

```text
ACTION: Ajouter crontab utilisateur deploy sur OVH, ex.:
  * * * * * cd /path/to/gestion && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
REASON: Laravel scheduler requires OS runner
IMPACT: Exécution automatique des tâches planifiées
NO ACTION EXECUTED
```

### C — Wrapper backup process-only

```text
ACTION: Créer script sécurisé (hors Git) chargeant gestion_backup + CACHE_STORE=file + CA bundle
        avant backup:run, OU adapter le scheduler Laravel
REASON: PrivilegedCommandGuard refuse backup:run avec gestion_app
IMPACT: Backups planifiés fonctionnels
NO ACTION EXECUTED
```

### D — CA permanent PHP CLI OVH

```text
ACTION: Configurer curl.cainfo / openssl.cafile dans php.ini CLI
REASON: Upload/monitoring R2 sans cURL 60
IMPACT: Offsite fiable depuis cron
NO ACTION EXECUTED
```

### E — Alerting

```text
ACTION: Configurer BACKUP_ALERT_* + SMTP sur production .env
REASON: Notification des échecs backup/R2
NO ACTION EXECUTED
```

---

## Windows / WAMP

```text
WINDOWS TASK SCHEDULER: NOT RELEVANT TO PRODUCTION
Local WAMP used for development only — excluded from production verdict
```

---

**STOP — AUDIT COMPLETE. No server configuration executed.**
