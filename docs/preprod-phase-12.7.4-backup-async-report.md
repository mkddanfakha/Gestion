```text
========================================
PRE-PROD 12.7.4 — CRÉATION ASYNC
========================================

STATUS: COMPLETE

CREATE FLOW:
UI POST store
 → BackupCreationService::start
 → CreateBackupJob::dispatch (job_id UUID)
 → progress Cache (queued → running → completed|failed)
 → Artisan::call('backup:production' [--only-db])  [SEUL lock]
 → sidecar meta source=manual
 → ActivityLog BACKUP_CREATED
 → UI poll GET /admin/backups/create-status/{jobId}

LOCK:
PRESERVED — ownership unique dans backup:production
CreateBackupJob / BackupController : PAS de BackupConcurrencyGuard::runBackup

PROGRESS ENDPOINT:
GET admin.backups.create-status/{jobId}
- JSON status / percentage / message
- 403 si job d’un autre user
- 404 si inconnu / expiré

SIDECAR:
Après succès job : type DATABASE|FULL, source=manual, status=valid, sha256

AUDIT:
BACKUP_CREATED via BackupAuditService

UI:
- Bannière progression (barre %, message)
- Polling 2s, timeout ~6 min avec message worker
- Plus de Swal bloquant pendant toute la création

QUEUE NOTE:
QUEUE_CONNECTION=database (.env.example) → nécessite `php artisan queue:work`
avec driver `sync`, le job s’exécute inline dans la requête.
Aucun .env modifié dans cette phase.

TESTS:
PASS — BackupCriticalFixesTest (20)
PASS — BackupStrategyTest + ProductionBackupRunnerTest (26)

BUILD:
PASS — npm run build

SECURITY 12.7.1–12.7.3:
PRESERVED
(PathGuard, ImportService, Inspector, ConcurrencyGuard, meta import)

DATABASE:
UNCHANGED

ENV:
UNCHANGED

SCHEDULER:
UNCHANGED

OVH:
NOT TOUCHED — ARRÊT OBLIGATOIRE ICI

PRODUCTION:
UNTOUCHED

DEPLOYMENT:
NOT EXECUTED

FILES CREATED:
- app/Services/Backup/BackupCreationService.php
- app/Services/Backup/BackupCreationProgress.php
- docs/preprod-phase-12.7.4-backup-async-report.md

FILES MODIFIED:
- app/Jobs/CreateBackupJob.php
- app/Services/Backup/BackupAuditService.php
- app/Http/Controllers/Admin/BackupController.php
- app/Http/Middleware/HandleInertiaRequests.php
- routes/web.php
- resources/js/lib/routes.ts
- resources/js/pages/Admin/Backups/Index.vue
- tests/Unit/Infrastructure/BackupCriticalFixesTest.php

OUT OF SCOPE (non commencé):
- 12.7.5 restore UX avancée
- 12.7.6 manifeste archive natif
- Toute opération OVH / déploiement

========================================
END PRE-PROD 12.7.4
========================================
```
