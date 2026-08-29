# PRE-PROD 2 — Destructive Surface Audit

**Date:** 2026-08-23  
**Mode:** static audit + controlled hardening (no writes to `gestion`)

---

## 1. Executive summary

The remaining high-risk path after PRE-PROD 1 was **application backup restore**: `BackupController::restoreDatabase()` executed `DROP DATABASE IF EXISTS` on whatever MySQL database name was in config (typically `gestion`), with only RBAC `backups.restore` + a frontend checkbox.

PRE-PROD 2 blocks that path for protected databases and fail-closes unknown targets.

---

## 2. Destructive surface

| Path | Mechanism | Pre-P2 | Post-P2 |
| ---- | --------- | ------ | ------- |
| `migrate:fresh/refresh/reset` | Artisan | PROTECTED (P1) | PROTECTED |
| `db:wipe` | Artisan | PROTECTED (P1) | PROTECTED |
| `Artisan::call(...)` same commands | Programmatic | PROTECTED (P1) | PROTECTED |
| `BackupController::restore` → `DROP DATABASE` | HTTP PDO | **UNPROTECTED** | **PROTECTED** |
| `BackupController::restoreFiles` | Overwrites app files | CAUTION | Blocked when restore blocked (early exit) |
| `CreateBackupJob` | `backup:run` | SAFE (dump) | SAFE |
| `composer setup` → `migrate --force` | Schema migrate | CAUTION | CAUTION (non-fresh) |
| Diagnose/Test MySQL `exec` | Diagnostics | SAFE | SAFE |
| Scripts `scripts/*.ts` | No Laravel DB | SAFE | SAFE |
| `measure-rbac-queries.php` | Historical | ABSENT | ABSENT |

---

## 3. Restore architecture (current)

```text
POST /admin/backups/{backup}/restore
  → auth + CSRF (web middleware)
  → AuthorizationService backups.restore
  → confirmation_phrase=RESTORE (backend)
  → DatabaseSafetyGuard::assertSafeForRestore(mysql.database)
  → extract zip
  → restoreDatabase():
       assertSafeForDropDatabase(dbName)
       DROP DATABASE / CREATE / import SQL   # only allow-listed DBs
  → restoreFiles(base_path)
  → cache clear
```

**Recommended future (PRE-PROD 3, not implemented destructive switch):**

```text
Backup → gestion_recovery → validate → compare → human switch → gestion
```

---

## 4. Dangerous commands

| Command | Class | Risk |
| ------- | ----- | ---- |
| migrate:fresh/refresh/reset | Protected wrappers | DESTRUCTIVE — blocked on `gestion` |
| db:wipe | Protected wrapper | DESTRUCTIVE — blocked on `gestion` |
| backup:run/clean/monitor | Spatie | SAFE / CAUTION (cleanup deletes archives) |
| rbac:migrate-legacy-permissions | Custom | CAUTION (data-modifying pivots) |
| attachments:cleanup | Custom | CAUTION (disk) |
| stock:check-consistency | Custom | SAFE |
| DiagnoseMySQL / TestMySQLConnection | Custom | SAFE (exec diagnostics) |

---

## 5. Dangerous scripts

| Script | DB | Risk |
| ------ | -- | ---- |
| `scripts/verify-expiration-status.ts` | none | SAFE |
| `scripts/detect-lan-ipv4.ts` | none | SAFE |
| No `scripts/*.php` present | — | N/A |

Policy: no `scripts/*.php` may bootstrap `.env` and run destructive Artisan against métier DB.

---

## 6. Dangerous migrations

Classified in PRE-PROD 0; unchanged:

- Structural `dropIfExists` in `down()` — normal
- DATA-MODIFYING: credit_limit updates, SKU/FA number conversions, store/stock init
- DESTRUCTIVE schema: `dropColumn('credit_limit')`, FK CASCADE

`migrate` (non-fresh) on `gestion` remains **allowed** for future schema evolution (documented CAUTION).

---

## 7. Dangerous routes

| Route | Auth | RBAC | CSRF | Safety guard | Status |
| ----- | ---- | ---- | ---- | ------------ | ------ |
| `admin.backups.restore` POST | web auth | `backups.restore` | yes | **yes (P2)** | PROTECTED |
| `admin.backups.store` | web auth | `backups.create` | yes | N/A (dump) | SAFE |
| `admin.backups.destroy` | web auth | `backups.delete` | yes | deletes zip only | CAUTION |
| `admin.backups.import` | web auth | `backups.create` | yes | upload zip | CAUTION |

---

## 8. Dangerous jobs

| Job | Behavior | Status |
| --- | -------- | ------ |
| `CreateBackupJob` | `Artisan::call('backup:run')` | SAFE |
| No restore job found | — | N/A |

---

## 9. Classification legend used in report

`PROTECTED` / `PARTIALLY PROTECTED` / `UNPROTECTED` / `NOT APPLICABLE` / `NOT TESTED` / `SAFE` / `CAUTION` / `DESTRUCTIVE`
