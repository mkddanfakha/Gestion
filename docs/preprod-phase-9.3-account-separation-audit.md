# PRE-PROD 9.3 — Account Separation Audit

**Date :** 2026-08-24  
**Mode :** READ ONLY audit + policy hardening (no MySQL CREATE USER)

---

## Baseline (before)

```text
DB_DATABASE=gestion
DB_HOST=127.0.0.1
DB_USERNAME=gestion_app
DB_PASSWORD=<redacted / not shown>

users=3
companies=1
customers=0
products=0
sales=0
quotes=0
expenses=0
suppliers=0
purchase_orders=0
delivery_notes=0
inventory_sessions=0
inventory_items=0
stock_movements=0
```

### gestion_app grants (unchanged)

```text
USAGE ON *.*
SELECT, INSERT, UPDATE, DELETE ON gestion.*
```

Hosts: `127.0.0.1`, `localhost`

---

## Existing MySQL accounts (MKD-related)

| Account | Hosts | Role today |
|---------|-------|------------|
| `gestion_app` | localhost, 127.0.0.1 | Laravel runtime (CRUD) |
| `root` | localhost | DBA / full admin |
| `gestion_backup` | — | **NOT CREATED** |
| `gestion_restore` | — | **NOT CREATED** |
| `gestion_migration` | — | **NOT CREATED** |

`gestion_recovery` database exists (restore drill).  
`gestion_test` database **does not exist**.

---

## Account map

| Letter | Role | Name | Status |
|--------|------|------|--------|
| A | Runtime | `gestion_app` | CREATED + wired in `.env` |
| B | Backup | `gestion_backup` | Policy only |
| C | Restore | `gestion_restore` | Policy only |
| D | Migration | `gestion_migration` | Policy only |
| E | DBA | `root` | Present; CLI only (must not be Laravel runtime) |

---

## Code wiring (critical gap before 9.3 guards)

| Path | Credential source before guards |
|------|----------------------------------|
| Spatie `backup:run` / `BackupServiceProvider` | `database.connections.mysql` = `DB_USERNAME` |
| `CreateBackupJob` | `Artisan::call('backup:run')` |
| `MysqlPdoDumpImporter` | PDO with `DB_USERNAME` |
| `DatabaseRestoreService` / `db:restore` | same importer |
| `migrate` (incremental) | default connection username |
| `migrate:fresh` etc. | blocked by `DatabaseSafetyGuard` on `gestion` |

**Conclusion before guards :** backup + restore + migrate all shared runtime `DB_USERNAME` (`gestion_app`), which is CRUD-only → insufficient for LOCK TABLES / DROP DATABASE / DDL.

---

## 9.3 application hardening applied

- `DatabaseAccountGuard` — role checks (no secrets)
- `PrivilegedCommandGuard` — `backup:run` and mysql `migrate` require dedicated accounts
- `DatabaseRestoreService` / `MysqlPdoDumpImporter` — require `gestion_restore`
- `CreateBackupJob` — asserts backup account before run
- Runtime must not be `root` / privileged accounts (policy asserts + tests)

`.env` **not** modified. `gestion_app` grants **not** modified. Privileged MySQL users **not** created.

---

## HUMAN APPROVAL REQUIRED (account creation)

See final report Phase I blocks for:

- `OUI — CREATE gestion_backup`
- `OUI — CREATE gestion_restore`
- `OUI — CREATE gestion_migration`

individually.
