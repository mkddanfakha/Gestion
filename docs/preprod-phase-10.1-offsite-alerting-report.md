# PRE-PROD 10.1 — Offsite Backup & Alerting Configuration

**Date :** 2026-08-26  
**Mode :** CONFIGURATION + AUDIT — **aucun** `backup:run` / restore / migrate / seed / GRANT  
**`.env` runtime :** non modifié (clés documentées dans `.env.example` uniquement)

---

```text
OFFSITE:
NOT CONFIGURED

OFFSITE CREDENTIALS:
PRESENT (partial — keys set; values never displayed)

BUCKET:
MISSING (empty)

ENDPOINT:
MISSING

BACKUP DISK:
PASS (local serve=false; s3 visibility=private; env-driven BACKUP_DISKS)

BACKUP ACCOUNT:
PASS (grants SELECT, LOCK TABLES, SHOW VIEW, TRIGGER on gestion.*)

BACKUP WIRING:
FAIL (runtime DB_USERNAME=gestion_app; backup:run requires gestion_backup)

RETENTION:
PASS (30 / 12 / 12)

ALERTING:
PARTIAL (mechanism ready; not enabled until BACKUP_ALERT_* set in .env)

SCHEDULER CODE:
PASS (02:00 / 03:00 / 04:00 Africa/Dakar)

SCHEDULER RUNNER:
NOT VERIFIED (no Windows Task Scheduler entry for artisan schedule:run found)

SECRETS:
PASS (.env gitignored; no secrets in config/docs/tests; placeholders only)

GESTION:
UNCHANGED (38 tables, 0 métier, 75 migrations)

GESTION_RECOVERY:
UNCHANGED (27 tables)

BACKUP EXECUTED:
NO

RESTORE EXECUTED:
NO

MIGRATE EXECUTED:
NO

SEED EXECUTED:
NO

STATUS:
BLOCKED — CONFIGURATION REQUIRED
```

---

## 1. Offsite — audit

| Élément | État |
|---------|------|
| `AWS_ACCESS_KEY_ID` | (set) — non affiché |
| `AWS_SECRET_ACCESS_KEY` | (set) — non affiché |
| `AWS_DEFAULT_REGION` | `us-east-1` |
| `AWS_BUCKET` | **vide** |
| `AWS_ENDPOINT` | **MISSING** |
| `BACKUP_DISKS` | défaut **`local`** (s3 non ajouté tant que bucket vide) |
| `php artisan backup:status` → offsite | **`NOT_CONFIGURED`** |

### Manque pour OVH / S3-compatible (action humaine hors chat)

Dans **`.env` local uniquement** (ne pas coller ici) :

1. `AWS_BUCKET=<nom-bucket>`
2. `AWS_ENDPOINT=<endpoint Object Storage>` (souvent requis pour OVH)
3. Vérifier `AWS_USE_PATH_STYLE_ENDPOINT` si le fournisseur l’exige
4. Puis seulement : `BACKUP_DISKS=local,s3`

**Ne pas inventer** bucket/endpoint. Tant que bucket vide → offsite reste NOT CONFIGURED.

### Secrets hygiene

| Check | Résultat |
|-------|----------|
| `.env` dans `.gitignore` | OUI |
| Secrets dans `config/` | NON (uniquement `env()`) |
| Secrets dans `docs/` | NON |
| Secrets dans tests | NON |
| `.mysql-*.local` gitignorés | OUI |

---

## 2. Disque backup

| Disque | Rôle | Exposition |
|--------|------|------------|
| `local` | archives Spatie sous `storage/app/private/{APP_NAME}/` | `serve=false` |
| `s3` | offsite (quand `BACKUP_DISKS` l’inclut) | `visibility=private` ; pas d’URL publique app |

- Pas de stockage prévu de `.env` dans les sources Spatie (exclu dans `config/backup.php`).
- Archives historiques nov. 2025 encore présentes localement : verdict inspector **`CAUTION_CONTAINS_DOTENV`** — à ne pas traiter comme backup production 9.4 ; ne pas les republier offsite sans revue.

**Aucun upload offsite exécuté.**

---

## 3. Rétention

| Politique | Config | Verdict |
|-----------|--------|---------|
| Daily | 30 | PASS |
| Weekly | 12 | PASS |
| Monthly | 12 | PASS |

`backup:clean` = suppression d’**archives ZIP** sur disques backup uniquement — **jamais** `DROP DATABASE` / tables / lignes métier.

---

## 4. Alerting

### Avant 10.1

`via()` = `[]` partout → aucune alerte mail.

### Après 10.1 (code)

- Helper `App\Notifications\Backup\BackupAlertChannels`
- Mail Spatie **uniquement si** :
  - `BACKUP_ALERT_MAIL_ENABLED=true`
  - `BACKUP_ALERT_EMAIL` = email valide
- Sinon `via()` reste vide (pas d’envoi)
- Spatie exige un `mail.to` syntaxiquement valide au boot → placeholder `backup-alerts-disabled@example.com` (jamais utilisé tant que gate off)
- SMTP déjà présent dans l’environnement local (`MAIL_MAILER=smtp`, host Gmail) — **aucun test d’envoi** effectué
- Logs échec : message d’exception seulement (pas de password / AWS secret)

### Signaux couverts (quand activé)

