# Cursor / AI Agent — Database Safety Checklist

**Mandatory before any phase that may touch the database.**

---

## PRE-CURSOR CHECKLIST

```text
[ ] Vérifier APP_ENV
[ ] Vérifier DB_CONNECTION
[ ] Vérifier DB_HOST
[ ] Vérifier DB_DATABASE
[ ] Exécuter: php artisan db:safety-check
[ ] Vérifier que la base n'est pas PROTECTED avant toute opération destructive
[ ] Utiliser uniquement une base de test (gestion_test / sqlite :memory:) pour migrate:fresh
[ ] Ne jamais utiliser --force pour contourner le guard
[ ] Backup avant opération sensible (PRE-PROD 2 / ops)
[ ] Ne jamais créer de script PHP avec Artisan::call('migrate:fresh') branché sur .env local
```

---

## HARD RULES FOR AGENTS

1. **DO NOT** run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, or `db:wipe` against `gestion`.
2. **DO NOT** write temporary `scripts/*.php` that bootstrap Laravel and call destructive Artisan commands.
3. **DO** prefer PHPUnit/Pest with `phpunit.xml` (SQLite `:memory:`).
4. **DO** call `php artisan db:safety-check` and stop if `STATUS: PROTECTED` when a destructive op is planned.
5. If a command is blocked by `DATABASE SAFETY BLOCK`, treat it as **success of the safety system**, not a bug to bypass.

---

## INCIDENT REFERENCE

- Date: 2026-08-23 ~01:31 UTC
- Cause: `php scripts/measure-rbac-queries.php` → `Artisan::call('migrate:fresh', ['--force' => true])` → `.env` → `gestion`
- Mitigation: `App\Database\DestructiveCommandGuard` + `DatabaseSafetyGuard`

---

## QUICK COMMANDS

```bash
php artisan db:safety-check
php artisan db:safety-check --json
php artisan test tests/Unit/Infrastructure/DatabaseSafetyTest.php
```
