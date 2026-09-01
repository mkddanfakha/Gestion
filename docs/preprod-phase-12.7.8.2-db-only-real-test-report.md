# PRE-PROD 12.7.8.2 — TEST RÉEL BACKUP DB-ONLY

## STATUS

```text
FAIL
```

Un seul test DB-only a été lancé. **STOP** — aucune correction automatique, aucun retry, aucun changement de code / `.env` / config.

---

## 1. État initial (lecture)

| Indicateur | Valeur |
|---|---|
| failed_jobs (total) avant | 4 |
| failed_jobs CreateBackupJob avant | 4 |
| pending_jobs (notifications) | 10 |
| QUEUE_CONNECTION | database |
| backup lock | NO |
| PrivilegedCredentialLoader | OK (`gestion_backup` / `gestion` / `127.0.0.1` / `3306`) |
| ZIP existants avant | 4 |

Anciens failed jobs : **non supprimés**, **non retentés**.

---

## 2. Test exécuté (un seul)

Mécanisme application (inchangé) :

```text
BackupCreationService::start(onlyDb=true, user_id=1)
  → CreateBackupJob
  → Artisan::call('backup:production', ['--only-db' => true])
  → PrivilegedProcessRunner + gestion_backup
```

Worker local temporaire :

```text
php artisan queue:work --tries=1 --timeout=300 -vvv
```

(arrêté après le FAIL — pas de worker permanent, scheduler inchangé)

---

## 3. Observation du job

| Champ | Valeur |
|---|---|
| progress `job_id` | `1de695de-30ba-4168-9612-ba8192a224a5` |
| failed_jobs UUID | `4229659e-8235-4573-a7c5-ac47b16efae9` |
| only_db | true |
| user_id | 1 |
| dispatch | 2026-08-31T01:44:17+00:00 |
| RUNNING (worker) | 2026-08-31 01:44:19 |
| FAIL (worker) | 2026-08-31 01:44:31 |
| durée | ~11–12 s |
| statut final | **FAIL** |
| progress cache | `status=failed`, `percentage=0` |

### Exception exacte (failed_jobs)

```text
RuntimeException
backup:production failed with exit code 1
CreateBackupJob.php:77
```

### Cause sous-jacente (logs Laravel, sans secrets)

Credentials / dump **atteints** :

```text
backup.job.create.start
MySqlForcedTcp::getDumpCommand … mysqldump … gestion
MySqlForcedTcp::getProcess … --host="127.0.0.1" --protocol=TCP … gestion
```

Puis échec Spatie sur destination **s3** :

```text
Could not connect to disk s3 because:
GuzzleHttp\Exception\RequestException:
cURL error 60: SSL certificate problem: unable to get local issuer certificate
… r2.cloudflarestorage.com/mkd-pro-backups/ …
backup.failed { message: Could not connect to disk s3 … }
backup.job.create.failed { exit_code: 1 }
```

Configuration observée (lecture seule) :

```text
config('backup.backup.destination.disks') = ["local","s3"]
```

(`BACKUP_DISKS` local inclut `s3` ; `.env` **non modifié** dans cette phase.)

---

## 4. Point d’échec (chaîne)

```text
BEFORE_CREDENTIAL_LOAD     PASS
DURING_CREDENTIAL_LOAD     PASS  (gestion_backup OK)
BEFORE_DUMP                PASS
DURING_DUMP                PASS  (mysqldump lancé ; SQL dans ZIP local)
DURING_ARCHIVE             PARTIAL  (ZIP local créé)
DURING_STORAGE             FAIL  ← disk s3 / SSL cURL 60
DURING_MANIFEST            NOT REACHED  (job exit ≠ 0)
DURING_PROGRESS            OK (queued → running → failed)
```

**Classification :** `DURING_STORAGE`

---

## 5. Artefact local partiel (non succès métier)

Un ZIP a été écrit **localement** malgré l’échec global du job :

| Champ | Valeur |
|---|---|
| fichier | `2026-08-31-01-44-23.zip` |
| taille | 31697 octets (> 0) |
| lisible | OUI (ZipArchive OK) |
| contenu | `db-dumps/mysqlforcedtcp-gestion.sql` (~266058 octets uncompressed) |
| sidecar `meta/…json` | **ABSENT** |
| manifeste / SHA-256 sidecar | **ABSENT** |
| `verifyIntegrity` | `MANIFEST_INVALID` (manifeste absent) |
| `BACKUP_CREATED` | **ABSENT** (aucune ligne autour de 01:44) |

Le job considère l’opération **échouée** car `backup:production` retourne exit code 1 (échec connexion disk `s3`).  
Pas d’attache manifeste / audit côté `CreateBackupJob` (branche succès non atteinte).

ZIP **non supprimé** (consigne). Restore / import : **non exécutés**.

---

## 6. Progression UI / cache

```text
queued (5 %)
  → running (~15 %, message création)
  → failed (0 %, message générique d’échec)
```

**Plus bloqué indéfiniment à 5 % « file d’attente »** pour ce run : le worker a bien démarré le job ; l’échec est post-credentials.

---

## 7. Lock

```text
lock = OK
already in progress = non observé
backup_locked après test = NO
```

Pas de double lock Controller/Job constaté.

---

## 8. Checklist résultats

```text
BACKUP_DB_ONLY=FAIL
QUEUE_JOB=FAIL
PRIVILEGED_CREDENTIALS=PASS
DATABASE_DUMP=PASS (dump exécuté ; SQL présent dans ZIP local)
ARCHIVE=PARTIAL (ZIP local présent ; pipeline Spatie en échec sur s3)
SIDECAR=FAIL
MANIFEST=FAIL
SHA256=FAIL
INTEGRITY=FAIL
AUDIT=FAIL
PROGRESS=PASS (non bloqué à 5 %)
LOCK=PASS
```

---

## 9. Failed jobs

| Avant | Après |
|---|---|
| 4 | **5** |

Nouveau : `4229659e-8235-4573-a7c5-ac47b16efae9`  
Anciens 4 : **conservés**.  
`FAILED_JOBS_DELETED=NO` / `FAILED_JOBS_RETRIED=NO`.

---

## 10. Cause racine (phase 12.7.8.2)

Credentials locaux **OK** (12.7.8.1).  
Échec actuel : Spatie tente le disk destination **`s3`** (R2) ; l’environnement **Windows/WAMP local** échoue SSL (**cURL error 60** — certificat CA manquant / non configuré pour PHP).

Ce n’est **pas** un échec `PrivilegedCredentialLoader`.  
Ce n’est **pas** un échec `gestion_app` utilisé pour le dump (dump via subprocess `gestion_backup`).

Correction éventuelle (hors scope — **non implémentée**) : phase suivante humaine pour décider entre CA PHP local, ou `BACKUP_DISKS=local` uniquement en local, sans toucher OVH/prod.

---

## 11. Safety

```text
DATABASE_MODIFIED=NO
RESTORE_EXECUTED=NO
IMPORT_EXECUTED=NO
OVH_MODIFIED=NO
PRODUCTION_MODIFIED=NO
ENV_MODIFIED=NO
SCHEDULER_MODIFIED=NO
CODE_MODIFIED=NO
FULL_BACKUP_EXECUTED=NO
BACKUP_DB_ONLY_EXECUTED=YES
FAILED_JOBS_DELETED=NO
FAILED_JOBS_RETRIED=NO
```

---

## STATUS

```text
FAIL
WAITING HUMAN VALIDATION
NO AUTO-FIX
NO 12.7.9
```

**STOP.**
