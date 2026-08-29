# PRE-PROD 12 — Production Cutover Readiness / Final Safety Gate

**Date :** 2026-08-27  
**Mode :** **AUDIT ONLY** — aucune modification DB / `.env` / backup / restore / GRANT / commit  
**Environnement :** WAMP local · PHP 8.4.0 · MySQL `127.0.0.1:3306` · branche `main`

---

## Executive summary

MKD-Pro présente une **surface de sécurité database/backup/restore mature** (PRE-PROD 1–11). Les garde-fous Laravel, la séparation des comptes MySQL et le restore drill sont **vérifiés**. Des **conditions opérationnelles** empêchent un cutover production **sans actions humaines** : scheduler OS absent, alerting mail non activé, bundle CA PHP non permanent, rotation secrets due, dépôt Git non stabilisé.

**Verdict final :** `PRODUCTION READY WITH CONDITIONS`

---

## Baseline sécurité (début / fin audit)

| Métrique | Début | Fin | Match |
|----------|-------|-----|-------|
| `gestion` tables | 38 | 38 | YES |
| migrations | 75 | 75 | YES |
| users | 0 | 0 | YES |
| companies | 0 | 0 | YES |
| customers | 0 | 0 | YES |
| products | 0 | 0 | YES |
| sales / quotes / expenses / suppliers | 0 | 0 | YES |
| `gestion_recovery` | PRESENT (27 tables) | PRESENT (27 tables) | YES |
| `gestion_test` | ABSENT | ABSENT | YES |

```text
GESTION UNCHANGED: YES
AUDIT MODIFIED gestion: NO
```

---

## Checklist cutover

| Gate | Status | Evidence | Risk |
|------|--------|----------|------|
| Runtime least privilege | **PASS** | `gestion_app` CRUD on `gestion.*` only; `db:safety-check` LIKELY LP | Low |
| Root excluded from runtime | **PASS** | `DB_USERNAME=gestion_app`; safety check Uses root: NO | Low |
| Migration account | **PASS** | CREATE/ALTER/INDEX; **DROP absent**; probe DROP DATABASE gestion=DENIED | Low |
| Backup account | **PASS** | SELECT/LOCK TABLES/SHOW VIEW/TRIGGER on `gestion.*`; no DML | Low |
| Restore account | **PASS** | `gestion_recovery.*` + `gestion_test.*` only; probe gestion=DENIED | Low |
| Database destructive guards | **PASS** | migrate:fresh/wipe blocked; Pest 111 infra | Low |
| Restore DB-only | **PASS** | `DatabaseRestoreService`; files not touched | Low |
| Restore target allow-list | **PASS** | gestion BLOCKED; RESTORE phrase required | Low |
| Local backup | **PASS** | Latest `2026-08-26-21-30-29.zip` SHA valid, ZIP valid | Low |
| Offsite R2 (evidence) | **PASS** | R2 object exists; SHA match local (RO verify with CA bundle) | Low |
| Automated offsite wiring | **PASS WITH WARNING** | `BACKUP_DISKS=local,s3`; path-style true; CA required for CLI | Medium |
| Backup integrity | **PASS** | SHA-256, SCHEMA_ONLY, `.env` absent (latest prod backups) | Low |
| Restore drill | **PASS WITH WARNING** | PRE-PROD 11 VERIFIED; bootstrap/LOCK TABLES lessons | Medium |
| Scheduler Laravel | **PASS** | 02:00/03:00/04:00 Africa/Dakar + withoutOverlapping | Low |
| Scheduler OS | **NOT VERIFIED** | No Windows task for `schedule:run` found | **High** |
| Alerting | **FAIL** | `BACKUP_ALERT_*` NOT_SET; mail channel inactive | **High** |
| SSL / CA | **PASS WITH WARNING** | SSL enabled; `curl.cainfo`/`openssl.cafile` empty in php.ini; process CA present | Medium |
| Secret hygiene (Git) | **PASS** | `.env`, `.mysql-*`, private backups gitignored | Low |
| Secret rotation | **BLOCKER** (ops) | Historical CLI traces → ROTATION REQUIRED (documented) | **High** |
| RPO | **PASS** | Latest backup ~0.8h age; threshold 24h | Low |
| RTO | **NOT VERIFIED** | Restore drill duration ~4s (schema-only); no formal RTO test with data | Medium |
| Pest | **PASS** | 111/111 infrastructure | Low |
| Build | **PASS** | `npm run build` OK (~2m23s) | Low |
| Git hygiene | **PASS WITH WARNING** | Many modified/untracked safety files; no secrets in index | Medium |
| `gestion` unchanged | **PASS** | Baseline match end-to-end | — |

---

## MySQL accounts (SHOW GRANTS + probes)

### gestion_app — PASS
- SELECT, INSERT, UPDATE, DELETE on `gestion.*` (localhost + 127.0.0.1)
- No DROP/ALTER/CREATE/LOCK TABLES/GRANT OPTION

