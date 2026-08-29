# PRE-PROD 10.2 — Automated Offsite Backup Test

**Date :** 2026-08-26  
**Approbation :** `OUI — RUN AUTOMATED OFFSITE BACKUP TEST`  
**Mode :** `backup:run --only-db` (lecture MySQL + écriture archives local/R2) — **aucun** restore / migrate / seed / DROP / GRANT

---

```text
========================================
MKD-PRO PRE-PROD 10.2
AUTOMATED OFFSITE BACKUP TEST
========================================

APPROVAL:
OUI — RUN AUTOMATED OFFSITE BACKUP TEST

WIRING (.env human):
BACKUP_DISKS=local,s3
AWS_USE_PATH_STYLE_ENDPOINT=true

RUNTIME PROCESS-ONLY:
DB_USERNAME=gestion_backup
CACHE_STORE=file
BACKUP_LOCK_CACHE_STORE=file
SSL verify=ENABLED (cacert.pem)
.env runtime after test: gestion_app (UNCHANGED)

COMMAND:
php artisan backup:run --only-db
exit_code=0
elapsed_s≈8.81

LOCAL BACKUP:
PASS
file=2026-08-26-21-30-29.zip
size=7577
sha256=0f08190a3ad1df82feaa8c5c35005cba764012fc89e5e412f79db50401930f32

R2 BACKUP:
PASS
key=Gestion/2026-08-26-21-30-29.zip
size=7577
exists=YES

SHA-256:
MATCH

ZIP:
VALID (1 entry)

ENV:
ABSENT

TABLES IN DUMP:
38 CREATE TABLE

BUSINESS INSERTS:
0

FIRST PRODUCTION BACKUP:
PRESERVED
2026-08-26-15-16-45.zip
sha256=3231554e80931843fc634dab269fc9606f47f2208750f9bddf9cce2eca1453dc
MATCH

DATABASE:
UNCHANGED
tables=38 migrations=75 users=0 customers=0

gestion_recovery:
UNCHANGED (27 tables)

backup:status AFTER:
STATUS=OK
MODE=LOCAL_AND_OFFSITE
BACKUP_LOCAL=OK
BACKUP_OFFSITE=OK
RPO=OK
automated_offsite=CONFIGURED

RESTORE:
NOT EXECUTED

MIGRATE:
NOT EXECUTED

SEED:
NOT EXECUTED

VERDICT:
PASS
========================================
```

---

## Notes

1. Spatie a copié le ZIP sur **local** puis **s3** (« Successfully copied zip to disk named s3 »).
2. Compte MySQL du dump : `gestion_backup` (process-only) — conforme policy.
3. Lock file — pas d’INSERT MySQL requis pour le cache.
4. Scripts temporaires de run/verify : **supprimés**.
5. Suite recommandée : OS scheduler + alerting mail + PRE-PROD 11 restore drill (approbations séparées).

**STOP** — pas d’enchaînement automatique vers PRE-PROD 11.
