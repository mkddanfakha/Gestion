# PRE-PROD 10.2 — Automated Backup / Offsite / Scheduler / Alerting

**Date :** 2026-08-26  
**Mode :** AUDIT + durcissement opérationnel (fail-closed)  
**Interdit / non exécuté :** `backup:run`, restore, migrate, seed, DROP, GRANT, modification `.env`, création `gestion_test`, commit/push

---

## 1. EXECUTIVE SUMMARY

La preuve ponctuelle R2 (PRE-PROD 10 offsite) **existe**, mais la **chaîne Spatie automatisée** reste en **LOCAL ONLY** : `BACKUP_DISKS` n’est **pas** défini → défaut `local`. Le monitoring déclare désormais explicitement `BACKUP DEGRADED` (plus un faux `OK`).

**Verdict :** `READY WITH CONDITIONS` — **STOP** avant enable offsite + test `backup:run`.

| Domaine | Verdict |
|---------|---------|
| Premier backup local + SHA | VERIFIED |
| Copie R2 manuelle (test 10) | VERIFIED |
| Wiring Spatie LOCAL+R2 | **NOT_CONFIGURED** |
| Monitoring | PASS (amélioré) |
| Alerting mail | NOT_CONFIGURED |
| Scheduler Laravel | PASS (02/03/04 + withoutOverlapping) |
| Scheduler OS | NOT VERIFIED |
| Locks fichier | PASS |
| MySQL accounts | PASS |
| Pest | **111 passed** |
| gestion / gestion_recovery | UNCHANGED |

---

## 2. BACKUP ARCHITECTURE

```text
APP (.env DB=gestion_app)
  → PrivilegedCommandGuard (backup:run → gestion_backup requis)
  → BackupConcurrencyGuard (store file)
  → Spatie backup:run
  → destination disks = BACKUP_DISKS (default: local)
  → local: storage/app/private/{APP_NAME}/
  → s3: Cloudflare R2 (si s3 dans BACKUP_DISKS)
  → backup:monitor / backup:status / BackupAlertChannels
```

Spatie `copyToBackupDestinations` : **échec sur une destination → exception `BackupFailed`** (pas de succès global silencieux).  
**Nuance :** une notif `BackupWasSuccessful` peut être émise **par disque** avant l’échec du disque suivant ; le job global échoue quand même.

---

## 3. LOCAL BACKUP

| Item | Valeur |
|------|--------|
| Disque | `local` (`serve=false`) |
| Dossier | `Gestion` |
| Dernière archive | `2026-08-26-15-16-45.zip` |
| SHA-256 | `3231554e80931843fc634dab269fc9606f47f2208750f9bddf9cce2eca1453dc` |
| Verdict inspecteur | `SCHEMA_ONLY` (38 CREATE, 0 business inserts) |
| `.env` dans ZIP | ABSENT (premier backup prod) |
| Archives nov. 2025 | encore présentes ; verdict `CAUTION_CONTAINS_DOTENV` (historiques) |

`backup:status` : `BACKUP_LOCAL=OK`, âge ~6 h, RPO OK (≤ 24 h).

---

## 4. R2 OFFSITE

| Item | État |
|------|------|
| `AWS_ACCESS_KEY_ID` | SET |
| `AWS_SECRET_ACCESS_KEY` | SET |
| `AWS_BUCKET` | SET (`mkd-pro-backups`) |
| `AWS_ENDPOINT` | SET (R2-compatible) |
| `AWS_USE_PATH_STYLE_ENDPOINT` | **false** (test manuel a nécessité **true**) |
| Copie manuelle testée | VERIFIED (`mkdpro/preprod-10/2026-08-26-15-16-45.zip`) |
| Destination Spatie `s3` | **NO** (`BACKUP_DISKS` défaut `local`) |
| SSL verify=false | **JAMAIS** utilisé |
| CA PHP (`curl.cainfo`) | EMPTY sur WAMP → risque cURL 60 sans bundle |

---

## 5. AUTOMATED OFFSITE WIRING

```text
BACKUP_DISKS: NOT_SET → effective ["local"]
s3_in_backup_disks: NO
automated_offsite: NOT_CONFIGURED
backup:status STATUS: BACKUP DEGRADED
mode: LOCAL_ONLY
```

