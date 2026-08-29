# PRE-PROD 5 — Restore drill report

**Type :** RESTORE DRILL — NOT PRODUCTION RESTORE  
**Date :** 2026-08-24  
**Note :** ZIP de test `2025-11-28-19-59-37.zip` — **pas** une recovery des données métier d’août 2026.

## SHOW DATABASES

```text
gestion
gestion_recovery   ← créée (autorisation humaine) puis restaurée (DB-only)
...
```

## RESTORE DRILL RESULT

```text
BACKUP: 2025-11-28-19-59-37.zip
SHA256: ae7506087d723db902f1e55452fca50eb4217f2b93247f8e20601eed7b763732
TARGET: gestion_recovery
MODE: database (DB-only)
COMMAND: php artisan db:restore --backup=2025-11-28-19-59-37.zip --target=gestion_recovery --confirmation=RESTORE
STATUS: PASS (après correctif split SQL quote-aware)
DURATION_MS: 951
TABLES: 27
ERRORS: 0 (retry)
WARNINGS: CAUTION_CONTAINS_DOTENV (archive historique); dump nov.2025 ≠ août 2026
INTEGRITY: PASS (tables métier présentes, counts > 0)
FILES TOUCHED: NO
APPLICATION FILES MODIFIED: NO
DATABASE gestion TOUCHED: NO
.env: UNCHANGED (DB_DATABASE=gestion)
```

### Première tentative

Échec : split PDO naïf sur `;` cassait `INSERT INTO sessions` (payload PHP sérialisé).  
État partiel recovery (`cache` seule) — **recréé** au retry via `DROP/CREATE gestion_recovery` uniquement.

### Correctif

`MysqlPdoDumpImporter` : split quote-aware + strip `USE` / `CREATE DATABASE` / `DROP DATABASE` du dump.

## Recovery row counts (après restore)

| Table | Count |
|-------|------:|
| users | 4 |
| companies | 1 |
| customers | 7 |
| products | 20 |
| categories | 7 |
| sales | 9 |
| sale_items | 19 |
| quotes | 1 |
| quote_items | 2 |
| expenses | 2 |
| suppliers | 2 |
| purchase_orders | 3 |
| purchase_order_items | 7 |
| delivery_notes | 7 |
| delivery_note_items | 26 |

## GESTION BASELINE = FINAL

| Table | Before | After |
|-------|-------:|------:|
| users | 3 | 3 |
| customers | 0 | 0 |
| products | 0 | 0 |
| sales | 0 | 0 |
| quotes | 0 | 0 |
| expenses | 0 | 0 |

**GESTION UNCHANGED: YES**

## Critical file hashes

`.env`, `composer.json`, `package.json`, `config/database.php`, `config/backup.php`, `bootstrap/app.php`, `routes/web.php`, `BackupController.php` — **identiques** avant/après.
