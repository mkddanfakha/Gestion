# PRE-PROD 2 — Restore Security Policy

**Date:** 2026-08-23

---

## Rules

1. **Never** `DROP DATABASE gestion` from the application.
2. Application admin RBAC (`backups.restore`) is **not** sufficient for destroying a protected business database.
3. Restore that uses DROP/CREATE is allowed **only** for exact allow-listed names:
   - `gestion_recovery`
   - `gestion_test`
4. Unknown database names are **fail-closed** (blocked).
5. Backend requires `confirmation_phrase === RESTORE` (frontend checkbox alone is insufficient).
6. Exact name matching only (`gestion_recovery` ≠ `gestion`).

---

## Answers (restore audit)

| # | Question | Answer |
| - | -------- | ------ |
| 1 | Who can trigger restore? | Authenticated user with `backups.restore` via AuthorizationService |
| 2 | Route? | `POST admin/backups/{backup}/restore` |
| 3 | CLI command? | None dedicated; no Artisan restore command |
| 4 | RBAC? | `backups.restore` (PermissionCatalog / PermissionName) |
| 5 | Backend confirmation? | **Yes (P2):** `confirm` + `confirmation_phrase=RESTORE` |
| 6 | CSRF? | Yes (web middleware) |
| 7 | Uses DROP DATABASE? | Yes historically; now gated |
| 8 | Touches connected DB? | Yes — uses `config('database.connections.mysql.database')` |
| 9 | Via CLI? | Not as first-class command |
| 10 | Via job? | No restore job found |
| 11 | Multiple times? | Yes possible if allowed DB |
| 12 | Concurrency lock? | **No** (remaining risk) |
| 13 | Audit log? | Log channels `database.restore_blocked` / restore start; ActivityLogger restore events recommended for PRE-PROD 3 |
| 14 | Pre-restore backup? | **No** automatic (remaining risk) |
| 15 | Failed restore partial state? | **Yes risk** on allow-listed DB after DROP before import completes |

---

## Two-step recovery (recommended, not auto-switched)

```text
1. Point a dedicated env / ops procedure at gestion_recovery
2. Restore backup into gestion_recovery
3. Validate counts / spot-check
4. Human-approved cutover (outside silent app DROP of gestion)
```

Automatic silent cutover onto `gestion` remains **forbidden**.

---

## Config

```env
DB_PROTECTED_DATABASES=gestion
DB_RESTORE_ALLOWED_DATABASES=gestion_recovery,gestion_test
DB_RESTORE_CONFIRMATION_PHRASE=RESTORE
```
