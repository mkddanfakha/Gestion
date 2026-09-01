# PRE-PROD 12.7.8 — BACKUP CREDENTIALS FIX

## 1. Verdict

```text
FAIL — ROOT CAUSE REMAINS (partial fix only)
STOPPED — DB_DATABASE ABSENT IN SECRET FILE
```

Le renommage des clés username/password a été appliqué.  
Le test réel DB-only **n’a pas été exécuté** : après correction, `PrivilegedCredentialLoader` bloque encore sur une clé obligatoire absente (`DB_DATABASE`), et la phase **interdit** d’inventer / ajouter `DB_HOST` / `DB_PORT` / `DB_DATABASE` sans validation humaine.

---

## 2. Cause initiale

Fichier : `.mysql-gestion-backup.local` (racine projet, gitignored).

Avant correction :

```env
# commentaire PRE-PROD 9.3.1
DB_BACKUP_USERNAME=gestion_backup
DB_BACKUP_PASSWORD=<PRESENT>
```

`PrivilegedCredentialLoader` n’accepte que :

```text
DB_USERNAME, DB_PASSWORD, DB_HOST, DB_PORT, DB_DATABASE
```

Les clés `DB_BACKUP_*` étaient **ignorées** → `DB_USERNAME` vide →  
`DATABASE SAFETY BLOCK: backup credential file must define DB_USERNAME=gestion_backup.` (L54).

---

## 3. Correction

**Fichier modifié (seul autorisé) :** `.mysql-gestion-backup.local`

**Renommage effectué (valeurs inchangées) :**

| Avant | Après |
|---|---|
| `DB_BACKUP_USERNAME` | `DB_USERNAME` |
| `DB_BACKUP_PASSWORD` | `DB_PASSWORD` |

État actuel (sans secrets) :

```text
DB_USERNAME=gestion_backup
DB_PASSWORD=<PRESENT>
DB_HOST=<ABSENT>
DB_PORT=<ABSENT>
DB_DATABASE=<ABSENT>
```

Runtime inspecté (lecture seule `.env` / config, **non modifié**) :

```text
DB_USERNAME (runtime app) = gestion_app
DB_DATABASE (runtime app) = gestion
```

---

## 4. Safety

```text
DATABASE SAFETY BLOCK = PRESERVED
```

Vérification loader après renommage :

```text
LOADER=FAIL
RuntimeException
DATABASE SAFETY BLOCK: backup credential file must define DB_DATABASE.
PrivilegedCredentialLoader.php:66
```

Le username `gestion_backup` est maintenant accepté ; le block suivant (DB_DATABASE obligatoire) s’applique correctement.  
Aucun affaiblissement du loader. Aucune modification de `PrivilegedCredentialLoader.php`.

---

## 5. Test réel

```text
DB-ONLY BACKUP = NOT EXECUTED
```

Motif : arrêt obligatoire dès absences de `DB_HOST` / `DB_PORT` / `DB_DATABASE` dans le fichier secret (consigne phase §6).  
Aucun `backup:production`, aucun clic UI, aucun full backup.

---

## 6. Queue

```text
CreateBackupJob = NOT EXECUTED
```

Anciens failed jobs (12.7.7) : **conservés**, non retentés, non supprimés.

---

## 7. Progression

```text
N/A — aucun nouveau job lancé
```

---

## 8. ZIP

```text
N/A — pas de création
```

---

## 9. Manifest

```text
manifest_version = N/A
SHA-256 = N/A
```

---

## 10. Sidecar

```text
N/A
```

---

## 11. Audit

```text
BACKUP_CREATED = N/A (non exécuté)
```

---

## 12. UI

```text
N/A — test UI non lancé (blocage credentials)
```

---

## 13. Anciennes erreurs

Les failed jobs 12.7.7 restent en base (`queue:failed` non modifié).  
`FAILED_JOBS_DELETED=NO` / `FAILED_JOBS_RETRIED=NO`.

---

## 14. Cause restante (STOP)

Après renommage, la chaîne casse à :

```text
PrivilegedCredentialLoader::loadBackupCredentials
→ PrivilegedCredentialLoader.php:66
→ DATABASE SAFETY BLOCK: backup credential file must define DB_DATABASE.
```

Étape : **avant** Spatie / ZIP / manifeste.  
Impact : création async DB-only toujours impossible jusqu’à complétion du fichier secret.

### Action humaine requise (hors phase — à valider explicitement)

Compléter `.mysql-gestion-backup.local` avec au minimum :

```env
DB_USERNAME=gestion_backup
DB_PASSWORD=<déjà présent — ne pas régénérer>
DB_DATABASE=gestion
```

Optionnel (HOST a un défaut code `127.0.0.1` si omis ; PORT optionnel) :

```env
DB_HOST=127.0.0.1
DB_PORT=3306
```

Puis relancer **une** phase de test DB-only (équivalent 12.7.8 suite / 12.7.8b) avec worker.

**Ne pas** modifier `.env` runtime (`gestion_app`).

---

## 15. Production safety

```text
DATABASE_MODIFIED=NO
DATABASE_DATA_MODIFIED=NO
RESTORE_EXECUTED=NO
IMPORT_EXECUTED=NO
OVH_MODIFIED=NO
PRODUCTION_MODIFIED=NO
SCHEDULER_MODIFIED=NO
ENV_MODIFIED=NO
FULL_BACKUP_EXECUTED=NO
BACKUP_DB_ONLY_EXECUTED=NO
FAILED_JOBS_DELETED=NO
FAILED_JOBS_RETRIED=NO
CODE_MODIFIED=NO
```

Seule écriture hors rapport : renommage de clés dans `.mysql-gestion-backup.local`.  
Rapport : `docs/preprod-phase-12.7.8-backup-credentials-fix-report.md`.

---

## STATUS

```text
PARTIAL FIX APPLIED
TEST BLOCKED
WAITING HUMAN DECISION ON DB_DATABASE (AND OPTIONAL HOST/PORT)
```

**STOP** — pas de 12.7.9, pas de full backup, pas de restore, pas OVH.
