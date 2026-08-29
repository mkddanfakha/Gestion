# PRE-PROD 11 — Restore Drill & Recovery Verification

**Date :** 2026-08-26  
**Phase exécutée :** **A — AUDIT READ-ONLY** uniquement  
**Interdit / non exécuté :** CREATE DATABASE, restore, migrate, seed, DROP, modification `.env`, toucher `gestion` / `gestion_recovery`

---

## PHASE A — AUDIT READ-ONLY

### Bases MySQL

| Base | Statut | Tables | Charset / Collation |
|------|--------|--------|---------------------|
| `gestion` | **PRESENT** | 38 | utf8mb4 / utf8mb4_unicode_ci |
| `gestion_recovery` | **PRESENT** | 27 | utf8mb4 / utf8mb4_unicode_ci |
| `gestion_test` | **ABSENT** | — | — |

**Baseline métier `gestion` (RO) :** migrations=75 ; users/companies/customers/products/sales/quotes/expenses/suppliers = **0**  
**Baseline `gestion_recovery` (RO) :** users=4, customers=7 (drill historique nov. 2025 — **non modifié**)

### Comptes MySQL (`SHOW GRANTS` — secrets non affichés)

#### `gestion_app`
- USAGE on `*.*`
- **SELECT, INSERT, UPDATE, DELETE** on `gestion.*` only
- **Verdict :** PASS

#### `gestion_backup`
- USAGE on `*.*`
- **SELECT, LOCK TABLES, SHOW VIEW, TRIGGER** on `gestion.*` only
- **Verdict :** PASS

#### `gestion_restore`
- USAGE on `*.*`
- **SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER** on `gestion_recovery.*`
- Idem sur `gestion_test.*`
- **Aucun** privilège sur `gestion.*`
- **NO GRANT OPTION** observé
- **Verdict :** PASS (prêt pour cible `gestion_test` après CREATE)

#### `gestion_migration`
- USAGE on `*.*`
- **SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER** on `gestion.*`
- **DROP absent**
- **Verdict :** PASS

#### `root`
- DBA local WAMP — **hors** restore applicatif prévu

### Backup sélectionné (Phase B — RO)

| Item | Valeur |
|------|--------|
| Archive | `2026-08-26-21-30-29.zip` |
| Source | PRE-PROD 10.2 automated offsite test |
| Local | PRESENT (`storage/app/private/Gestion/`) |
| R2 | PRESENT (`Gestion/2026-08-26-21-30-29.zip`) |
| Taille | 7577 octets (local = R2) |
| SHA-256 local | `0f08190a3ad1df82feaa8c5c35005cba764012fc89e5e412f79db50401930f32` |
| SHA-256 R2 | `0f08190a3ad1df82feaa8c5c35005cba764012fc89e5e412f79db50401930f32` |
| SHA MATCH | **YES** |
| ZIP | VALID |
| Verdict inspecteur | SCHEMA_ONLY |
| SQL dump | YES |
| CREATE TABLE | 38 |
| Business INSERTs | 0 |
| `.env` | ABSENT |
| Premier backup prod `15-16-45` | CONSERVÉ (non utilisé pour ce drill) |

### Wiring applicatif (RO)

| Item | État |
|------|------|
| Runtime `.env` DB user | `gestion_app` |
| `BACKUP_DISKS` | `local,s3` |
| Commande restore officielle | `php artisan db:restore --backup=… --target=gestion_test --confirmation=RESTORE` |
| Allow-list restore | `gestion_recovery`, `gestion_test` (`DatabaseSafetyGuard`) |
| Cible `gestion` | BLOCKED |
| Mode | DB-only (pas de restore fichiers applicatifs) |
| Compte restore requis | `gestion_restore` (`DatabaseAccountGuard`) |

### Opérations exécutées

```text
DATABASES MODIFIED: NO
FILES MODIFIED: REPORT ONLY
RESTORE EXECUTED: NO
gestion_test CREATED: NO
```

---

## PHASE C — GATE EN ATTENTE

```text
HUMAN APPROVAL REQUIRED

Operation:
CREATE DATABASE gestion_test

Purpose:
isolated restore drill

Source:
2026-08-26-21-30-29.zip (SHA 0f08190a…30f32)

Target:
gestion_test (utf8mb4 / utf8mb4_unicode_ci)

Protected databases:
gestion
gestion_recovery

Expected impact:
gestion_test uniquement

Application files:
NO

gestion:
NO

gestion_recovery:
NO

Restore:
NOT YET EXECUTED

Migration:
NO

Seed:
NO
```