| Événement Spatie | Canal |
|------------------|-------|
| Backup failed | mail (+ log) |
| Unhealthy (âge / storage) | mail (+ log) |
| Backup / cleanup success | mail si gate on |
| Checksum / verify / offsite upload fail | **pas encore de notification dédiée** — à brancher en phase ultérieure sur `backup:verify` / upload |

### Action humaine (dans `.env`, hors chat)

```text
BACKUP_ALERT_MAIL_ENABLED=true
BACKUP_ALERT_EMAIL=<adresse-ops>
```

Ne pas coller de mot de passe SMTP ici (déjà géré par `MAIL_*`).

```text
ALERTING: PARTIAL
```

---

## 5. Scheduler

| Item | Valeur |
|------|--------|
| CODE | `backup:run` 02:00 ; `backup:clean` 03:00 ; `backup:monitor` 04:00 |
| Timezone | `Africa/Dakar` |
| OS Task Scheduler (Windows) | **aucune tâche** `artisan schedule:run` / laravel / gestion trouvée |
| RUNNER | **NOT VERIFIED** |

Action humaine : créer une tâche planifiée Windows (ou cron) :

```text
php artisan schedule:run
```

toutes les minutes (standard Laravel).

---

## 6. Backup command wiring

| Compte | Rôle |
|--------|------|
| `gestion_app` | runtime `.env` — **doit rester** |
| `gestion_backup` | dumps — grants OK |
| `root` | **interdit** pour backup applicatif |

`PrivilegedCommandGuard` + `CreateBackupJob` exigent `gestion_backup` pour `backup:run`.

Avec `.env` actuel (`gestion_app`) → `backup:run` **échoue** (voulu).

### Méthode process-only (documentée — NON exécutée)

Sans modifier le `.env` runtime durablement :

1. Lire le secret depuis `.mysql-gestion-backup.local` (hors Git)
2. Lancer **un processus** avec variables d’environnement :
   - `DB_USERNAME=gestion_backup`
   - `DB_PASSWORD=<from secret file>`
   - `DB_DATABASE=gestion`
3. Exécuter `php artisan backup:run` (ou `--only-db`)
4. Vérifier que le fichier `.env` sur disque reste `DB_USERNAME=gestion_app`

Même pattern que le migrate 9.4 (process-only).

```text
BACKUP WIRING: FAIL (until process-only used at Phase J)
```

---

## 7. Safety check (READ-ONLY)

| Check | Résultat |
|-------|----------|
| `gestion` PRESENT | OUI — 38 tables |
| Données métier | 0 (`users`/`customers`=0) |
| migrations | 75 |
| `gestion_recovery` | 27 tables UNCHANGED |
| `gestion_app` runtime | OUI |
| `gestion_backup` grants | PASS |
| `gestion_restore` scoped recovery/test | PASS |
| `gestion_migration` DROP | **ABSENT** |
| Global `*.*` hors USAGE | comptes app = USAGE only on `*.*` |

Pest (échantillon 10.1) : **21 passed** (BackupStrategy + BackupAlertChannels + AccountSeparation).

---

## 8. Fichiers modifiés (10.1)

| Fichier | Nature |
|---------|--------|
| `config/backup.php` | `BACKUP_DISKS`, mail.to gated, monitor disks |
| `config/filesystems.php` | s3 `visibility=private` |
| `app/Notifications/Backup/*` | `BackupAlertChannels` |
| `app/Console/Commands/BackupStatusCommand.php` | offsite/alerting status |
| `.env.example` | clés documentées (vides) |
| `tests/Unit/Infrastructure/BackupAlertChannelsTest.php` | nouveau |
| `docs/preprod-phase-10.1-offsite-alerting-report.md` | ce rapport |

**`.env` local : non modifié.**  
**MySQL : non modifié.**  
**`gestion` / `gestion_recovery` : non modifiés.**

---

## 9. Conditions pour lever le BLOCK

Avant `OUI — RUN FIRST PRODUCTION BACKUP`, l’humain doit :

1. **(Recommandé)** Compléter offsite : `AWS_BUCKET` + `AWS_ENDPOINT` puis `BACKUP_DISKS=local,s3`  
   **OU** accepter explicitement un **premier backup local-only** (offsite plus tard)
2. **(Recommandé)** Activer alerting : `BACKUP_ALERT_MAIL_ENABLED=true` + `BACKUP_ALERT_EMAIL=…`
3. **(Obligatoire pour fiabilité)** Installer le runner OS `schedule:run`
4. Accepter le **wiring process-only** `gestion_backup` pour l’exécution du backup

---

## 10. HUMAN APPROVAL — pas encore

```text
STATUS: BLOCKED — CONFIGURATION REQUIRED

Reason:
- OFFSITE incomplete (bucket/endpoint)
- ALERTING not enabled in .env
- SCHEDULER RUNNER not verified
- BACKUP WIRING requires process-only gestion_backup at execution time

DO NOT RUN backup:run yet.
```

Lorsque les conditions acceptées seront remplies, une approbation **séparée** sera requise :

```text
OUI — RUN FIRST PRODUCTION BACKUP
```

Cette phrase n’autorise **que** le premier backup (lecture MySQL + écriture archive).  
Elle n’autorise **pas** : restore, CREATE DATABASE, migrate, seed, DROP, GRANT, restore vers `gestion`.

---

```text
BACKUP EXECUTED: NO
RESTORE EXECUTED: NO
GESTION: UNCHANGED
GESTION_RECOVERY: UNCHANGED
STOP: HUMAN CONFIGURATION / DECISION REQUIRED
```
