# PRE-PROD 9.3.5 — Forensic Data Recovery Search

**Date :** 2026-08-26  
**Mode :** READ-ONLY strict — aucune restauration, aucune modification MySQL/fichiers  
**Objectif :** Inventorier toutes les sources permettant de récupérer les données MKD-Pro de `gestion` perdues lors de PRE-PROD 9.3.3

---

## 1. Incident context

### Chaîne causale historique (première perte)

```text
scripts/measure-rbac-queries.php
    ↓
Artisan::call('migrate:fresh', ['--force' => true])
    ↓
.env local DB_DATABASE=gestion
    ↓
destruction des données métier (2026-08-23)
```

Documentation : `docs/preprod-database-root-cause.md`, `docs/database-safety-policy.md`

### Incident PRE-PROD 9.3.3 (suppression définitive de la base)

```text
Compte gestion_migration — GRANT DROP ON gestion.*
    ↓
Probe privilèges : DROP DATABASE gestion
    ↓
SUCCÈS → base gestion supprimée (2026-08-25)
```

Documentation : `docs/preprod-phase-9.3.3-migration-account-CRITICAL.md`

### État connu avant 9.3.5

- `gestion` : **ABSENTE** (confirmé PRE-PROD 9.3.4)
- `gestion_recovery` : **PRÉSENTE** — copie drill restore **novembre 2025**, **≠** données août 2026
- État pré-drop de `gestion` (9.3.3) : users=3 (seed post-`migrate:fresh`), **métier=0**
- Exports utilisateur août 2026 existent localement (Downloads) — preuve que des données métier existaient dans l'app à ces dates, mais **non retrouvées dans un dump MySQL complet**

---

## 2. Safety baseline

```text
DATABASE SAFETY BASELINE

gestion:
ABSENT
(reconfirmé PRE-PROD 9.3.4 ; tentative re-vérification PDO root/gestion_app refusée — aucune écriture tentée)

gestion_recovery:
PRESENT

gestion_test:
ABSENT

gestion_app:
EXISTS

gestion_backup:
EXISTS

gestion_restore:
EXISTS

gestion_migration:
EXISTS

CURRENT APP DB_DATABASE:
gestion

CURRENT APP DB_USERNAME:
gestion_app

FILES MODIFIED:
NO

DATABASE MODIFIED:
NO

RESTORE EXECUTED:
NO
```

**Note :** Aucun mot de passe affiché. Aucune valeur secrète de `.env` exposée.

---

## 3. Sources searched