**Pourquoi :** décision humaine non encore appliquée dans `.env` (volontaire — fail-closed).  
**Pas de fallback silencieux « offsite OK »** : monitoring marque DEGRADED si R2 non câblé.

---

## 6. SHA-256 VERIFICATION

| Source | SHA-256 | Match |
|--------|---------|-------|
| Local first production | `3231554e…1453dc` | — |
| R2 test (phase 10) | même | MATCH |
| Nouveau backup auto | **NOT EXECUTED** | — |

---

## 7. MONITORING

Améliorations PRE-PROD 10.2 :

- `App\Database\BackupOperationalStatus`
- Champs : `STATUS`, `MODE`, `BACKUP_LOCAL`, `BACKUP_OFFSITE`, `LAST_BACKUP`, `LAST_OFFSITE_COPY`, `CHECKSUM`, `ZIP`, `BACKUP_AGE`, `RPO` (≤ 24 h)
- Si local OK et offsite absent/non câblé → **`BACKUP DEGRADED`** (pas `OK`)

`php artisan backup:status` (RO) confirmé.

---

## 8. ALERTING

| Canal | État |
|-------|------|
| Mail | **NOT_CONFIGURED** (`BACKUP_ALERT_MAIL_ENABLED` / `BACKUP_ALERT_EMAIL` NOT_SET) |
| Slack / Discord | webhook vide |
| Gate | `BackupAlertChannels::mailWhenConfigured()` |

Couverture Spatie existante : failed / successful / cleanup / unhealthy / healthy.  
**Manques fonctionnels explicites :** pas d’événement dédié « offsite-only failed » distinct du fail Spatie ; pas d’alerte RPO custom hors `backup:monitor` + mail.  
**Spam :** gate mail évite l’envoi tant que non activé.  
**Test mail réel :** NON (nécessite approbation).

```text
ALERTING CHANNEL: NOT CONFIGURED
```

---

## 9. LARAVEL SCHEDULER

`routes/console.php` (timezone app = **Africa/Dakar**) :

| Commande | Horaire | Overlap |
|----------|---------|---------|
| `backup:run` | 02:00 | `withoutOverlapping(180)` |
| `backup:clean` | 03:00 | `withoutOverlapping(120)` |
| `backup:monitor` | 04:00 | `withoutOverlapping(60)` |

`php artisan schedule:list` : Next Due cohérent.  
Échec : exit code non-zéro Spatie + event `BackupHasFailed` (mail si activé).

---

## 10. OS SCHEDULER

Recherche Task Scheduler Windows : **aucune tâche** `artisan schedule:run` / `gestion` / `mkdpro` identifiée (tâches génériques « Backup » OS non liées).

```text
SCHEDULER OS: NOT VERIFIED / NOT CONFIGURED
```

Proposition séparée (non exécutée) : tâche Windows toutes les minutes → `php artisan schedule:run` (chemin PHP WAMP + cwd projet).

---

## 11. LOCKS

| Item | Verdict |
|------|---------|
| Store | `file` (défaut `BACKUP_LOCK_CACHE_STORE`, env NOT_SET) |
| TTL backup | 7200 s |
| TTL restore | 1800 s |
| Release after success/exception | PASS (tests) |
| Concurrent acquire | BLOCKED (tests) |
| Indépendant `gestion_backup` INSERT | PASS |

---

## 12. RETENTION

Config Spatie : **30** jours all / **30** daily / **12** weekly / **12** monthly ; newest never deleted by default strategy.  
Cleanup non lancé (pas d’approbation purge).  
R2 : **pas encore** destination Spatie → rétention R2 lifecycle Cloudflare **hors** Spatie tant que non câblé.

---

## 13. MYSQL ACCOUNTS

Baseline GRANTs (RO, secrets non affichés) :

| Compte | Grants observés |
|--------|-----------------|
| `gestion_app` | SELECT, INSERT, UPDATE, DELETE on `gestion.*` |
| `gestion_backup` | SELECT, LOCK TABLES, SHOW VIEW, TRIGGER on `gestion.*` |
| `gestion_restore` | DDL+DML on `gestion_recovery.*` / `gestion_test.*` only |
| `gestion_migration` | CRUD + CREATE/ALTER/INDEX/REFERENCES on `gestion.*` — **DROP absent** |
| Runtime `.env` | `gestion_app` |

