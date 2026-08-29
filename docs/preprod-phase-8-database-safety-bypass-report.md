# PRE-PROD 8 — DATABASE SAFETY BYPASS ASSURANCE

**Date :** 2026-08-24  
**Scope :** Audit + hardening applicatif (jamais MySQL privileges — PRE-PROD 9)  
**Base métier :** `gestion` — READ ONLY pendant toute la phase

---

## ROOT CAUSE

Cause initiale (`Artisan::call('migrate:fresh', ['--force' => true])` via script bootstrap + `.env` → `gestion`) :

```text
STATUS: BLOCKED (wrappers + DestructiveCommandGuard + tests)
```

---

## DATABASE SAFETY

**PASS** (avec warnings documentés ci-dessous)

Kill switch central : `DatabaseSafetyGuard` (single source of truth).

---

## ARTISAN

**PASS** pour : `migrate:fresh|refresh|reset`, `db:wipe`, `db:seed`, `db:restore`

**WARNING** : `migrate` (non-fresh), `migrate:rollback` ne sont **pas** dans le kill switch wipe — peuvent altérer le schéma/données de `gestion` sans DROP total.

| COMMAND | DESTRUCTIVE? | GUARD | FORCE BYPASS? | STATUS |
|---------|--------------|-------|---------------|--------|
| migrate:fresh | YES | YES | NO | BLOCKED on gestion |
| migrate:refresh | YES | YES | NO | BLOCKED |
| migrate:reset | YES | YES | NO | BLOCKED |
| db:wipe | YES | YES | NO | BLOCKED |
| db:seed | YES (write) | YES (P8) | NO | BLOCKED |
| db:restore | YES | YES | NO | BLOCKED if target=gestion |
| migrate | schema write | NO | n/a | WARNING |
| migrate:rollback | schema write | NO | n/a | WARNING |
| backup:run/status/verify | NO / read | n/a | n/a | SAFE |
| db:safety-check | NO | n/a | n/a | SAFE |
| products:refresh-stock-expiration | UPDATE | NO | n/a | WARNING (ops) |

---

## ARTISAN::CALL

**PASS** — container `$app->extend` + `CommandStarting` + tests `Artisan::call(... --force)`.

---

## --force

**PASS** — `--force` ne désactive jamais `DatabaseSafetyGuard`.

---

## SCRIPTS

**PASS** — `scripts/` = TypeScript only (pas de bootstrap Laravel / Artisan / PDO).

Script incident `measure-rbac-queries.php` : **absent** (non versionné).

---

## COMPOSER

**WARNING**

```json
"setup": [ ..., "@php artisan migrate --force", ... ]
```

- Ne lance **pas** `migrate:fresh` / `db:wipe`
- Peut quand même **migrer le schéma** de `gestion` si `.env` pointe dessus
- `post-create-project-cmd` : `migrate --graceful` (même classe de risque)

**Correction proposée (non auto-appliquée au flux composer) :**  
documenter « ne jamais `composer setup` sur machine dont `DB_DATABASE=gestion` » ; éventuellement wrapper `MigrateCommand` dans une phase ultérieure.

---

## TEST ISOLATION

**PASS**

- `phpunit.xml` → SQLite `:memory:`
- `.env.testing` → SQLite `:memory:`
- `ProductionDatabaseMustNeverBeUsedByTests` présent
- Suite Infrastructure : **74 PASS**

---

## HTTP RESTORE

**PASS**

- Cible explicite obligatoire
- Allow-list exacte (case-insensitive)
- `gestion` → BLOCK
- Mode DB-only
- Phrase `RESTORE`
- Lock global
- Code mort `DROP DATABASE` retiré du controller (P8)

---

## JOBS

**PASS** — `CreateBackupJob` → `backup:run` seulement (sous lock). Pas de restore/migrate.

---

## SERVICES

**PASS**

```text
DatabaseSafetyGuard → target + operation → ALLOW/BLOCK
DatabaseRestoreService → explicit target only
ApplicationFilesRestoreService → séparée, live disabled
BackupConcurrencyGuard → locks
```

Hardening P8 : matching **case-insensitive** pour `gestion` / allow-list (Windows MySQL).

---

## MIGRATIONS

**WARNING** — migrations DATA-BEARING / `dropColumn` historiques existent ; exécution via `migrate` sur `gestion` non bloquée (voir ARTISAN).

---

## SEEDERS

**PASS** (après P8) — `db:seed` bloqué sur `gestion` via `ProtectedSeedCommand`.

