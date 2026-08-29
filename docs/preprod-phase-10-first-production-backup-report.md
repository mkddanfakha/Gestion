# PRE-PROD 10 — First Production Backup Report

**Date :** 2026-08-26  
**Approbation :** `OUI — RUN FIRST PRODUCTION BACKUP`  
**Mode :** lecture MySQL uniquement + écriture archive locale — **aucun** restore / migrate / seed / DROP / GRANT

---

```text
========================================
MKD-PRO PRE-PROD 10
FIRST PRODUCTION BACKUP
========================================

BACKUP EXECUTED:
YES

BACKUP TYPE:
--only-db (Spatie)

DATABASE ACCOUNT:
gestion_backup (process-only)

RUNTIME .env:
gestion_app (UNCHANGED)

DATABASE WRITE:
NO (métier)

GESTION TABLES BEFORE/AFTER:
38 / 38

GESTION USERS BEFORE/AFTER:
0 / 0

GESTION_RECOVERY:
UNCHANGED (27 tables)

OFFSITE UPLOAD:
NO (NOT CONFIGURED)

ARCHIVE:
storage/app/private/Gestion/2026-08-26-15-16-45.zip

SIZE:
7 577 bytes (~7.4 KB)

SHA-256:
3231554e80931843fc634dab269fc9606f47f2208750f9bddf9cce2eca1453dc

ZIP VALID:
YES

SQL PRESENT:
YES

CREATE TABLE COUNT:
38

BUSINESS INSERTS:
0

CONTAINS .env:
NO

VERDICT:
BACKUP VERIFIED
(inspector: SCHEMA_ONLY — expected: empty métier dataset)

ELAPSED:
4.16 s

MIGRATE:
NO

SEED:
NO

RESTORE:
NO

DROP:
NO
```

---

## 1. Exécution

| Étape | Résultat |
|-------|----------|
| Connect `gestion_backup` | OK |
| Grants | SELECT, LOCK TABLES, SHOW VIEW, TRIGGER uniquement |
| Lock concurrence | OK via **CACHE_STORE=file** process-only |
| `backup:run --only-db` | exit **0** |
| Copie disque `local` | OK |
| Upload `s3` | **non** (BACKUP_DISKS=local) |

### Note technique (lock)

`BackupConcurrencyGuard` utilise le Cache Laravel. Avec `CACHE_STORE=database`, `gestion_backup` **ne peut pas** INSERT dans `cache` (volontaire least-privilege).  

Pour ce run : `CACHE_STORE=file` en **process-only** (`.env` runtime inchangé).  

**Suivi recommandé :** documenter / adapter le guard backup pour un lock fichier par défaut lors des runs `gestion_backup` (hors scope de cette approbation).

---

## 2. Vérification archive

| Check | Résultat |
|-------|----------|
| Fichier présent | PASS |
| Taille > 0 | PASS (7577) |
| ZIP valide | PASS |
| Dump SQL présent | PASS |
| SHA-256 | PASS (ci-dessus) |
| CREATE TABLE | **38** (= schéma actuel) |
| Inserts métier | **0** (base vide — attendu) |
| `.env` dans ZIP | **NO** |
| Secrets markers SQL | non signalés |
| `backup:verify` Artisan | PASS (readable) |

```text
BACKUP VERIFIED
Inspector verdict label: SCHEMA_ONLY
(not a failure — no business rows exist yet)
```

---

## 3. Intégrité bases

| Base | Avant | Après |
|------|-------|-------|
| `gestion` tables | 38 | 38 |
| `gestion` users | 0 | 0 |
| `gestion_recovery` tables | 27 | 27 |

```text
GESTION: UNCHANGED (structure + empty data)
GESTION_RECOVERY: UNCHANGED
APPLICATION .env: UNCHANGED (DB_USERNAME=gestion_app)
```

---

## 4. Contenu (inspection sans restore)

- Archive DB-only Spatie
- Schéma aligné post-migrate 9.4 (38 tables)
- Aucune donnée métier (cohérent avec état actuel)
- Pas de credentials / `.env` dans l’archive

---

## 5. Offsite

```text
OFFSITE: NOT CONFIGURED
OFFSITE VERIFICATION: NOT TESTED
```

Copie locale **conservée**. Aucune suppression.

---

## 6. Limites / suite

| Item | État |
|------|------|
| Alerting mail | NOT_ENABLED (10.1) |
| Scheduler OS | NOT VERIFIED |
| Restore drill | **NOT RUN** — nécessite `OUI — CREATE gestion_test FOR RESTORE DRILL` |
| Dataset métier | toujours vide — prochain backup après seed aura des INSERT |

---

## 7. Approbations non utilisées

Cette phase **n’autorise pas** et **n’a pas exécuté** :

- restore vers `gestion` ou autre
- CREATE DATABASE
- migrate / seed
- DROP / GRANT / REVOKE
- modification `gestion_recovery`

---

```text
LOCAL BACKUP: PASS
BACKUP VERIFICATION: PASS
OFFSITE: NOT CONFIGURED
RESTORE DRILL: NOT TESTED
PRODUCTION STATUS: READY WITH CONDITIONS
(conditions: offsite, alerting, scheduler runner, restore drill, real business data later)

NEXT HUMAN GATES (examples):
OUI — CREATE gestion_test FOR RESTORE DRILL
(and/or complete offsite + alerting in .env)
```