| Zone | Méthode | Modifié |
|------|---------|---------|
| `storage/app/private/Gestion/` | Inventaire ZIP Spatie | NON |
| `storage/app/restore-temp/` | Dump extrait drill (lecture seule) | NON |
| `C:\Users\dmoha\Downloads\` | `*.sql`, `*.zip`, `*.xlsx`, `*.pdf` | NON |
| `C:\Users\dmoha\Documents\mes-db\` | `gestion.sql` | NON |
| Projet `gestion/` (récursif) | `*.sql`, `*.zip`, `*.gz`, `*.bak` | NON |
| MySQL datadir WAMP | Recherche `mysql-bin.*`, `ibdata1` | NON |
| `storage/logs/` | Grep backup/restore/migrate | NON |
| Git (`git log`, `--name-status`) | Historique dumps/versioning | NON |
| Bases MySQL (`SHOW DATABASES` — 9.3.4) | Métadonnées read-only | NON |
| Hébergement OVH / distant | **Non exploré** (autorisation humaine requise) | NON |

Extensions recherchées : `.zip`, `.sql`, `.sql.gz`, `.gz`, `.dump`, `.bak`, `.backup`, `.tar`, `.tar.gz`, `.7z`, `.xlsx`, `.xls`, `.csv`, `.pdf`, `.json`, `.tmp`, `.temp`, `.old`, `.orig`, `.copy`, `.save`

Mots-clés : `gestion`, `mysql`, `database`, `dump`, `snapshot`, `spatie`, `backup`, `mkd`, `pro`, `2026`, `08`, `INV2608`, `INV2608002`

---

## 4. Backups found

### Archives Spatie (projet)

| # | Chemin | Taille | mtime | SHA-256 |
|---|--------|-------:|-------|---------|
| 1 | `storage/app/private/Gestion/2025-11-28-19-59-37.zip` | 3 566 043 | 2025-11-28 20:00:50 | `ae7506087d723db902f1e55452fca50eb4217f2b93247f8e20601eed7b763732` |
| 2 | `storage/app/private/Gestion/2025-11-28-19-50-02.zip` | 3 565 966 | 2025-11-28 19:51:07 | `44cb0b15fc6cff54877bdc1c553b3ac61639c7cdaa69a18894e690dd5621bea2` |
| 3 | `storage/app/private/Laravel/drill-test.zip` | (drill) | 2025 | — |
| 4 | `Downloads/test/2025-11-28-19-59-37.zip` | 3 566 043 | 2025-11-28 | copie locale (= #1) |
| 5 | `Downloads/test/2025-11-28-19-50-02.zip` | 3 565 966 | 2025-11-28 | copie locale (= #2) |
| 6 | `Downloads/test/restore_.../mysqlforcedtcp-gestion.sql` | 82 292 | 2025-11-23 | copie drill (= dump #D) |

**Contenu manifestable (ZIP #1, sans extraction projet) :**
- `db-dumps/mysqlforcedtcp-gestion.sql`
- `application/` (875 entrées)
- `.env`, `.env.example` (présents dans l'archive — **ne pas exposer**)

### Autres archives

| Chemin | Taille | mtime | Type |
|--------|-------:|-------|------|
| `C:\Users\dmoha\Downloads\documents_20260729115851.zip` | 1 817 495 | 2026-07-29 11:59:01 | Export documents app (PDF/docs), **pas dump DB** |

**Offsite backup :** NOT CONFIGURED (`docs/preprod-backup-strategy.md`)

---

## 5. Dumps found

| Rang | Fichier | mtime | Taille | CREATE TABLE | INSERT | Données métier | INV2608002 | 2026-08 | SHA-256 |
|------|---------|-------|-------:|-------------:|-------:|----------------|------------|---------|---------|
| **A** | `Downloads/gestion (1).sql` | **2026-08-23 18:39** | 67 931 | **38** | 10 | **NON** (pas customers/products/sales/inventory) | NON | 264 refs | `6ec4efc6…5985` |
| B | `Downloads/gestion.sql` | 2025-12-03 15:45 | 70 799 | 27 | 23 | **OUI** | NON | 0 | `81dede9e…5ac9` |
| C | `Documents/mes-db/gestion.sql` | 2025-12-09 18:23 | 72 042 | 27 | 23 | **OUI** | NON | 0 | `3c8f8244…aa5e` |
| D | `storage/.../mysqlforcedtcp-gestion.sql` | 2025-11-23 12:34 | 82 292 | 27 | 23 | **OUI** | NON | 0 | `97dc4983…ed97e` |
| E | `Downloads/gestion-avec-toutes les donnees.sql` | 2026-08-25 20:08 | 3 222 | 1 | 1 | **NON** (users `gestion_recovery` uniquement) | NON | 0 | `c8478751…7a7b` |

### Analyse détaillée

#### A — `gestion (1).sql` (phpMyAdmin, 2026-08-23)

- Dump de la base **`gestion`** post-`migrate:fresh` / seed RBAC
- Schéma **complet août 2026** : `inventory_sessions`, `inventory_items`, `stores`, `product_stocks`, `stock_movements`, `attachments`, `form_drafts`, etc.
- Migrations jusqu'à `2026_08_22_000002_add_application_summary_to_inventory_sessions_table`
- **10 INSERT** : cache, companies, migrations, notification_*, permissions, sessions, stores, users (3), user_permissions
- Users datés **2026-08-22 23:31** (`@example.net` — seed factory)
- **Aucun INSERT** : customers, products, sales, inventories
- **Valeur :** schéma + comptes système — **PAS une sauvegarde métier août 2026**

#### B/C/D — Dumps novembre–décembre 2025 (27 tables)

- customers=7, products=20, sales=9 (dates ventes max **2025-11-28**)
- Produit « Pomme » : `expiration_date = 2026-07-17` (seule trace 2026 dans les données)
- **Pas de tables inventaire/stock unifié** (schéma pré-août 2026)
- Source connue du drill → `gestion_recovery`
- **Valeur :** meilleure copie SQL **données métier complètes (2025)**, pas août 2026

#### E — `gestion-avec-toutes les donnees.sql`

- Export phpMyAdmin **table `users` seule** depuis `gestion_recovery` (4 users nov 2025)
- Nom **trompeur** — ne contient pas « toutes les données »

---

## 6. Exports found

| Fichier | mtime | Taille | Lignes (sheet) | Contenu | Preuve août 2026 |
|---------|-------|-------:|---------------:|---------|------------------|
| `clients_2026-08-10_202453.xlsx` | 2026-08-10 20:24 | 7 165 | 7 | Clients (ex. Aliou sylla) | **OUI** |
| `clients_2026-08-15_214421.xlsx` | 2026-08-15 21:44 | 8 354 | 14 | Clients (colonnes identité étendues) | **OUI** |
| `inventaire_INV2608002_2026-08-22_194539.xlsx` | 2026-08-22 19:45 | 10 998 | 16 | INV2608002, nom « test2 », date **22/08/2026 13:13**, statut Clôturé | **OUI** |
| `inventaire_INV2608002_2026-08-22_194417.pdf` | 2026-08-22 19:44 | 27 404 | — | Même inventaire (PDF) | **OUI** |
| `documents_20260729115851.zip` | 2026-07-29 11:59 | 1 817 495 | 36 fichiers | Documents commerciaux app | Partiel (juillet) |

**SHA-256 exports août :**
- clients_2026-08-15 : `42e11eadc7ca965058f42ad9047fa5400c1bbf1e68be05719ecd7667ce63dc30`
- clients_2026-08-10 : `7b1e4e1ffcd3ff082e45ad5f9df72b6fdab2b4c7e69219fc5e22e2559662b572`
- inventaire INV2608002 xlsx : `b7f8b3d80ce2640e01497a55418323f6bdaee97fb799785e29ec50759f098ac5`

**Valeur recovery :** sources **partielles** — réconciliation manuelle requise ; absentes de `gestion_recovery` et des dumps SQL locaux.

---

## 7. MySQL files found

| Élément | Résultat |
|---------|----------|
| `ibdata1` | Présence WAMP (datadir système) — **non modifié, non copié** |
| `*.ibd` / `*.frm` / `*.sdi` | Non inventoriés fichier par fichier (datadir système) |
| `mysql-bin.*` / `binlog.*` | **Aucun fichier binlog trouvé** dans l'arborescence WAMP explorée |
| `relay-log.*` | Non trouvé |

**Important :** Aucune manipulation des fichiers moteur MySQL. Aucun `RESET MASTER`, `PURGE BINARY LOGS`, `FLUSH LOGS`.

---

## 8. Binlogs

```text
log_bin:
OFF
(source : PRE-PROD 9.3.4 / audits database-safety ; re-vérification datadir : aucun mysql-bin.*)

