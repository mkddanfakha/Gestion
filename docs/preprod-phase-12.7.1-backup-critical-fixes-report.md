```text
========================================
PRE-PROD 12.7.1 — BACKUP CRITICAL FIXES
========================================

STATUS: COMPLETE

DOUBLE LOCK:
FIXED
- Ownership unique : BackupConcurrencyGuard dans RunProductionBackupCommand uniquement
- BackupController::store n’appelle plus runBackup() autour de Artisan::call
- CreateBackupJob n’acquiert plus le lock (appelle seulement backup:production)
- Tests : acquisition unique, concurrence bloquée, libération succès/échec

PATH TRAVERSAL:
FIXED
- Nouveau BackupPathGuard : sanitize, basename regex .zip, rejet ../ / absolus / drives
- resolveExistingBackupPath() : realpath + containment dans le répertoire backups
- download / destroy / restore / restore-files passent par BackupPathGuard
- basename() seul n’est pas la seule défense (containment réel)

IMPORT SECURITY:
FIXED
- Nouveau BackupImportService :
  upload → quarantine (storage/app/backup-quarantine)
  → ZipArchive safety (traversal, absolute, symlink mode)
  → BackupArchiveInspector
  → rejet INVALID_NO_SQL / INVALID_TOO_SMALL
  → promote vers dossier backups
- IMPORT ≠ RESTORE (aucune restauration déclenchée)
- Quarantaine nettoyée en finally

BACKUP CREATION:
FIXED
- sleep(2) supprimé
- Chaîne UI conservée : Artisan::call('backup:production' [--only-db])
- CreateBackupJob aligné (pas de double lock) ; non dispatché par l’UI (12.7.4 plus tard)
- Logs structurés backup.ui.create.*

FILES MODIFIED:
- app/Http/Controllers/Admin/BackupController.php (réécriture ciblée)
- app/Jobs/CreateBackupJob.php

FILES CREATED:
- app/Services/Backup/BackupPathGuard.php
- app/Services/Backup/BackupImportService.php
- tests/Unit/Infrastructure/BackupCriticalFixesTest.php
- docs/preprod-phase-12.7.1-backup-critical-fixes-report.md

TESTS:
PASS — BackupCriticalFixesTest (16 tests)
PASS — BackupStrategyTest + ProductionBackupRunnerTest (26 tests, non-régression)

BUILD:
PASS (npm run build, exit code 0)

DATABASE:
UNCHANGED

ENV:
UNCHANGED

SCHEDULER:
UNCHANGED (backup:production 02:00 / clean / monitor)

OVH:
UNCHANGED

PRODUCTION:
UNTOUCHED

DEPLOYMENT:
NOT EXECUTED

NEXT PHASE:
12.7.2 (UX) — non commencée ; attendre validation

========================================
END PRE-PROD 12.7.1
========================================
```
