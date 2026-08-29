# MySQL privilege matrix (PRE-PROD 9.3)

Policy matrix derived from real code paths (Spatie backup, `MysqlPdoDumpImporter`, Laravel migrations).  
**No secrets. No automatic GRANT.**

| Operation | Account | Required privilege | Reason | Target | Destructive? | Human approval? |
|-----------|---------|-------------------|--------|--------|--------------|-----------------|
| Laravel HTTP/CRUD | `gestion_app` | SELECT, INSERT, UPDATE, DELETE | Eloquent / Query Builder | `gestion.*` | No | Done (9.1/9.2) |
| Spatie mysqldump | `gestion_backup` | SELECT | Read table data | `gestion.*` | No | YES to CREATE |
| Spatie mysqldump (current flags: no single-transaction) | `gestion_backup` | LOCK TABLES | Default mysqldump locking | `gestion.*` | No* | YES to CREATE |
| Spatie mysqldump (views) | `gestion_backup` | SHOW VIEW | Dump views if present | `gestion.*` | No | YES to CREATE |
| `backup:verify` | n/a (filesystem) | none on MySQL | ZIP inspection only | files | No | No |
| Restore DROP/CREATE DB | `gestion_restore` | DROP DATABASE, CREATE DATABASE | Explicit in `MysqlPdoDumpImporter` | `gestion_recovery` / `gestion_test` only | Yes (allow-list) | YES to CREATE |
| Restore schema/data | `gestion_restore` | CREATE, DROP, ALTER, INDEX, REFERENCES, SELECT, INSERT, UPDATE, DELETE | Dump replay | allow-list DB `.*` | Yes | YES to CREATE |
| Restore toward `gestion` | any | — | **FORBIDDEN** (Laravel guard + policy) | `gestion` | Critical | Never |
| `migrate` DDL | `gestion_migration` | CREATE, ALTER, INDEX, REFERENCES (**DROP révoqué 9.4**) | Migrations CREATE/ALTER ; DROP TABLE ⇒ procédure DBA | `gestion.*` | Yes (schema) | YES ; DROP TABLE via root |
| `migrate` data | `gestion_migration` | SELECT, INSERT, UPDATE, DELETE | Data migrations | `gestion.*` | Maybe | YES to CREATE |
| `DROP DATABASE gestion` via migration acct | `gestion_migration` | — | **REVOKED 9.4** (`DROP ON gestion.*` ⇒ DROP DATABASE) | `gestion` | Critical | Never re-GRANT without approval |
| `migrate:fresh` on `gestion` | — | — | Blocked by `DatabaseSafetyGuard` | `gestion` | Critical | Never |
| DBA emergency | `root` | ALL + GRANT OPTION | CLI only | `*.*` | Yes | Out of band |

\* LOCK TABLES is non-destructive to row data but is a privileged dump requirement with current Spatie config (`use_single_transaction=false`).

---

## Forbidden on `gestion_app` (runtime)

CREATE, ALTER, DROP, INDEX, TRUNCATE, LOCK TABLES, REFERENCES, CREATE/DROP DATABASE, CREATE USER, GRANT OPTION, FILE, SUPER, RELOAD, SHUTDOWN, PROCESS, EVENT, TRIGGER, routines.

---

## Account creation status

| Account | Created |
|---------|---------|
| `gestion_app` | YES |
| `gestion_backup` | YES |
| `gestion_restore` | YES |
| `gestion_migration` | YES (DROP révoqué PRE-PROD 9.4) |
| `root` | YES (DBA) |
