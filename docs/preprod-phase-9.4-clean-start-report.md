# PRE-PROD 9.4 — Clean Start & Production Continuation

**Date :** 2026-08-26  
**Statut :** EN COURS — gates d’approbation humaine ouverts  
**Mode :** aucune opération destructive exécutée sans phrase d’approbation exacte

---

```text
========================================
MKD-PRO PRE-PROD 9.4
CLEAN START & PRODUCTION CONTINUATION
========================================

OLD DATA RECOVERY:
STOPPED BY HUMAN DECISION

HISTORICAL EVIDENCE:
PRESERVED

gestion_recovery:
PRESERVED (27 tables, drill nov. 2025)

gestion:
PRESENT (38 tables, métier vide — migrate 9.4 DONE)

SCHEMA:
PASS (75/75 migrations)

RUNTIME ACCOUNT:
gestion_app (.env inchangé)

ROOT RUNTIME:
NO

BACKUP ACCOUNT:
EXISTS — SELECT, LOCK TABLES, SHOW VIEW, TRIGGER ON gestion.*

RESTORE ACCOUNT:
EXISTS — scoped gestion_recovery.* + gestion_test.*

MIGRATION ACCOUNT:
EXISTS — DROP REVOKED — used for migrate only

MIGRATION DROP DATABASE RISK:
BLOCKED

BACKUP:
NOT RUN

BACKUP VERIFICATION:
N/A

OFFSITE:
NOT CONFIGURED

RESTORE DRILL:
NOT RUN

RESTORE INTEGRITY:
N/A

SECURITY TESTS:
PENDING

PEST:
PENDING (full suite)

BUILD:
PENDING

GESTION:
CLEAN SCHEMA — NO HISTORICAL DATA

PRODUCTION STATUS:
NOT READY

BLOCKERS:
1. SEED TEST DATA ON gestion (approbation requise)
2. backup vérifié + restore drill + offsite
3. Pest / build complets
```

---

## Phase A — Audit préalable (READ-ONLY) — FAIT

### Bases

| Base | État |
|------|------|
| `gestion` | **ABSENT** |
| `gestion_recovery` | **PRESENT** (27 tables) |
| `gestion_test` | **ABSENT** |

### Comptes MySQL

| Compte | Existe | Grants observés (root SHOW GRANTS) |
|--------|--------|-------------------------------------|
| `gestion_app` | OUI (`localhost` + `127.0.0.1`) | `SELECT, INSERT, UPDATE, DELETE ON gestion.*` |
| `gestion_backup` | OUI | `SELECT, LOCK TABLES, SHOW VIEW, TRIGGER ON gestion.*` |
| `gestion_restore` | OUI | CRUD+DDL sur `gestion_recovery.*` et `gestion_test.*` uniquement |
| `gestion_migration` | OUI | `SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER ON gestion.*` |
| `root` | OUI | DBA (mot de passe vide local WAMP — **ne jamais utiliser en runtime Laravel**) |

### Laravel `.env` (sans secrets)

```text
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=gestion
DB_USERNAME=gestion_app
```

```text
DATABASE SAFETY:
PASS (grants migration) / FAIL (gestion absente)
(raison restante: gestion absente — REVOKE DROP FAIT)
```

### Phase C post-REVOKE

| Compte | Verdict |
|--------|---------|
| `gestion_app` | **PASS** (CRUD) |
| `gestion_backup` | **PASS** |
| `gestion_restore` | **PASS** (pas `gestion.*`) |
| `gestion_migration` | **PASS** (DROP révoqué) |
| `root` | **PASS** (DBA only) |

### Autres

| Check | Résultat |
|-------|----------|
| `log_bin` | OFF (0) |
| Git branch | `main` |
| Working tree | nombreux changements pré-existants (safety/backup/docs/assets) |
| Pest binaire | présent (`vendor/bin/pest`) |
| Vite build manifest | présent |
| Connexion self-test comptes via `.mysql-*.local` | **FAIL 1045** — secrets locaux potentiellement désynchronisés (à traiter après REVOKE, hors Git) |

### Actions Phase A

- **Aucune** modification MySQL
- **Aucune** restauration
- Script d’audit temporaire **supprimé** après exécution

---

## Phase B — Correction critique `gestion_migration` — **EXÉCUTÉ**

**Approbation :** `OUI — REVOKE DROP FROM gestion_migration` (2026-08-26)

### Avant

```text
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER
ON `gestion`.* TO `gestion_migration`@`localhost` / `127.0.0.1`
```

### Commandes exécutées

