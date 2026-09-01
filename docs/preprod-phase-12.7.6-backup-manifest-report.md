# PRE-PROD 12.7.6 — MANIFESTE ET INTÉGRITÉ DES SAUVEGARDES

## STATUS

COMPLETE

## FILES CREATED

- `app/Services/Backup/BackupManifest.php`
- `app/Services/Backup/BackupManifestService.php`
- `tests/Unit/Infrastructure/BackupManifestTest.php`
- `docs/preprod-phase-12.7.6-backup-manifest-report.md`

## FILES MODIFIED

- `app/Services/Backup/BackupMetadataService.php` — écriture sidecar déléguée au ManifestService
- `app/Services/Backup/BackupImportService.php` — hash après promotion ; manifeste serveur officiel
- `app/Services/Backup/BackupCreationService.php` — manifeste après ZIP final
- `app/Services/Backup/BackupAuditService.php` — événement `BACKUP_INTEGRITY_CHECKED`
- `app/Http/Controllers/Admin/BackupController.php` — hints listing, inspect + integrity, action `verifyIntegrity`
- `routes/web.php` — `POST /admin/backups/{backup}/verify-integrity`
- `resources/js/lib/routes.ts` — route `admin.backups.verify-integrity`
- `resources/js/pages/Admin/Backups/Index.vue` — colonne intégrité, hash tronqué, action « Vérifier l'intégrité », affichage inspect restore

## MANIFEST

Format versionné `manifest_version: 1` (sidecar inchangé : `meta/{zip}.json`).

Champs principaux :

- `manifest_version`, `backup_id`, `filename`, `type`, `source`, `status`
- `created_at`, `imported_at`
- `application.name` / `application.version`
- `environment` (APP_ENV uniquement, jamais de secrets)
- `database.included` / `driver` / `database_name` (nom logique optionnel, jamais credentials)
- `files.included`
- `archive.size_bytes` / `archive.sha256`
- alias plats (`sha256`, `size_bytes`, …) pour compatibilité listing 12.7.3

Legacy 12.7.3 sans `manifest_version` : normalisé en lecture via `BackupManifest::normalizeLegacy` (compatibilité « à vérifier »).

Aucun mot de passe / secret `.env` / credential DB dans le manifeste (`assertNoSecrets`).

## INTEGRITY

Flux création / écriture :

1. ZIP final sur disque
2. existence + taille
3. `hash_file('sha256')` sur le fichier stocké
4. génération manifeste
5. écriture sidecar
6. validation structure minimale

Vérification (`BackupManifestService::verifyIntegrity`) :

1. `BackupPathGuard`
2. existence fichier
3. lecture manifeste
4. recalcul SHA-256
5. comparaison `hash_equals`
6. cohérence taille / structure
7. résultats : `VALID` | `INVALID` | `MISSING` | `MANIFEST_INVALID`

Listing : hint léger (`manifest_present` / `unknown` / …) sans re-hash obligatoire.  
Action UI « Vérifier l'intégrité » : re-hash réel, lecture seule (pas de restore, pas de `backup:production`).

## IMPORT

- Quarantaine + `BackupArchiveInspector` + `BackupPathGuard` conservés
- Promotion du ZIP puis hash du fichier **final**
- Manifeste **serveur** uniquement (`source=imported`)
- Hash / manifeste éventuel fourni par l'utilisateur ou dans l'archive : **non fiables**, non utilisés comme référence
- Aucune restauration automatique

## RESTORE

Protections PRE-PROD 12.7.5 conservées :

- inspection réelle (`BackupArchiveInspector`)
- validation manifeste / intégrité si disponible (INVALID soft-bloque `can_restore` UX)
- allow-list inchangée
- double confirmation inchangée
- sauvegarde de sécurité optionnelle inchangée
- cible jamais `gestion`

## AUDIT

Ajout : `BACKUP_INTEGRITY_CHECKED` via `BackupAuditService::integrityChecked`

Payload : `filename`, `backup_id`, `result`, `type`, `source`, `compatibility` — jamais secret / password / token.

## TESTS

```
php artisan test tests/Unit/Infrastructure/BackupManifestTest.php tests/Unit/Infrastructure/BackupCriticalFixesTest.php
```

Résultat exact :

- `BackupManifestTest` : **8 passed**
- `BackupCriticalFixesTest` : **22 passed** (régression PathGuard, import, create async, inspect, restore allow-list)
- **Total : 30 passed (118 assertions)** — exit code 0

Couverture manifeste : version, type DB/FULL, source, SHA-256, taille, lecture, INVALID/MISSING/MANIFEST_INVALID, legacy sans version, import serveur, secrets rejetés, PathGuard.

## BUILD

```
npm run build
```

Résultat exact : **PASS** — `✓ built in 1m 54s` (exit code 0)

## SECURITY

- PathGuard **PRESERVED**
- Inspector **PRESERVED**
- quarantine **PRESERVED**
- allow-list **PRESERVED**
- double confirmation **PRESERVED**
- safety backup **PRESERVED**

## OVH

UNCHANGED

## PRODUCTION

UNCHANGED

## DATABASE

production : UNCHANGED

## ENV

production : UNCHANGED

## SCHEDULER

UNCHANGED

## DEPLOYMENT

NOT EXECUTED

---

Phase 12.7.6 terminée. En attente de validation humaine explicite avant toute phase suivante.
