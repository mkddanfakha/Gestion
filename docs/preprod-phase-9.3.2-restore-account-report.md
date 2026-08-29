# PRE-PROD 9.3.2 — Restore Account Report

**Date :** 2026-08-25  
**Approval :** `OUI — CREATE gestion_restore`  
**.env :** UNCHANGED (`gestion_app`)  
**gestion :** UNCHANGED

---

```text
PRE-PROD 9.3.2 — RESTORE ACCOUNT

ACCOUNT:
gestion_restore

CREATED:
YES

HOSTS:
localhost
127.0.0.1

ALLOWED DATABASES:
gestion_recovery
gestion_test

GESTION:
BLOCKED (no grants on gestion.*)

GLOBAL PRIVILEGES:
NONE (USAGE only on *.*)

DANGEROUS PRIVILEGES:
NONE
(no GRANT OPTION, CREATE USER, SUPER, FILE, ALL on *.*)

RESTORE CAPABILITY (MySQL probes):
PASS on allow-list
- CREATE/DROP/ALTER/INDEX/DML on gestion_recovery.*
- CREATE DATABASE / DROP DATABASE gestion_test
- CREATE DATABASE IF NOT EXISTS gestion_recovery
- DROP DATABASE on non-allow-listed names: DENIED
- DROP DATABASE gestion: DENIED

FULL Artisan db:restore:
NOT RUN (would require wiring credentials outside DB_USERNAME; .env unchanged by design)

PASSWORD:
STORED OUTSIDE GIT (.mysql-gestion-restore.local)

STATUS:
VERIFIED
```

---

## SHOW GRANTS

```text
USAGE ON *.*
SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER
  ON gestion_recovery.*
  ON gestion_test.*
```

## Denied on métier / admin

| Opération | Result |
|-----------|--------|
| SELECT/INSERT/DDL on `gestion.*` | DENIED |
| DROP DATABASE `gestion` | DENIED |
| CREATE DATABASE (random) | DENIED |
| CREATE USER / GRANT | DENIED |

## Integrity

```text
gestion users=3 companies=1 …
gestion_recovery tables preserved (27)
gestion_test absent after probe cleanup
ENV DB_USERNAME=gestion_app
```

## STOP

`gestion_migration` **not** created.

```text
Reply required for next account:
"OUI — CREATE gestion_migration"
```
