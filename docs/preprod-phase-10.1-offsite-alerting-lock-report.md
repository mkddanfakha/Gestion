# PRE-PROD 10.1 — Offsite + Alerting + Backup Lock Hardening

**Date :** 2026-08-26  
**Mode :** AUDIT + CONFIGURATION + HARDENING  
**Interdit / non exécuté :** backup:run, upload offsite, restore, migrate, seed, DROP, GRANT, REVOKE, 2ᵉ backup

---

```text
========================================
PRE-PROD 10.1
OFFSITE + ALERTING + BACKUP LOCK
========================================

FIRST PRODUCTION BACKUP:
VERIFIED

ARCHIVE:
2026-08-26-15-16-45.zip

SHA256:
3231554e80931843fc634dab269fc9606f47f2208750f9bddf9cce2eca1453dc

DATABASE:
gestion

DATABASE MODIFIED:
NO

GESTION_RECOVERY MODIFIED:
NO

RUNTIME ACCOUNT:
gestion_app

BACKUP ACCOUNT:
gestion_backup

RESTORE ACCOUNT:
gestion_restore

MIGRATION ACCOUNT:
gestion_migration

----------------------------------------
OFFSITE
----------------------------------------

OFFSITE:
NOT CONFIGURED

BUCKET:
MISSING

ENDPOINT:
MISSING

CREDENTIALS:
PRESENT (partial — key/secret set; never displayed)

UPLOAD TEST:
NOT EXECUTED

----------------------------------------
LOCK
----------------------------------------

MYSQL CACHE LOCK:
INCOMPATIBLE WITH BACKUP ACCOUNT

FILE LOCK:
PASS

CONCURRENCY:
PASS

STALE LOCK:
PASS (TTL expiry tested)

CRASH HANDLING:
PASS (finally releases) / NOT VERIFIED (hard process kill)

LOCK ARCHITECTURE:
BackupConcurrencyGuard uses dedicated store config('backup.lock_cache_store')
default = file (BACKUP_LOCK_CACHE_STORE). Independent of métier DB.
Multi-node: set shared store (redis) — never gestion_backup DB cache.

----------------------------------------
ALERTING
----------------------------------------

EMAIL:
NOT CONFIGURED (gate READY — BACKUP_ALERT_* unset)

BACKUP FAILURE:
PARTIAL (log always; mail when enabled)

STALE BACKUP:
PARTIAL (Spatie monitor MaximumAgeInDays=1; mail when enabled)

CHECKSUM FAILURE:
FAIL (no dedicated alert channel yet — verify is CLI/manual)

OFFSITE FAILURE:
FAIL (offsite not configured; no upload alert)

RESTORE FAILURE:
FAIL (no restore-failure notifier wired this phase)

SECRET LEAK:
PASS (notifications/logs must not embed secrets — policy)

----------------------------------------
SCHEDULER
----------------------------------------

CODE:
PASS (02:00 backup / 03:00 clean / 04:00 monitor, Africa/Dakar)

OS RUNNER:
NOT VERIFIED

----------------------------------------
RETENTION
----------------------------------------

30 DAILY:
PASS

12 WEEKLY:
PASS

12 MONTHLY:
PASS

----------------------------------------
SECURITY
----------------------------------------

SECRETS IN GIT:
NO

ENV EXPOSED:
NO

BACKUP PUBLIC:
NO (local serve=false; s3 visibility=private when used)

BACKUP ACCOUNT LEAST PRIVILEGE:
PASS

----------------------------------------
DATABASE SAFETY
----------------------------------------

gestion:
UNCHANGED (38 tables, 0 métier, 75 migrations)

gestion_recovery:
UNCHANGED (27 tables)

DATABASE DESTRUCTIVE OPERATIONS:
BLOCKED

----------------------------------------
TESTS
----------------------------------------

PEST:
110 PASSED (Infrastructure)

BUILD:
NOT REQUIRED (no frontend change)

----------------------------------------
STATUS
----------------------------------------

READY WITH CONDITIONS
========================================
```

---

## 1. Baseline READ-ONLY

| Check | Résultat |
|-------|----------|
| `gestion` | PRESENT — 38 tables |
| migrations | 75 |
| business data | 0 |
| `gestion_recovery` | PRESENT — 27 — inchangée |
| runtime | `gestion_app` |
| `gestion_migration` DROP | **ABSENT** |
| `gestion_app` DDL | **ABSENT** |
| `gestion_backup` DML/DDL write | **ABSENT** (SELECT, LOCK, SHOW VIEW, TRIGGER) |
| `gestion_restore` → `gestion` | **aucun grant** |

**Anomalie :** aucune → suite autorisée.

---

## 2. First verified production backup

| Check | Résultat |
|-------|----------|
| Path | `storage/app/private/Gestion/2026-08-26-15-16-45.zip` |
| Exists | YES |
| SHA-256 match | YES |
| Size | 7577 |
| ZIP / SQL | YES |
| CREATE TABLE | 38 |
| business inserts | 0 |
| `.env` / secret markers | NO |
| Archive deleted? | **NO** |

```text
FIRST VERIFIED PRODUCTION BACKUP: CONFIRMED
```

---

## 3–4. Offsite

