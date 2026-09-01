```text
========================================
PRE-PROD 12.7 — BACKUP SYSTEM AUDIT
========================================

STATUS: AUDIT ONLY

DATE: 2026-08-30
SCOPE: Module UI « Sauvegardes » + chaîne Spatie / restore / scheduler
MODE: Lecture seule — aucun code, DB, .env, OVH, scheduler, backup ou restore modifié

----------------------------------------
1. CURRENT ARCHITECTURE
----------------------------------------

PAGE
  resources/js/pages/Admin/Backups/Index.vue
  Accès menu : BootstrapLayout (admin + canView('backups'))

ROUTE (middleware auth + verified + EnsureUserIsAdmin)
  GET    /admin/backups                         → index
  POST   /admin/backups                         → store (création)
  DELETE /admin/backups/{backup}                → destroy
  GET    /admin/backups/{backup}/download       → download
  POST   /admin/backups/{backup}/restore        → restore (DB-only)
  POST   /admin/backups/{backup}/restore-files  → restoreApplicationFiles (dry-run)
  POST   /admin/backups/import                  → import

CONTROLLER
  App\Http\Controllers\Admin\BackupController
  (~795 lignes) — mélange listing, création, import ZIP, download,
  destroy, restore DB, restore files, legacy méthodes retired

BACKUP ENGINE (création)
  store() → BackupConcurrencyGuard::runBackup
         → Artisan::call('backup:production' [--only-db])
         → RunProductionBackupCommand
         → BackupConcurrencyGuard::runBackup  (2e lock — voir bugs)
         → PrivilegedProcessRunner::runBackupRun
         → sous-processus : php artisan backup:run (credentials gestion_backup)
         → Spatie laravel-backup (ZIP)

JOB (mort)
  App\Jobs\CreateBackupJob — importé dans BackupController mais JAMAIS dispatché
  Progress Cache prévue mais non branchée à l’UI

RESTORE DB
  DatabaseRestoreService
    → phrase RESTORE (DatabaseSafetyGuard)
    → cible allow-list (gestion_recovery, gestion_test — JAMAIS gestion)
    → BackupArchiveInspector (sha256, présence SQL)
    → extract SQL only
    → SqlDumpImporter
    → RestoreAuditLogger (logs Laravel)

RESTORE FILES
  ApplicationFilesRestoreService
    → phrase FILES_RESTORE
    → dry-run forcé côté UI/controller
    → live restore désactivé en PRE-PROD

STORAGE
  Disk Spatie : BACKUP_DISKS (défaut « local »)
  local root = storage/app/private
  Dossier = config('backup.backup.name') = APP_NAME
  Option S3/R2 via disk « s3 » (credentials .env) — non modifié ici

SCHEDULER (NE PAS TOUCHER — inventaire seulement)
  02:00  backup:production
  03:00  backup:clean
  04:00  backup:monitor

CARTES DE FLUX EXISTANTES

Création manuelle :
  UI → POST store → Guard lock → Artisan backup:production
    → Guard lock (nested) → subprocess backup:run → ZIP → redirect flash

Import :
  UI FormData → POST import → ZipArchive check basique
    → copie sous storage/app/private/{APP_NAME}/{originalFilename}
    → redirect flash (pas de restauration)

Restore DB :
  UI Swal (cible + RESTORE) → POST restore
    → DatabaseRestoreService → import SQL → allow-list DB

----------------------------------------
2. CURRENT FEATURES
----------------------------------------

IMPLÉMENTÉ (partiel) :
  [x] Liste des ZIP locaux (filesystem DirectoryIterator)
  [x] Création « complète » (bouton principal)
  [x] Création « DB uniquement » (only_db=true)
  [x] Import ZIP (validation légère)
  [x] Download authentifié
  [x] Suppression avec confirmation Swal
  [x] Restore DB-only vers bases allow-listées + phrase RESTORE
  [x] Route restore-files (dry-run only)
  [x] Scheduler backup:production / clean / monitor
  [x] Process-only credentials (gestion_backup)
  [x] Concurrency locks file (backup / restore séparés)
  [x] Inspection archive (BackupArchiveInspector) — utilisée au restore, pas à l’import

NON IMPÉMENTÉ / INCOMPLET :
  [ ] Manifeste MKD-Pro (backup_id, version, type, checksums)
  [ ] Distinction claire type DATABASE vs FULL dans la liste
  [ ] Statuts (EN COURS / VALIDE / ÉCHEC / CORROMPUE / IMPORTÉE)
  [ ] Utilisateur ayant lancé l’opération
  [ ] Durée / progression réelle côté UI
  [ ] Quarantaine import + validation approfondie
  [ ] Backup de sécurité automatique avant restore
  [ ] Journal ActivityLog métier (BACKUP_CREATED / IMPORTED / …)
  [ ] Permissions granulaires pour gestionnaire (route réservée admin)
  [ ] Job async réellement utilisé
  [ ] Restore fichiers live (volontairement bloqué PRE-PROD)

----------------------------------------
3. CURRENT BUGS
----------------------------------------

BUG ID: BK-01
ACTION: Créer une sauvegarde depuis l’UI (store)
EXPECTED: backup:production s’exécute sous un seul lock
ACTUAL: BackupController::store appelle BackupConcurrencyGuard::runBackup,
        puis RunProductionBackupCommand appelle à nouveau runBackup
        → Cache::add échoue (« already in progress »)
FRONTEND: Index.vue createBackup / createBackupDbOnly
BACKEND: BackupController::store + RunProductionBackupCommand::handle
ROOT CAUSE: Double acquisition du même lock (non-réentrant)
SEVERITY: CRITICAL

BUG ID: BK-02
ACTION: Afficher succès après création / import
EXPECTED: SweetAlert succès visible
ACTUAL: onSuccess affiche Swal succès, puis onFinish appelle Swal.close()
FRONTEND: Index.vue (createBackup, createBackupDbOnly, showImportModal)
BACKEND: N/A
ROOT CAUSE: Cycle de vie Inertia onFinish ferme le dialogue de succès
SEVERITY: HIGH / UX

BUG ID: BK-03
ACTION: Download / destroy avec nom de fichier
EXPECTED: Accès limité au dossier de backups
ACTUAL: $backupName concaténé sans basename() / normalisation stricte
FRONTEND: route param
BACKEND: BackupController::download / destroy
ROOT CAUSE: Path traversal potentiel (../ hors dossier)
SEVERITY: CRITICAL / SECURITY
NOTE: DatabaseRestoreService::resolveBackupPath utilise basename() — correct

BUG ID: BK-04
ACTION: Import d’une sauvegarde
EXPECTED: Quarantaine, hash, type, métadonnées, nom unique
ACTUAL: Copie sous le nom client original ; validation Zip minimale ;
        BackupArchiveInspector non utilisé ; doublon = refus brut
FRONTEND: showImportModal
BACKEND: BackupController::import (~280 lignes monolithiques)
ROOT CAUSE: Import = copie fichier, pas pipeline validation
SEVERITY: HIGH / SECURITY / ARCHITECTURE

BUG ID: BK-05
ACTION: Liste des sauvegardes
EXPECTED: type, statut, user, durée, id
ACTUAL: name, path, size (string), date, timestamp seulement
FRONTEND: table Index.vue
BACKEND: getBackupsFromFilesystem
ROOT CAUSE: Pas de modèle/métadonnées
SEVERITY: MEDIUM / UX / ARCHITECTURE

BUG ID: BK-06
ACTION: Création longue
EXPECTED: Job async + progression
ACTUAL: Artisan::call synchrone dans la requête HTTP + sleep(2)
FRONTEND: Swal loading figé
BACKEND: store() ; CreateBackupJob mort
ROOT CAUSE: Timeout PHP/proxy + job non branché
SEVERITY: HIGH

BUG ID: BK-07
ACTION: Permissions restore_files
EXPECTED: enum PermissionName + UI
ACTUAL: Catalog a backups.restore_files ; enum PermissionName n’a pas
        de case BackupsRestoreFiles ; UI n’expose pas restore-files
FRONTEND: canRestore('backups') seulement
BACKEND: checkPermission(..., 'restore_files')
ROOT CAUSE: Catalogue / enum / UI désalignés
SEVERITY: MEDIUM / ARCHITECTURE

BUG ID: BK-08
ACTION: Logs métier ActivityLog
EXPECTED: BACKUP_CREATED / IMPORTED / RESTORED / DELETED
ACTUAL: \Log::info verbeux dans controller ; RestoreAuditLogger pour restore
ROOT CAUSE: Pas d’intégration ActivityLogger pour backups UI
SEVERITY: MEDIUM

BUG ID: BK-09
ACTION: createBackup onSuccess sans vérifier flash.error
        (createBackupDbOnly)
EXPECTED: Afficher erreur serveur
ACTUAL: Affiche toujours « succès » puis reload
FRONTEND: createBackupDbOnly
SEVERITY: MEDIUM / UX

BUG ID: BK-10
ACTION: Import — détection contenu
EXPECTED: Refuser archives malveillantes / path traversal internes
ACTUAL: Boucle ZipArchive : présence db-dumps/*.sql ou « fichiers » ;
        pas de rejet ../, chemins absolus, symlinks
SEVERITY: HIGH / SECURITY

BUG ID: BK-11
ACTION: Texte d’aide page
EXPECTED: Distinguer DB vs FULL ; restore ≠ gestion métier
ACTUAL: « Les sauvegardes incluent la base et les fichiers » (toujours) ;
        warning restore générique peu précis vs réalité allow-list
SEVERITY: LOW / UX

----------------------------------------
4. SECURITY ISSUES
----------------------------------------

S-01 Path traversal download/destroy (BK-03) — CRITICAL
S-02 Import ZIP sans quarantaine ni inspection path entries (BK-04, BK-10) — HIGH
S-03 Limite 10 Go côté UI/controller, non alignée forcément php.ini upload — MEDIUM
S-04 Logs controller peuvent inclure chemins filesystem verboses — LOW
S-05 Restore vers gestion : PROTÉGÉ (DatabaseSafetyGuard allow-list) — PASS
S-06 File restore live : BLOQUÉ PRE-PROD — PASS (volontaire)
S-07 Credentials backup : process-only (PrivilegedProcessRunner) — PASS (12.2)
S-08 Route admin middleware EnsureUserIsAdmin — PASS (surface limitée)

----------------------------------------
5. ARCHITECTURE ISSUES
----------------------------------------

A-01 God controller BackupController (création + import + listing + restore)
A-02 Import logique inline vs BackupArchiveInspector non réutilisé
A-03 CreateBackupJob orphelin (double source de vérité création)
A-04 Pas de couche BackupMetadata / modèle Eloquent
A-05 Pas de format manifeste MKD-Pro
A-06 Double lock création manuelle (BK-01)
A-07 Types DATABASE/FULL non formalisés dans le domaine
A-08 Statuts non formalisés
A-09 Activity audit incomplete
A-10 Responsabilités Spatie (ZIP) vs UI métier non découplées proprement

À CONSERVER (ne pas casser) :
  - backup:production + PrivilegedProcessRunner + gestion_backup
  - Scheduler 02:00 / clean / monitor
  - DatabaseRestoreService + allow-list + phrase RESTORE
  - ApplicationFilesRestoreService dry-run / FILES_RESTORE
  - BackupConcurrencyGuard (file store)
  - BackupArchiveInspector
  - DatabaseSafetyGuard protections gestion

À REFACTORISER :
  - BackupController (découper services)
  - Index.vue (UX professionnelle + flux clairs)
  - Import pipeline
  - Métadonnées / statut / type
  - Suppression du double lock
  - Job async réel pour création UI

----------------------------------------
6. UX ISSUES
----------------------------------------

U-01 Pas de carte « Créer » avec radio DB / FULL — boutons dispersés header
U-02 Pas de colonne Type / Statut / Auteur
U-03 Import via Swal file input — fragile, peu accessible mobile
U-04 Progression factice (Swal loading) sans étapes réelles
U-05 Succès flash écrasé par Swal.close (BK-02)
U-06 Actions icônes seules sans menu ⋮ ni labels
U-07 Pas de dark-mode spécifique (dépend BootstrapLayout) — OK basique
U-08 Restore UI claire (cible + RESTORE) — point positif à conserver/améliorer
U-09 Pas d’aperçu post-import (taille, hash, type détecté)

----------------------------------------
7. PROPOSED ARCHITECTURE
----------------------------------------

Séparation claire (noms adaptés au projet existant) :

  BackupListingService          — inventaire ZIP + métadonnées
  BackupCreationService         — orchestre création manuelle (appelle backup:production
                                  SANS double lock ; option only-db)
  BackupImportService           — quarantaine → validate → promote
  BackupValidationService       — wrap/étend BackupArchiveInspector + manifeste
  BackupStorageService          — paths sécurisés (basename), disks local/s3
  DatabaseRestoreService        — CONSERVER (déjà correct)
  ApplicationFilesRestoreService — CONSERVER (déjà correct)
  BackupAuditService            — ActivityLogger + RestoreAuditLogger

Controller mince :
  BackupController → Form Requests → Services → Inertia props

Création async recommandée :
  UI POST → Job CreateBackupJob (une seule acquisition lock dans le job
  OU uniquement dans backup:production — pas les deux)
  → polling status endpoint (Cache progress déjà prévu dans le Job)

NE PAS créer toutes les classes d’un coup : plan par phases (voir § plan).

----------------------------------------
8. BACKUP FORMAT
----------------------------------------

FORMAT ACTUEL (Spatie) :
  ZIP {
    db-dumps/*.sql
    [fichiers app relatifs si FULL]
  }
  Nom fichier : timestamp Spatie
  Chiffrement optionnel BACKUP_ARCHIVE_PASSWORD
  Pas de manifeste applicatif

FORMAT CIBLE PROPOSÉ (compatible Spatie, évolutif) :

  Option A (minimal, recommandée court terme) :
    Conserver ZIP Spatie
    + sidecar JSON {APP_NAME}/meta/{zipBasename}.json
      {
        "backup_id": "uuid",
        "application": "MKD-Pro",
        "application_version": "...",
        "backup_format_version": 1,
        "created_at": "ISO8601",
        "type": "DATABASE|FULL",
        "source": "manual|scheduler|import",
        "user_id": null|int,
        "sha256": "...",
        "size_bytes": N,
        "status": "valid|failed|imported|corrupted|incompatible"
      }

  Option B (moyen terme) :
    Archive MKD contenant :
      manifest.json
      database.sql[.gz]
      files.tar.gz (si FULL)
    + capacité d’importer aussi les ZIP Spatie legacy

Compatibilité : les ZIP Spatie existants restent restaurables DB-only.

----------------------------------------
9. IMPORT FLOW
----------------------------------------

CIBLE :
  1. Upload → storage/app/backup-quarantine/{uuid}.zip
  2. Validation extension/MIME/taille
  3. ZipArchive open + reject path traversal / absolute paths
  4. BackupArchiveInspector (+ manifeste si présent)
  5. Calcul sha256
  6. Promote → private/{APP_NAME}/{safeName}.zip + meta JSON
  7. Status = IMPORTED / VALID
  8. Audit BACKUP_IMPORTED
  9. JAMAIS restore automatique

----------------------------------------
10. RESTORE FLOW
----------------------------------------

CIBLE (DB-only d’abord — aligné PRE-PROD actuel) :
  1. Sélection backup
  2. Validation intégrité (inspector)
  3. Compatibilité version (si manifeste)
  4. Avertissement UI
  5. Confirmation phrase RESTORE
  6. Cible allow-list uniquement
  7. (Optionnel PRE-PROD+) safety backup de l’état cible si non vide
  8. DatabaseRestoreService
  9. Vérification post (tables métier basiques)
  10. Audit success/failure

FILES restore : rester séparé, dry-run jusqu’à décision explicite post PRE-PROD.

IMPORTANT : restauration UI ne doit JAMAIS cibler `gestion` tant que la
politique safety actuelle s’applique.

----------------------------------------
11. ROLE MATRIX
----------------------------------------

Contexte actuel :
  Routes sous EnsureUserIsAdmin → seuls les admins atteignent la page.
  Permissions backups.* existent ; admin = bypass AuthorizationService.
  RolePresets gestionnaire/vendeur : AUCUNE permission backups.

PROPOSITION :

  Action          | Admin | Gestionnaire | Vendeur
  ----------------+-------+--------------+--------
  Voir liste      |  OUI  | NON*         | NON
  Créer DB/FULL   |  OUI  | NON*         | NON
  Importer        |  OUI  | NON          | NON
  Télécharger     |  OUI  | NON*         | NON
  Supprimer       |  OUI  | NON          | NON
  Restore DB      |  OUI  | NON          | NON
  Restore files   |  OUI  | NON          | NON

  * Option future : lecture seule / download pour gestionnaire si besoin métier.
    Ne pas ouvrir restore hors admin.

----------------------------------------
12. AUDIT LOG
----------------------------------------

Événements cibles (ActivityLog + logs techniques) :
  BACKUP_CREATED
  BACKUP_CREATE_FAILED
  BACKUP_IMPORTED
  BACKUP_IMPORT_REJECTED
  BACKUP_DOWNLOADED
  BACKUP_DELETED
  BACKUP_RESTORED          (déjà partiel via restore.audit)
  BACKUP_RESTORE_FAILED

Champs : user_id, backup_id/name, type, sha256, result, message
Interdit : passwords, secrets, contenu dump

----------------------------------------
13. STORAGE
----------------------------------------

ACTUEL :
  local → storage/app/private/{APP_NAME}/*.zip
  BACKUP_DISKS peut inclure s3 (R2/OVH Object) — config déjà prévue
  Cleanup Spatie DefaultStrategy (30j / quotas)

RECOMMANDATION :
  - Conserver local comme source de vérité UI locale
  - Offsite (R2) = copie scheduler existante — ne pas mélanger dans l’UI
    sans statut de sync explicite
  - Quarantaine séparée pour imports
  - Ne pas modifier OVH/R2 dans les phases d’implémentation UI
    sans validation humaine

----------------------------------------
14. AUTOMATIC BACKUP
----------------------------------------

EXISTE :
  Schedule backup:production @ 02:00
  backup:clean @ 03:00
  backup:monitor @ 04:00

RÈGLE :
  Ne pas modifier le scheduler dans l’implémentation UI.
  L’UI doit lister les ZIP produits automatiquement comme les manuels
  (différencier via meta.source = scheduler quand métadonnées disponibles ;
   heuristique temporaire : absence de meta = unknown/scheduler).

----------------------------------------
15. RECOMMENDED IMPLEMENTATION PLAN
----------------------------------------

PHASE 12.7.1 — Stabilisation critique (sans changer le produit)
  - Corriger double lock (BK-01)
  - basename() download/destroy (BK-03)
  - Fix Swal onFinish vs onSuccess (BK-02)
  - Retirer sleep(2) ; logs controller excessifs

PHASE 12.7.2 — UX page Sauvegardes
  - Refonte Index.vue (carte création DB/FULL, table colonnes, actions)
  - Types DATABASE / FULL affichés
  - Messages restore alignés allow-list

PHASE 12.7.3 — Import professionnel
  - BackupImportService + quarantaine + Inspector + path traversal
  - Métadonnées sidecar
  - Audit IMPORTED

PHASE 12.7.4 — Création async
  - Brancher CreateBackupJob correctement (un seul lock)
  - Endpoint status / progress
  - ActivityLog CREATED

PHASE 12.7.5 — Restore UX renforcée
  - Double confirmation + aperçu inspection
  - Safety backup optionnel vers recovery
  - Ne pas toucher scheduler / OVH

PHASE 12.7.6 — Manifeste / compatibilité version (si besoin)
  - Format v1 sidecar puis éventuel format archive natif

----------------------------------------
16. FILES TO MODIFY (implémentation future — PAS maintenant)
----------------------------------------

  app/Http/Controllers/Admin/BackupController.php
  resources/js/pages/Admin/Backups/Index.vue
  app/Jobs/CreateBackupJob.php
  app/Console/Commands/RunProductionBackupCommand.php (coordination lock)
  app/Enums/PermissionName.php (restore_files si exposé)
  app/Services/ActivityLogger.php (événements backup)
  tests/Unit/Infrastructure/*Backup*

----------------------------------------
17. FILES TO CREATE (implémentation future — PAS maintenant)
----------------------------------------

  app/Services/Backup/BackupCreationService.php
  app/Services/Backup/BackupImportService.php
  app/Services/Backup/BackupListingService.php
  app/Services/Backup/BackupStorageService.php
  app/Services/Backup/BackupValidationService.php (ou étendre Inspector)
  app/Http/Requests/Admin/Backup/*Request.php
  resources/js/components/backups/* (optionnel)
  docs/preprod-phase-12.7.*-implementation-report.md

----------------------------------------
18. DATABASE CHANGES
----------------------------------------

COURT TERME : aucune migration obligatoire (sidecar JSON fichiers).
OPTIONNEL : table backup_records (id, filename, type, status, sha256,
            user_id, source, created_at) — décision à valider.

----------------------------------------
19. ENV CHANGES
----------------------------------------

AUDIT : aucune.
FUTUR éventuel : BACKUP_IMPORT_MAX_MB, BACKUP_QUARANTINE_PATH —
  à valider explicitement ; ne pas toucher secrets / OVH / R2 sans accord.

----------------------------------------
OVH:
NO

PRODUCTION:
NOT TOUCHED

DEPLOYMENT:
NOT EXECUTED

CODE MODIFIED:
NO

DATABASE MODIFIED:
NO

ENV MODIFIED:
NO

SCHEDULER MODIFIED:
NO

BACKUP EXECUTED:
NO

RESTORE EXECUTED:
NO

----------------------------------------
SYNTHÈSE POUR VALIDATION HUMAINE
----------------------------------------

1. EXISTANT
   Spatie + backup:production process-only + UI admin monolithique +
   restore DB allow-list solide + file restore dry-run.

2. BUGS MAJEURS
   Double lock UI (création cassée), path traversal download/delete,
   Swal succès fermé, import trop naïf, job mort, métadonnées absentes.

3. RISQUES
   Sécurité import/ZIP, timeout sync, confusion restore vs écrasement
   métier (mitigé côté serveur), dette controller.

4. À CONSERVER
   Safety DB, allow-list restore, process-only backup, scheduler,
   Inspector, séparation DB/files restore.

5. À REFACTORISER
   Controller, UI, import, métadonnées, lock unique, async.

6. ARCHITECTURE CIBLE
   Services découpés + ZIP Spatie + meta sidecar + UI claire +
   import quarantaine + restore inchangé dans ses garde-fous.

7. PLAN
   12.7.1 critiques → 12.7.2 UX → 12.7.3 import → 12.7.4 async →
   12.7.5 restore UX → 12.7.6 manifeste.

**ATTENTE VALIDATION EXPLICITE AVANT TOUTE IMPLÉMENTATION.**

========================================
END PRE-PROD 12.7
========================================
```