### gestion_backup — PASS
- SELECT, LOCK TABLES, SHOW VIEW, TRIGGER on `gestion.*`
- No INSERT/UPDATE/DELETE/DDL

### gestion_restore — PASS
- DDL+DML on `gestion_recovery.*` and `gestion_test.*` only
- Probes: DROP DATABASE gestion **DENIED**; SELECT gestion **DENIED**
- No access to `gestion.*`

### gestion_migration — PASS
- CRUD + CREATE/ALTER/INDEX/REFERENCES on `gestion.*`
- **DROP absent** on `gestion.*`
- Probe DROP DATABASE gestion **DENIED**

### root — DBA only
- Present; not used by Laravel runtime

---

## Database safety guards — PASS

`php artisan db:safety-check`:
- STATUS: **PROTECTED**
- Destructive migrations: **BLOCKED**
- Restore to gestion: **BLOCKED**
- Allow-list: gestion_recovery, gestion_test
- Pest: migrate:fresh/refresh/wipe/seed/restore-to-gestion blocked (111 tests)

---

## Restore safety — PASS

- Explicit `--target` required
- Confirmation `RESTORE` required
- `--force` does not bypass guards
- Dump neutralization: USE/CREATE DATABASE/DROP DATABASE stripped; DROP DATABASE gestion refused; LOCK TABLES stripped (PRE-PROD 11 fix)
- DB-only: ApplicationFilesRestoreService separated

---

## Backup system — PASS WITH WARNING

| Item | State |
|------|-------|
| Spatie DB backup | CONFIGURED |
| `BACKUP_DISKS` | `local,s3` |
| Latest archive | `2026-08-26-21-30-29.zip` |
| SHA-256 | `0f08190a3ad1df82feaa8c5c35005cba764012fc89e5e412f79db50401930f32` |
| Verdict | SCHEMA_ONLY · 38 CREATE · 0 business INSERTs · `.env` ABSENT |
| Lock store | `file` (default; `BACKUP_LOCK_CACHE_STORE` env NOT_SET → default OK) |
| Retention | 30 / 30 daily / 12 weekly / 12 monthly |
| Nov 2025 archives | Still local; verdict CAUTION_CONTAINS_DOTENV (historical) |

**Warning :** `backup:status` reports `backup_offsite=FAILED` in default CLI (no CA in php.ini) while R2 object **exists** when verified with `storage/app/private/cacert.pem`. Scheduled uploads may fail without permanent CA config.

---

## Offsite R2 — PASS (evidence)

- Bucket: SET · Endpoint: SET · Path-style: true
- R2 key `Gestion/2026-08-26-21-30-29.zip`: EXISTS, 7577 o
- SHA local = SHA R2 (verified RO during audit)
- `verify=false`: **never used**

---

## Restore drill evidence — PASS WITH WARNING

Per [`docs/preprod-phase-11-restore-drill-report.md`](preprod-phase-11-restore-drill-report.md):
- Backup → `gestion_test` → 38 tables / 75 migrations
- gestion + gestion_recovery unchanged; `.env` unchanged
- Cleanup: gestion_test deleted
- Warnings: process-only bootstrap env; LOCK TABLES strip required

---

## gestion_recovery — PRESERVED

- 27 tables · drill novembre 2025 (users=4, customers=7)
- **Not** August 2026 recovery · **not modified** during audit
- Archive historique conservée

---

## Data loss closure — PASS (documentation)

[`docs/preprod-data-loss-closure.md`](preprod-data-loss-closure.md) documents:
- August 2026 data loss · no complete dump · binlogs OFF
- Partial exports only · human decision 9.4 clean start
- gestion_recovery = Nov 2025 drill, distinct

---

## Scheduler

**Laravel — PASS**
- `backup:run` 02:00 · `backup:clean` 03:00 · `backup:monitor` 04:00
- Timezone: Africa/Dakar · withoutOverlapping enabled

**OS — NOT VERIFIED**
- No Windows Scheduled Task running `php artisan schedule:run` detected

---

## Alerting — FAIL (not configured)

- `BACKUP_ALERT_MAIL_ENABLED`: NOT_SET
- `BACKUP_ALERT_EMAIL`: NOT_SET
- `BackupAlertChannels`: gate prevents spam (good) but **no real channel**
- No test email sent (audit-only)

---

## PHP / SSL — PASS WITH WARNING

```
php.ini curl.cainfo: (empty)
php.ini openssl.cafile: (empty)
storage/app/private/cacert.pem: PRESENT (gitignored)
```

Permanent php.ini CA configuration **not verified**.

---

## Secret hygiene

| Item | Status |
|------|--------|
| `.env` in Git | ABSENT (ignored) |
| `.mysql-*.local` in Git | ABSENT (ignored) |
| SQL dumps / backup ZIPs in Git | ABSENT (ignored) |
| Historical CLI exposure | **ROTATION REQUIRED** |

