# PRE-PROD 9.4.1 — Migration Preflight Audit

**Date :** 2026-08-26  
**Mode :** READ-ONLY — aucun `migrate`, seed, DDL/DML métier, GRANT/REVOKE, ni modification `.env`  
**Approbation migrate :** **NON demandée / NON exécutée**

---

## Verdict

```text
MIGRATION PREFLIGHT: PASS

MIGRATION PRIVILEGE GAP (DROP TABLE):
NO

DROP privilege required for empty-DB migrate path:
NO

Re-GRANT DROP:
NOT PROPOSED
```

**Lecture :** sur une base `gestion` **vide**, le parcours `up()` des 75 migrations n’appelle **aucun** `DROP TABLE` / `Schema::dropIfExists`. Les opérations `DROP COLUMN` / `DROP INDEX` / `DROP FOREIGN KEY` passent par `ALTER TABLE` et sont couvertes par les privilèges actuels (`ALTER`, `INDEX`, `REFERENCES`) **sans** `DROP`.

**Ne pas confondre avec l’exécution Artisan :** le `.env` runtime actuel utilise `gestion_app` ; `PrivilegedCommandGuard` **refusera** `php artisan migrate` tant que la connexion MySQL n’utilise pas `gestion_migration` (wiring credentials — hors scope privilège DROP).

---

## 1. Nombre total de migrations

| Emplacement | Fichiers |
|-------------|----------|
| `database/migrations/` | 72 |
| `app/Modules/NotificationCenter/Database/Migrations/` (via `loadMigrationsFrom`) | 3 |
| **Total** | **75** |

---

## 2. Opérations DDL/DML détectées (méthodes `up()` uniquement)

Analyse statique du corps de `up()` — les `down()` **ne s’exécutent pas** lors d’un `migrate` neuf.

| Opération | Occurrences / fichiers (up) | Privilège MySQL typique |
|-----------|----------------------------|-------------------------|
| `Schema::create` | 37 appels | CREATE (+ REFERENCES si FK) |
| `Schema::table` (ALTER) | 47 appels | ALTER |
| `foreignId` / `foreign` | 58 appels | REFERENCES (+ ALTER/CREATE) |
| `dropColumn` | 1 fichier | **ALTER** (pas DROP TABLE) |
| `dropUnique` / `DROP INDEX` | 5 fichiers | **ALTER** / INDEX |
| `dropForeign` | 3 fichiers | **ALTER** + REFERENCES |
| `Schema::dropIfExists` / `DROP TABLE` | **0** | DROP — **N/A** |
| `TRUNCATE` | **0** | — |
| `RENAME TABLE` | **0** | — |
| `DB::statement` (raw) | 4 fichiers | ALTER / UPDATE selon SQL |
| DML / Eloquent data migrations | plusieurs (no-op sur base vide) | SELECT/INSERT/UPDATE/DELETE |

### Raw SQL (`up`)

| Fichier | Contenu typique |
|---------|-----------------|
| `2025_10_20_002300_modify_customers_table_make_credit_limit_required.php` | ALTER / `change()` |
| `2025_10_20_003758_update_existing_customers_credit_limit.php` | `UPDATE customers …` (0 lignes si vide) |
| `2025_10_25_052451_update_sku_length_to_6_chars.php` | `ALTER TABLE … DROP INDEX` + MODIFY + ADD UNIQUE |
| `2025_10_25_053130_make_sku_nullable_in_products.php` | idem DROP INDEX / MODIFY |

### Data migrations (`up`) — impact sur base vide

| Fichier | Comportement attendu si 0 lignes métier |
|---------|----------------------------------------|
| convert sale numbers / SKUs / PO / DN | no-op (collections vides) |
| `2026_08_21_000003_initialize_main_stores_and_product_stocks.php` | `Company::query()` vide → no-op |
| `2026_08_21_000005_create_opening_balance_movements.php` | stocks vides → no-op |
| Notification settings create | structure seulement |

---

## 3. Migrations nécessitant potentiellement DROP

### A. `DROP TABLE` / destruction base — **AUCUNE en `up()`**

```text
Schema::dropIfExists / Schema::drop / DROP TABLE / DROP DATABASE in up():
0 files
```

Les `Schema::dropIfExists` présents dans le dépôt sont **uniquement dans `down()`** → non exécutés par `migrate` (forward).