earliest available event:
N/A (binary logging disabled)

latest available event:
N/A

binary logs covering August 2026:
NO
```

**HIGH VALUE RECOVERY SOURCE via binlogs :** **NON APPLICABLE**

---

## 9. Git evidence

```text
git status : nombreux fichiers modifiés/non suivis (safety/backup) — aucune modification effectuée en 9.3.5

git log --all --oneline : historique features (RBAC, inventory, attachments…) — pas de commit de dump SQL/ZIP

git log --diff-filter=A -- "*.sql" "*.zip" : aucun dump/versionné dans le dépôt
```

**Conclusion :** Les backups ne sont **pas** versionnés Git. Aucune trace Git d'un dump août 2026 complet.

---

## 10. Logs evidence

| Fichier | Recherche | Résultat |
|---------|-----------|----------|
| `storage/logs/laravel.log` | backup, mysqldump, migrate:fresh, db:restore, 2026-08-2* | **Aucune correspondance** |
| `storage/logs/notification-2026-08-16.log` | — | Logs notification uniquement |
| `storage/logs/notification-2026-08-17.log` | — | Logs notification uniquement |
| Terminaux / docs PRE-PROD | migrate:fresh 2026-08-23, DROP DATABASE 9.3.3 | Documentés dans `docs/` |

**Conclusion :** Pas de trace log Laravel d'un `backup:run` récent vers un dump exploitable août 2026.

---

## 11. August 2026 evidence

| Référence | Trouvée où | Dans dumps SQL ? | Dans gestion_recovery ? |
|-----------|------------|------------------|-------------------------|
| `INV2608002` | Exports xlsx/pdf 2026-08-22 | **NON** | **NON** |
| `2026-08-22` | `gestion (1).sql` (users/migrations) | OUI (système) | NON |
| `2026-08-15` / `2026-08-10` | Exports clients xlsx | NON | NON |
| Données clients août | Exports xlsx | NON | NON (recovery max 2025-10-29) |
| Schéma inventaire août | `gestion (1).sql` (CREATE TABLE) | OUI (structure) | NON (tables absentes) |

**Références réellement trouvées (pas inventées) :**
- `INV2608002` — export inventaire 22/08/2026
- Users seed `2026-08-22 23:31:40–42` dans `gestion (1).sql`
- Migrations `2026_08_21_*`, `2026_08_22_*` dans `gestion (1).sql`

---

## 12. Candidate ranking

```text
BACKUP CANDIDATE RANKING