| Item | Status |
|------|--------|
| AWS key/secret | PRESENT (masked) |
| AWS_BUCKET | **MISSING** (empty) |
| AWS_ENDPOINT | **MISSING** |
| BACKUP_DISKS includes s3 | NO |
| Upload | **NOT EXECUTED** |

Config code déjà prête (`BACKUP_DISKS`, disque `s3` privé). **Ne pas uploader** tant que bucket/endpoint non fournis par l’humain.

Procédure de test offsite (préparée, **non exécutée**) :

1. checksum archive locale  
2. upload → disque `s3`  
3. existence + taille  
4. download copie temp  
5. checksum identique  
6. ZIP OK  
7. supprimer **uniquement** la copie temp (pas l’archive locale)

Approbation requise plus tard : `OUI — TEST OFFSITE BACKUP` — **seulement si OFFSITE CONFIGURED**.

---

## 5. Lock hardening (appliqué)

### Diagnostic

| Mode | Résultat |
|------|----------|
| `CACHE_STORE=database` + `gestion_backup` | INSERT `cache` **refusé** |
| `CACHE_STORE=file` process-only (26/08) | backup **OK** |

### Solution retenue (A — simple)

`BackupConcurrencyGuard` utilise **toujours** `Cache::store(config('backup.lock_cache_store', 'file'))`.

- `.env.example` : `BACKUP_LOCK_CACHE_STORE=file`
- Pas de droits INSERT ajoutés à `gestion_backup`
- Multi-serveur : documenter `redis` plus tard

### Analyse risques

| Risque | Évaluation |
|--------|------------|
| 2 processus même serveur | **bloqués** (file lock + TTL) — PASS tests |
| 2 machines | file lock **non partagé** → utiliser redis |
| Expiration / stale | TTL 7200s backup / 1800s restore — test TTL 1s PASS |
| Crash soft (exception) | `finally` release — PASS |
| Kill -9 | lock expire au TTL — NOT VERIFIED hard-kill |
| Persistance | fichier sous `storage/framework/cache/data` |

---

## 6. Concurrency tests

Pest étendu (`BackupStrategyTest`) : acquire double, release, exception, TTL, store=file malgré `cache.default=database`, checksum archive réelle.

```text
PEST Infrastructure: 110 passed
```

Aucun second backup réel de `gestion`.

---

## 7. Alerting

| Capacité | État |
|----------|------|
| `BackupAlertChannels` | prêt |
| `BACKUP_ALERT_MAIL_ENABLED` / `EMAIL` | **non définis** dans `.env` |
| SMTP local | présent (non testé / non affiché) |
| via() effectif | **[]** jusqu’à activation humaine |
| Checksum / offsite / restore alerts | **gaps** (CLI verify only) |

---

## 8–9. Monitoring & planification

| Item | Valeur |
|------|--------|
| `backup:status` / `backup:verify` | présents |
| MaximumAgeInDays | 1 (RPO ≤ 24h ciblé) |
| Retention 30/12/12 | PASS config |
| Cleanup exécuté cette phase | **NO** |
| Scheduler OS | **NOT VERIFIED** (0 tâche artisan) |

---

## 11. Secret leak incident

Scan terminaux Cursor (patterns `-p…`, `IDENTIFIED BY '…'`, `DB_PASSWORD=` valeur) :

```text
SECRET FOUND (pattern hits in historical terminal logs): YES
terminal_files_with_possible_secret_patterns=3
```

**Aucune valeur recopiée ici.**

```text
ROTATION REQUIRED
(recommandé pour mots de passe MySQL / secrets éventuellement exposés en CLI historique)
```

Approbation séparée requise avant toute rotation.

---

## 12. Git

| Item | Status |
|------|--------|
| `.env` ignored | YES |
| `.mysql-*.local` | YES |
| `storage/app/private/*` (ZIP) | YES |
| `storage/logs/*` | YES |
| `/*.sql` ajouté 10.1 | YES |
| Commit auto | **NO** |

---

## 13–14. Tests / Build

- Pest Infrastructure : **110 passed**
- Vite build : **non nécessaire**

---

## STATUS

```text
READY WITH CONDITIONS

Conditions:
1. OFFSITE bucket + endpoint manquants
2. ALERTING mail non activé dans .env
3. SCHEDULER OS non vérifié
4. ROTATION REQUIRED (secrets CLI historiques)
5. Gaps alertes checksum/offsite/restore
```

---

## HUMAN GATES — STOP

### Offsite

```text
HUMAN ACTION REQUIRED
OFFSITE CONFIGURATION INCOMPLETE

Compléter dans .env (hors chat) :
AWS_BUCKET=...
AWS_ENDPOINT=...
BACKUP_DISKS=local,s3

Puis seulement, si prêt :
OUI — TEST OFFSITE BACKUP
```

**Ne pas proposer d’upload tant que OFFSITE ≠ CONFIGURED.**

### Autres (non autorisés par cette phase)

```text
OUI — CREATE gestion_test FOR RESTORE DRILL
OUI — ROTATE MYSQL SECRETS
```

### Non exécuté

```text
UPLOAD OFFSITE: NO
SECOND BACKUP: NO
RESTORE: NO
gestion / gestion_recovery: UNCHANGED
```