`backup:run` refuse `gestion_app` (tests AccountSeparation).

---

## 14. SECRET HYGIENE

| Contrôle | Verdict |
|----------|---------|
| `.env` / `.mysql-*.local` gitignored | PASS |
| `/*.sql` gitignored | PASS |
| `storage/app/private/*` gitignored | PASS |
| Secrets dans Git index | NONE found |
| Rotation (traces CLI historiques) | **SECRET ROTATION REQUIRED** (étape séparée) |

---

## 15. TEST RESULTS

```text
./vendor/bin/pest tests/Unit/Infrastructure
Tests: 111 passed (281 assertions)
```

Inclut locks, safety, restore safety, accounts, alerting channels, backup:status DEGRADED, operational offsite missing → DEGRADED.

---

## 16. BUILD

```text
BUILD: NOT RUN
```

Aucune modification frontend / Vite dans cette phase.

---

## 17. GIT

```text
GIT: MODIFIED
```

Changements 10.2 (non commités) notamment :

- `app/Database/BackupOperationalStatus.php` (nouveau)
- `app/Console/Commands/BackupStatusCommand.php`
- `routes/console.php` (withoutOverlapping)
- `tests/Unit/Infrastructure/BackupStrategyTest.php`
- docs rapport

Pas de commit / push.

---

## 18. DATABASE BASELINE

| Métrique | Valeur |
|----------|--------|
| gestion exists | YES |
| tables | 38 |
| migrations | 75 |
| users / companies / customers / products / sales / quotes / expenses / suppliers / purchase_orders / delivery_notes / inventory_sessions / stock_movements | **0** |
| gestion_recovery tables | 27 |

```text
GESTION UNCHANGED: YES
gestion_recovery UNCHANGED: YES
```

---

## 19. REMAINING RISKS

1. Spatie n’upload **pas** encore vers R2 (`BACKUP_DISKS`).
2. `AWS_USE_PATH_STYLE_ENDPOINT=false` alors que R2 a nécessité `true`.
3. CA PHP WAMP vide → échec SSL possible sur upload auto.
4. OS scheduler absent → jobs 02/03/04 **ne tourneront pas** seuls.
5. Alerting mail non activé.
6. Notif `BackupWasSuccessful` possible **par disque** avant fail offsite.
7. Archives nov. 2025 locales contiennent encore `.env` (historiques).
8. Secret rotation encore due.

---

## 20. HUMAN ACTIONS REQUIRED

### A — Enable automatic offsite (BLOQUANT pour chaîne auto)

```text
HUMAN APPROVAL REQUIRED

Operation:
Enable automatic offsite destination for production backups

Required change (hors chat, dans .env):
BACKUP_DISKS=local,s3
AWS_USE_PATH_STYLE_ENDPOINT=true

Impact:
Future Spatie backups will be written locally AND to R2

No database modification: YES
No restore: YES
No migration: YES
```

### B — Après A uniquement

```text
HUMAN APPROVAL REQUIRED

Operation:
RUN AUTOMATED OFFSITE BACKUP TEST

Target database: gestion
Expected effect: READ production database and create backup only
Expected writes: backup storage only (local + R2)
Database modification: NO
Restore: NO
Migration: NO
Seed: NO
```

Phrase exacte attendue (proposition) :

```text
OUI — RUN AUTOMATED OFFSITE BACKUP TEST
```

### C — Autres (séparés)

- Configurer Task Scheduler OS → `schedule:run`
- Activer alerting : `BACKUP_ALERT_MAIL_ENABLED=true` + email valide
- Fixer `curl.cainfo` / `openssl.cafile` dans `php.ini`
- `SECRET ROTATION REQUIRED` (approbation dédiée)
- PRE-PROD 11 restore drill (`OUI — CREATE gestion_test FOR RESTORE DRILL`)

---

```text
STOP — HUMAN REVIEW REQUIRED
Ne pas enchaîner PRE-PROD 11.
Ne pas modifier .env automatiquement.
Ne pas exécuter backup:run sans nouvelle approbation.
```
