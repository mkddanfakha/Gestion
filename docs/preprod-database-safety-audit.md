# PRE-PROD 0 — Database Safety Audit

**Date :** 2026-08-23  
**Mode :** forensic + analyse statique (aucune exécution destructive)  
**Base protégée :** `gestion` — non modifiée pendant cet audit

---

## 1. Executive Summary

MKD-Pro permettait à un script CLI bootstrapant Laravel avec `.env` local d’exécuter **`migrate:fresh --force`** sur la base métier `gestion`, sans garde-fou. Aucun `.env.testing` n’existe. Les backups Spatie locaux datent de nov. 2025 et ne contiennent pas de données métier exploitables. Plusieurs autres chemins destructifs existent (restauration admin, `composer setup`, migrations data-bearing).

---

## 2. Dangerous Paths

### 2.1 Chemins confirmés dans le codebase

| Chemin | Mécanisme | DB cible | Niveau |
| ------ | --------- | -------- | ------ |
| Script CLI bootstrap Laravel | `require bootstrap/app.php` + `.env` | `gestion` (local) | **CRITIQUE** (incident) |
| `php artisan migrate:fresh` | Commande Laravel native | `.env` actif | **CRITIQUE** |
| `php artisan db:wipe` | Commande Laravel native | `.env` actif | **CRITIQUE** |
| `composer setup` | `@php artisan migrate --force` | `.env` actif | **ÉLEVÉ** |
| `BackupController::restore()` | `DROP DATABASE` + `CREATE DATABASE` + import SQL | `config(database)` | **CRITIQUE** (protégé RBAC) |
| `RefreshDatabase` (tests) | migrate:fresh en test | SQLite si PHPUnit | **FAIBLE** en CI/local si phpunit respecté |
| `php artisan test` sans phpunit | Risque si env surchargé | Variable | **MOYEN** |
| Migrations `DB::statement(UPDATE…)` | Modifie données existantes | `gestion` si migrate | **MOYEN** |
| `rbac:migrate-legacy-permissions` | Modifie pivots permissions | `.env` actif | **FAIBLE** (non destructif global) |
| `attachments:cleanup` | Supprime fichiers orphelins | Disque, pas DB tables | **FAIBLE** |
| `notifications:optimize-tables` | `OPTIMIZE TABLE` | MySQL | **FAIBLE** |
| `CreateBackupJob` | `Artisan::call('backup:run')` | Lecture DB | **SAFE** |

### 2.2 Recherche statique — occurrences

Recherche repo actuel (`migrate:fresh`, `db:wipe`, etc.) : **0 occurrence** dans le code versionné.  
Les chemins destructifs passent par **API Laravel native** ou **code applicatif** (`BackupController`, seeders, migrations).

`git log -S "migrate:fresh"` : commit `b8b45b9` signalé, mais **aucune occurrence** dans l’arbre de travail actuel → probablement historique de recherche binaire ou chaîne dans contenu non présent (script temporaire jamais commité).

`git log -S "measure-rbac-queries"` : **aucun résultat**.

---

## 3. Test Isolation Audit

### 3.1 `phpunit.xml`

