# PRE-PROD 9.2 — Application Database Cutover Report

**Date :** 2026-08-24  
**Mode :** CONTROLLED CUTOVER — configuration Laravel uniquement  
**Database modification :** FORBIDDEN (respected)  
**Schema modification :** FORBIDDEN (respected)

---

```text
========================================
MKD-PRO PRE-PROD 9.2
APPLICATION DATABASE CUTOVER
========================================

ROOT CAUSE:
IDENTIFIED
(Laravel runtime used MySQL root with global privileges)

OLD RUNTIME USER:
root

NEW RUNTIME USER:
gestion_app

DATABASE:
gestion

HOST:
127.0.0.1

CUTOVER:
PASS

LARAVEL CONNECTION:
PASS

CRUD PRIVILEGES:
VERIFIED
(SELECT, INSERT, UPDATE, DELETE ON gestion.* — SHOW GRANTS)

DESTRUCTIVE PRIVILEGES:
DENIED
(verified via SHOW GRANTS; no DROP/ALTER/CREATE/GRANT OPTION)

DROP DATABASE:
DENIED
(absent from grants; not tested against gestion)

ALTER TABLE:
DENIED
(absent from grants; not tested against gestion)

CREATE DATABASE:
DENIED
(absent from grants)

CREATE USER:
DENIED
(absent from grants)

GRANT OPTION:
ABSENT

READ-ONLY SMOKE TESTS:
PASS
(admin actingAs; HTTP 200; SQL_ERRORS: NONE)

DATABASE BASELINE:
users=3 customers=0 products=0 categories=0
sales=0 sale_items=0 quotes=0 quote_items=0
expenses=0 suppliers=0
purchase_orders=0 purchase_order_items=0
delivery_notes=0 delivery_note_items=0
inventory_sessions=0 inventory_items=0
stock_movements=0 companies=1

DATABASE FINAL:
users=3 customers=0 products=0 categories=0
sales=0 sale_items=0 quotes=0 quote_items=0
expenses=0 suppliers=0
purchase_orders=0 purchase_order_items=0
delivery_notes=0 delivery_note_items=0
inventory_sessions=0 inventory_items=0
stock_movements=0 companies=1

GESTION UNCHANGED:
YES

APPLICATION FILES:
UNCHANGED (no restore / no file overwrite)

SECRETS:
PASSWORD NOT IN GIT
.env gitignored
.mysql-gestion-app.local gitignored
.mysql-cutover-rollback.local gitignored
password never written to docs

GIT:
.env not tracked
no automatic commit
.gitignore updated for rollback snapshot file

ROLLBACK:
AVAILABLE
(.mysql-cutover-rollback.local contains prior DB_USERNAME/DB_PASSWORD)

TESTS:
ApplicationDatabaseUserTest — PASS (9 passed)
db:safety-check — PASS (MySQL user gestion_app, Uses root: NO)

BUILD:
NOT RUN (out of scope for cutover)

PRODUCTION STATUS:
READY WITH CONDITIONS

REMAINING RISKS:
- Offsite backup NOT CONFIGURED
- Backup account SEPARATE / NOT CONFIGURED
- Restore account SEPARATE / NOT CONFIGURED
- Migration/admin account still root (CLI only — expected)
- External MySQL CLI as root can still destroy gestion
- Schema entirely MyISAM (no transactional ROLLBACK)
- Monitoring / alerting not validated in this phase
- Pre-cutover backup: local ZIP archives exist (2025-11-28) but not a fresh pre-cutover snapshot (PRE-CUTOVER BACKUP: NOT AVAILABLE as dedicated fresh run)

NEXT STEP:
PRE-PROD 9.3 — SEPARATE MIGRATION / BACKUP / RESTORE ACCOUNTS
```

---

## Procedure executed

### 1. Baseline (read-only)

Counts captured before any `.env` change (see block above).

### 2. Pre-cutover backup

```text
PRE-CUTOVER BACKUP: NOT AVAILABLE
(no automatic backup:run)
Existing local archives (read-only inventory):
- storage/app/private/Gestion/2025-11-28-19-59-37.zip
- storage/app/private/Gestion/2025-11-28-19-50-02.zip
```

### 3. Grant verification (before cutover)

```text
gestion_app@localhost:
  USAGE ON *.*
  SELECT, INSERT, UPDATE, DELETE ON gestion.*

gestion_app@127.0.0.1:
  USAGE ON *.*
  SELECT, INSERT, UPDATE, DELETE ON gestion.*
```

