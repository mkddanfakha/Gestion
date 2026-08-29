# PRE-PROD 10 — Phase A — Backup System Audit (READ-ONLY)

**Date :** 2026-08-26  
**Mode :** READ-ONLY — aucun `backup:run`, aucun restore, aucun seed, aucun GRANT/REVOKE, aucun changement `.env` / MySQL / `gestion`  
**Contexte schéma :** `gestion` = 38 tables / 75 migrations / 0 donnée métier (post 9.4)

---

```text
PHASE A — BACKUP AUDIT

LOCAL BACKUP IMPLEMENTATION: PRESENT (Spatie)
OFFSITE: NOT CONFIGURED (incomplete)
BACKUP ACCOUNT: EXISTS (grants OK) — NOT WIRED TO RUNTIME/.env
FIRST PRODUCTION BACKUP: NOT RUN
HUMAN ACTION REQUIRED: YES (credentials offsite + wiring backup account + approval Phase I)
```

---

## 1. État actuel

### Stack

| Composant | État |
|-----------|------|
| Package | `spatie/laravel-backup` (config `config/backup.php`) |
| Dump MySQL | Spatie DbDumper + `App\DbDumpers\MySqlForcedTcp` (`BackupServiceProvider`) |
| UI admin | `BackupController` — index / store / download / destroy / import / restore (DB-only service) |
| Job | `CreateBackupJob` (queue) + lock `BackupConcurrencyGuard` |
| Verify | `php artisan backup:verify {path}` → `BackupArchiveInspector` |
| Status | `php artisan backup:status` |
| Cleanup Spatie | `backup:clean` |
| Monitor Spatie | `backup:monitor` |

### Destination locale

| Item | Valeur |
|------|--------|
| Disque Spatie | `local` uniquement (`config/backup.php` → `destination.disks`) |
| Root disque | `storage/app/private` |
| Dossier archive | `{APP_NAME}` → **`Gestion`** |
| Chemin attendu | `storage/app/private/Gestion/*.zip` |
| Archives actuelles de la base **propre** 9.4 | **0** (aucun backup post-migrate) |
| Archives historiques nov. 2025 | hors scope production actuelle (copies éventuelles ailleurs ; **≠** backup de `gestion` vide actuelle) |

### Base / dump

| Item | Valeur |
|------|--------|
| Source DB | connexion `env('DB_CONNECTION')` → **mysql** / DB `gestion` |
| `mysqldump` path | `config/database.php` défaut WAMP ; clé `.env` `MYSQL_DUMP_PATH` = **MISSING** (fallback code) |
| `use_single_transaction` | **false** → nécessite `LOCK TABLES` (aligné avec grants `gestion_backup`) |
| Exclusions fichiers | `vendor`, `node_modules`, `.git`, **`.env`**, logs, cache, backup-temp, restore-temp, dossier backups |
| Chiffrement archive | `BACKUP_ARCHIVE_PASSWORD` = **MISSING** → password Spatie null / encryption default |

### Scheduler (`routes/console.php`)

| Commande | Fréquence | Timezone app |
|----------|-----------|--------------|
| `backup:run` | daily **02:00** | `Africa/Dakar` |
| `backup:clean` | daily **03:00** | idem |
| `backup:monitor` | daily **04:00** | idem |

**Prérequis OS :** `php artisan schedule:run` (cron / Task Scheduler Windows) — **non vérifié comme service actif** dans cet audit (hors code).

### Rétention (déjà dans config)

| Paramètre | Valeur | Cible PRE-PROD 10 |
|-----------|--------|-------------------|
| keep_all_backups_for_days | 30 | OK |
| keep_daily_backups_for_days | 30 | OK (Daily 30) |
| keep_weekly_backups_for_weeks | 12 | OK |
| keep_monthly_backups_for_months | 12 | OK |
| keep_yearly | 2 | bonus |
| max storage cleanup | 5000 MB | OK |

`backup:clean` ne touche **que** les archives ZIP sur le disque de backup Spatie — **pas** MySQL / `gestion`.

### Notifications / alerting

| Canal | État |
|-------|------|
| Classes Spatie custom `App\Notifications\Backup\*` | `via()` = **[]** (désactivées) |
| Échec | log `backup.failed` (message exception uniquement — **pas** de secret forcé) |
| Mail | `mail.to` = placeholder `your@example.com` ; Spatie mail channel **non branché** (via vide) |
| Slack / Discord | webhooks vides |
| Alerting opérationnel | **FAIL / NOT OPERATIONAL** |

### Compte MySQL backup

```text
gestion_backup@localhost / 127.0.0.1:
GRANT SELECT, LOCK TABLES, SHOW VIEW, TRIGGER ON `gestion`.*
```

| Attendu | Observé |
|---------|---------|
| SELECT | OUI |
| SHOW VIEW | OUI |
| TRIGGER | OUI |
| LOCK TABLES | OUI |
| INSERT/UPDATE/DELETE/CREATE/ALTER/DROP | **ABSENT** ✓ |
| CREATE USER / GRANT OPTION | **ABSENT** ✓ |