```xml
<env name="APP_ENV" value="testing"/>
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

**Verdict :** configuration PHPUnit **correcte** pour isolation.

### 3.2 `tests/Pest.php`

- `RefreshDatabase` appliqué à **Feature** et à **8 fichiers Unit RBAC/Stock**
- En contexte PHPUnit → SQLite `:memory:`

### 3.3 `tests/TestCase.php`

- Pas de surcharge DB ; hérite du standard Laravel.

### 3.4 `.env.testing`

- **ABSENT** (`Test-Path .env.testing` → False)
- Risque si un outil charge `.env` au lieu de `phpunit.xml`

### 3.5 Contournements possibles

| Scénario | Risque |
| -------- | ------ |
| `php scripts/*.php` bootstrap Laravel | **CRITIQUE** — charge `.env` |
| `php -r` avec bootstrap (agent Cursor) | **CRITIQUE** — incident documenté |
| `php artisan test` | Devrait charger phpunit.xml — **SAFE** si standard |
| Test lancé via IDE sans phpunit.xml | **MOYEN** |
| CI GitHub (`.github/workflows/tests.yml`) | MySQL `gestion_test` — **isolé** |

### 3.6 CI GitHub

- Service MySQL `gestion_test`
- `cp .env.example .env` puis override DB → **correct**
- `php artisan migrate --force` sur CI uniquement
- `continue-on-error: true` partout — fiabilité CI faible mais pas de risque local direct

---

## 4. Script Audit (`scripts/*`)

| Script | Fonction | DB utilisée | Peut écrire DB ? | Peut détruire ? | Niveau |
| ------ | -------- | ----------- | ---------------- | --------------- | ------ |
| `verify-expiration-status.ts` | Tests TS expiration notifications | Aucune | Non | Non | **SAFE** |
| `detect-lan-ipv4.ts` | Détection IPv4 LAN pour Vite | Aucune | Non | Non | **SAFE** |
| `measure-rbac-queries.php` | *(supprimé, non versionné)* | `gestion` via `.env` | Oui | **Oui** (`migrate:fresh`) | **DANGEROUS** |

**Recommandation :** interdire tout nouveau script PHP bootstrap Laravel sans garde-fou DB explicite.

---

## 5. Commandes Artisan custom

| Commande | Effet DB | Protection | Risque |
| -------- | -------- | ---------- | ------ |
| `rbac:migrate-legacy-permissions` | Modifie `user_permissions` | `--dry-run`, confirmation | Faible |
| `user:set-role` | Modifie `users.role` | CLI admin | Faible |
| `attachments:cleanup` | Supprime fichiers disque | `--dry-run`, confirm | Faible (disque) |
| `stock:check-consistency` | Lecture (+ rapport) | Lecture | SAFE |
| `CheckMySQL*` / `DiagnoseMySQL` | Diagnostic | Lecture | SAFE |
| Spatie `backup:*` | Dump / cleanup archives | Planifié | SAFE (lecture + fichiers) |

**Planifié (`routes/console.php`) :**

- `backup:run` — 02:00 daily
- `backup:clean` — 03:00 daily
- `backup:monitor` — 04:00 daily
- Commandes notifications — maintenance tables

---

## 6. Migration Safety Report

### 6.1 Migrations structurelles standard

~75 migrations avec `Schema::dropIfExists` en `down()` — **normal**, destructif uniquement en rollback.

### 6.2 Migrations data-bearing / destructives

| Migration | Opération | Destructive ? | Réversible ? | Risque |
| --------- | --------- | ------------- | ------------ | ------ |
| `2025_10_20_002300_modify_customers_table_make_credit_limit_required` | `UPDATE customers SET credit_limit=0` | Oui (données) | Partiel | Moyen |
| `2025_10_20_003758_update_existing_customers_credit_limit` | `UPDATE` 0.01 ↔ 0 | Oui | Partiel | Moyen |
| `2025_10_25_052450_convert_existing_skus_to_6_chars` | Conversion SKU | Oui | Non | Moyen |
| `2025_10_25_045632_convert_existing_sale_numbers_to_9_chars` | Conversion numéros | Oui | Non | Moyen |
| `2025_11_08_030249_convert_sale_numbers_to_fa_format` | Conversion format FA | Oui | Non | Moyen |
| `2025_10_28_170137_convert_existing_po_numbers_to_new_format` | Conversion BC | Oui | Non | Moyen |
| `2025_10_25_164702_add_foreign_key_constraints_with_cascade_delete` | FK CASCADE DELETE | Oui (effet cascade) | Partiel | Élevé |
| `2026_08_10_220000_drop_credit_limit_from_customers_table` | `dropColumn credit_limit` | Oui | Oui (down) | Élevé |
| `2026_08_21_000003_initialize_main_stores_and_product_stocks` | Seed magasins/stocks via Eloquent | Oui (écrit) | Non | Moyen |
| `2025_10_25_052451_update_sku_length_to_6_chars` | `ALTER TABLE` + DROP INDEX | Oui | Partiel | Moyen |
| `2025_10_25_053130_make_sku_nullable_in_products` | `ALTER TABLE` SKU | Oui | Partiel | Moyen |

**Note :** ces migrations sont **normales en production** mais dangereuses si exécutées sur mauvaise base ou après `migrate:fresh` sur base métier.

---

## 7. Seeder Audit

| Seeder | Classification | Détail |
| ------ | -------------- | ------ |
| `PermissionSeeder` | **DEVELOPMENT / SYSTEM** | Idempotent `firstOrCreate` — utilisé post-incident |
| `DatabaseSeeder` | **DEVELOPMENT ONLY** | User test + Category/Product/Customer seeders |
| `CategorySeeder` | **DEVELOPMENT ONLY** | `Category::create` × 6 |
| `ProductSeeder` | **DEVELOPMENT ONLY** | `Product::create` × 14 |
| `CustomerSeeder` | **DEVELOPMENT ONLY** | `Customer::create` × 5 démo |

Aucun seeder ne truncate explicitement, mais **`DatabaseSeeder` sur base peuplée** ajoute des doublons démo.

---

## 8. Environment Audit

### 8.1 `.env` (valeurs non secrètes uniquement)

| Variable | Valeur |
| -------- | ------ |
| `APP_ENV` | `local` |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` | `127.0.0.1` (défaut Laravel si non surchargé) |
| `DB_PORT` | `3306` (défaut) |
| `DB_DATABASE` | **`gestion`** |
| `DB_USERNAME` | `root` |
| `DB_PASSWORD` | `[REDACTED]` |

### 8.2 `.env.example`

| Variable | Valeur |
| -------- | ------ |
| `APP_ENV` | `local` |
| `DB_CONNECTION` | `sqlite` (décalage avec prod local réel) |

### 8.3 `.env.testing`

- **Absent**

### 8.4 Risque CLI

Toute commande `php artisan …` ou script bootstrap Laravel en local charge **`.env` → `gestion`**.

`bootstrap/cache/config.php` : **absent** (pas de cache config figé).

---

## 9. Backup Audit

### 9.1 Configuration actuelle

| Paramètre | Valeur |
| --------- | ------ |
| Planification | `backup:run` 02:00, `backup:clean` 03:00, `backup:monitor` 04:00 |
| Destination | disque `local` → `storage/app/private/Gestion/` |
| Rétention | `keep_all_backups_for_days` = **7** |
| Monitor | `MaximumAgeInDays` = **1** |

### 9.2 État constaté (forensic recovery search)

- 2 ZIP : **28/11/2025** uniquement
- Dumps SQL internes : **schéma seulement**, pas de données métier
- Aucun backup juillet/août 2026
- `log_bin` = **OFF** → pas de PITR MySQL
- Backups sur **même disque** que MySQL/WAMP

### 9.3 Faiblesses

1. Rétention 7 jours insuffisante
2. Pas de copie offsite
3. Dumps Spatie sans INSERT métier (configuration dump à auditer)
4. Pas de backup obligatoire pre-Cursor / pre-migration
5. Restauration admin = `DROP DATABASE` sans garde-fou nom base

---

## 10. Failles de sécurité identifiées

| ID | Faille | Statut |
| -- | ------ | ------ |
| F1 | Script mesure bootstrap `.env` sans garde-fou | **CONFIRMÉ** (incident) |
| F2 | `migrate:fresh --force` autorisé sur `gestion` | **CONFIRMÉ** |
| F3 | Aucune liste de bases protégées | **CONFIRMÉ** |
| F4 | Absence `.env.testing` | **CONFIRMÉ** |
| F5 | Scripts et tests destructifs non séparés physiquement (DB) | **CONFIRMÉ** |
| F6 | Agent Cursor peut exécuter PHP CLI destructif | **CONFIRMÉ** |
| F7 | Backup rétention 7j + même disque | **CONFIRMÉ** |
| F8 | Pas de procédure pre-Cursor | **CONFIRMÉ** |
| F9 | `.env.example` (sqlite) ≠ `.env` réel (mysql gestion) | **CONFIRMÉ** |
| F10 | `BackupController::restoreDatabase` DROP/CREATE DATABASE | **CONFIRMÉ** (protégé RBAC seulement) |
| F11 | `composer setup` lance `migrate --force` | **CONFIRMÉ** |

---

## 11. Attachments / données résiduelles

- 5 fichiers attachments disque (`quotes/2,5`, `expenses/3`, `purchase-orders/2`)
- Exports Excel/PDF août 2026 dans `Downloads` (hors scope implémentation)

---

## 12. Git / terminaux

| Source | Finding |
| ------ | ------- |
| Git `measure-rbac-queries` | Jamais commité |
| Git `migrate:fresh` | Aucune occurrence working tree |
| Terminal `216656.txt` | Tests + script — corrélation 01:31 UTC |
| Terminal `216654.txt` | Script seul — 01:24 UTC |
| Transcript Cursor | Création script avec `migrate:fresh` documentée |

---

## 13. Tests de sécurité à prévoir (post-validation)

Voir `docs/preprod-database-hardening-plan.md` section 16 — 6 tests proposés, **jamais sur `gestion`**.
