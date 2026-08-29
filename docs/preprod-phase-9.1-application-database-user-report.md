# PRE-PROD 9.1 — Application Database User Report

**Date :** 2026-08-24  
**Mode :** Account created + privilege validation. **`.env` cutover NOT executed** (PRE-PROD 9.2).

---

## Executive Summary

Compte runtime least-privilege **`gestion_app`** créé sur MySQL avec **CRUD only** (`SELECT, INSERT, UPDATE, DELETE` sur `gestion.*`).  
Laravel continue d’utiliser **`root`** via `.env` — bascule volontairement reportée à PRE-PROD 9.2.

Mot de passe stocké uniquement dans **`.mysql-gestion-app.local`** (gitignoré). Non documenté ici.

---

## Before / After

### Before

```text
Laravel → root → gestion (*.* admin)
gestion_app → NOT CREATED
```

### After (PRE-PROD 9.1)

```text
Laravel → root          (inchangé — .env NOT modified)
gestion_app@localhost   → CRUD on gestion.*
gestion_app@127.0.0.1   → CRUD on gestion.*
```

---

## DATABASE SAFETY BASELINE

```text
Database: gestion
User (Laravel runtime): root  ← still
App account: gestion_app      ← created, not wired
Host: 127.0.0.1:3306
Environment: local

WARNING:
Laravel currently still uses MySQL root.
Protected by DatabaseSafetyGuard: YES
MySQL least-privilege account: CREATED (not yet used by app)
```

### gestion row counts (after CREATE + validation)

| Table | Count |
|-------|------:|
| users | 3 |
| customers | 0 |
| products | 0 |
| sales | 0 |
| quotes | 0 |
| expenses | 0 |
| suppliers | 0 |
| purchase_orders | 0 |
| delivery_notes | 0 |
| inventory_sessions | 0 |
| stock_movements | 0 |

```text
GESTION UNCHANGED: YES
```

---

## HUMAN APPROVAL

```text
Approved: OUI — CRUD only
Date: 2026-08-24
Privileges granted: SELECT, INSERT, UPDATE, DELETE ON gestion.*
Optional LOCK TABLES / CREATE TEMPORARY TABLES: NOT GRANTED
```

---

## Account creation (executed)

```sql
CREATE USER IF NOT EXISTS 'gestion_app'@'localhost' IDENTIFIED BY '***SECRET***';
CREATE USER IF NOT EXISTS 'gestion_app'@'127.0.0.1' IDENTIFIED BY '***SECRET***';
GRANT SELECT, INSERT, UPDATE, DELETE ON gestion.* TO 'gestion_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON gestion.* TO 'gestion_app'@'127.0.0.1';
FLUSH PRIVILEGES;
```

### SHOW GRANTS (verified)

```text
GRANT USAGE ON *.* TO `gestion_app`@`localhost`
GRANT SELECT, INSERT, UPDATE, DELETE ON `gestion`.* TO `gestion_app`@`localhost`

GRANT USAGE ON *.* TO `gestion_app`@`127.0.0.1`
GRANT SELECT, INSERT, UPDATE, DELETE ON `gestion`.* TO `gestion_app`@`127.0.0.1`
```

Static proof: **no** `DROP`, `CREATE`, `ALTER`, `GRANT OPTION`, or `*.*` admin on `gestion_app`.

---

## Privilege Matrix

| Compte | Connexion Laravel | Global | gestion.* | DROP DB | GRANT |
|--------|-------------------|--------|-----------|---------|-------|
| root | YES (current) | ADMIN | ALL | YES | YES |
| gestion_app | NO (cutover pending) | USAGE only | CRUD | NO | NO |

---

## Validation results

### Allowed (PASS)

| Test | Result |
|------|--------|
| CONNECT as gestion_app | PASS (`gestion_app@localhost` / `127.0.0.1`) |
| SELECT (users, customers) | PASS (3 / 0) |
| INSERT into `cache` | PASS |
| UPDATE `users` (no-op `updated_at`) | PASS |
| DELETE residual probe row | PASS |

**Note MyISAM :** toutes les tables `gestion` sont **MyISAM**. `START TRANSACTION` / `ROLLBACK` ne sont pas effectifs. Preuve CRUD = INSERT puis DELETE de nettoyage (clé `__mkd_priv_test__` absente après test). Aucune donnée métier persistée.

### Denied (PASS = ACCESS DENIED)

| Operation | Result |
|-----------|--------|
| DROP DATABASE gestion | DENIED (1044) |
| CREATE DATABASE test_forbidden_mkd | DENIED (1044) |
| DROP TABLE gestion.users | DENIED (1142) |
| ALTER TABLE gestion.users … | DENIED (1142) |
| CREATE USER | DENIED (1227) |
| GRANT ALL ON *.* | DENIED (1045) |

---

## Runtime Compatibility

```text
NOT TESTED via Laravel (.env still root)
Account capability: VERIFIED at MySQL CLI
```

Smoke post-cutover (PRE-PROD 9.2) : login, dashboard, CRUD métier, jobs, cache, sessions, PDF/Excel.

---

## Laravel Protection

Still PASS (PRE-PROD 1–8). Indépendant du compte MySQL.

## MySQL Protection

```text
ACCOUNT CREATED: YES
ENV CUTOVER: NOT EXECUTED
RUNTIME BOUNDARY: PARTIAL (account exists; app still root)
```

---

## Config / artefacts

| Artefact | Status |
|----------|--------|
| `config/database-accounts.php` | Policy CRUD-only |
| `tests/Unit/Infrastructure/ApplicationDatabaseUserTest.php` | Policy tests |
| `.mysql-gestion-app.local` | Password (gitignored) |
| `.gitignore` | Contains `.mysql-gestion-app.local` |
| `.env` | **NOT modified** |

---

## Remaining Risks

1. `.env` still uses `root` → full admin until PRE-PROD 9.2
2. Backup / restore / migration accounts not configured
3. MySQL CLI as root can still destroy `gestion`
4. Offsite backup still missing
5. Schéma entièrement MyISAM (pas de transactions ACID)

---

## Status block

```text
ACCOUNT CREATED: YES
ENV CUTOVER: NOT EXECUTED
GESTION UNCHANGED: YES
CRUD VALIDATED: YES
DDL/ADMIN DENIED: YES
PRODUCTION STATUS: READY WITH CONDITIONS
HUMAN ACTION REQUIRED: YES (PRE-PROD 9.2 APPLICATION CUTOVER)
NEXT STEP: Explicit approval to switch .env DB_USERNAME/DB_PASSWORD to gestion_app + smoke tests
```
