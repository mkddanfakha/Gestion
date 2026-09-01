# PRE-PROD 12.7.17 — PRIVILEGED RESTORE IMPLEMENTATION REPORT

## OBJECTIVE

Implémenter l'architecture de restauration privilégiée identifiée en PRE-PROD 12.7.16 : le runtime Laravel (`gestion_app`) ne peut pas exécuter directement un restore MySQL, mais peut déléguer à un subprocess isolé utilisant `gestion_restore` vers des cibles allow-listées (`gestion_recovery`, `gestion_test`). Aucun restore réel n'a été exécuté dans cette phase.

---

## ROOT CAUSE FROM 12.7.16

```text
gestion_app
→ DatabaseAccountGuard
→ 403
```

Le processus HTTP Laravel tournait sous `gestion_app`. `DatabaseRestoreService::restore()` appelait `DatabaseAccountGuard::assertAccountForOperation('restore')` qui exige `gestion_restore`, provoquant `ProtectedDatabaseException` et HTTP 403. Le backup disposait déjà de `PrivilegedProcessRunner` + `gestion_backup` ; le restore HTTP n'avait pas d'équivalent.

---

## ARCHITECTURE BEFORE

```text
HTTP POST restore
→ BackupController::restore()
→ DatabaseRestoreService::restore()
→ DatabaseAccountGuard::assertAccountForOperation('restore')
→ gestion_app ≠ gestion_restore
→ ProtectedDatabaseException → HTTP 403
```

Le service tentait d'importer le SQL directement via la connexion Laravel (`SqlDumpImporter` + PDO runtime). Aucun subprocess privilégié, aucun fichier `.mysql-gestion-restore.local` consommé par le flux HTTP.

---

## ARCHITECTURE AFTER

```text
Laravel (gestion_app)
→ DatabaseRestoreService
→ validations (backup, manifest, SHA-256, target allow-list, credentials)
→ DatabaseAccountGuard (inchangé — refuse restore direct sous gestion_app)
→ PrivilegedRestoreProcessRunner
→ subprocess php artisan db:restore (MKDPRO_PRIVILEGED_SUBPROCESS=restore)
→ gestion_restore
→ mysql import
→ gestion_recovery / gestion_test
```

Dans le subprocess enfant :

```text
DatabaseRestoreService::restore()
→ isRestoreSubprocess() = true
→ DatabaseAccountGuard::assertAccountForOperation('restore') → PASS (gestion_restore)
→ SqlDumpImporter (import PDO)
```

---

## CREDENTIAL SEPARATION

| Operation   | Account         |
| ----------- | --------------- |
| Application | gestion_app     |
| Backup      | gestion_backup  |
| Restore     | gestion_restore |

- Backup : `.mysql-gestion-backup.local` via `PrivilegedCredentialLoader::loadBackupCredentials()`
- Restore : `.mysql-gestion-restore.local` via `PrivilegedCredentialLoader::loadRestoreCredentials()`
- Le mot de passe restore n'apparaît jamais en CLI ; il est injecté uniquement dans l'environnement du subprocess.

---

## RESTORE TARGETS

| Database         | Status  |
| ---------------- | ------- |
| gestion          | BLOCKED |
| gestion_recovery | ALLOWED |
| gestion_test     | ALLOWED |
| other            | BLOCKED |

Validation via `DatabaseSafetyGuard::assertExplicitRestoreTarget()` avant subprocess, et à nouveau dans `PrivilegedRestoreProcessRunner::assertRestoreTargetAllowed()`.

---

## SECURITY

Vérifications en place :

```text
DatabaseAccountGuard          — actif, non contourné, pas de bypass local
BackupPathGuard               — chemins backup sanitizés
BackupManifest + SHA-256      — intégrité vérifiée avant subprocess
allow-list                    — gestion_recovery / gestion_test uniquement
credential separation         — gestion_restore exclusif au restore subprocess
shell injection protection    — allow-list fail-closed sur cibles malicieuses
password redaction            — sanitizeForLog + pas de secret en argv CLI
```