Seeders (`DatabaseSeeder` + factories) = DEVELOPMENT/TEST — dangereux s’ils tournaient sur métier (désormais bloqués).

---

## PDO / DIRECT DATABASE ACCESS

**WARNING** (limite connue → PRE-PROD 9)

```text
Laravel protection ≠ MySQL privilege protection
```

Un client `mysql` CLI / PDO hors app avec droits root peut encore `DROP DATABASE gestion`.

Importer app : `MysqlPdoDumpImporter` refuse dump contenant `DROP DATABASE gestion` ; DROP réel uniquement après `assertExplicitRestoreTarget`.

---

## BYPASS TESTS

```text
74/74 PASS (Unit/Infrastructure, dont BypassAssuranceTest)
```

Scénarios couverts notamment :

| # | Scénario | Résultat |
|---|----------|----------|
| 1 | allow-list matrix (gestion*, GESTION, …) | PASS |
| 2 | APP_ENV local/testing/production/staging + gestion | BLOCKED |
| 3 | Artisan::call migrate:fresh --force | BLOCKED |
| 4 | migrate:refresh/reset/wipe --force | BLOCKED |
| 5 | db:seed --force + gestion | BLOCKED |
| 6 | db:restore --target=gestion --force | BLOCKED |
| 7 | DROP DATABASE gestion / GESTION | BLOCKED |
| 8 | restore sans target (config mysql) | BLOCKED |
| 9 | HTTP-style target=gestion / gestion_backup | BLOCKED |
| 10 | POSITIVE :memory: / allow-list recovery|test | PASS |
| 11 | scripts/ sans PHP bootstrap destructif | PASS |
| 12 | composer setup ≠ migrate:fresh | PASS (documenté) |
| 13 | dump DROP gestion refusé | PASS |

---

## GESTION BASELINE

```text
HOST: 127.0.0.1
DATABASE: gestion
USER: root
APP_ENV: local

users=3
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

## GESTION FINAL

Identique au baseline.

## GESTION UNCHANGED

```text
YES
```

---

## FILESYSTEM

Restore non exécuté en P8. Modifications = code de protection / tests / docs uniquement.

## GIT

```text
MODIFIED
```

Fichiers touchés par PRE-PROD 8 (principaux) :

- `app/Database/DatabaseSafetyGuard.php` (case-insensitive)
- `app/Database/Console/ProtectedSeedCommand.php` (nouveau)
- `app/Providers/AppServiceProvider.php`
- `config/database-safety.php` (`db:seed`)
- `app/Http/Controllers/Admin/BackupController.php` (purge code mort DROP)
- `tests/Unit/Infrastructure/BypassAssuranceTest.php` (nouveau)
- `tests/Unit/Infrastructure/DatabaseSafetyTest.php`
- `docs/preprod-phase-8-database-safety-bypass-report.md`

---

## FINDINGS

| ID | Severity | Finding | Status |
| -- | -------- | ------- | ------ |
| F8-01 | HIGH | `GESTION`/`Gestion` non protégés (match strict) sur Windows MySQL | **FIXED** (strcasecmp) |
| F8-02 | MEDIUM | `db:seed` non bloqué sur `gestion` | **FIXED** (ProtectedSeedCommand) |
| F8-03 | HIGH | Code mort `BackupController::restoreDatabase` contenait encore `DROP DATABASE` | **FIXED** (méthodes retired stubs only) |
| F8-04 | MEDIUM | `composer setup` → `migrate --force` sur `.env` métier | **DOCUMENTED** (WARNING) |
| F8-05 | MEDIUM | `migrate` / `migrate:rollback` non dans kill switch | **DOCUMENTED** (WARNING) |
| F8-06 | HIGH | PDO/`mysql` CLI hors Laravel | **DEFERRED** → PRE-PROD 9 |
| F8-07 | LOW | Commandes métier UPDATE (`products:refresh-stock-expiration`) | **DOCUMENTED** |

---

## FINAL VERDICT

```text
DATABASE SAFETY VERIFIED WITH WARNINGS
```

Bypass Laravel connus (fresh/wipe/seed/restore/Artisan::call/--force/APP_ENV/allow-list) : **bloqués et testés**.  
Risques résiduels : `migrate` non-fresh, composer setup, MySQL root CLI.

---

## NEXT STEP

```text
PRE-PROD 9 — MYSQL PRIVILEGE HARDENING
```
