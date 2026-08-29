# PRE-PROD 9.3.4 — Recovery Validation & Safe Production Restore Plan

**Date :** 2026-08-25 / 2026-08-26  
**Mode :** READ-ONLY + DRY-RUN + PLAN ONLY  
**Écritures :** AUCUNE (`gestion_recovery` inchangée, `gestion` non recréée, aucun REVOKE/GRANT/RESTORE)

---

```text
PRE-PROD 9.3.4

RECOVERY VALIDATION

ROOT CAUSE:
IDENTIFIED
(GRANT DROP ON gestion.* ⇒ MySQL autorise DROP DATABASE gestion ;
probe 9.3.3 a exécuté DROP DATABASE gestion)

gestion:
ABSENT

gestion_recovery:
PRESENT

RECOVERY DATA:
VERIFIED
(métier non vide : clients/produits/ventes/… présents)

AUGUST DATA:
MISSING / NOT MATCHED
(dates created_at = oct–nov 2025 ; INV2608002 ABSENT ; exports août 2026 hors DB)

SCHEMA:
FAIL vs code MKD-Pro actuel (11 tables manquantes + ALTER 2026_08_*)

DATA INTEGRITY:
WARNING
(orphelins logiques pré-existants ; MyISAM sans FK)

RELATIONSHIP INTEGRITY:
WARNING
(po_items orphelins=1 ; dn_items orphelins=7 — PRE-EXISTING / dump)

RECOVERY SOURCE:
VERIFIED
(Spatie ZIP 2025-11-28-19-59-37 → db:restore → gestion_recovery, PRE-PROD 5/7.1)

RESTORE DRY-RUN:
PASS (documenté uniquement — NO DATABASE MODIFIED)

gestion_migration:
DANGEROUS
(DROP ON gestion.* toujours présent)

REVOKE DROP:
NOT EXECUTED

GESTION RESTORE:
NOT EXECUTED

DATABASE gestion:
ABSENT

DATABASE gestion_recovery:
UNCHANGED

APPLICATION FILES:
UNCHANGED

.env:
UNCHANGED
(DB_DATABASE=gestion, DB_USERNAME=gestion_app)

SECRETS:
NOT EXPOSED

TESTS:
103/103 PASS (Infrastructure Pest)

BUILD:
NOT RUN

GIT:
MODIFIED (docs/config/tests from prior phases — aucun commit)
```

---

## 1. INCIDENT

Lors de PRE-PROD 9.3.3, `gestion_migration` a reçu `DROP` sur `gestion.*`.  
Un probe `DROP DATABASE gestion` a **réussi**. La base métier `gestion` n’existe plus.

Correction de la policy :

```text
ATTENTION:
MySQL permet à DROP ON gestion.*
de supprimer DATABASE gestion.
La policy « DROP TABLE oui / DROP DATABASE non » était incorrecte.
```

---

## 2. CURRENT DATABASE STATE

| Database | Status |
|----------|--------|
| `gestion` | **ABSENT** |
| `gestion_recovery` | **PRESENT** |
| `gestion_test` | **ABSENT** |

### `.env` (no password)

```text
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=gestion
DB_USERNAME=gestion_app
```

### Runtime

```text
RUNTIME STATUS:
gestion_app → least privilege (CRUD on gestion.*)
configuration unchanged
connection to gestion → fails until DB recreated (expected)
```

### Comptes MySQL (SHOW GRANTS résumé)

| Account | Scope | Dangerous |
|---------|-------|-----------|
| `gestion_app` | CRUD `gestion.*` | no DDL |
| `gestion_backup` | SELECT/LOCK/SHOW VIEW/TRIGGER `gestion.*` | no |
| `gestion_restore` | DDL+DML `gestion_recovery.*` + `gestion_test.*` | can DROP those DBs |
| `gestion_migration` | full table DDL+DML `gestion.*` incl. **DROP** | **YES — DROP DATABASE gestion** |
| `root` | DBA | YES |

