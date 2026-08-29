# MySQL security boundary

## Defense in depth

```text
CURSOR / DEVELOPER
        │
        ▼
Laravel commands / HTTP / Jobs
        │
        ▼
DatabaseSafetyGuard + DestructiveCommandGuard
        │
        ▼
Application services (Restore / Backup)
        │
        ▼
gestion_app  ──── SELECT/INSERT/UPDATE/DELETE ────► gestion
        │
        ✗ DROP / ALTER / CREATE (denied by MySQL)

MYSQL ROOT (CLI only)
        │
        ├── migrations / maintenance
        ├── emergency recovery
        └── NEVER used by Laravel runtime
```

## Two different protections

| Attack | Laravel | MySQL least-privilege |
|--------|---------|------------------------|
| `Artisan::call('migrate:fresh')` | BLOCKED | would also fail without DDL |
| HTTP restore → `gestion` | BLOCKED | would also fail without DROP on `gestion` |
| `mysql -u root -e "DROP DATABASE gestion"` | N/A | **still possible today** |
| PDO with app credentials + DROP | Laravel may not intercept raw PDO | **blocked only after account split** |

```text
BLOCKED BY LARAVEL  ≠  BLOCKED BY MYSQL PRIVILEGE MODEL
```

Today: first column is largely PASS; second column is **NOT YET IMPLEMENTED**.

## Missing layers (PRE-PROD 9)

1. Non-root runtime account  
2. Separated backup credentials  
3. Restore account scoped away from `gestion`  
4. Optional: OS-level restriction on mysql CLI for developers  

## Offsite / backup

Still required for production readiness (PRE-PROD 3/4) — privilege hardening does not replace offsite copies.