1. Downloads/gestion (1).sql
   date fichier: 2026-08-23 18:39
   taille: 67 931 o
   type: phpMyAdmin SQL (schéma complet)
   database: gestion
   données métier: ABSENTES
   score: 13/25 — POSSIBLE CANDIDATE (schéma uniquement)

2. Exports août 2026 (clients + INV2608002 xlsx/pdf)
   date: 2026-08-10 à 2026-08-22
   taille: 7–27 Ko chacun
   type: export utilisateur
   database: N/A (hors MySQL)
   score: 16/25 — STRONG CANDIDATE (partiel / réconciliation)

3. Downloads/gestion.sql + mes-db/gestion.sql
   date: 2025-12-03 / 2025-12-09
   taille: ~71 Ko
   type: phpMyAdmin SQL (données 2025)
   database: gestion
   données métier: OUI (max ventes 2025-11-28, produit exp. 2026-07-17)
   score: 13/25 — POSSIBLE CANDIDATE (pré-août)

4. Spatie ZIP 2025-11-28-19-59-37.zip
   date: 2025-11-28
   taille: 3.5 Mo
   type: Spatie full backup
   database: gestion (dump interne)
   score: 12/25 — POSSIBLE CANDIDATE (= source gestion_recovery drill)

5. gestion-avec-toutes les donnees.sql
   date: 2026-08-25
   taille: 3 222 o
   type: export users recovery
   score: 3/25 — NOT USEFUL (hors scope août métier)

6. documents_20260729115851.zip
   date: 2026-07-29
   type: documents app
   score: 5/25 — WEAK (complément documentaire)