---

## 3. RECOVERY DATABASE STATE

```text
RECOVERY BASELINE
database: gestion_recovery
tables: 27
engine: MyISAM (all)
FK declared: 0
migrations table: 44 rows, max_batch=32
latest migration: 2025_11_21_191248_add_is_active_to_users_table
```

### TABLE INVENTORY (exact COUNT)

| table | engine | cols | PK | FK | rows |
|-------|--------|-----:|--:|--:|-----:|
| cache | MyISAM | 3 | 1 | 0 | 10 |
| cache_locks | MyISAM | 3 | 1 | 0 | 0 |
| categories | MyISAM | 6 | 1 | 0 | 7 |
| companies | MyISAM | 14 | 1 | 0 | 1 |
| customers | MyISAM | 12 | 1 | 0 | 7 |
| delivery_note_items | MyISAM | 8 | 1 | 0 | 26 |
| delivery_notes | MyISAM | 19 | 1 | 0 | 7 |
| expenses | MyISAM | 14 | 1 | 0 | 2 |
| failed_jobs | MyISAM | 7 | 1 | 0 | 0 |
| job_batches | MyISAM | 10 | 1 | 0 | 0 |
| jobs | MyISAM | 7 | 1 | 0 | 0 |
| media | MyISAM | 18 | 1 | 0 | 6 |
| migrations | MyISAM | 3 | 1 | 0 | 44 |
| notification_reads | MyISAM | 7 | 1 | 0 | 14 |
| password_reset_tokens | MyISAM | 3 | 1 | 0 | 2 |
| permissions | MyISAM | 7 | 1 | 0 | 65 |
| products | MyISAM | 19 | 1 | 0 | 20 |
| purchase_order_items | MyISAM | 8 | 1 | 0 | 7 |
| purchase_orders | MyISAM | 14 | 1 | 0 | 3 |
| quote_items | MyISAM | 9 | 1 | 0 | 2 |
| quotes | MyISAM | 14 | 1 | 0 | 1 |
| sale_items | MyISAM | 9 | 1 | 0 | 19 |
| sales | MyISAM | 18 | 1 | 0 | 9 |
| sessions | MyISAM | 6 | 1 | 0 | 3 |
| suppliers | MyISAM | 14 | 1 | 0 | 2 |
| user_permissions | MyISAM | 5 | 1 | 0 | 64 |
| users | MyISAM | 13 | 1 | 0 | 4 |

---

## 4. RECOVERY DATA INVENTORY

| table | row_count |
|-------|----------:|
| users | 4 |
| companies | 1 |
| customers | 7 |
| categories | 7 |
| products | 20 |
| suppliers | 2 |
| sales | 9 |
| sale_items | 19 |
| quotes | 1 |
| quote_items | 2 |
| expenses | 2 |
| purchase_orders | 3 |
| purchase_order_items | 7 |
| delivery_notes | 7 |
| delivery_note_items | 26 |
| inventory_sessions | **ABSENT** |
| inventory_items | **ABSENT** |
| stock_movements | **ABSENT** |

### Date ranges (`created_at`)

| table | min | max |
|-------|-----|-----|
| customers | 2025-10-18 | 2025-10-29 |
| products | 2025-10-18 | 2025-11-18 |
| sales | 2025-11-04 | 2025-11-28 |
| quotes | 2025-11-08 | 2025-11-08 |
| expenses | 2025-10-25 | 2025-11-04 |
| purchase_orders | 2025-10-27 | 2025-10-28 |
| delivery_notes | 2025-10-28 | 2025-11-04 |

**Aucune date août 2026 dans les tables métier recovery.**

---

## 5. SCHEMA COMPARISON (statique)

App migrations : **72** fichiers (+ module NotificationCenter).  
Recovery : schéma ~**novembre 2025**.

### Tables manquantes (attendues par le code actuel)

