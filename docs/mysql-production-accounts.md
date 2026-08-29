# MySQL production accounts (target design)

**Do not create these accounts without human approval.**  
**Never store real passwords in this file.**

## Current (as of PRE-PROD 9 audit)

| Role | Account | Notes |
|------|---------|-------|
| Laravel runtime | `root`@`localhost` | **CRITICAL** — full admin |
| Backup (Spatie) | same as runtime | no separation |
| Restore drill | same as runtime | allow-list is Laravel-only |

## Recommended accounts

### `gestion_app` (runtime)

- Host: `localhost` / `127.0.0.1`
- Database: `gestion` only
- Privileges: `SELECT, INSERT, UPDATE, DELETE` on `gestion.*`
- Optional: `LOCK TABLES`, `CREATE TEMPORARY TABLES` if required by jobs
- **No** CREATE / ALTER / DROP / GRANT

### `gestion_backup`

- Privileges: `SELECT, LOCK TABLES, SHOW VIEW` on `gestion.*`
- Used by mysqldump / Spatie only

### `gestion_restore`

- Privileges on `gestion_recovery.*` and optionally `gestion_test.*`:
  - CREATE, DROP, ALTER, INDEX, SELECT, INSERT, UPDATE, DELETE
  - CREATE/DROP DATABASE **only if** restore recreates the schema DB (prefer limited to those names via careful grants — MySQL cannot easily restrict DROP DATABASE to one name without careful design; prefer dedicated instance or strict ops runbook)
- **Zero** privileges on `gestion`

### `gestion_test_user`

- Privileges on `gestion_test.*` only (including DDL for PHPUnit/MySQL tests if used)
- Prefer SQLite `:memory:` for CI

### `root`

- CLI / phpMyAdmin / emergency only
- **Never** in Laravel `.env` for runtime

## Human migration procedure (not executed in PRE-PROD 9)

See `docs/preprod-phase-9-mysql-privilege-audit.md` § Plan de migration.