```

**Priorité chronologique données métier :**
1. Août 2026 → **exports utilisateur uniquement** (pas dump SQL complet)
2. Juillet 2026 → trace produit `2026-07-17` dans dumps 2025
3. Novembre 2025 → Spatie ZIP / gestion_recovery / gestion.sql

---

## 13. Recovery sources

### Source A — Exports utilisateur août 2026 (RECOMMANDÉ pour preuves août)

```text
RECOVERY SCORE
Date relevance: 5/5
Schema completeness: N/A (partiel)
Business data completeness: 2/5 (clients + 1 inventaire)
August 2026 evidence: 5/5
Integrity evidence: 3/5
TOTAL: ~16/25 — STRONG CANDIDATE (partiel)
```

**Usage :** Réconciliation manuelle après recréation contrôlée de `gestion` — **approbation humaine requise**.

### Source B — `gestion (1).sql` (schéma août 2026)

```text
RECOVERY SCORE
Date relevance: 4/5 (fichier août, données système seulement)
Schema completeness: 5/5 (38 tables)
Business data completeness: 0/5
August 2026 evidence: 1/5 (structure/migrations)
Integrity evidence: 4/5
TOTAL: 14/25 — POSSIBLE CANDIDATE
```

**Usage :** Référence schéma ou import structure — **ne restaure pas le métier**.

### Source C — Spatie ZIP / gestion.sql / gestion_recovery (novembre 2025)

```text
RECOVERY SCORE
Date relevance: 2/5
Schema completeness: 3/5 (27/38 tables)
Business data completeness: 4/5 (2025 complet)
August 2026 evidence: 0/5
Integrity evidence: 4/5
TOTAL: 13/25 — POSSIBLE CANDIDATE
```

**Usage :** Baseline métier 2025 + drill — **≠ recovery août 2026**.

### Source composite (stratégie humaine probable)

1. Restaurer baseline 2025 (`gestion_recovery` ou ZIP Spatie) → `gestion`
2. Appliquer migrations août (`gestion_migration` après **REVOKE DROP**)
3. Réconcilier exports août 2026 (clients, INV2608002)
4. Valider intégrité relationnelle

**Aucune de ces étapes exécutée en 9.3.5.**

---

## 14. Sources rejected

| Source | Raison de rejet |
|--------|-----------------|
| `gestion_recovery` seule | Drill **novembre 2025** — pas données août 2026 ; INV2608002 absent |
| `gestion-avec-toutes les donnees.sql` | 4 users recovery uniquement |
| `gestion (1).sql` seul | Schéma sans métier — état post-wipe |
| Binlogs MySQL | `log_bin=OFF` — aucun PITR |
| Git | Aucun dump versionné |
| Offsite OVH | Non configuré / non accessible sans action humaine |
| `documents_20260729115851.zip` | Documents juillet — pas base de données |

```text
gestion_recovery
    =
ancienne copie novembre 2025 / restore drill

Recovery source August 2026
    =
exports utilisateur locaux (partiel)
    +
éventuellement composite avec dumps 2025 + schéma gestion (1).sql
    —
AUCUN dump MySQL complet août 2026 trouvé
```

---

## 15. Recommended next action

### Immédiat (humain)

1. **Revue humaine** de ce rapport et des SHA-256
2. **Ne pas** confondre `gestion_recovery` avec recovery août 2026
3. Décider stratégie composite (baseline 2025 + migrate + exports août)

### Approbations séparées requises (non exécutées)

```text
OUI — REVOKE DROP FROM gestion_migration
OUI — PREPARE RESTORE gestion FROM [source choisie]
OUI — EXECUTE RESTORE gestion
OUI — RECONCILE FROM exports août 2026
```

### Sources externes à vérifier manuellement

```text
EXTERNAL RECOVERY SOURCE — HUMAN ACTION REQUIRED

- Sauvegardes OVH / hébergeur (si existantes avant 2026-08-25)
- Snapshots VM / Windows Volume Shadow Copy (VSS) sur la machine dev
- phpMyAdmin historique exports (autres dossiers utilisateur)
- Cloud personnel (OneDrive, Google Drive) — non exploré
- Copie `.env` / credentials backup Spatie offsite — NOT CONFIGURED
```

### Si candidat août trouvé ultérieurement

```text
RECOVERY CANDIDATE FOUND
HUMAN APPROVAL REQUIRED
No restore executed.
No database created.
No existing database modified.
```

---

## 14bis. Comparaison schéma (CURRENT APPLICATION TABLES)

Tables attendues par le code (≈38, d'après migrations + `gestion (1).sql`) :

`users`, `companies`, `customers`, `categories`, `products`, `sales`, `sale_items`, `quotes`, `quote_items`, `suppliers`, `purchase_orders`, `purchase_order_items`, `delivery_notes`, `delivery_note_items`, `expenses`, `permissions`, `user_permissions`, `media`, `notification_reads`, `activity_logs`, `attachments`, `form_drafts`, `stores`, `product_stocks`, `stock_movements`, `inventory_sessions`, `inventory_items`, `notification_global_settings`, `notification_type_settings`, `user_notification_preferences`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`, `password_reset_tokens`, `migrations`, …