No DROP / ALTER / CREATE / GRANT OPTION / CREATE USER / CREATE|DROP DATABASE.

### 4. MySQL connection test (gestion_app, READ only)

```text
SELECT 1 → OK
COUNT(users) → 3
COUNT(customers) → 0
USER/CURRENT_USER → gestion_app
DATABASE → gestion
```

Destructive ops **not** executed against `gestion` (per rules). Denied status established from GRANT inventory (+ prior PRE-PROD 9.1 live DENIED evidence on isolated statements).

### 5. Cutover configuration

Laravel loads credentials from `.env` → `config/database.php` (`env('DB_*')`).

Modified **only** local `.env`:

```text
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=gestion
DB_USERNAME=gestion_app
DB_PASSWORD=<from .mysql-gestion-app.local — not shown>
```

Not modified:

- `.env.example` (no secret)
- `.env.testing` (sqlite `:memory:`)
- application PHP for connection bypass
- MySQL grants / root account
- `gestion` / `gestion_recovery` data or schema

Config cache: `bootstrap/cache/config.php` absent → no `config:clear` required.

Rollback snapshot written to `.mysql-cutover-rollback.local` (gitignored) before switch.

### 6. Laravel connection proof

```text
Laravel DB connection: SUCCESS
Database: gestion
Runtime user: gestion_app@127.0.0.1
Config username: gestion_app
Host: 127.0.0.1
```

`php artisan db:safety-check`:

```text
MySQL user  : gestion_app
Uses root   : NO
STATUS      : PROTECTED
```

### 7. Read-only smoke tests

HTTP kernel + `actingAs` admin (`users.id=3`, role=admin).

| Route | HTTP | Result |
|-------|-----:|--------|
| /dashboard | 200 | PASS |
| /customers | 200 | PASS |
| /products | 200 | PASS |
| /sales | 200 | PASS |
| /quotes | 200 | PASS |
| /expenses | 200 | PASS |
| /suppliers | 200 | PASS |
| /purchase-orders | 200 | PASS |
| /delivery-notes | 200 | PASS |
| /inventory | 200 | PASS |
| /company | 200 | PASS |

```text
SQL_ERRORS: NONE
(no Access denied / SQLSTATE / PDOException)
```

Note: first attempt with `vendeur` returned HTTP 403 on some modules (RBAC) — not MySQL. Retried with admin → all PASS.

No INSERT/UPDATE/DELETE performed on `gestion` during this phase.  
`gestion_test` not used (no write smoke required after GRANT + connection proof).

### 8. Sensitive services (inspection only)

| Surface | Runtime with gestion_app |
|---------|--------------------------|
| migrate / migrate:fresh / db:wipe / db:seed | Laravel guards still block on `gestion`; MySQL lacks DDL |
| db:restore / HTTP restore | Still allow-listed / guarded; MySQL lacks DROP/CREATE on target DBs for app user |
| Spatie backup | Still separate concern — may need LOCK TABLES later via backup account |
| Jobs / Eloquent | CRUD only |
| Composer scripts / PDO root | Outside Laravel runtime credentials |

```text
RUNTIME ACCOUNT:
gestion_app

BACKUP ACCOUNT:
SEPARATE / NOT CONFIGURED

RESTORE ACCOUNT:
SEPARATE / NOT CONFIGURED

MIGRATION ACCOUNT:
SEPARATE / NOT CONFIGURED
(administrative CLI remains root — not wired to Laravel)
```

### 9. Final baseline

Identical to before. `GESTION UNCHANGED: YES`.

### 10. Config flag

`config/database-accounts.php` → `env_cutover_executed` set to `true` (status flag only; no secrets).

---

## Verdict rationale

Cutover **PASS**, but production verdict remains **READY WITH CONDITIONS** because:

1. Offsite backup not configured  
2. Dedicated backup / restore / migration accounts not implemented  
3. Root MySQL CLI remains a destructive path outside Laravel  
4. No fresh dedicated pre-cutover backup run in this phase  
5. Monitoring / alerting not in scope here  

---

## Rollback procedure (if needed)

Do **not** touch MySQL grants or data. Restore `.env` from `.mysql-cutover-rollback.local`:

```text
DB_USERNAME=root
DB_PASSWORD=<from rollback file>
```

Then re-run `php artisan db:safety-check` and recount baseline.
