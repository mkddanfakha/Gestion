# MySQL Privilege Policy — MKD-Pro

**Status :** DOCUMENTED (PRE-PROD 9) — **not yet applied** to the live MySQL server.

## Absolute rules

1. Laravel **runtime** must never use MySQL `root`.
2. Application code must never receive `GRANT OPTION` or global `*.*` privileges.
3. `DROP DATABASE` / schema DDL must not be available to the runtime account.
4. Passwords must live only in `.env` / secret managers — never in Git or docs.
5. Changes to MySQL users/privileges require **explicit human approval**.

## Privilege layers

| Layer | Account (target) | Privileges |
|-------|------------------|------------|
| Runtime | `gestion_app` | SELECT, INSERT, UPDATE, DELETE on `gestion.*` (+ LOCK TABLES if needed) |
| Maintenance | admin / root (CLI only) | CREATE, ALTER, INDEX, DROP TABLE, migrations |
| Backup | `gestion_backup` | SELECT, LOCK TABLES, SHOW VIEW on `gestion.*` (mysqldump) |
| Restore | `gestion_restore` | DDL+DML **only** on `gestion_recovery` / `gestion_test` — **never** on `gestion` |
| Tests | `gestion_test_user` or SQLite | isolated |

## Classification

| Privilege | Runtime | Maintenance | Restore (recovery only) |
|-----------|---------|-------------|-------------------------|
| SELECT/INSERT/UPDATE/DELETE | YES | YES | YES |
| CREATE/ALTER/INDEX/DROP TABLE | NO | YES | YES (recovery DB) |
| DROP DATABASE | NO | emergency only | YES (allow-listed DBs only) |
| GRANT OPTION | NO | NO (root only) | NO |
| FILE / SUPER | NO | NO | NO |

## Current state (audit)

Laravel uses `root@localhost` with broad `*.*` grants including DROP, CREATE, GRANT OPTION.

```text
GESTION PROTECTED BY LARAVEL: YES
GESTION PROTECTED BY MYSQL: NOT YET IMPLEMENTED
```