Runtime `.env` :

```text
DB_USERNAME=gestion_app
DB_DATABASE=gestion
```

→ **`gestion_backup` n’est pas le compte utilisé par Laravel runtime.**

### Offsite / S3

| Clé `.env` (présence seulement) | État |
|--------------------------------|------|
| `AWS_ACCESS_KEY_ID` | (set) |
| `AWS_SECRET_ACCESS_KEY` | (set) |
| `AWS_BUCKET` | **vide** |
| `AWS_ENDPOINT` | **MISSING** |
| `AWS_DEFAULT_REGION` | `us-east-1` |
| Disque dans `backup.destination.disks` | **`local` seul** — **pas** `s3` |

```text
OFFSITE: NOT CONFIGURED
(credentials partiels présents ; bucket/endpoint/disque backup absents)
```

**Ne pas inventer** bucket/endpoint. Action humaine requise.

### Queue

`QUEUE_CONNECTION=database` — `CreateBackupJob` implémente `ShouldQueue` ; le controller web peut aussi exécuter `backup:run` en synchrone (Windows: sous-processus). Worker queue **non audité** comme processus actif.

---

## 2. Ce qui est déjà sécurisé

1. **Séparation comptes** (policy + MySQL) : `gestion_app` ≠ `gestion_backup` ≠ `gestion_restore` ≠ `gestion_migration`
2. **`PrivilegedCommandGuard`** : `backup:run` exige le compte `gestion_backup` (fail-closed si runtime = `gestion_app`)
3. **`CreateBackupJob`** : `DatabaseAccountGuard::assertAccountForOperation(BACKUP)` + `BackupConcurrencyGuard`
4. **Restore vers `gestion` bloqué** (allow-list `gestion_recovery` / `gestion_test`)
5. **`.env` exclu** de la source fichiers Spatie (évite récurrence archives historiques contenant `.env`)
6. **`BackupArchiveInspector`** : SHA-256, ZIP, SQL present, CREATE count, inserts métier, flag `.env` dans ZIP — **sans import**
7. **Rétention** déjà alignée Daily 30 / Weekly 12 / Monthly 12
8. **Cleanup** = fichiers archive uniquement
9. **DROP révoqué** sur `gestion_migration` (hors backup, mais safety globale)
10. Tests Pest Infrastructure (dont BackupStrategy) — suite présente

---

## 3. Ce qui manque (gaps)

| # | Gap | Impact |
|---|-----|--------|
| 1 | **Wiring `gestion_backup`** pour `backup:run` (process/env dédié) | Scheduler + UI + Artisan **échoueront** avec `DB_USERNAME=gestion_app` |
| 2 | **Offsite** : bucket/endpoint + `destination.disks` incluant `s3` | Pas de copie indépendante du serveur |
| 3 | **Alerting réel** (mail/Slack opérationnel) | Échecs silencieux hors log fichier |
| 4 | **Premier backup** de la `gestion` post-9.4 | Aucune archive production récente |
| 5 | **Verify automatique post-`backup:run`** | `backup:verify` existe mais n’est **pas** chaîné au scheduler |
| 6 | **Checksum sidecar** persisté (fichier `.sha256`) | SHA calculé à l’inspection, pas forcément stocké à côté de chaque ZIP |
| 7 | **Immutabilité / Object Lock** offsite | Non analysable tant que bucket absent |
| 8 | Confirmation **cron/schedule:run** OS | Scheduler code OK, exécution OS inconnue |
| 9 | `MYSQL_DUMP_PATH` explicite dans `.env` | Dépend du fallback WAMP hardcodé |
| 10 | Historical ZIP Spatie pouvaient contenir `.env` | Config actuelle exclut ; archives anciennes à traiter avec caution |

---

## 4. Risques

| Risque | Sévérité |
|--------|----------|
| Fausse impression de protection (scheduler planifié mais compte runtime refuse `backup:run`) | **HIGH** |
| Pas d’offsite → perte disque local = perte backups | **HIGH** |
| Alerting désactivé → panne backup non vue | **HIGH** |
| Base métier encore vide → backup actuel = schéma surtout (`SCHEMA_ONLY`) | INFO (attendu) |
| UI `BackupController` lance `backup:run` sous credentials `.env` | **HIGH** sans wiring temporaire |
| Compromission serveur → suppression archives locales si pas d’object lock offsite | MEDIUM/HIGH |

---

## 5. Configuration nécessaire (documentaire — NON appliquée)

### A. Wiring backup (humain)

Sans modifier le runtime HTTP durablement :

- fournir credentials `gestion_backup` via secret local (déjà `.mysql-gestion-backup.local` attendu) ;
- exécuter `backup:run` avec **env process-only** `DB_USERNAME=gestion_backup` + password (même pattern que migrate 9.4) ;
- **ne pas** laisser `gestion_backup` comme `DB_USERNAME` runtime.

### B. Offsite (humain — credentials réels)

Fournir / compléter **sans les coller dans le chat** :

