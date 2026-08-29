# PRE-PROD 5 — Phase 0 Restore Audit (avant modification)

**Date :** 2026-08-24  
**Règle :** ce document a été produit **avant** les changements d’architecture DB-only.  
**Base `gestion` :** non modifiée pendant l’audit (lecture de code uniquement).

---

## 1. Comment une restauration est déclenchée

| Canal | Route / commande | Entrée |
|-------|------------------|--------|
| HTTP | `POST /admin/backups/{backup}/restore` → `BackupController::restore` | `confirm`, `confirmation_phrase` |
| UI | `resources/js/pages/Admin/Backups/Index.vue` `confirmRestore()` | phrase `RESTORE` uniquement |
| Artisan restore DB | **AUCUNE** commande `db:restore` | n/a |
| Job restore | **AUCUN** | n/a |
| Schedule | `backup:run` / `clean` / `monitor` uniquement | pas de restore |

RBAC : permission `backups.restore` via `checkPermission`.

---

## 2. Comment la base cible est déterminée (FAIL actuel)

Dans `executeRestore()` :

```php
$mysqlDatabase = config('database.connections.mysql.database');
DatabaseSafetyGuard::assertSafeForRestore($mysqlDatabase);
```

Dans `restoreDatabase()` :

```php
$dbName = $dbConfig['database']; // config mysql / DB_DATABASE / .env
```

**La cible n’est pas fournie par l’utilisateur.** Elle est **implicite**.

Conséquences :

- Si `.env` a `DB_DATABASE=gestion` → restore **BLOCKED** (PRE-PROD 2) — correct pour protéger `gestion`.
- Pour restaurer dans `gestion_recovery`, il faudrait **changer** `DB_DATABASE` / config mysql → **interdit** par PRE-PROD 5.
- `assertSafeForRestore(null)` retombe encore sur la config mysql (contournement conceptuel).

---

## 3. Comment les fichiers sont restaurés

`executeRestore()` après dump SQL :

1. Extraire **tout** le ZIP dans `storage/app/restore-temp/...`
2. `restoreDatabase($dbDumpPath)`
3. **`restoreFiles($tempDir, base_path())`** — copie vers le code live
4. `Artisan::call(config/cache/view/route:clear)`

`restoreFiles()` exclut `.env`, `storage/logs`, backup-temp.  
**N’exclut pas** `app/`, `config/`, `resources/`, `public/`, `routes/`, `composer.json`.

---

## 4. Chemins qui peuvent écraser des données

| Chemin | Cible | Sévérité |
|--------|-------|----------|
| `DROP DATABASE IF EXISTS \`{$dbName}\`` | base mysql **configurée** | CRITICAL si allow-list + mauvais .env (mitigé si cible=`gestion`) |
| Import SQL PDO / `mysql.exe` | même base | CRITICAL |
| `restoreFiles(..., base_path())` | **fichiers applicatifs live** | CRITICAL même si DB = recovery |
| `deleteDirectory(temp)` | temp restore seulement | SAFE |
| Import ZIP (`import`) | stockage backups | WARNING (pas DB) |

---

## 5. Contrôles existants

| Contrôle | Couverture | Contournable ? |
|----------|------------|----------------|
| Allow-list `gestion_recovery`, `gestion_test` | DROP/restore si nom = config mysql | Oui : changer `.env` |
| Phrase `RESTORE` | HTTP | Non suffisant seul |
| RBAC `backups.restore` | HTTP | Admin peut toujours lancer le flux fusionné |
| Lock global `BackupConcurrencyGuard` | HTTP + job backup | TTL 30 min restore |
| `--force` migrate | kill switch P1 | N/A restore |
| Cible HTTP explicite | **ABSENT** | — |
| Mode DB-only | **ABSENT** | files toujours après DB |
| Commande Artisan restore | **ABSENTE** | scripts pourraient PDO direct |

---

## 6. Routes concernées

```text
GET    admin/backups                  index
POST   admin/backups                  store (backup)
DELETE admin/backups/{backup}         destroy
GET    admin/backups/{backup}/download
POST   admin/backups/{backup}/restore  ← DB + FILES fusionnés
POST   admin/backups/import
```

---

## 7. Commandes Artisan concernées

| Commande | Restore ? |
|----------|-----------|
| `backup:run` / `clean` / `monitor` / `status` / `verify` | Non |
| `db:safety-check` | Lecture |
| `db:restore` | **N’existe pas** |

`Artisan::call('backup:run')` dans `BackupController` / `CreateBackupJob` : backup, pas restore.

---

## 8. Scripts

`scripts/` : TypeScript only (`verify-expiration-status.ts`, `detect-lan-ipv4.ts`). Aucun restore PHP.

---

## 9. Verdict Phase 0

```text
RESTORE TARGET: IMPLICIT (config mysql)
FILE RESTORE: COUPLED TO DB RESTORE
GESTION DROP: BLOCKED when config name is gestion
RECOVERY WITHOUT .env CHANGE: IMPOSSIBLE
PRE-PROD 5 CODE CHANGES: REQUIRED
```