| Source | Présentes | Absentes vs app | Supplémentaires | Données métier | Dates max |
|--------|----------:|-----------------|-----------------|----------------|-----------|
| gestion (1).sql | 38 | 0 | 0 | **Vide** (seed) | 2026-08-22 (système) |
| gestion.sql / Spatie | 27 | 11 (inventory, stores, attachments…) | 0 | **Complètes 2025** | ventes 2025-11-28 |
| gestion_recovery | 27 | 11 | 0 | 7 clients, 20 produits, 9 ventes | 2025-11-28 |
| Exports août | N/A | N/A | N/A | Partiel | **2026-08-22** (INV2608002) |

---

## 15bis. Date réelle des données par source

| Source | oldest business | newest business | 2026 data | August 2026 | RECOVERY VALUE |
|--------|-----------------|-----------------|-----------|-------------|----------------|
| gestion (1).sql | N/A | 2026-08-22 (users) | YES (système) | Structure only | **LOW** (schéma) |
| gestion.sql | 2025-10-18 | 2025-11-28 (+ exp 2026-07-17) | Partiel | **NO** | **MEDIUM** (2025) |
| Spatie ZIP / recovery | 2025-10-18 | 2025-11-28 | Partiel | **NO** | **MEDIUM** (2025 drill) |
| Exports août 2026 | — | **2026-08-22** | YES | **YES** | **HIGH** (partiel) |

---

```text
========================================
MKD-PRO PRE-PROD 9.3.5
FORENSIC DATA RECOVERY SEARCH
========================================

DATABASE gestion:
ABSENT

DATABASE gestion_recovery:
PRESENT

DATABASE MODIFIED:
NO

FILES MODIFIED:
NO

DATABASES CREATED:
NO

DATABASES DROPPED:
NO

RESTORE EXECUTED:
NO

MIGRATION EXECUTED:
NO

SEED EXECUTED:
NO

GRANT EXECUTED:
NO

REVOKE EXECUTED:
NO

BACKUPS FOUND:
5 SQL dumps + 2 Spatie ZIP + 1 drill-test ZIP + 4 exports août + 1 zip documents juillet

LATEST BACKUP (fichier):
Downloads/gestion (1).sql — 2026-08-23 18:39 (schéma seulement, sans métier)

LATEST BUSINESS DATA FOUND:
Exports inventaire INV2608002 — 2026-08-22 (partiel, hors MySQL)
Dumps SQL complets — ventes max 2025-11-28 ; produit exp. 2026-07-17

AUGUST 2026 DATA FOUND:
YES (exports utilisateur + schéma gestion (1).sql ; PAS de dump SQL métier complet)

AUGUST 2026 RECOVERY CANDIDATE:
Exports clients_2026-08-*.xlsx + inventaire_INV2608002_2026-08-22 (xlsx/pdf)
Composite possible : gestion.sql/Spatie (2025) + migrations + réconciliation exports

BINLOG COVERAGE:
OFF — NO August 2026 coverage

EXPORTS FOUND:
4 fichiers août 2026 (2 clients xlsx, 1 inventaire xlsx, 1 inventaire pdf)

RECOVERY SOURCE:
Aucun dump MySQL unique août 2026 — stratégie composite requise

RECOVERY CONFIDENCE:
LOW (dump complet août)
MEDIUM (recovery partielle via exports + baseline 2025)

GESTION RECOVERY DRILL:
NOVEMBER 2025 — NOT AUGUST 2026

PRODUCTION DATABASE:
NOT RECREATED

PRODUCTION DATABASE:
NOT RESTORED

STATUS:
FORENSIC SEARCH COMPLETE

NEXT ACTION:
HUMAN REVIEW REQUIRED
```
