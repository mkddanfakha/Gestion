# PRE-PROD 12.7.18 — CONTROLLED RESTORE PROTOCOL

## STATUS

```text
PROTOCOL PREPARED
```

## RESTORE EXECUTED

```text
NO
```

## TARGET

```text
gestion_recovery
```

## PRODUCTION DATABASE PROTECTION

```text
VERIFIED
```

## PRE-RESTORE CHECKS

```text
PASS / WARNING
```

Le protocole automatisé `php artisan restore:verify-protocol` exécute l'ensemble des contrôles PRE-RESTORE en lecture seule. Sans backup spécifié et sans connexion MySQL active dans l'environnement de test, le statut global peut être `WARNING` (snapshots MySQL et backup non fournis) — aucun échec de sécurité structurelle.

Contrôles implémentés :

- mode DRY-RUN (aucun restore)
- validation cible explicite
- matrice allow-list complète
- cibles d'injection shell
- environnement (sans mot de passe)
- base runtime = `gestion`
- séparation des comptes MySQL
- credentials restore (sans mot de passe)
- état Git (read-only)
- empreinte fichiers projet
- backup / manifest / SHA-256 / SQL (si `--backup` fourni)
- snapshots read-only `gestion` + cible
- baseline foreign keys
- couches de protection `gestion`
- architecture subprocess privilégiée
- safety backup

## POST-RESTORE CHECKS

```text
IMPLEMENTED — NOT EXECUTED
```

Méthode `ControlledRestoreVerificationService::runPostRestoreChecks()` prête pour la phase restore réel :

- comparaison `gestion` BEFORE/AFTER (empreinte schéma + row counts)
- vérification cible
- schéma / row counts / FK orphans
- tables système
- empreinte fichiers projet

## TARGET ALLOW-LIST

```text
VERIFIED
```

| Database         | Status  |
| ---------------- | ------- |
| gestion          | BLOCKED |
| gestion_recovery | ALLOWED |
| gestion_test     | ALLOWED |
| autre_base       | BLOCKED |
| gestion_recovery_x | BLOCKED |
| gestion_test_x   | BLOCKED |

## ACCOUNT SEPARATION

```text
VERIFIED
```

| Account         | Restore direct |
| --------------- | -------------- |
| gestion_app     | DENIED         |
| gestion_backup  | DENIED         |
| gestion_restore | ALLOWED        |

## SUBPROCESS SAFETY

```text
VERIFIED
```

Chemin documenté et testé :

```text
runtime Laravel (gestion_app)
→ DatabaseRestoreService
→ PrivilegedRestoreProcessRunner
→ php artisan db:restore
→ MKDPRO_PRIVILEGED_SUBPROCESS=restore
→ gestion_restore
```

`target=gestion` → subprocess NON lancé (tests 12.7.17 + protocole 12.7.18).

## SQL / MANIFEST / SHA VALIDATION

```text
VERIFIED
```

- `BackupManifestService::verifyIntegrity()` intégré au protocole
- `ControlledRestoreSqlInspector` inspecte le SQL sans import
- Détection références dangereuses vers `gestion` dans le dump

## GESTION PROTECTION

```text
VERIFIED
```

8 couches indépendantes vérifiées :

1. `DatabaseSafetyGuard::assertExplicitRestoreTarget`
2. `BackupController` validation
3. `DatabaseRestoreService`
4. `PrivilegedRestoreProcessRunner`
5. `DatabaseAccountGuard`
6. `PrivilegedCommandGuard` (`db:restore`)
7. Matrice protocole automatisé
8. Tests injection shell

## GESTION MODIFIED

```text
NO
```

## APPLICATION FILES

```text
UNCHANGED (except protocol implementation files listed below)
```

Fichiers ajoutés pour le protocole uniquement — aucune modification `.env`, production, OVH, R2.

## FILES CREATED

- `app/Services/Restore/ControlledRestoreVerificationService.php`
- `app/Services/Restore/ControlledRestoreSnapshotCollector.php`
- `app/Services/Restore/ControlledRestoreSqlInspector.php`
- `app/Console/Commands/RestoreVerifyProtocolCommand.php`
- `tests/Unit/Infrastructure/ControlledRestoreVerificationServiceTest.php`
- `docs/preprod-phase-12.7.18-report.md`

## FILES MODIFIED

Aucun fichier applicatif existant modifié hors ajouts ci-dessus.

## COMMAND

```bash
php artisan restore:verify-protocol --target=gestion_recovery --backup=<filename.zip> --json
```

Messages obligatoires :

```text
DRY-RUN ONLY
NO RESTORE EXECUTED
```

Refus immédiat si `--target=gestion`.

## TESTS

```text
75 passed
0 failed
```

Suites exécutées :

| Suite | Tests | Résultat |
| ----- | ----- | -------- |
| ControlledRestoreVerificationServiceTest | 18 | PASS |
| PrivilegedRestoreProcessRunnerTest | 16 | PASS |
| DatabaseRestoreArchitectureTest | 16 | PASS |
| RestoreSafetyTest | 13 | PASS |
| AccountSeparationTest | 12 | PASS |

## REAL RESTORE

```text
NOT EXECUTED
```

## DATABASE STATUS

```text
gestion: UNCHANGED
gestion_recovery: UNCHANGED (read-only snapshots only)
gestion_test: UNCHANGED
```

## ENVIRONMENT

```text
.env: UNCHANGED
OVH: UNCHANGED
R2: UNCHANGED
scheduler: UNCHANGED
production: UNTOUCHED
```

## NEXT PHASE

```text
PRE-PROD 12.7.18 — REAL CONTROLLED RESTORE
```

Une **approbation humaine explicite** sera nécessaire avant l'exécution du restore réel vers `gestion_recovery`.

Workflow prévu :

```text
PRE-RESTORE CHECKS (restore:verify-protocol)
        ↓
HUMAN APPROVAL GATE
        ↓
REAL RESTORE (phase séparée)
        ↓
POST-RESTORE CHECKS (runPostRestoreChecks)
        ↓
FINAL REPORT
```

## VERDICT

```text
CONTROLLED RESTORE PROTOCOL PREPARED
```
