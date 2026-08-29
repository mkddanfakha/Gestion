# PRE-PROD — Post-Migration Schema Verification

**Date :** 2026-08-26  
**Mode :** READ-ONLY (données / privilèges / `.env`)  
**Seed / restore / import / migrate / GRANT / REVOKE :** **NON exécutés**

---

```text
PRE-PROD — POST-MIGRATION SCHEMA VERIFICATION

TABLES EXPECTED / FOUND:
38 / 38
MISSING: []
EXTRA: []

MIGRATIONS:
75 / 75
last: 2026_08_22_000002_add_application_summary_to_inventory_sessions_table

BUSINESS DATA:
EMPTY (all métier tables = 0 rows)
migrations table: 75 rows (metadata only)

gestion_recovery:
UNCHANGED — 27 tables
users=4 customers=7 products=20 sales=9

RUNTIME ACCOUNT:
gestion_app (.env)

MIGRATION ACCOUNT:
gestion_migration — DROP ABSENT
grants: SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER ON gestion.*

APP ACCOUNT:
gestion_app — SELECT, INSERT, UPDATE, DELETE ON gestion.*
Drop/Create/Alter/Index/References: N

gestion DESTROYABLE BY gestion_app:
NO (no DROP privilege)

SECURITY GUARDS:
ACTIVE (DestructiveCommandGuard + PrivilegedCommandGuard + DatabaseSafetyGuard)

PEST INFRASTRUCTURE:
103 passed (255 assertions)

BUILD:
NOT REQUIRED (skipped — no frontend change in this audit)

ANOMALIES:
INFO — all tables ENGINE=MyISAM → foreign_keys_count=0 (FK non enforced by MySQL)
INFO — column customers.birthday present (not date_of_birth) — matches migration

VERDICT:
PASS

NEXT:
STOP — human approval required before seed / backup / restore / any write
```

---

## 1. Tables

| Attendu | Trouvé | Missing | Extra |
|--------:|-------:|---------|-------|
| 38 | 38 | aucune | aucune |

Liste alignée sur `docs/preprod-9.4-schema-baseline.md`.

## 2. Migrations

| Check | Résultat |
|-------|----------|
| Lignes `gestion.migrations` | **75** |
| Dernière migration | `2026_08_22_000002_add_application_summary_to_inventory_sessions_table` |

## 3. Colonnes / index / contraintes

| Check | Résultat |
|-------|----------|
| Colonnes critiques inventaire / stock / attachments / identité client | **PASS** (`birthday`, `nationality`, `identity_document_*`, `application_summary`, `scope_context`, …) |
| `customers.credit_limit` | **ABSENT** (attendu post `drop_credit_limit`) |
| Unique `(company_id, reference)` sur `inventory_sessions` | **PRESENT** |
| Contraintes UNIQUE | **20** |
| FOREIGN KEY MySQL | **0** — toutes les tables en **MyISAM** (FK déclarées en migrations non appliquées par le moteur) |

## 4. Données métier `gestion`

```text
users=0 companies=0 customers=0 products=0 sales=0 …
permissions=0 attachments=0 inventory_*=0 …
business_nonzero=[]
```

Aucune donnée 2025 / août importée. Aucun seed.

## 5. `gestion_recovery`

| Métrique | Valeur |
|----------|--------|
| Tables | **27** (baseline drill) |
| users / customers / products / sales | 4 / 7 / 20 / 9 |
| Modifiée pendant cet audit | **NON** |

## 6–7. Comptes MySQL

### `gestion_migration`

```text
SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER ON gestion.*
Drop_priv=N
```

`DROP` : **ABSENT** ✓  
(DELETE présent en plus de la liste minimale — normal pour migrations data.)

### `gestion_app`

```text
SELECT, INSERT, UPDATE, DELETE ON gestion.*
Drop_priv=N Create_priv=N Alter_priv=N Index_priv=N References_priv=N
```

## 8. Runtime

```text
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=gestion
DB_USERNAME=gestion_app
DB_PASSWORD=(set — non affiché)
```

`.env` **non modifié** pendant cet audit. Aucun secret exposé.

## 9. Destruction de `gestion` par `gestion_app`

Impossible via MySQL : pas de privilège `DROP` → `DROP DATABASE` / `DROP TABLE` refusés.  
Inférence grants + `mysql.db` (aucune commande destructive exécutée).

## 10. Protections Laravel

| Guard | Statut |
|-------|--------|
| `DestructiveCommandGuard` | enregistré (`CommandStarting`) |
| `PrivilegedCommandGuard` | enregistré (`migrate` / `backup:run`) |
| `DatabaseSafetyGuard` | `gestion` protégée ; restore allow-list hors `gestion` |
| `db:seed` / `migrate:fresh` / `db:wipe` | bloqués sur `gestion` (tests PASS) |

## 11. Secrets

- Aucun mot de passe affiché
- Aucun `.env` / `.mysql-*.local` commité dans cette phase
- Pest : assertions « password never exposed » PASS

## 12. Pest (sécurité)

```text
php vendor/bin/pest tests/Unit/Infrastructure
Tests: 103 passed (255 assertions)
```

Suites : AccountSeparation, ApplicationDatabaseUser, BypassAssurance, BackupStrategy, DatabaseRestoreArchitecture, DatabaseSafety, MysqlPrivilegeBoundary, RestoreSafety.

## 13. Build

**Non nécessaire** pour cette vérification schéma/DB — **non exécuté**.

---

## Baseline final

```text
gestion = PRESENT
gestion tables = 38
gestion business rows = 0
gestion migrations = 75
gestion_recovery = UNCHANGED (27 tables)
migration executed this phase = NO
seed executed = NO
restore executed = NO
privileges modified = NO
.env modified = NO
DATA WRITES = NO
```

## Anomalies

| Sévérité | Item |
|----------|------|
| INFO | Engine MyISAM global → FK MySQL non enforce (comportement historique WAMP / dump) |
| NONE | Pas d’écart bloquant schéma vs migrations actuelles |

```text
VERDICT: PASS
STATUS: POST-MIGRATION VERIFICATION COMPLETE
STOP: HUMAN APPROVAL REQUIRED BEFORE NEXT OPERATION
```