`activity_logs`, `attachments`, `form_drafts`, `stores`, `product_stocks`, `stock_movements`, `inventory_sessions`, `inventory_items`, `notification_global_settings`, `notification_type_settings`, `user_notification_preferences`

### Impact runtime si on clone recovery → `gestion` sans migrate

- Ventes / stocks / livraisons PO : **bloqués**
- Inventaire : **absent**
- Attachments / activity_logs : **cassés**
- Verdict schéma pour app 2026-08 : **FAIL** (nécessite migrate contrôlé **après** restore, jamais avant validation humaine)

---

## 6–8. DATA / RELATIONSHIP INTEGRITY

| Check | Orphans | Class |
|-------|--------:|-------|
| sale_items → sales | 0 | PASS |
| sale_items → products | 0 | PASS |
| sales → customers | 0 | PASS |
| quote_items → quotes/products | 0 | PASS |
| po_items → po | **1** | WARNING — **PRE-EXISTING** (déjà noté PRE-PROD 7.1) |
| dn_items → dn | **7** | WARNING — **PRE-EXISTING** |
| FK MySQL | 0 | MyISAM — non enforce |

---

## 9. AUGUST 2026 DATA MATCH

| SOURCE | RECOVERY | CORRESPONDANCE |
|--------|----------|----------------|
| ZIP Spatie `2025-11-28-19-59-37.zip` | schéma+données | **MATCH** (source connue du drill) |
| `gestion.sql` (Downloads / mes-db, déc.2025) | — | historique ≠ août 2026 |
| Exports clients nov–déc 2025 | customers=7 | plausible alignement 2025 |
| `clients_2026-08-10_*.xlsx` (7 lignes sheet) / `clients_2026-08-15_*.xlsx` (14 lignes) | customers=7, max date 2025-10-29 | **PARTIAL / mismatch** (exports août existent ; recovery = snapshot 2025) |
| `inventaire_INV2608002_2026-08-22_*.xlsx` (16 lignes) | inventory_* ABSENT | **ABSENT** |
| État `gestion` pré-drop 9.3.3 (users=3, métier=0) | recovery métier non vide | recovery **≠** état pré-drop ; recovery = snapshot drill 2025 |

### Domaines critiques

| Domaine | STATUS |
|---------|--------|
| utilisateurs | PASS (4 users 2025) — ≠ les 3 users post-wipe pré-drop |
| entreprise | PASS (1) |
| clients | PARTIAL (7, oct 2025) — exports août 2026 non reflétés |
| produits | PARTIAL (20, jusqu’à nov 2025) |
| ventes / devis / dépenses / FO / BC / BL | PASS pour dump 2025 |
| inventaires INV2608002 | **MISSING** |
| mouvements de stock | **MISSING** |

```text
CONCLUSION:
gestion_recovery est une copie EXPLOITABLE du dump Spatie nov.2025
(drill PRE-PROD 5), utile pour reconstruitre une base applicative 2025,
mais CE N’EST PAS une recovery complète des données métier d’août 2026.
Les exports locaux août 2026 restent des sources externes à réconcilier.
```

---

## 10. RECOVERY SOURCE

| Champ | Valeur |
|-------|--------|
| Méthode | `php artisan db:restore` DB-only (PRE-PROD 5) |
| ZIP | `storage/app/private/Gestion/2025-11-28-19-59-37.zip` |
| Date archive | 2025-11-28 |
| Docs | `docs/preprod-restore-drill-report.md`, `docs/preprod-7.1-recovery-integrity-report.md` |
| Checksum | non recalculé cette mission (fichiers inchangés) |

**Aucune réimport / DROP / CREATE effectué en 9.3.4.**

---

## 11. RESTORE DRY-RUN (NO EXECUTION)

