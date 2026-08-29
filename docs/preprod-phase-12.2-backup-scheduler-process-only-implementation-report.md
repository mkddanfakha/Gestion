# PRE-PROD 12.2 — Backup Scheduler Process-Only Implementation Report

**Date :** 2026-08-27  
**Mode :** **IMPLEMENTATION LOCAL** — aucun déploiement OVH, aucun backup réel, aucune modification MySQL

---

```text
========================================
MKD-PRO PRE-PROD 12.2
BACKUP SCHEDULER PROCESS-ONLY IMPLEMENTATION
========================================

IMPLEMENTATION: YES
AUDIT REFERENCE: docs/preprod-phase-12.2-backup-scheduler-process-only-audit.md

DATABASE MODIFIED: NO
GESTION MODIFIED: NO
GESTION_RECOVERY MODIFIED: NO
GESTION_TEST MODIFIED: NO

ENV MODIFIED: NO (permanent .env unchanged)
GRANTS MODIFIED: NO
BACKUP EXECUTED: NO
RESTORE EXECUTED: NO
MIGRATE EXECUTED: NO
SEED EXECUTED: NO

OVH DEPLOYED: NO
OVH CRON CONFIGURED: NO
PRODUCTION BACKUP RUN: NO
```

---

## 1. Fichiers créés

| Fichier | Rôle |
|---------|------|
| `app/Database/PrivilegedCredentialLoader.php` | Lecture sécurisée `.mysql-gestion-backup.local` |
| `app/Database/PrivilegedProcessRunner.php` | Subprocess Symfony Process, env-only secrets |
| `app/Database/PrivilegedProcessResult.php` | Résultat sanitizé (stdout/stderr redacted) |
| `app/Console/Commands/RunProductionBackupCommand.php` | `backup:production` |
| `tests/Unit/Infrastructure/ProductionBackupRunnerTest.php` | Tests process-only / scheduler / guards |
| `docs/preprod-phase-12.2-backup-scheduler-process-only-implementation-report.md` | Ce rapport |

---

## 2. Fichiers modifiés

| Fichier | Modification |
|---------|--------------|
| `routes/console.php` | `backup:run` → `backup:production` à 02:00 |
| `app/Providers/AppServiceProvider.php` | `registerRuntimeAccountGuard()` au boot |
| `app/Database/DatabaseAccountGuard.php` | `isPrivilegedSubprocess()` |
| `app/Http/Controllers/Admin/BackupController.php` | UI → `Artisan::call('backup:production')` |
| `app/Jobs/CreateBackupJob.php` | Queue → `Artisan::call('backup:production')` |

**Non modifiés (volontairement) :** `.env`, MySQL, `PrivilegedCommandGuard`, `config/backup.php`, credentials R2, cron OVH.

---

## 3. Architecture finale

```text
OVH cron (futur)
    → schedule:run (gestion_app, .env runtime)
    → Laravel Scheduler
    → backup:production @ 02:00
        → PrivilegedCredentialLoader (.mysql-gestion-backup.local)
        → PrivilegedProcessRunner
            → subprocess env:
                MKDPRO_PRIVILEGED_SUBPROCESS=backup
                DB_USERNAME=gestion_backup
                DB_PASSWORD=<secret via env only>
                DB_DATABASE=gestion
                CACHE_STORE=file
                BACKUP_LOCK_CACHE_STORE=file
                + héritage AWS_* / BACKUP_DISKS depuis parent
            → php artisan backup:run
                → PrivilegedCommandGuard OK
                → Spatie backup → local + s3 (R2)

Parallèle inchangé:
Web/HTTP → gestion_app → gestion
```

---

## 4. Comptes et séparation

| Contexte | Compte MySQL | Confirmé |
|----------|--------------|----------|
| Runtime Laravel (HTTP, schedule parent, queue parent) | `gestion_app` | OUI (`db:safety-check`) |
| Subprocess backup | `gestion_backup` | OUI (env process-only) |
| `gestion_backup` comme runtime permanent | **INTERDIT** | Guard boot + policy |

**Marqueur subprocess :** `MKDPRO_PRIVILEGED_SUBPROCESS=backup` — skip `assertRuntimeUsernameAllowed()` ; `PrivilegedCommandGuard` reste actif.