**Phrase exacte requise :**

```text
OUI — CREATE gestion_test FOR RESTORE DRILL
```

---

## Sections suivantes (non exécutées)

- Phase D — CREATE `gestion_test`
- Phase E/F — Preflight + `db:restore`
- Phase G–K — Intégrité, non-impact, tests
- Phase L — Cleanup (`OUI — DELETE gestion_test AFTER DRILL`)
- Phase M — Verdict final

**STOP — HUMAN REVIEW REQUIRED**

---

## PHASE D — CREATE `gestion_test` (2026-08-26)

**Approbation :** `OUI — CREATE gestion_test FOR RESTORE DRILL`

| Étape | Résultat |
|-------|----------|
| `gestion_test` avant | ABSENT |
| CREATE DATABASE | OK (root DBA — création schéma uniquement) |
| Charset / collation | utf8mb4 / utf8mb4_unicode_ci |
| Tables avant restore | **0** |
| `gestion` | UNCHANGED (38 tables, 0 users) |
| `gestion_recovery` | UNCHANGED (27 tables) |

Probe `gestion_restore` (RO, sans toucher `gestion`) :

| Opération | Résultat |
|-----------|----------|
| DROP DATABASE `gestion_test` | ALLOWED |
| CREATE DATABASE `gestion_test` | ALLOWED |
| DROP DATABASE `gestion` | **DENIED** |

---

## PHASE E — RESTORE PREFLIGHT (STOP)

```text
SOURCE DATABASE:
NOT DIRECTLY USED

SOURCE BACKUP:
2026-08-26-21-30-29.zip
SHA-256: 0f08190a3ad1df82feaa8c5c35005cba764012fc89e5e412f79db50401930f32
LOCAL + R2: MATCH

TARGET DATABASE:
gestion_test

RESTORE ACCOUNT:
gestion_restore

MODE:
DATABASE ONLY

APPLICATION FILE RESTORE:
DISABLED

TARGET gestion:
BLOCKED (probe DROP DATABASE gestion = DENIED)

TARGET gestion_recovery:
NOT TARGETED

LOCK restore:
AVAILABLE (BackupConcurrencyGuard)

ARCHIVE:
VALID · SCHEMA_ONLY · 38 CREATE TABLE · 0 INSERT métier · .env ABSENT

DUMP NEUTRALIZATION:
USE / CREATE DATABASE / DROP DATABASE stripped; DROP DATABASE gestion refused
```

```text
HUMAN APPROVAL REQUIRED

Operation:
EXECUTE RESTORE DRILL INTO gestion_test

Command:
php artisan db:restore --backup=2026-08-26-21-30-29.zip --target=gestion_test --confirmation=RESTORE

Protected databases:
gestion = untouched
gestion_recovery = untouched

Expected impact:
gestion_test only (importer may DROP/CREATE gestion_test then import SQL)

Restore:
NOT YET EXECUTED
```

**Phrase exacte requise :**

```text
OUI — EXECUTE RESTORE DRILL INTO gestion_test
```

**STOP — en attente de cette approbation.**

---

## PHASE F — RESTORE RÉEL (2026-08-26)

**Approbation :** `OUI — EXECUTE RESTORE DRILL INTO gestion_test`

### Tentatives (reproductibilité — erreurs non masquées)

| # | Résultat | Cause |
|---|----------|-------|
| 1 | **FAIL** | Bootstrap Laravel + `DB_DATABASE=gestion` → `gestion_restore` ne peut pas lire `gestion` (NotificationCenter) |
| 2 | **FAIL** | Dump mysqldump contient `LOCK TABLES` — privilège absent pour `gestion_restore` sur `gestion_test.*` (policy volontaire) |
| 3 | **PASS** | Process-only : `DB_DATABASE=gestion_test`, `DB_HOST=localhost`, compte `gestion_restore` + neutralisation `LOCK/UNLOCK TABLES` dans `MysqlPdoDumpImporter` |

**Correctif minimal (découvert par le drill) :** strip `LOCK TABLES` / `UNLOCK TABLES` dans `MysqlPdoDumpImporter` — aligné `config/database-accounts.php` (pas de LOCK TABLES sur restore).

### Restore officiel réussi