```text
SOURCE: gestion_recovery
TARGET: gestion

OPERATIONS (planned only):
1. Pré-backup: mysqldump gestion_recovery → fichier hors Git (humain)
2. Snapshot ZIP Spatie existants (copie lecture)
3. CREATE DATABASE gestion (humain)
4. Import schéma+données depuis dump de gestion_recovery
   OU clone logique équivalent (mysqldump | mysql)
5. Verify COUNT tables
6. Verify orphans
7. REVOKE DROP on gestion_migration BEFORE any migrate
8. migrate incrémental (gestion_migration) — seulement après approbation séparée
9. Smoke Laravel (gestion_app)
10. Validation humaine

DRY RUN ONLY
NO DATABASE MODIFIED
```

---

## 12. gestion_migration SECURITY ISSUE

```text
SHOW GRANTS (current):
USAGE ON *.*
SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER
ON gestion.*
```

```text
PROPOSED SECURITY FIX

gestion_migration:
DROP = DANGEROUS (implies DROP DATABASE)

proposed action:
REVOKE DROP ON gestion.* FROM 'gestion_migration'@'localhost';
REVOKE DROP ON gestion.* FROM 'gestion_migration'@'127.0.0.1';

Remaining for migrations needing drop column/table:
- Option A: keep DROP but never probe DROP DATABASE; operational ban + runbook
- Option B: REVOKE DROP; use temporary elevated DBA for destructive downs only
- Option C: separate gestion_migration_ddl account used only under dual control

RECOMMENDED immediate:
REVOKE DROP (fail-closed) until human redesigns DDL workflow

STATUS:
WAITING HUMAN APPROVAL
```

---

## 13. PROPOSED RESTORE PLAN (not executed)

1. Sauvegarde préalable de `gestion_recovery` (mysqldump read-only export)  
2. Sécuriser `gestion_recovery` (ne jamais DROP)  
3. `CREATE DATABASE gestion` — **approbation dédiée**  
4. Restore DB-only recovery → `gestion` (pas files)  
5. Vérifier tables + counts  
6. Vérifier comptes / grants  
7. `REVOKE DROP` migration **avant** tout migrate  
8. Smoke `gestion_app`  
9. Décider migrate vs freeze schéma 2025  
10. Réconciliation manuelle exports août 2026 (clients / INV2608002)  
11. Backup post-restore + offsite (phase 10)  
12. Validation finale humaine  

### Architecture « RECOVERY RESTORE MODE » (future)

- target explicite `gestion` **hors** allow-list app normale  
- double confirmation + phrase distincte (ex. `RECOVER_PRODUCTION`)  
- backup préalable obligatoire  
- lock global restore  
- audit log  
- dry-run  
- post-verify counts  
- **jamais** disponible à un admin RBAC seul sans gate humain  

`DatabaseRestoreService` reste DB-only ; allow-list actuelle bloque encore `gestion` (correct pour l’app). Le restore production doit être un **chemin d’urgence séparé**, pas un élargissement silencieux de l’allow-list.

---

## 14. HUMAN APPROVALS REQUIRED

```text
1. OUI — REVOKE DROP FROM gestion_migration

2. OUI — PREPARE RESTORE gestion FROM gestion_recovery
   (dump préalable, scripts, checklist — toujours sans écrire)

3. OUI — EXECUTE RESTORE gestion FROM gestion_recovery
   (CREATE DATABASE + import — opération réelle)

4. (optionnel ultérieur) OUI — MIGRATE gestion AFTER RESTORE
5. (optionnel) OUI — IMPORT AUGUST 2026 EXPORTS RECONCILIATION PLAN
```

Une approbation ≠ les autres.

---

## 15. SAFETY STATUS

```text
gestion_recovery: UNCHANGED (read-only confirmed)
gestion: ABSENT (unchanged since incident)
REVOKE: NOT EXECUTED
RESTORE: NOT EXECUTED
.env: UNCHANGED
NO SEED / MIGRATE / DROP / TRUNCATE / DELETE
Pest infrastructure: 103/103 PASS
```

---

## NEXT

Attendre les réponses humaines explicites ci-dessus.  
Priorité suggérée : **(1) REVOKE DROP** avant toute recréation de `gestion`.
