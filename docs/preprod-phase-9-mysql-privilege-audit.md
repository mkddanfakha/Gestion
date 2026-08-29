# PRE-PROD 9 — MySQL Privilege Audit

**Date :** 2026-08-24  
**Mode :** AUDIT ONLY — aucun GRANT / REVOKE / CREATE USER / DROP / ALTER sur MySQL  
**Base métier :** `gestion` — UNCHANGED

---

## 1. Baseline forensic

| Item | Valeur |
|------|--------|
| USER() | `root@localhost` |
| CURRENT_USER() | `root@localhost` |
| VERSION() | `9.1.0` |
| SHOW DATABASES | `gestion`, `gestion_recovery`, (+ autres apps) |
| `gestion_test` | **ABSENT** |
| Tables in `gestion` | **38** |
| Row counts | users=3, customers/products/sales/quotes/expenses/suppliers/PO/BL/inventory/stock=0 |

Aligné avec PRE-PROD 8 baseline.

---

## 2. Laravel DB identity

```text
CRITICAL FINDING:
Laravel uses MySQL root.

Laravel DB user:     root
Laravel DB host:     127.0.0.1
Laravel DB port:     3306
Laravel DB database: gestion
Laravel DB connection: mysql
```

Password: **not displayed**.

---

## 3. Grants (summary)

`SHOW GRANTS FOR CURRENT_USER()` (root@localhost):

- Broad privileges on `*.*` including **SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, EVENT, TRIGGER, CREATE USER, …**
- **WITH GRANT OPTION**
- Plus numerous dynamic admin privileges (SUPER-class / SYSTEM_VARIABLES_ADMIN / …)

```text
DROP DATABASE PRIVILEGE: PRESENT (global)
DROP TABLE PRIVILEGE: PRESENT (global)
ALTER PRIVILEGE: PRESENT
CREATE PRIVILEGE: PRESENT
GRANT OPTION: PRESENT
GLOBAL PRIVILEGES: YES (*.*)
```

Classification: **CRITICAL**

---

## 4. Root cause update

```text
ROOT CAUSE:
A destructive development script executed migrate:fresh against
the production/development database gestion.

PRIMARY FAILURE:
Environment/database isolation failure.

SECONDARY FAILURE:
Laravel application was using a MySQL account with excessive
administrative privileges.

TERTIARY FAILURE:
No independent MySQL privilege boundary prevented the destructive
operation.
```

Laravel kill switch (PRE-PROD 1–8) addresses the **application** path.  
MySQL least-privilege (this phase, **design only**) is the **third** line of defense.

---

## 5. Runtime vs maintenance privileges

| Need | Runtime (`gestion_app`) | Maintenance (root/admin CLI) |
|------|-------------------------|------------------------------|
| CRUD métier | REQUIRED | YES |
| Migrations CREATE/ALTER/DROP TABLE | NOT for runtime | REQUIRED |
| `migrate:fresh` / wipe | FORBIDDEN | emergency only |
| Restore into `gestion` | FORBIDDEN | human cutover only |
| Restore into `gestion_recovery` | via `gestion_restore` | YES |

Code evidence: migrations use `Schema::`, `dropColumn`, `DB::statement` → need DDL account for maintenance, **not** runtime.

---

## 6. Backup / restore credential separation

| Concern | Status |
|---------|--------|
| Spatie backup uses same `.env` mysql user | **WARNING / HIGH** — no separation |
| Restore importer uses mysql config credentials | **WARNING** — same root today |
| Laravel allow-list blocks restore→gestion | **PASS** (app layer) |

---

## 7. External / PDO paths (read-only inventory)

| FILE | PURPOSE | DESTRUCTIVE CAPABILITY | RISK |
|------|---------|------------------------|------|
| `MysqlPdoDumpImporter` | DB-only restore | DROP/CREATE allow-listed DB | CONTROLLED by guard |
| `BackupController` (retired methods) | stubs throw | none | SAFE |
| `BackupController` proc_open backup:run | Spatie backup | dump via mysqldump | SAFE (read) |
| `MySqlForcedTcp` | mysqldump wrapper | dump | SAFE |
| `TestMySQLConnection` / `DiagnoseMySQL` | diagnostics | exec mysqldump help | LOW |
| `OptimizeNotificationTablesCommand` | OPTIMIZE TABLE | maintenance | WARNING if run as root on gestion |
| `composer setup` | migrate --force | schema change | WARNING |

Raw `mysql` CLI as root: **CRITICAL** — outside Laravel.

---

## 8. Composer

```text
composer setup → php artisan migrate --force
```

Can alter schema of whatever `DB_DATABASE` points to. Does **not** call migrate:fresh.  
**Do not run** on machines pointing at `gestion` without human approval.

---

## 9. Defense matrix

| Couche | Protection | Status |
|--------|------------|--------|
| Cursor | règles explicites | DOCUMENTED |
| Git | scripts PHP measure absents | PASS |
| Laravel | DatabaseSafetyGuard | PASS |
| Artisan | DestructiveCommandGuard + wrappers | PASS |
| HTTP | RestoreSafety + explicit target | PASS |
| Service | DatabaseRestoreService | PASS |
| Tests | SQLite :memory: | PASS |
| Environment | .env.testing | PASS |
| MySQL | least-privilege runtime | **NOT YET IMPLEMENTED** |
| Backup account | separated | **MISSING** |
| Recovery account | separated | **MISSING** |
| Offsite | external copy | NOT CONFIGURED (P3/P4) |

---

## 10. Plan de migration futur (HUMAN ONLY)

### ACTION REQUIRES HUMAN APPROVAL

Aucune des commandes SQL suivantes n’a été exécutée.

```text
ACTION REQUIRES HUMAN APPROVAL

Database: mysql system (mysql.user / grants)
User: (new) gestion_app — NOT root
Operation: CREATE USER + GRANT least privilege + later switch Laravel .env
SQL COMMAND THAT WOULD BE EXECUTED: (example — DO NOT RUN without review)

CREATE USER 'gestion_app'@'localhost' IDENTIFIED BY '***SECRET***';
GRANT SELECT, INSERT, UPDATE, DELETE ON gestion.* TO 'gestion_app'@'localhost';
FLUSH PRIVILEGES;

Why: remove DROP/ALTER/CREATE from Laravel runtime credentials
Risk: mis-typed GRANT could affect wrong schema; password handling
Expected result: app works for CRUD; migrate:fresh fails at MySQL even if Laravel guard bypassed
```

### Steps A–H (summary)

A. Create `gestion_app` (+ optional backup/restore users)  
B. Grant least privilege  
C. Test on `gestion_test` (create DB if approved)  
D. Staging smoke tests  
E. Functional checklist (login, clients, ventes, …)  
F. Switch `.env` `DB_USERNAME` / password (human)  
G. Verify destructive SQL fails as app user  
H. Keep root for admin CLI only  

---

## 11. Tests

Non-destructive Unit tests: `tests/Unit/Infrastructure/MysqlPrivilegeBoundaryTest.php`

---

## 12. Verdict

```text
PRODUCTION STATUS: READY WITH CONDITIONS
HUMAN ACTION REQUIRED: YES
GESTION PROTECTED BY MYSQL: NOT YET IMPLEMENTED
```