---

## 5. Gestion du secret

| Exigence | Statut |
|----------|--------|
| Fichier `.mysql-gestion-backup.local` gitignored | PASS |
| Mot de passe hors argv CLI | PASS (assertion + tests) |
| Mot de passe hors logs/exceptions | PASS (`sanitizeForLog`, messages génériques) |
| `.env` permanent inchangé | PASS |
| Aucun secret dans Git | PASS |

---

## 6. Cache / lock

| Composant | Store subprocess |
|-----------|------------------|
| `CACHE_STORE` | `file` (forcé subprocess) |
| `BACKUP_LOCK_CACHE_STORE` | `file` (forcé subprocess) |
| Mutex scheduler `withoutOverlapping` | cache runtime parent (`gestion_app`) — inchangé |

---

## 7. Scheduler

Vérification `php artisan schedule:list` :

```text
0 2 * * *  php artisan backup:production
0 3 * * *  php artisan backup:clean
0 4 * * *  php artisan backup:monitor
```

Horaires et TTL `withoutOverlapping` conservés (180 / 120 / 60 min).

---

## 8. Tests exécutés

### Infrastructure (Pest)

```text
./vendor/bin/pest tests/Unit/Infrastructure
Tests: 124 passed (310 assertions)
Duration: ~44s
```

Inclut **13 nouveaux tests** `ProductionBackupRunnerTest.php` :

- credential loader (parse, missing file, wrong user)
- env vs argv (password not in command line)
- sanitizeForLog
- subprocess marker
- `backup:run` refusé pour `gestion_app`
- `backup:production` avec runner simulé (pas de backup réel)
- échec subprocess sans fuite secret
- scheduler → `backup:production` (pas `backup:run` direct)
- guards runtime (backup/restore/migration/root refusés)

### Suite complète

La suite Pest complète échoue sur une **erreur préexistante** (`createTestProduct()` redéclarée entre Feature tests) — hors scope 12.2.

### Build

Non exécuté (changements backend PHP uniquement, pas de assets frontend).

---

## 9. État MySQL avant / après

| Base | Avant | Après | Modifié |
|------|-------|-------|---------|
| `gestion` | 38 tables, 75 migrations | 38 tables, 75 migrations | **NON** |
| `gestion_recovery` | drill nov 2025 (audit) | 0 tables (information_schema — base absente ou vide localement) | **NON** |
| `gestion_test` | absente | absente | **NON** |

Runtime vérifié : `DB_USERNAME=gestion_app`, `Uses root: NO`.

Aucune migration, seed, restore, backup réel exécuté pendant cette phase.

---

## 10. Confirmations opérationnelles

| Item | Statut |
|------|--------|
| Backup production réel | **NON EXÉCUTÉ** |
| `backup:run` direct en production | **NON EXÉCUTÉ** |
| Modification `.env` permanent | **NON** |
| Déploiement OVH | **NON** |
| Cron OVH | **NON** |
| `BACKUP_DISKS=local,s3` | Inchangé (config existante) |
| `AWS_USE_PATH_STYLE_ENDPOINT` | Inchangé (config existante) |
| SSL verify=false | **JAMAIS** introduit |

---

## 11. Prochaines étapes (approbation humaine requise)

1. **Déploiement du code + secret sur OVH**
   - Copier `.mysql-gestion-backup.local` (mode 600, hors Git)
   - Vérifier `php`, chemins projet, permissions `storage/`

2. **Configuration cron OVH**
   ```cron
   * * * * * cd /chemin/projet && /usr/bin/php artisan schedule:run >> storage/logs/scheduler.log 2>&1
   ```

3. **Test réel scheduler/backup sur OVH** (fenêtre contrôlée)
   - `php artisan backup:production` manuel avec approbation
   - Vérifier ZIP local + R2 + `backup:status`

Approbations suggérées :

```text
OUI — DEPLOY BACKUP PRODUCTION WRAPPER ON OVH
OUI — CONFIGURE OVH CRON schedule:run
OUI — RUN FIRST SCHEDULED BACKUP ON OVH
```

---

```text
STATUS: IMPLEMENTATION COMPLETE — WAITING FOR HUMAN APPROVAL
```