```sql
REVOKE DROP ON `gestion`.* FROM 'gestion_migration'@'localhost';
REVOKE DROP ON `gestion`.* FROM 'gestion_migration'@'127.0.0.1';
FLUSH PRIVILEGES;
```

### Après

```text
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER
ON `gestion`.* TO `gestion_migration`@`localhost` / `127.0.0.1`

mysql.db Drop_priv = N (localhost + 127.0.0.1)
DROP_STILL_PRESENT_IN_GRANTS: NO
```

### Probe `DROP DATABASE gestion`

- Connexion directe `gestion_migration` via `.mysql-gestion-migration.local` : **1045** (secret local désynchronisé — hors scope REVOKE)
- Probe miroir (user éphémère avec **mêmes grants post-REVOKE**, sans DROP) :
  - `SQLSTATE[42000] 1044 Accès refusé … Base 'gestion'`
  - **PASS** — `DROP DATABASE` refusé sans privilège DROP
- User éphémère **supprimé** immédiatement
- `gestion` : toujours **ABSENT** (non créée)
- `gestion_recovery` : **PRESERVED**

### Non exécuté

- CREATE DATABASE gestion
- migrate / seed / restore
- REVOKE autres privilèges
- rotation mots de passe

### Policy code

`config/database-accounts.php` : `DROP` retiré de `migration_privileges` ; `migration_forbidden_privileges` documenté.

```text
MIGRATION DROP DATABASE RISK:
BLOCKED
```

---

## Phase F — Préparation `gestion` propre — **EXÉCUTÉ**

**Approbation :** `OUI — PREPARE CLEAN gestion` (2026-08-26)

```text
DATABASE: gestion
BEFORE: ABSENT
ACTION: CREATE DATABASE `gestion` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
AFTER: PRESENT
TABLES: 0
DATA IMPORTED: NO
gestion_recovery: PRESERVED (unchanged)
DROP DATABASE probe (no-DROP grants): ACCESS_DENIED PASS
migrate: NO
seed: NO
restore: NO
```

**Non exécuté :** migrate, seed, import dumps 2025/août, copie depuis `gestion_recovery`.

---

## Phase G — Schéma MKD-Pro — **EXÉCUTÉ**

**Approbation :** `OUI — RUN MIGRATE ON gestion` (2026-08-26)

```text
Compte: gestion_migration (env process-only — .env fichier reste gestion_app)
Commande: artisan migrate --force
Résultat: 75/75 DONE, exit=0
Tables: 0 → 38
migrations rows: 75
Données métier: toutes à 0 (sauf table migrations)
gestion_recovery: 27 tables UNCHANGED
DROP privilege: always ABSENT during migrate
drop_credit_limit migration: DONE (ALTER dropColumn — OK without DROP priv)
```

Baseline : [`docs/preprod-9.4-schema-baseline.md`](preprod-9.4-schema-baseline.md)

---

## Phase H — Données de test — EN ATTENTE D’APPROBATION

```text
BASE CIBLE: gestion
ACTION: seed dataset de test NOUVEAU (préfixes TEST-*)
IMPACT: INSERT métier (pas d'import 2025 / exports août)
ÉTAT AVANT: schéma complet, 0 lignes métier
RISQUE: db:seed Artisan est BLOQUÉ sur gestion par DatabaseSafetyGuard
        → seed dédié / seeder manuel contrôlé requis (pas db:seed générique)
APPROBATION REQUISE:
```

```text
OUI — SEED TEST DATA ON gestion
```

**Non exécuté.**

---

## Phases I–N, P–Q — BLOQUÉES (après seed)

---

## Phase O — Règles Cursor — FAIT

---

## Fichiers touchés par 9.4 (docs/config)

| Fichier | Action |
|---------|--------|
| `docs/preprod-data-loss-closure.md` | créé |
| `docs/preprod-phase-9.4-clean-start-report.md` | mis à jour |
| `docs/preprod-phase-9.4.1-migration-preflight-report.md` | créé |
| `docs/preprod-9.4-schema-baseline.md` | créé |
| `.cursor/rules/database-safety-gestion.mdc` | créé |
| `config/database-accounts.php` | DROP retiré policy |
| `docs/mysql-privilege-matrix.md` | mis à jour |

**`.env` : NON modifié** (`DB_USERNAME=gestion_app`).

---

## Prochaine étape

```text
OUI — SEED TEST DATA ON gestion
```

Puis backup / restore drill / offsite / Pest / build.

```text
MIGRATE: DONE 75/75
SCHEMA: PASS
SEED: NOT EXECUTED
gestion_recovery: PRESERVED
PRODUCTION STATUS: NOT READY
```
