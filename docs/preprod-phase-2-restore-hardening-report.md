# PRE-PROD 2 — Restore Hardening Report

**Date:** 2026-08-23  
**Verdict:** READY (with documented remaining risks)

---

## 1. Executive summary

PRE-PROD 2 closes the critical **HTTP restore → DROP DATABASE gestion** hole left after PRE-PROD 1.  
`DatabaseSafetyGuard` now blocks restore/DROP on protected databases, fail-closes unknown targets, requires backend phrase `RESTORE`, and allow-lists only `gestion_recovery` / `gestion_test`.  
`gestion` row counts unchanged after hardening. Safety tests: **25/25 PASS**.

---

## 2. Destructive surface

See `docs/preprod-database-destructive-surface-audit.md`.

---

## 3. Restore architecture

See `docs/preprod-restore-security.md`.

Early kill switch in `BackupController::restore()` + second check in `restoreDatabase()` before PDO `DROP DATABASE`.

---

## 4. Dangerous commands

Artisan destructive set still **PROTECTED** (P1 wrappers).  
`migrate` (non-fresh): **PARTIALLY PROTECTED** / CAUTION — intentionally not fully blocked.

---

## 5. Dangerous scripts

Audited — **SAFE** TS scripts only; no dangerous PHP scripts present.

---

## 6. Dangerous migrations

Audited (PRE-PROD 0/2) — DATA-MODIFYING / DESTRUCTIVE schema listed; no code changes to migrations.

---

## 7. Dangerous routes

`admin.backups.restore` → **PROTECTED**.

---

## 8. Dangerous jobs

`CreateBackupJob` → **SAFE**. No restore job.

---

## 9. RBAC protection

Restore still requires `AuthorizationService` → `backups.restore`.  
RBAC alone **cannot** authorize DROP of `gestion` (guard rejects independently of role).

---

## 10. DatabaseSafetyGuard coverage

| Capability | Status |
| ---------- | ------ |
| migrate:fresh/refresh/reset/wipe | PROTECTED |
| Artisan::call same | PROTECTED |
| `--force` bypass | PROTECTED (no bypass) |
| APP_ENV bypass | PROTECTED (no bypass) |
| DROP DATABASE gestion | PROTECTED |
| restore → gestion | PROTECTED |
| restore → unknown DB | PROTECTED (fail-closed) |
| restore → gestion_recovery | ALLOWED (policy) |
| restore confirmation phrase | PROTECTED |
| File overwrite restore when DB blocked | PROTECTED (early return) |
| Automatic cutover to gestion | NOT IMPLEMENTED (by design) |
| Concurrent restore lock | NOT PROTECTED (remaining) |
| Pre-restore backup of target | NOT PROTECTED (remaining) |

---

## 11. Tests

| Suite | Result |
| ----- | ------ |
| DatabaseSafetyTest | 13 PASS |
| RestoreSafetyTest | 12 PASS |
| **Total** | **25 PASS (51 assertions)** |

---

## 12. Bypass attempts

| Attempt | Result |
| ------- | ------ |
| `--force` + migrate:fresh + gestion | BLOCKED |
| APP_ENV=testing + restore gestion | BLOCKED |
| APP_ENV=production + DROP gestion | BLOCKED |
| confirm=true without phrase | BLOCKED |
| gestion vs gestion_recovery confusion | PASS (exact match) |

---

## 13. Remaining risks

1. `php artisan migrate` (non-fresh) on `gestion` still possible  
2. No concurrency lock on restore of allow-listed DBs  
3. Failed restore after DROP on allow-listed DB can leave empty DB  
4. `restoreFiles` still overwrites app files when restore is allowed  
5. ActivityLogger structured events for restore not fully wired (Log::warning only)  
6. Backup retention / offsite still weak (PRE-PROD 3)  
7. No automatic creation of `gestion_recovery` (manual ops)

---

## 14. Recommendations PRE-PROD 3

- Backup strategy: retention 30d + offsite + pre-Cursor checklist  
- Optional restore-into-recovery workflow UX without touching `gestion`  
- Concurrency lock + pre-restore snapshot for allow-listed DBs  
- Stronger ActivityLogger events for restore lifecycle  
- Decide policy for non-fresh `migrate` on protected DBs (warn vs require backup flag)

---

## Files created / modified

**Created:**  
`tests/Unit/Infrastructure/RestoreSafetyTest.php`  
`docs/preprod-database-destructive-surface-audit.md`  
`docs/preprod-restore-security.md`  
`docs/preprod-phase-2-restore-hardening-report.md`

**Modified:**  
`app/Database/DatabaseSafetyGuard.php`  
`app/Database/ProtectedDatabaseException.php`  
`config/database-safety.php`  
`app/Http/Controllers/Admin/BackupController.php`  
`app/Console/Commands/DatabaseSafetyCheckCommand.php`  
`resources/js/pages/Admin/Backups/Index.vue`  
`phpunit.xml`  
`.env.testing`  
`.env.example`

---

## Base métier verification

Before/after (read-only):

```text
users: 3
customers: 0
products: 0
sales: 0
```

```text
gestion modified: NO
gestion migrated: NO
gestion seeded: NO
gestion wiped: NO
gestion truncated: NO
gestion restored: NO
```