- `AWS_BUCKET` (non vide)
- `AWS_ENDPOINT` (ex. OVH Object Storage)
- région / path-style si besoin
- puis (phase ultérieure, après approbation config) : ajouter `s3` à `backup.destination.disks`

### C. Alerting (humain)

- canal mail réel **ou** Slack webhook ;
- réactiver `via()` des notifications **sans** logger secrets ;
- remplacer `your@example.com`.

### D. Scheduler OS

- Task Scheduler / cron : `* * * * * php artisan schedule:run`

---

## 6. Commandes qui seraient exécutées (phases futures — PAS maintenant)

| Phase | Commande / action | Approbation requise |
|-------|-------------------|---------------------|
| I→J | `backup:run` (idéalement `--only-db` ou full) sous `gestion_backup` | `OUI — RUN FIRST PRODUCTION BACKUP` |
| J | `backup:verify {path}` | inclus après J |
| L | upload offsite (si configuré) | config humaine + vérif |
| M | `CREATE DATABASE gestion_test` | `OUI — CREATE gestion_test FOR RESTORE DRILL` |
| N | restore → `gestion_test` via `gestion_restore` | approbation restore drill séparée |

**Phase A : aucune de ces commandes exécutée.**

---

## 7. Fichiers qui seraient modifiés (phases futures — PAS maintenant)

| Fichier | Changement potentiel | Phase |
|---------|----------------------|-------|
| `.env` | `AWS_BUCKET`, `AWS_ENDPOINT`, éventuellement `MYSQL_DUMP_PATH`, mail | C/G (humain) |
| `config/backup.php` | `destination.disks` += `s3` ; notifications channels | C/G |
| `config/filesystems.php` | déjà disque `s3` présent | C (si endpoint) |
| Docs `docs/preprod-phase-10-*` | rapports | J/L/O |
| Archives sous `storage/app/private/Gestion/` | nouveaux ZIP | J |

**Phase A : seul ce rapport créé.**

---

## 8. Secrets nécessaires (ne jamais les afficher / committer)

| Secret | Usage | Dans Git ? |
|--------|-------|------------|
| Mot de passe `gestion_backup` | mysqldump | NON (fichier `.mysql-*.local` / secret store) |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | offsite | NON |
| `BACKUP_ARCHIVE_PASSWORD` (optionnel) | ZIP encrypt | NON |
| `DB_PASSWORD` runtime `gestion_app` | app | NON |

Présents partiellement dans `.env` local (clés AWS) — **bucket manquant** ⇒ inutilisable pour backup Spatie offsite.

---

## 9. Vérification cleanup vs MySQL

```text
backup:clean
  → Spatie DefaultStrategy
  → delete old ZIP on backup disks only
  → CANNOT DROP DATABASE
  → CANNOT DELETE rows in gestion
  → CANNOT touch gestion_recovery schema/data via MySQL
```

---

## 10. Compatibilité compte backup ↔ dump

Avec `use_single_transaction=false`, Spatie/mysqldump utilise le verrouillage tables :

```text
gestion_backup privileges: SUFFICIENT for current dump flags
INSERT/UPDATE/DELETE/DDL: correctly ABSENT
```

Aucun changement de privilèges proposé en Phase A.

---

## 11. Décisions humaines requises avant suite

### STOP — Phase A complète

Pour continuer PRE-PROD 10, fournir / décider :

1. **Offsite**  
   - Compléter `AWS_BUCKET` + `AWS_ENDPOINT` (ou confirmer « offsite plus tard »)  
   - Ne pas coller les secrets dans le chat

2. **Alerting**  
   - Adresse mail / webhook réel **ou** accepter PARTIAL (logs only)

3. **Premier backup** (Phase I) — uniquement après wiring `gestion_backup` :  
   ```text
   OUI — RUN FIRST PRODUCTION BACKUP
   ```

4. **Restore drill** — plus tard, phrase séparée :  
   ```text
   OUI — CREATE gestion_test FOR RESTORE DRILL
   ```

---

## Baseline fin Phase A

```text
gestion: UNCHANGED (non touchée)
gestion_recovery: UNCHANGED (non touchée)
backup:run: NOT EXECUTED
backup:clean: NOT EXECUTED
restore: NOT EXECUTED
seed: NOT EXECUTED
privileges modified: NO
.env modified: NO
GRANT/REVOKE: NO
```

---

## Verdict Phase A

```text
BACKUP LOCAL CODE: READY WITH CONDITIONS
BACKUP ACCOUNT GRANTS: PASS
BACKUP ACCOUNT WIRING: FAIL (runtime = gestion_app)
OFFSITE: NOT CONFIGURED
ALERTING: FAIL (channels empty)
SCHEDULER CODE: PASS (OS runner UNKNOWN)
FIRST PRODUCTION BACKUP: NOT RUN
RETENTION CONFIG: PASS

NEXT: HUMAN DECISIONS + Phase I approval only when ready
DO NOT RUN backup:run until: OUI — RUN FIRST PRODUCTION BACKUP
```
