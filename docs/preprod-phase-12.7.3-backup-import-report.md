```text
========================================
PRE-PROD 12.7.3 — IMPORT PROFESSIONNEL
========================================

STATUS: COMPLETE

IMPORT PIPELINE (12.7.1 PRESERVED + EXTENDED):
- Quarantaine → ZipArchive safety → BackupArchiveInspector → promote
- IMPORT ≠ RESTORE (aucune restauration automatique)
- BackupPathGuard inchangé pour chemins ZIP

SIDECAR METADATA (v1):
- Nouveau BackupMetadataService
- Chemin : {backupDir}/meta/{zipBasename}.json
- Contenu : backup_id, application MKD-Pro, format_version=1,
  created_at, type DATABASE|FULL, source import|manual|scheduler,
  user_id, sha256, size_bytes, status, original_filename, verdict
- Écrit à l’import réussi
- Lu au listing (origine / statut Importée)
- Supprimé avec la sauvegarde (destroy)

AUDIT:
- Nouveau BackupAuditService
- BACKUP_IMPORTED (succès, métadonnées non secrètes)
- BACKUP_IMPORT_REJECTED (refus, raison tronquée)
- Échec d’audit non bloquant pour l’import

UI:
- Colonne / libellé Origine (Importée si meta.source=import)
- Statut « Importée » lorsque meta.status=imported
- Aperçu post-import (fichier, type, taille, SHA-256, rappel non-restore)
- flash.import_preview partagé via HandleInertiaRequests

RESTORE / SAFETY:
- Allow-list DB inchangée
- Restauration toujours confirmée (phrase RESTORE)
- PathGuard / ConcurrencyGuard / Inspector préservés
- Chaîne backup:production inchangée

TESTS:
PASS — BackupCriticalFixesTest (18 tests, +2 meta/import FULL)
PASS — BackupStrategyTest + ProductionBackupRunnerTest (26)

BUILD:
PASS — npm run build

SECURITY 12.7.1:
PRESERVED

DATABASE:
UNCHANGED (pas de migration ; sidecar fichiers)

ENV:
UNCHANGED

SCHEDULER:
UNCHANGED

OVH:
UNCHANGED

PRODUCTION:
UNTOUCHED

DEPLOYMENT:
NOT EXECUTED

FILES CREATED:
- app/Services/Backup/BackupMetadataService.php
- app/Services/Backup/BackupAuditService.php
- docs/preprod-phase-12.7.3-backup-import-report.md

FILES MODIFIED:
- app/Services/Backup/BackupImportService.php
- app/Http/Controllers/Admin/BackupController.php
- app/Http/Middleware/HandleInertiaRequests.php
- resources/js/pages/Admin/Backups/Index.vue
- tests/Unit/Infrastructure/BackupCriticalFixesTest.php

OUT OF SCOPE (non commencé):
- 12.7.4 async CreateBackupJob
- 12.7.5 restore UX avancée
- 12.7.6 manifeste natif dans l’archive
- Sidecar automatique pour créations manual/scheduler (réservé phases suivantes)

========================================
END PRE-PROD 12.7.3
========================================
```
