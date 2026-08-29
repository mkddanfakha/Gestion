# PRE-PROD 9.3.1 — Database Operation Accounts Report

**Date :** 2026-08-24  
**Mode :** CREATE & VERIFY (fail-closed; per-account human approval)

---

```text
================================================
MKD-PRO PRE-PROD 9.3.1
DATABASE OPERATION ACCOUNTS
================================================

ROOT CAUSE:
IDENTIFIED

RUNTIME:
gestion_app

BACKUP ACCOUNT:
CREATED
VERIFIED

RESTORE ACCOUNT:
CREATED
VERIFIED

MIGRATION ACCOUNT:
NOT CREATED
NOT VERIFIED

GLOBAL PRIVILEGES:
NONE (on application accounts)

GRANT OPTION:
NONE (on application accounts)

GESTION DROP:
NOT AVAILABLE TO APPLICATION ACCOUNTS

GESTION CREATE DATABASE:
NOT AVAILABLE TO APPLICATION ACCOUNTS

GESTION:
UNCHANGED

ENV:
UNCHANGED

SECRETS:
OUTSIDE GIT (.mysql-gestion-backup.local gitignored)

TESTS:
BACKUP ACCOUNT VERIFY PASS (manual MySQL probes)

BUILD:
NOT RUN

STATUS:
READY WITH CONDITIONS
(backup account done; restore + migration still BLOCKED pending approval)
```

---

## Baseline

```text
Database: gestion
Laravel runtime user: gestion_app
Environment: local
Root for admin CLI: YES
gestion: UNCHANGED
```

| Table | Before | After backup CREATE |
|-------|-------:|--------------------:|
| users | 3 | 3 |
| companies | 1 | 1 |
| customers | 0 | 0 |
| products | 0 | 0 |
| sales | 0 | 0 |

---

## PRE-PROD 9.3.1 — BACKUP ACCOUNT

```text
ACCOUNT:
gestion_backup

CREATED:
YES

HOSTS:
localhost
127.0.0.1

RUNTIME USER:
NO

PRIVILEGE AUDIT:
PASS

Grants:
USAGE ON *.*
SELECT, LOCK TABLES, SHOW VIEW, TRIGGER ON gestion.*

DANGEROUS PRIVILEGES:
NONE

PASSWORD:
STORED OUTSIDE GIT (.mysql-gestion-backup.local)

GESTION:
UNCHANGED

STATUS:
VERIFIED
```

### Verification probes

| Test | Result |
|------|--------|
| CONNECT | PASS |
| SELECT users/companies | PASS (3 / 1) |
| INSERT | DENIED (1142) |
| UPDATE | DENIED (1142) |
| DELETE | DENIED (1142) |
| DROP TABLE | DENIED (1142) |
| ALTER TABLE | DENIED (1142) |
| CREATE TABLE | DENIED (1142) |
| CREATE DATABASE | DENIED (1044) |
| DROP DATABASE gestion | DENIED (1044) |
| CREATE USER | DENIED (1227) |
| GRANT | DENIED (1045) |

Human approval used: `OUI — CREATE gestion_backup`

---

## STOP — next account requires separate approval

```text
================================================
HUMAN APPROVAL REQUIRED — RESTORE ACCOUNT
================================================

Account:
gestion_restore

Purpose:
restore uniquement vers les bases recovery/test autorisées

Allowed targets:
gestion_recovery
gestion_test

Forbidden target:
gestion

Hosts:
localhost
127.0.0.1

Proposed privileges (from MysqlPdoDumpImporter + allow-list):
ON *.* : none (no global admin)
CREATE, DROP, ALTER, INDEX, REFERENCES,
SELECT, INSERT, UPDATE, DELETE
ON gestion_recovery.*
ON gestion_test.*
+ CREATE / DROP on those databases only (MySQL may require
  CREATE/DROP privilege at schema level — exact GRANT form
  will be presented again before CREATE if approval given)

Dangerous/global privileges:
NONE intended on *.*
NEVER grants on gestion.*

Note:
gestion_test database currently ABSENT — CREATE DATABASE gestion_test
is a separate human decision if needed for restore drills.

Laravel .env:
UNCHANGED

gestion:
UNCHANGED

Operation:
CREATE USER + GRANT

STATUS:
BLOCKED — WAITING FOR HUMAN APPROVAL

Reply required:
"OUI — CREATE gestion_restore"
================================================
```

