# PRE-PROD 9.3.3 — Migration Account — CRITICAL FAILURE

**Date :** 2026-08-25  
**Approval used :** `OUI — CREATE gestion_migration`

---

```text
FAILED — HUMAN REVIEW REQUIRED

account: gestion_migration
operation: privilege probe DROP DATABASE gestion
error: SUCCEEDED (unexpected) — MySQL DROP privilege on gestion.* allows DROP DATABASE
database target: gestion
current grants: USAGE; SELECT,INSERT,UPDATE,DELETE,CREATE,DROP,REFERENCES,INDEX,ALTER ON gestion.*
gestion baseline (before CREATE): users=3 companies=1 customers=0 products=0 sales=0
gestion final: DATABASE MISSING (dropped)
```

---

## What happened

1. `CREATE USER` + `GRANT … DROP ON gestion.*` executed as approved.  
2. Verification probe ran `DROP DATABASE gestion` expecting **DENIED**.  
3. MySQL **accepted** the command: privilege `DROP` on `db.*` includes **`DROP DATABASE db`**.  
4. No automatic recreate / restore / REVOKE performed (fail-closed policy).

## Current state (read-only)

```text
gestion: ABSENT
gestion_recovery: PRESENT (27 tables, users=4 — drill dump Nov 2025, NOT the pre-drop state)
Laravel .env: still DB_DATABASE=gestion / DB_USERNAME=gestion_app (app cannot connect)
Backups local ZIP: 2025-11-28-*.zip present
Offsite: NOT CONFIGURED
```

## Accounts

| Account | Status |
|---------|--------|
| gestion_app | EXISTS (grants on missing DB) |
| gestion_backup | EXISTS |
| gestion_restore | EXISTS |
| gestion_migration | EXISTS — **can DROP DATABASE gestion if recreated** until REVOKE |

## Privilege design flaw

```text
MIGRATION PRIVILEGE GAP / DESIGN FLAW:
GRANT DROP ON gestion.*  ⇒  allows DROP DATABASE gestion
Cannot separate DROP TABLE vs DROP DATABASE with this grant model alone.
```

## Human decisions required (do not execute until approved)

Propose choosing explicitly, e.g.:

1. `OUI — RECREATE EMPTY gestion` (CREATE DATABASE only; then decide schema path)  
2. `OUI — REVOKE DROP FROM gestion_migration` (and redefine migration privileges)  
3. `OUI — RESTORE INTO gestion_recovery FROM zip …` (never imply restore→gestion)  
4. `OUI — RESTORE TOWARDS gestion` (**only if you explicitly accept risk** — historically forbidden by policy)

**No action executed beyond this report.**
