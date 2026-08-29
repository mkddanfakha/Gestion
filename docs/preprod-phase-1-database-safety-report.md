# PRE-PROD 1 — Database Safety Report

**Date:** 2026-08-23  
**Mode:** DATABASE SAFETY HARDENING ONLY  
**Verdict:** `PRE-PROD 1 — READY`

---

## 1. Audit initial

### Configuration

| Item | Finding |
| ---- | ------- |
| `.env` | `APP_ENV=local`, `DB_CONNECTION=mysql`, `DB_DATABASE=gestion` |
| `.env.example` | sqlite by default; now documents `DB_PROTECTED_DATABASES` |
| `.env.testing` | **Created** — sqlite `:memory:`, never `gestion` |
| `phpunit.xml` | sqlite `:memory:` + `DB_PROTECTED_DATABASES=gestion` + `BROADCAST_CONNECTION=log` |
| `config/database.php` | default from `env('DB_CONNECTION')` |
| Laravel Console Kernel | **Does not dispatch `CommandStarting` during PHPUnit** (`runningUnitTests()` skip) |

### Scripts (`scripts/`)

| Script | Classification | Reason |
| ------ | -------------- | ------ |
| `verify-expiration-status.ts` | SAFE | Pure TypeScript, no Laravel/DB |
| `detect-lan-ipv4.ts` | SAFE | Pure Node, no Laravel/DB |
| `measure-rbac-queries.php` | ABSENT (was DANGEROUS) | Historical; never reintroduce without guard |

### Dangerous commands (app code)

| Location | Risk |
| -------- | ---- |
| `BackupController::restoreDatabase` | `DROP DATABASE` — UI/RBAC only; **not covered by migrate kill switch** (PRE-PROD 2) |
| `CreateBackupJob` / backup Artisan | Non-destructive dump |
| `composer setup` | `migrate --force` (non-fresh) — still possible on `gestion` |

### Tests

- Massive use of `RefreshDatabase` under Pest Feature/Unit RBAC — uses PHPUnit sqlite `:memory:` (safe).
- New suite: `tests/Unit/Infrastructure/DatabaseSafetyTest.php` — **no RefreshDatabase**.

---

## 2. Root cause protection

### Previous failure path

```text
script PHP
→ Laravel bootstrap
→ .env
→ gestion
→ Artisan::call('migrate:fresh', ['--force' => true])
→ DROP ALL TABLES
```

### Why it can no longer complete

1. **Container `extend()`** wraps `FreshCommand`, `RefreshCommand`, `ResetCommand`, `WipeCommand` with protected subclasses that call `DatabaseSafetyGuard::assertDestructiveOperationAllowed()` **before** `parent::handle()`.
2. Guard checks the **configured database name** (exact match). If `gestion` → throws `ProtectedDatabaseException`.
3. **`--force` does not bypass** the guard (checked before Laravel's confirm/force logic).
4. **`CommandStarting` listener** remains as defense-in-depth for real CLI (and forced on in tests via `rerouteSymfonyCommandEvents()`).
5. Scripts can also call `DatabaseSafetyGuard::assertDestructiveOperationAllowed()` explicitly.

`APP_ENV` alone is never trusted: `testing` + `DB_DATABASE=gestion` is still **BLOCKED**.

---

## 3. Fichiers créés

| File |
| ---- |
| `config/database-safety.php` |
| `app/Database/DatabaseSafetyGuard.php` |
| `app/Database/ProtectedDatabaseException.php` |
| `app/Database/DestructiveCommandGuard.php` |
| `app/Database/Console/ProtectedMigrateFreshCommand.php` |
| `app/Database/Console/ProtectedMigrateRefreshCommand.php` |
| `app/Database/Console/ProtectedMigrateResetCommand.php` |
| `app/Database/Console/ProtectedDbWipeCommand.php` |
| `app/Console/Commands/DatabaseSafetyCheckCommand.php` |
| `.env.testing` |
| `tests/Unit/Infrastructure/DatabaseSafetyTest.php` |
| `docs/database-safety-policy.md` |
| `docs/cursor-database-safety.md` |
| `docs/preprod-phase-1-database-safety-report.md` |

---

## 4. Fichiers modifiés

| File | Change |
| ---- | ------ |
| `app/Providers/AppServiceProvider.php` | Register extend wrappers + CommandStarting guard |
| `tests/Pest.php` | Register Unit/Infrastructure without RefreshDatabase |
| `phpunit.xml` | `DB_PROTECTED_DATABASES`, `BROADCAST_CONNECTION=log` |
| `.env.example` | Document `DB_PROTECTED_DATABASES` |

---

## 5. Fichiers supprimés

| File | Reason |
| ---- | ------ |
| Accidental SQLite file `./gestion` (project root) | Artifact from early failed tests when guard did not yet wrap commands; **not** MySQL `gestion` |
| Temporary `tests/Feature/Infrastructure/DatabaseSafetyTest.php` | Moved to Unit (avoid RefreshDatabase) |

---

## 6. Tests

| Test | Résultat |
| ---- | -------- |
| migrate:fresh protection | **PASS** |
| migrate:refresh protection | **PASS** |
| migrate:reset protection | **PASS** |
| db:wipe protection | **PASS** |
| --force bypass protection | **PASS** |
| Artisan::call protection | **PASS** |
| testing isolation | **PASS** |
| db:safety-check | **PASS** |
| gestion vs gestion_test exact match | **PASS** |
| APP_ENV=testing + gestion still blocked | **PASS** |

```text
Tests: 13 passed (32 assertions)
```

Command used (SQLite only):

```bash
php artisan test tests/Unit/Infrastructure/DatabaseSafetyTest.php
```

---

## 7. Base métier

```text
gestion modified: NO
gestion migrated: NO
gestion seeded: NO
gestion wiped: NO
gestion truncated: NO
gestion restored: NO
```

`php artisan db:safety-check` (read-only) against local `.env`:

```text
Database    : gestion
STATUS      : PROTECTED
Destructive migrations: BLOCKED
Database wipe: BLOCKED
No changes performed.
```

---

## 8. Données

```text
customers: untouched
products: untouched
sales: untouched
quotes: untouched
expenses: untouched
purchase_orders: untouched
delivery_notes: untouched
inventory: untouched
users: untouched
```

---

## 9. Build

Not required (no frontend changes).

---

## 10. Remaining risks (PRE-PROD 2+)

| Risk | Notes |
| ---- | ----- |
| `BackupController::restore` → `DROP DATABASE` | Still possible with RBAC permission |
| `php artisan migrate` (non-fresh) | Not blocked — can still alter schema on `gestion` |
| `composer setup` → `migrate --force` | Non-fresh migrate |
| Spatie backup retention / offsite | Still weak (see PRE-PROD 0) |
| Data recovery | Out of scope for this phase |

---

## 11. Verdict

```text
PRE-PROD 1 — READY
```

Even if Cursor, a developer, or a script reproduces the 23/08/2026 scenario (`Artisan::call('migrate:fresh', ['--force' => true])` while `.env` points at `gestion`), the operation is **refused before any destructive change**.
