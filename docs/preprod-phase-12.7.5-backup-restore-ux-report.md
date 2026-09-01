```text
========================================
PRE-PROD 12.7.5 — RESTORE UX
========================================

STATUS: COMPLETE

RESTORE UX:
- Modale en 2 étapes (double confirmation)
  1) Aperçu inspection (Inspector) + Continuer
  2) Cible allow-list + case « Je comprends… »
     + sauvegarde de sécurité optionnelle
     + phrase RESTORE + bouton Restaurer
- Jamais de restore au clic sur une ligne
- Mode DB-only uniquement ; fichiers non touchés

INSPECT ENDPOINT:
GET /admin/backups/{backup}/inspect
- BackupPathGuard + BackupArchiveInspector
- JSON sans contenu SQL (sha256, verdict, sql_present, can_restore, …)
- Aucune restauration effectuée

SAFETY BACKUP (optionnel):
- Case cochée par défaut
- Avant restore : Artisan::call('backup:production', --only-db)
- Échec safety → restauration annulée
- Sidecar meta source=manual si succès
- Cible restore reste allow-list (jamais gestion)

BACKEND RESTORE:
- DatabaseRestoreService inchangé dans ses garde-fous
- Validation ajoutée : acknowledge_overwrite required
- Audit BACKUP_RESTORED / BACKUP_RESTORE_FAILED
- Messages d’erreur métier FR

TESTS:
PASS — BackupCriticalFixesTest (+ inspect / restore guards)
PASS — DatabaseRestoreArchitectureTest
PASS — RestoreSafetyTest
PASS — BackupStrategyTest + ProductionBackupRunnerTest
Total run pertinent : 51 + 26 PASS

BUILD:
PASS — npm run build

SECURITY / ALLOW-LIST:
PRESERVED
- gestion protégée
- gestion_recovery / gestion_test only
- phrase RESTORE
- PathGuard / ConcurrencyGuard / Inspector

DATABASE:
UNCHANGED

ENV:
UNCHANGED

SCHEDULER:
UNCHANGED

OVH:
UNTOUCHED

PRODUCTION:
UNTOUCHED

DEPLOYMENT:
NOT EXECUTED

FILES MODIFIED:
- app/Http/Controllers/Admin/BackupController.php
- app/Services/Backup/BackupAuditService.php
- resources/js/pages/Admin/Backups/Index.vue
- resources/js/lib/routes.ts
- routes/web.php
- tests/Unit/Infrastructure/BackupCriticalFixesTest.php
- docs/preprod-phase-12.7.5-backup-restore-ux-report.md

OUT OF SCOPE:
- 12.7.6 manifeste natif
- Restore fichiers live
- OVH / déploiement

========================================
END PRE-PROD 12.7.5
========================================
```