### B. Opérations « DROP* » en `up()` (non équivalentes à `DROP` privilege table/database)

| # | Fichier | Opération exacte | Priv. requis | Exécuté sur base vide ? | Problème sans priv. DROP ? |
|---|---------|------------------|--------------|-------------------------|----------------------------|
| 1 | `2025_10_25_042542_update_sale_number_length_final.php` | `dropUnique(['sale_number'])` | ALTER/INDEX | OUI | **NON** |
| 2 | `2025_10_25_045631_update_sale_number_length_to_9_chars.php` | `dropUnique(['sale_number'])` | ALTER/INDEX | OUI | **NON** |
| 3 | `2025_10_25_052451_update_sku_length_to_6_chars.php` | `ALTER TABLE … DROP INDEX` | ALTER/INDEX | OUI (si index existe) | **NON** |
| 4 | `2025_10_25_053130_make_sku_nullable_in_products.php` | `ALTER TABLE … DROP INDEX` | ALTER/INDEX | OUI | **NON** |
| 5 | `2025_10_25_164702_add_foreign_key_constraints_with_cascade_delete.php` | `dropForeign` ×5 puis recreate | ALTER + REFERENCES | OUI | **NON** |
| 6 | `2025_10_28_161101_make_purchase_order_id_required_in_delivery_notes.php` | `dropForeign` + `change()` | ALTER + REFERENCES | OUI | **NON** |
| 7 | `2026_07_31_000001_make_purchase_order_id_nullable_on_delivery_notes.php` | `dropForeign` + `change()` | ALTER + REFERENCES | OUI | **NON** |
| 8 | `2026_08_10_220000_drop_credit_limit_from_customers_table.php` | `dropColumn('credit_limit')` | **ALTER** | OUI | **NON** |
| 9 | `2026_08_17_000001_add_scope_context_to_form_drafts_table.php` | `dropUnique('form_drafts_unique_scope')` | ALTER/INDEX | OUI | **NON** |

**Référence MySQL :** le privilège `ALTER` sur une table inclut la capacité d’ajouter/supprimer colonnes et index via `ALTER TABLE`. Le privilège `DROP` est requis pour `DROP TABLE` / `DROP DATABASE` (et partitions) — **confirmé dangereux en 9.3.3** — **non requis** pour ce parcours `up()`.

```text
MIGRATION PRIVILEGE GAP: NO
(ne pas réaccorder DROP)
```

---

## 4. Migrations nécessitant CREATE / ALTER / INDEX / REFERENCES / DML

| Besoin | Couvert par grants actuels ? |
|--------|------------------------------|
| CREATE TABLE | OUI (`Create_priv=Y`) |
| ALTER TABLE / ADD COLUMN / MODIFY | OUI (`Alter_priv=Y`) |
| INDEX / UNIQUE | OUI (`Index_priv=Y`) |
| FOREIGN KEY (REFERENCES) | OUI (`References_priv=Y`) |
| SELECT/INSERT/UPDATE/DELETE data migrations | OUI |
| DROP TABLE / DROP DATABASE | **ABSENT** — acceptable car non requis en `up()` |

---

## 5. Privilèges actuels `gestion_migration`

```text
gestion_migration@localhost:
GRANT USAGE ON *.*
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER
  ON `gestion`.*

gestion_migration@127.0.0.1:
GRANT USAGE ON *.*
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER
  ON `gestion`.*

mysql.db Drop_priv = N (both hosts)
```

| Privilège | Statut |
|-----------|--------|
| CREATE | **présent** |
| ALTER | **présent** |
| DROP | **ABSENT** |
| INDEX | **présent** |
| REFERENCES | **présent** |
| SELECT | **présent** |
| INSERT | **présent** |
| UPDATE | **présent** |
| DELETE | **présent** |
| CREATE DATABASE | **refusé** (USAGE only on `*.*`) |
| DROP DATABASE | **refusé** (pas de DROP ; probe 9.4 ACCESS_DENIED) |
| CREATE USER | **refusé** |
| GRANT OPTION | **absent** |
| Autres bases (`gestion_recovery`, etc.) | **pas de grant** sur ces schémas |

**Aucun GRANT/REVOKE exécuté pendant ce préflight.**

