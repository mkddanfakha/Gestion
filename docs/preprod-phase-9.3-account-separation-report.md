# PRE-PROD 9.3 — Database Account Separation Report

**Date :** 2026-08-24  
**Mode :** HARDENING — policy + application guards  
**MySQL CREATE USER :** NOT EXECUTED  
**.env modification :** NOT EXECUTED  
**gestion_app grants :** UNCHANGED

---

```text
========================================
MKD-PRO PRE-PROD 9.3
DATABASE ACCOUNT SEPARATION
========================================

ROOT CAUSE:
IDENTIFIED
(Backup/restore/migrate shared Laravel DB_USERNAME; root historically used as runtime)

RUNTIME ACCOUNT:
gestion_app

RUNTIME LEAST PRIVILEGE:
PASS
(SELECT/INSERT/UPDATE/DELETE on gestion.* only)

BACKUP ACCOUNT:
NOT CREATED

RESTORE ACCOUNT:
NOT CREATED

MIGRATION ACCOUNT:
NOT CREATED

ROOT ACCOUNT:
DBA ONLY
(SHOW GRANTS: global ALL + GRANT OPTION — documented, not modified)

PRIVILEGE MATRIX:
PASS
(docs/mysql-privilege-matrix.md)

RUNTIME ROOT:
NO

GESTION:
UNCHANGED

TEST ISOLATION:
PASS
(Pest uses sqlite :memory:; gestion never targeted)

ARTISAN SAFETY:
PASS
(backup:run / mysql migrate refuse gestion_app; destructive cmds still blocked)

RESTORE SAFETY:
PASS
(target allow-list + restore account required)

SECRETS:
PASS
(.env / credential locals gitignored; not in docs)

BYPASS TESTS:
Policy PASS (live DDL on gestion_test SKIPPED — DB absent)
HUMAN APPROVAL REQUIRED to create gestion_test for live DENIED probes

PEST:
103/103 PASS
(Infrastructure safety suite)

BUILD:
NOT RUN

PRODUCTION STATUS:
READY WITH CONDITIONS

HUMAN ACTION REQUIRED:
YES
```

---

## Baseline BEFORE = AFTER

| Table | Before | After |
|-------|-------:|------:|
| users | 3 | 3 |
| companies | 1 | 1 |
| customers | 0 | 0 |
| products | 0 | 0 |
| sales | 0 | 0 |
| quotes | 0 | 0 |
| expenses | 0 | 0 |
| suppliers | 0 | 0 |
| purchase_orders | 0 | 0 |
| delivery_notes | 0 | 0 |
| inventory_sessions | 0 | 0 |
| inventory_items | 0 | 0 |
| stock_movements | 0 | 0 |

```text
DATABASE gestion:
UNCHANGED
```

Laravel still:

```text
DB_DATABASE=gestion
DB_USERNAME=gestion_app
```

---

## Deliverables

| Artefact | Status |
|----------|--------|
| `docs/preprod-phase-9.3-account-separation-audit.md` | Created |
| `docs/mysql-privilege-matrix.md` | Created |
| `config/database-accounts.php` | Expanded (policy flags; accounts not created) |
| `app/Database/DatabaseAccountGuard.php` | Added |
| `app/Database/PrivilegedCommandGuard.php` | Added |
| Restore / backup job account asserts | Added |
| `tests/Unit/Infrastructure/AccountSeparationTest.php` | Added |

---

## Phase I — HUMAN APPROVAL REQUIRED (no action executed)

### 1) gestion_backup

```text
HUMAN APPROVAL REQUIRED

Account:
gestion_backup

Purpose:
Dedicated Spatie / mysqldump backup credentials (never Laravel DB_USERNAME)

Proposed privileges:
SELECT, LOCK TABLES, SHOW VIEW ON gestion.*

Target:
gestion.*

Risk:
LOW–MEDIUM (read + table locks during dump; no DROP/ALTER)

No action executed.
```

Reply: `OUI — CREATE gestion_backup`

### 2) gestion_restore

```text
HUMAN APPROVAL REQUIRED

Account:
gestion_restore

Purpose:
Controlled restore into allow-listed recovery databases only

Proposed privileges:
CREATE/DROP DATABASE (scoped as tightly as MySQL permits) +
CREATE, DROP, ALTER, INDEX, REFERENCES, SELECT, INSERT, UPDATE, DELETE
on gestion_recovery.* and gestion_test.*

Target:
gestion_recovery, gestion_test
NEVER gestion

Risk:
HIGH (can destroy recovery/test DBs) — Laravel still blocks restore→gestion

No action executed.
```

Reply: `OUI — CREATE gestion_restore`

### 3) gestion_migration

```text
HUMAN APPROVAL REQUIRED

Account:
gestion_migration

Purpose:
Structural migrations only (CLI / controlled ops — never runtime)

Proposed privileges:
SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
ON gestion.*

Target:
gestion.*

Risk:
HIGH (schema change on production DB) — still no CREATE/DROP DATABASE, no GRANT

No action executed.
```

Reply: `OUI — CREATE gestion_migration`

Approvals must be **individual**. Global “create all” is not accepted as implicit.

---

## Operational impact (fail-closed)

Until privileged accounts are created **and** credentials are wired outside `DB_USERNAME`:

| Command | With current `gestion_app` |
|---------|----------------------------|
| App HTTP CRUD | OK |
| `backup:run` | **REFUSED** (account guard) |
| `db:restore` | **REFUSED** (account guard) |
| `migrate` (mysql) | **REFUSED** (account guard) |
| `backup:verify` | OK (filesystem only) |
| `db:safety-check` | OK (read-only) |

This is intentional FAIL-CLOSED after cutover to CRUD-only runtime.

---

## Remaining conditions

1. Privileged MySQL accounts not created  
2. Backup/restore credential wiring not implemented (separate env keys)  
3. Offsite backup still NOT CONFIGURED  
4. `gestion_test` missing → live DENIED DDL probes deferred  
5. Root CLI remains capable of destroying `gestion`  
6. Schema still MyISAM  

---

## Next step

Await individual human approvals for CREATE of `gestion_backup` / `gestion_restore` / `gestion_migration`, then wire non-runtime credentials (still never into Git).