```text
php artisan db:restore --backup=2026-08-26-21-30-29.zip --target=gestion_test --confirmation=RESTORE
exit_code=0
duration_ms≈3908
account=gestion_restore (process-only)
mode=database
files_touched=false
sha256=0f08190a3ad1df82feaa8c5c35005cba764012fc89e5e412f79db50401930f32
```

---

## PHASE G — INTÉGRITÉ POST-RESTORE

| Contrôle | Backup attendu | `gestion_test` | Résultat |
|----------|----------------|----------------|----------|
| Tables | 38 CREATE | 38 | **PASS** |
| migrations | 75 rows (seed schéma) | 75 | **PASS** |
| users / companies / customers / products / sales / quotes / expenses / suppliers | 0 | 0 | **PASS** |
| sessions | 1 (dump Spatie/runtime) | 1 | **PASS** (non métier) |
| Schéma | SCHEMA_ONLY | SCHEMA_ONLY | **PASS** |

---

## PHASE H — NON-IMPACT

| Base | Avant | Après | Résultat |
|------|-------|-------|----------|
| `gestion` tables | 38 | 38 | **UNCHANGED** |
| `gestion` users | 0 | 0 | **UNCHANGED** |
| `gestion_recovery` tables | 27 | 27 | **UNCHANGED** |
| `gestion_recovery` users | 4 | 4 | **UNCHANGED** |

```text
GESTION UNCHANGED: YES
gestion_recovery UNCHANGED: YES
```

---

## PHASE I — APPLICATION SMOKE TEST

```text
NOT EXECUTED (production .env inchangé — gestion_app / gestion)
```

Recommandation : smoke test isolé via `.env.testing` ou variables process-only pointant `DB_DATABASE=gestion_test` (approbation séparée).

---

## PHASE J — PROTECTIONS `gestion_restore`

| Probe | Résultat |
|-------|----------|
| DROP DATABASE `gestion` | **DENIED** |
| SELECT `gestion.*` | **DENIED** |
| DROP/CREATE `gestion_test` | ALLOWED (restore drill) |
| GRANT / CREATE USER | non testé directement — absent des GRANTs |

---

## PHASE K — REPRODUCTIBILITÉ

Procédure documentée validée avec prérequis process-only :

1. Sélection backup + SHA ✓  
2. CREATE `gestion_test` (approbation DBA/root) ✓  
3. Process-only : `gestion_restore`, `DB_DATABASE=gestion_test`, `DB_HOST=localhost`, cache `file` ✓  
4. `db:restore --target=gestion_test --confirmation=RESTORE` ✓  
5. Vérification tables / counts / non-impact ✓  

**Point d'attention ops :** sans overrides process-only, le bootstrap Laravel échoue si `DB_DATABASE=gestion` et compte restore.

---

## PHASE L — CLEANUP (STOP)

`gestion_test` contient le drill réussi (38 tables). **Non supprimée.**

```text
HUMAN APPROVAL REQUIRED

Operation:
DROP DATABASE gestion_test

Reason:
cleanup after successful restore drill

Protected databases:
gestion = untouched
gestion_recovery = untouched

Target:
gestion_test

Restore result:
PASS

Integrity:
PASS
```

**Phrase exacte requise :**

```text
OUI — DELETE gestion_test AFTER DRILL
```

---

## FINAL VERDICT

```text
RESTORE DRILL VERIFIED WITH WARNINGS
```

**Warnings :**
1. Deux échecs initiaux documentés (bootstrap DB + LOCK TABLES mysqldump)  
2. Smoke test applicatif non exécuté (`.env` prod intact)  
3. `gestion_test` laissée en place en attente cleanup  

**Tests Pest (restore-related) :** 52 passed

**`.env` production :** UNCHANGED (`gestion_app` / `gestion`)

---

## PHASE L — CLEANUP (2026-08-27)

**Approbation :** `OUI — DELETE gestion_test AFTER DRILL`

| Étape | Résultat |
|-------|----------|
| `gestion_test` avant | PRESENT (38 tables) |
| `DROP DATABASE gestion_test` | OK |
| `gestion_test` après | **ABSENT** |
| `gestion` | UNCHANGED (38 tables, 0 users) |
| `gestion_recovery` | UNCHANGED (27 tables, 4 users) |
| `.env` runtime | UNCHANGED (`gestion_app` / `gestion`) |

```text
CLEANUP: PASS
PRE-PROD 11: CLOSED
FINAL VERDICT: RESTORE DRILL VERIFIED WITH WARNINGS
```
