# Database Safety Policy — MKD-Pro

**Effective:** 2026-08-23 (PRE-PROD 1)  
**Status:** ENFORCED in application code

---

## NEVER

An AI agent or developer must **never** execute on the business database `gestion`:

```bash
php artisan migrate:fresh
php artisan migrate:refresh
php artisan migrate:reset
php artisan db:wipe
```

And especially:

```php
Artisan::call('migrate:fresh', ['--force' => true]);
```

must never be used in a measurement / benchmark / temporary script that bootstraps Laravel with the local `.env`.

`--force` **does not** bypass the kill switch.

---

## WHY

On 2026-08-23, a temporary script (`scripts/measure-rbac-queries.php`) bootstrapped Laravel with `.env` (`DB_DATABASE=gestion`) and called `migrate:fresh --force`, wiping business data.

PHPUnit was already isolated on SQLite `:memory:`. The failure mode was **CLI scripts outside PHPUnit**.

---

## SAFE PATTERN

Any script that needs a rebuilt database must:

1. Detect its environment (`APP_ENV`)
2. Detect the resolved database name
3. Verify the database is explicitly a **test** database (never `gestion`)
4. Refuse any other target
5. Only then run a destructive operation

```php
use App\Database\DatabaseSafetyGuard;

DatabaseSafetyGuard::assertDestructiveOperationAllowed('migrate:fresh');
// only continues if not protected
```

Or use:

```bash
php artisan db:safety-check
```

---

## Protected databases

Configured in `config/database-safety.php` / `DB_PROTECTED_DATABASES`.

Default: **`gestion`** (exact match).

`gestion_test` is **not** protected by listing `gestion`.

---

## Test isolation

- `phpunit.xml` → SQLite `:memory:`
- `.env.testing` → SQLite `:memory:`
- Never set `DB_DATABASE=gestion` under `APP_ENV=testing`

---

## Allowed targets for destructive ops

| Target | Destructive migrate/wipe |
| ------ | ------------------------ |
| `gestion` | **BLOCKED** |
| `gestion_test` | Allowed (if configured) |
| SQLite `:memory:` | Allowed |
| Other non-listed names | Allowed |

---

## Related docs

- `docs/cursor-database-safety.md` — pre-phase checklist
- `docs/preprod-database-hardening-plan.md` — broader plan
- `docs/preprod-phase-1-database-safety-report.md` — PRE-PROD 1 report
- `docs/preprod-phase-5-restore-architecture.md` — DB-only restore
- `docs/preprod-recovery-runbook.md` — emergency recovery

## Restore (PRE-PROD 5)

- Explicit `target_database` / `--target` required (never inferred from `.env` / `DB_DATABASE`)
- Allow-list: `gestion_recovery`, `gestion_test`
- `gestion` always blocked for restore/DROP from the application
- Database restore never restores application files
- File restore is a separate operation (`FILES_RESTORE`, dry-run only in PRE-PROD)
