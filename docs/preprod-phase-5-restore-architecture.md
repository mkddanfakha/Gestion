# PRE-PROD 5 — Restore architecture

## Séparation

| Opération | Service | Confirmation | Cible | Fichiers app |
|-----------|---------|--------------|-------|--------------|
| Database restore | `DatabaseRestoreService` | `RESTORE` | explicite allow-list | **jamais** |
| Files restore | `ApplicationFilesRestoreService` | `FILES_RESTORE` | n/a | dry-run only (live disabled) |

`DatabaseRestoreService` n’injecte **pas** le service fichiers.

## Cible explicite

Interdit : inférer depuis `config('database.connections.mysql.database')`, `DB_DATABASE`, `.env`.

API unique : `DatabaseSafetyGuard::assertExplicitRestoreTarget($name)`

- trim only (pas de fuzzy, pas de case-fold)
- identifiant `[A-Za-z][A-Za-z0-9_]*`
- fail-closed allow-list : `gestion_recovery`, `gestion_test`
- `gestion` toujours BLOCK
- `--force` ignoré

## HTTP

`POST admin/backups/{backup}/restore`

Champs obligatoires : `target_database`, `restore_mode=database`, `confirmation_phrase=RESTORE`, `confirm`.

Cible `gestion` → 403.

## Artisan

```text
php artisan db:restore --backup=FILE.zip --target=gestion_recovery --confirmation=RESTORE
```

`--target=gestion` → BLOCK (y compris via `Artisan::call` et `--force`).

## Lock

**Global** (`BackupConcurrencyGuard` restore), pas par target.

Raison : un seul serveur MySQL, DROP/CREATE + import concurrent dangereux même sur des bases différentes ; un restore fichiers dry-run partage le même lock.

TTL restore : 30 minutes. Stale lock expire tout seul.

## Importer

`MysqlPdoDumpImporter` utilise host/user/pass de la config **uniquement** pour la connexion serveur.

Le nom DROP/CREATE est **uniquement** la cible explicite déjà validée.

Dump contenant `DROP DATABASE gestion` → refusé.

## Cutover

Aucun rename `gestion` ↔ `gestion_recovery`. Aucun changement `.env` automatique.