---

## Application — PASS (non-destructive)

- Laravel connects as `gestion_app` to `gestion`
- `migrate:status`: 75 migrations Ran
- Dashboard route registered
- No seed/migrate/write performed

---

## Tests & build

- **Pest infrastructure:** 111/111 PASS
- **Build:** PASS (`npm run build`, ~2m23s, assets generated under `public/build/`)

---

## Git — PASS WITH WARNING

- Branch: `main`
- Status: **MODIFIED** (extensive PRE-PROD safety work uncommitted)
- No secrets in tracked files
- Build regenerated many `public/build/assets/*` (expected after build)

---

## Critical blockers

| # | Blocker | Impact | Priority |
|---|---------|--------|----------|
| 1 | **OS scheduler NOT VERIFIED** | Daily backup/monitor will not run unattended | P0 |
| 2 | **Alerting NOT CONFIGURED** | Backup/R2 failures invisible to ops | P0 |
| 3 | **SECRET ROTATION REQUIRED** | Historical credential exposure | P0 |
| 4 | **PHP CA not in php.ini** | R2 upload/monitoring may fail from CLI/cron | P1 |

---

## Remaining risks

1. Empty métier database (0 rows) — operational, not a safety failure
2. `APP_ENV=local` — production env not finalized
3. Old Nov 2025 local ZIPs contain `.env` — retention/cleanup policy
4. `backup:status` offsite probe false-negative without CA in default PHP
5. RTO not formally measured with realistic data volume
6. Large uncommitted diff — release process needed before deploy

---

## HUMAN ACTIONS REQUIRED

### 1. OS Scheduler (P0)
Install Windows Task Scheduler job (every minute):
`php artisan schedule:run` with correct PHP path, project cwd, and CA env if needed.
**Approval:** separate ops decision.

### 2. Alerting mail (P0)
Set in `.env` (hors chat):
`BACKUP_ALERT_MAIL_ENABLED=true` + valid `BACKUP_ALERT_EMAIL` + working SMTP.
**Approval:** separate; no test mail without approval.

### 3. Secret rotation (P0)
Rotate R2 keys, MySQL service passwords, any exposed SMTP creds per ROTATION REQUIRED policy.
**Approval:** explicit human procedure.

### 4. PHP CA permanent (P1)
Set `curl.cainfo` and `openssl.cafile` in WAMP `php.ini` → Mozilla bundle path.
No automatic php.ini edit in this audit.

### 5. Production cutover env (P1)
Prepare production `.env`: `APP_ENV=production`, HTTPS URL, queue/mail, retain `gestion_app`.
**Approval:** cutover checklist separate.

### 6. Git release (P1)
Review, commit, and tag PRE-PROD 1–12 safety stack before production deploy.
**Approval:** explicit commit request.

---

## Format final obligatoire

```text
========================================
MKD-PRO PRE-PROD 12
PRODUCTION CUTOVER READINESS
FINAL SAFETY GATE
========================================

DATABASE SAFETY:
PASS (PROTECTED — fail-closed guards active)

RUNTIME LEAST PRIVILEGE:
PASS (gestion_app CRUD only)

MYSQL ACCOUNTS:
PASS (separation verified; DROP revoked on migration)

BACKUP:
PASS (local + Spatie; latest SHA valid)

OFFSITE:
PASS (R2 evidence; SHA match verified RO)

RESTORE:
PASS (DB-only; allow-list; gestion blocked)

RESTORE DRILL:
PASS WITH WARNING (PRE-PROD 11 verified)

SCHEDULER:
Laravel PASS / OS NOT VERIFIED

ALERTING:
FAIL (not configured)

SECRET HYGIENE:
PASS WITH WARNING (Git clean; ROTATION REQUIRED)

RPO:
PASS (≤ 24h)

RTO:
NOT VERIFIED

PEST:
111/111 PASS

BUILD:
PASS

GIT:
PASS WITH WARNING (modified, no secrets tracked)

GESTION BASELINE:
38 tables / 75 migrations / 0 métier rows

GESTION FINAL:
IDENTICAL

GESTION UNCHANGED:
YES

CRITICAL BLOCKERS:
- OS scheduler NOT VERIFIED
- Alerting NOT CONFIGURED
- SECRET ROTATION REQUIRED
- PHP CA not permanent in php.ini

REMAINING RISKS:
- Empty métier DB; APP_ENV=local; old Nov ZIPs with .env; uncommitted safety stack

HUMAN ACTIONS REQUIRED:
- Install OS schedule:run task
- Enable BACKUP_ALERT_*
- Rotate secrets
- Fix php.ini CA
- Production .env cutover
- Git commit/release

FINAL VERDICT:
PRODUCTION READY WITH CONDITIONS

========================================
```

**STOP — AUDIT COMPLETE. No corrective actions executed.**