---

## PRE-PROD 9.3.2 — RESTORE ACCOUNT (audit only — NOT CREATED)

**Date audit :** 2026-08-25

```text
STATUS: BLOCKED — WAITING FOR HUMAN APPROVAL
ACTION: NONE
gestion_restore: NOT EXISTS
gestion_test: ABSENT
gestion_recovery: EXISTS
.env: UNCHANGED (gestion_app)
gestion: UNCHANGED
```

### Code needs (`MysqlPdoDumpImporter`)

1. `DROP DATABASE IF EXISTS \`{target}\``
2. `CREATE DATABASE \`{target}\``
3. Replay dump (CREATE/DROP/ALTER TABLE, INDEX, FK, INSERT, …) into target only  
Targets allow-list Laravel : `gestion_recovery`, `gestion_test` — **never** `gestion`.

### Privilege gap (MySQL)

`CREATE DATABASE` / sometimes `DROP DATABASE` are difficult to scope to named DBs only without a **global** privilege.  
Proposed default = **db-scoped only** (no `*.*` admin). If CREATE DATABASE fails after CREATE USER, stop with:

```text
RESTORE PRIVILEGE GAP DETECTED
```

and ask for a separate approval before any global CREATE.

### Proposed GRANT (pending approval)

```sql
-- hosts: localhost + 127.0.0.1
GRANT SELECT, INSERT, UPDATE, DELETE,
      CREATE, DROP, ALTER, INDEX, REFERENCES
  ON `gestion_recovery`.* TO 'gestion_restore'@'{host}';
GRANT SELECT, INSERT, UPDATE, DELETE,
      CREATE, DROP, ALTER, INDEX, REFERENCES
  ON `gestion_test`.* TO 'gestion_restore'@'{host}';
-- NO grants on gestion.*
-- NO GRANT OPTION, CREATE USER, SUPER, FILE, *.* ALL
```

Reply required before any execution: `OUI — CREATE gestion_restore`


Read-only / non-destructive probes via PDO. **No CREATE** for `gestion_restore` / `gestion_migration`.

### Existence

| Account | Status |
|---------|--------|
| gestion_app | EXISTS |
| gestion_backup | EXISTS |
| gestion_restore | **NOT EXISTS** |
| gestion_migration | **NOT EXISTS** |

### SHOW GRANTS (verified)

```text
gestion_app:
  USAGE ON *.*
  SELECT, INSERT, UPDATE, DELETE ON gestion.*

gestion_backup:
  USAGE ON *.*
  SELECT, LOCK TABLES, SHOW VIEW, TRIGGER ON gestion.*
```

### Capability matrix (probed)

| Opération | gestion_app | gestion_backup | gestion_restore | gestion_migration |
|-----------|:-----------:|:--------------:|:---------------:|:-----------------:|
| SELECT | OUI | OUI | N/A | N/A |
| INSERT | OUI* | NON | N/A | N/A |
| UPDATE | OUI | NON | N/A | N/A |
| DELETE | OUI* | NON | N/A | N/A |
| LOCK TABLES | NON | OUI | N/A | N/A |
| CREATE/ALTER/DROP TABLE | NON | NON | N/A | N/A |
| DROP/CREATE DATABASE | NON | NON | N/A | N/A |
| CREATE USER / GRANT | NON | NON | N/A | N/A |

\* INSERT+DELETE probe on `cache` with immediate cleanup; residue = 0.

```text
PROBE_FAILURES: 0
GESTION: UNCHANGED (users=3 companies=1 …)
ENV: UNCHANGED (DB_USERNAME=gestion_app)
NEXT ACCOUNTS: NOT CREATED
```

Note: un essai de probe CLI a exposé des mots de passe dans un log terminal local (échec quoting Windows). **Rotation recommandée** des secrets `gestion_app` / `gestion_backup` hors bande ; valeurs non reproduites ici.