---

## 6. Compatibilité migration ↔ privilèges

```text
COMPATIBILITY: PASS

Empty-DB forward migrate requires DROP privilege: NO
DROP TABLE in up(): 0
DROP COLUMN / INDEX / FK in up(): covered by ALTER+INDEX+REFERENCES
```

### Risques opérationnels (hors privilege DROP)

| Risque | Niveau | Détail |
|--------|--------|--------|
| Wiring Artisan | **HIGH** pour exécution | `.env` → `DB_USERNAME=gestion_app` ; `PrivilegedCommandGuard` exige `gestion_migration` pour `migrate` MySQL |
| Secret `.mysql-gestion-migration.local` | **HIGH** | connexions 1045 observées précédemment — resync humain avant migrate |
| `->change()` sans `doctrine/dbal` | MEDIUM | Laravel 12 + native schema ; surveiller échecs éventuels sur MODIFY/enum |
| Migrations Eloquent (stores/stocks) | LOW | no-op si aucune company |
| Engine MyISAM / FK | LOW/INFO | historique projet ; FK peuvent être non enforced selon engine |

---

## 7. Protections confirmées (code)

| Composant | Rôle | Statut |
|-----------|------|--------|
| `PrivilegedCommandGuard` | `migrate` MySQL ⇒ compte `gestion_migration` | **actif** (`AppServiceProvider` → `CommandStarting`) |
| `DatabaseAccountGuard::assertAccountForOperation('migration')` | fail-closed si username ≠ migration | **actif** |
| `DestructiveCommandGuard` + `DatabaseSafetyGuard` | bloque `migrate:fresh/refresh/reset`, `db:wipe`, `db:seed` sur `gestion` | **actif** (`db:seed` listé dans `config/database-safety.php`) |
| Protected migrate:* command classes | remplacement container | **présent** |
| `--force` | ne contourne pas | **confirmé** (tests BypassAssurance) |
| Matching DB | exact + case-insensitive | **policy** |
| Restore allow-list | jamais `gestion` | **policy** |
| Règle Cursor | `.cursor/rules/database-safety-gestion.mdc` | **présent** |

```text
php artisan migrate (mysql + gestion_migration):
ALLOWED by policy (account gate)

php artisan migrate (mysql + gestion_app):
BLOCKED by PrivilegedCommandGuard

migrate:fresh / refresh / reset / db:wipe / db:seed on gestion:
BLOCKED by DatabaseSafetyGuard
```

---

## 8. Risques éventuels (synthèse)

1. **Ne pas** réaccorder `DROP` — non nécessaire pour ce préflight PASS.
2. Avant un futur migrate approuvé : basculer credentials **temporairement** vers `gestion_migration` (hors Git), sans laisser ce compte en runtime HTTP.
3. Resynchroniser le mot de passe `gestion_migration` si 1045 persiste.
4. Après migrate : revenir `.env` sur `gestion_app`.
5. Ne jamais `migrate:fresh` sur `gestion`.

---

## 9. Verdict final

```text
MIGRATION PREFLIGHT: PASS

Reason:
- 75 migrations audited (up() only)
- 0 DROP TABLE / dropIfExists in forward path
- DROP COLUMN / INDEX / FK covered without DROP privilege
- Current grants sufficient for CREATE/ALTER/INDEX/REFERENCES/DML
- Re-GRANT DROP: NOT RECOMMENDED / NOT PROPOSED

Execution status:
migrate NOT run
```

---

## Baseline (fin de préflight)

```text
gestion = PRESENT
gestion = 0 tables
gestion_recovery = UNCHANGED (27 tables)
migration executed = NO
seed executed = NO
restore executed = NO
privileges modified = NO
.env modified = NO
FILES MODIFIED (app/DB): NO
REPORT CREATED: docs/preprod-phase-9.4.1-migration-preflight-report.md
```

---

## STOP — approbation humaine requise pour la suite

Aucune migration n’a été exécutée.

Pour autoriser l’étape suivante (hors scope de ce document), une phrase d’approbation **explicite** reste nécessaire, par exemple :

```text
OUI — RUN MIGRATE ON gestion
```

Avant exécution, l’opérateur devra aussi résoudre le **wiring** `gestion_migration` (username + secret), sans modifier les grants DROP.