Ordre des validations runtime (gestion_app) :

```text
HTTP → permission → backup path → archive inspect → manifest SHA-256
→ target allow-list → credentials restore → privileged subprocess
```

Safety backup (optionnel) : `backup:production --only-db` via `gestion_backup` ; si échec (`exitCode !== 0`), le restore n'est pas appelé.

---

## FILES CREATED

- `app/Database/PrivilegedRestoreProcessRunner.php`
- `tests/Unit/Infrastructure/PrivilegedRestoreProcessRunnerTest.php`
- `docs/preprod-phase-12.7.17-report.md`
- `.mysql-gestion-restore.local` (fichier local gitignored, préexistant, normalisé au format `DB_USERNAME`/`DB_PASSWORD`)

---

## FILES MODIFIED

- `app/Database/DatabaseAccountGuard.php` — `isRestoreSubprocess()`
- `app/Database/PrivilegedCommandGuard.php` — enregistrement `db:restore`
- `app/Database/PrivilegedCredentialLoader.php` — `loadRestoreCredentials()`, `RESTORE_CREDENTIAL_FILE`
- `app/Database/PrivilegedProcessRunner.php` — `SUBPROCESS_OPERATION_RESTORE`
- `app/Services/Restore/DatabaseRestoreService.php` — délégation subprocess + import dans subprocess uniquement
- `tests/Unit/Infrastructure/DatabaseRestoreArchitectureTest.php` — simulation subprocess pour tests existants

---

## FILES DELETED

Aucun fichier source supprimé dans le cadre de 12.7.17.

---

## TESTS

| Test suite          | Result |
| ------------------- | ------ |
| Account separation  | PASS   |
| Target allow-list   | PASS   |
| Credential loader   | PASS   |
| Privileged runner   | PASS   |
| Restore service     | PASS   |
| Safety backup       | PASS   |
| Manifest integrity  | PASS   |
| Shell injection     | PASS   |
| Password redaction  | PASS   |
| UI restore response | PASS   |
| Frontend build      | PASS   |

Détail exécuté :

- `PrivilegedRestoreProcessRunnerTest` — 16 tests PASS
- `DatabaseRestoreArchitectureTest` — 16 tests PASS
- `RestoreSafetyTest` — 13 tests PASS
- `AccountSeparationTest` — 12 tests PASS
- `backupRestoreResponse.test.ts` — 14 tests PASS
- `npm run build` — PASS

Tous les tests sont mockés ou utilisent SQLite `:memory:` / subprocess simulé. Aucune base MySQL réelle restaurée.

---

## REAL RESTORE

```text
REAL RESTORE: NOT EXECUTED
```

---

## DATABASE STATUS

```text
gestion: UNCHANGED
gestion_recovery: UNCHANGED
gestion_test: UNCHANGED
```

---

## ENVIRONMENT

```text
.env: UNCHANGED
OVH: UNCHANGED
R2: UNCHANGED
scheduler: UNCHANGED
production: UNTOUCHED
```

`.mysql-gestion-restore.local` : présent localement, ignoré par Git (`.gitignore:51`).

---

## CRITICAL SECURITY CHECKS

| Check | Question | Answer |
| ----- | -------- | ------ |
| 1 | Peut-on restaurer vers `gestion` ? | **NO** |
| 2 | Peut-on restaurer avec `gestion_app` (direct) ? | **NO** |
| 3 | Le subprocess restore utilise `gestion_restore` ? | **YES** |
| 4 | Le target est validé AVANT le subprocess ? | **YES** |
| 5 | SHA-256 invalide peut lancer le subprocess ? | **NO** |
| 6 | Safety backup échoué peut lancer le restore ? | **NO** |
| 7 | Un mot de passe peut apparaître dans les logs ? | **NO** |

---

## VERDICT

```text
PRIVILEGED RESTORE IMPLEMENTED
```

Architecture implémentée, sécurité testée par mocks, subprocess correctement câblé. Le restore réel fera l'objet de PRE-PROD 12.7.18 avec approbation humaine explicite.
