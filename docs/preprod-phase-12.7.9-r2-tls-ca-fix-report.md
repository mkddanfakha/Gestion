# PRE-PROD 12.7.9 — R2 TLS/CA FIX

## STATUS

```text
PASS
```

---

## ROOT_CAUSE

PHP CLI WAMP **8.4.0** n’avait **aucun** bundle CA configuré :

```text
curl.cainfo = (empty)
openssl.cafile = (empty)
openssl.capath = (empty)
```

Conséquence : Guzzle/AWS SDK → HTTPS R2 échouait avec `cURL error 60: SSL certificate problem: unable to get local issuer certificate` à l’étape **DURING_STORAGE**, alors que dump + ZIP locaux réussissaient déjà.

Aucun override applicatif `verify=false` / `CURLOPT_SSL_VERIFYPEER=false` trouvé dans le code backup / S3.

---

## CORRECTION

1. Téléchargement du bundle CA Mozilla **officiel** :  
   `https://curl.se/ca/cacert.pem`  
   → `C:/wamp64/bin/php/php8.4.0/extras/ssl/cacert.pem` (121 certificats, ~189 KB)

2. Configuration **locale** `C:\wamp64\bin\php\php8.4.0\php.ini` (backup : `php.ini.preprod-12.7.9.bak`) :

```ini
curl.cainfo = "C:/wamp64/bin/php/php8.4.0/extras/ssl/cacert.pem"
openssl.cafile = "C:/wamp64/bin/php/php8.4.0/extras/ssl/cacert.pem"
```

**Aucune** désactivation SSL.  
**Aucun** changement `.env` / code backup / OVH / production / scheduler.

---

## PHP_VERSION

```text
PHP 8.4.0 (cli) ZTS VC15 x64
Binary: C:\wamp64\bin\php\php8.4.0\php.exe
Loaded php.ini: C:\wamp64\bin\php\php8.4.0\php.ini
```

## CURL_CA

```text
curl.cainfo = C:/wamp64/bin/php/php8.4.0/extras/ssl/cacert.pem
EXISTS = YES
```

## OPENSSL_CA

```text
openssl.cafile = C:/wamp64/bin/php/php8.4.0/extras/ssl/cacert.pem
EXISTS = YES
```

---

## HTTPS_TEST

Avant correction (sans CA) :

```text
PHP_CURL errno=60 SSL certificate problem
```

Après correction (ini CA, sans bypass) :

```text
PHP_CURL_DEFAULT_INI errno=0 http=400  (TLS OK ; 400 attendu sans auth bucket root)
PHP_STREAM_OPENSSL PASS
```

```text
HTTPS_TEST = PASS
TLS certificate verification = PASS
```

## R2_CONNECTION

```text
Storage::disk('s3')->files() = PASS (connexion OK)
R2_CONNECTION = PASS
```

---

## DB_ONLY_BACKUP

Un seul test via `BackupCreationService::start(onlyDb=true)` :

| Champ | Valeur |
|---|---|
| job_id | `2def0a2b-46c9-467a-b3f9-a316d7e4c9fc` |
| dispatch | 2026-08-31T20:06:42+00:00 |
| completed | 2026-08-31T20:06:51+00:00 |
| status | completed |
| percentage | 100 |
| filename | `2026-08-31-20-06-45.zip` |

```text
DB_ONLY_BACKUP = PASS
QUEUE_JOB = PASS
PROGRESS = PASS (queued → running → completed, non bloqué à 5 %)
LOCK = PASS (pas de « already in progress », lock libéré)
```

---

## ZIP

```text
ZIP = PASS
path logique : storage/.../Gestion/2026-08-31-20-06-45.zip
size = 32465 (> 0)
entries = 1
SQL = db-dumps/mysqlforcedtcp-gestion.sql (~285903 octets)
```

## R2_STORAGE

```text
R2_STORAGE = PASS
object = Gestion/2026-08-31-20-06-45.zip
size = 32465 (identique au ZIP local)
```

## MANIFEST / SHA256 / INTEGRITY

```text
sidecar meta/{zip}.json = PRESENT
manifest_version = 1
type = DATABASE
source = manual
status = valid
SHA256 = PASS (hash fichier == hash manifeste)
INTEGRITY = VALID
```

Hash tronqué : `313c1392111d…03c2d90ef6e8`

## AUDIT

```text
AUDIT = PASS
BACKUP_CREATED id=90 @ 2026-08-31 20:06:51
description: Sauvegarde créée : 2026-08-31-20-06-45.zip
```

---

## Sécurité TLS

```text
SSL_VERIFICATION_DISABLED = NO
TLS_VERIFICATION_BYPASSED = NO
INSECURE_S3_CONFIGURATION = NO
```

---

## Failed jobs

Anciens failed jobs **conservés** (non supprimés, non retentés).  
Aucun nouveau failed `CreateBackupJob` pour ce run réussi.

---

## Safety global

```text
DATABASE_MODIFIED = NO
PRODUCTION_MODIFIED = NO
OVH_MODIFIED = NO
SCHEDULER_MODIFIED = NO
ENV_MODIFIED = NO
RESTORE_EXECUTED = NO
IMPORT_EXECUTED = NO
FULL_BACKUP_EXECUTED = NO
CODE_BACKUP_LOGIC_MODIFIED = NO
```

Modifications hors dépôt Laravel (runtime local WAMP uniquement) :

```text
PHP_INI_MODIFIED = YES (CA paths only)
CACERT_BUNDLE_INSTALLED = YES (curl.se officiel)
```

---

## Checklist finale

```text
BACKUP_DB_ONLY=PASS
DATABASE_DUMP=PASS
ZIP=PASS
R2_STORAGE=PASS
SIDECAR=PASS
MANIFEST=PASS
SHA256=PASS
INTEGRITY=PASS
AUDIT=PASS
QUEUE_JOB=PASS
PROGRESS=PASS
LOCK=PASS
HTTPS_TEST=PASS
R2_CONNECTION=PASS
```

---

## STATUS

```text
PASS
WAITING HUMAN VALIDATION
NO DEPLOY
NO NEXT PHASE
```

**STOP.**
