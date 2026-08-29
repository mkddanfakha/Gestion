# PRE-PROD 10 — Offsite Backup Verification Report

**Date :** 2026-08-26  
**Approbation :** `OUI — TEST OFFSITE BACKUP`  
**Mode :** upload + verify lecture seule — **aucun** `backup:run` / restore / migrate / seed / DROP / GRANT

---

```text
========================================
MKD-PRO PRE-PROD 10
OFFSITE BACKUP VERIFICATION
========================================

APPROVAL:
OUI — TEST OFFSITE BACKUP

LOCAL ARCHIVE:
storage/app/private/Gestion/2026-08-26-15-16-45.zip

LOCAL SHA-256:
3231554e80931843fc634dab269fc9606f47f2208750f9bddf9cce2eca1453dc

LOCAL SIZE:
7577 bytes

OFFSITE PROVIDER:
Cloudflare R2 (S3-compatible)

BUCKET:
mkd-pro-backups (name only; credentials never displayed)

REMOTE KEY:
mkdpro/preprod-10/2026-08-26-15-16-45.zip

PATH STYLE:
true (required for this R2 endpoint)

UPLOAD:
PASS

REMOTE EXISTS:
YES

REMOTE SIZE:
7577

SIZE MATCH:
YES

DOWNLOAD VERIFY:
PASS

SHA-256 MATCH:
YES

ZIP VALID:
YES (1 entry)

SSL VERIFY:
ENABLED (Mozilla CA bundle; verify=false NEVER used)

LOCAL ARCHIVE KEPT:
YES

REMOTE OBJECT KEPT:
YES

SECOND backup:run:
NO

DATABASE gestion MODIFIED:
NO

gestion_recovery MODIFIED:
NO

----------------------------------------
POST-TEST RO BASELINE
----------------------------------------
gestion_tables=38
migrations=75
users=0
customers=0
recovery_tables=27

----------------------------------------
DEPENDENCY
----------------------------------------
league/flysystem-aws-s3-v3: installed (^3.x) for S3/R2 adapter

----------------------------------------
BACKUP_DISKS
----------------------------------------
STATUS: still default / local-only in runtime .env
NOTE: this test used Storage::disk('s3') directly.
Spatie will upload to R2 only after human sets:
  BACKUP_DISKS=local,s3
  AWS_USE_PATH_STYLE_ENDPOINT=true

----------------------------------------
WINDOWS SSL (blocker fixed for test)
----------------------------------------
php.ini curl.cainfo / openssl.cafile: EMPTY on WAMP PHP 8.4.0
First PutObject failed: cURL error 60 (unable to get local issuer certificate)
Mitigation for this test only:
  storage/app/private/cacert.pem (Mozilla bundle, gitignored under private/)
  + AWS SDK http.verify => that path
  + CURL_CA_BUNDLE / SSL_CERT_FILE env for process
Permanent fix (human / ops): set in php.ini
  curl.cainfo = "C:/path/to/cacert.pem"
  openssl.cafile = "C:/path/to/cacert.pem"

----------------------------------------
VERDICT
----------------------------------------
OFFSITE_TEST: PASS
STATUS: READY WITH CONDITIONS

CONDITIONS REMAINING:
1. Set BACKUP_DISKS=local,s3 (+ path-style true) for scheduled Spatie offsite
2. Permanent php.ini CA bundle (or equivalent) for CLI/FPM
3. Scheduler OS runner NOT VERIFIED
4. Backup alerting mail still disabled until BACKUP_ALERT_* enabled
5. Secret rotation still recommended (historical CLI traces)
6. Restore drill still needs: OUI — CREATE gestion_test FOR RESTORE DRILL
========================================
```

---

## 1. Scope executed

| Action | Result |
|--------|--------|
| Precheck bucket / endpoint / keys present | PASS (values not logged) |
| Install `league/flysystem-aws-s3-v3` | PASS (was missing) |
| Upload first production ZIP to R2 | PASS |
| Remote exists + size | PASS |
| Download to temp + SHA-256 | PASS |
| ZIP open | PASS |
| Keep local + remote | YES |
| `backup:run` | **NOT** executed |
| Touch `gestion` / `gestion_recovery` | **NO** |

## 2. Failure then fix

1. Silent `Storage::put` → `false` (`throw=false` default).
2. With `throw=true`: nested `UnableToWriteFile` → `S3Exception` → **cURL error 60** (no CA bundle on WAMP PHP).
3. Process-only CA bundle + `http.verify` → upload OK with **path-style=true**.

## 3. Safety

- No secrets printed (URL / credentials redacted in debug).
- Temp script `tmp_offsite_test.php` removed after PASS.
- `cacert.pem` under `storage/app/private/` (gitignored).
- Runtime `.env` DB account unchanged (`gestion_app`).

## 4. Human follow-ups (no auto-exec)

```text
# .env (hors chat) — recommended for Spatie destinations
BACKUP_DISKS=local,s3
AWS_USE_PATH_STYLE_ENDPOINT=true

# php.ini (WAMP) — permanent SSL
curl.cainfo="…/cacert.pem"
openssl.cafile="…/cacert.pem"
```

Next gates (exact phrases only):

- `OUI — CREATE gestion_test FOR RESTORE DRILL` — restore drill isolé  
- Approbation séparée pour rotation secrets / enable alerting / verify scheduler OS  

**STOP** — offsite copy of first production backup verified; no further destructive or backup-run actions without new approval.
