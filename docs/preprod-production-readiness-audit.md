# PRE-PROD 4 — Production Readiness Audit

**Date :** 2026-08-24  
**Scope :** Final safety gate (no business features)  
**Base témoin :** `gestion` — READ ONLY pendant l’audit  

---

## Executive Summary

MKD-Pro a un **kill switch destructif réel** (vérifié dans le code + 34 tests isolés) pour `migrate:fresh` / `refresh` / `reset` / `db:wipe` et pour le restore HTTP vers `gestion`.

Il n’est **pas** prêt pour une production métier complète tant que :

1. une **copie offsite** n’est pas configurée ;
2. un **restore drill** vers `gestion_recovery` n’a pas été exécuté et vérifié ;
3. le restore HTTP continue d’écraser des **fichiers applicatifs** dès qu’une base allow-list est ciblée.

**Verdict :** `PRODUCTION READY WITH CONDITIONS`

| Usage | Verdict |
|-------|---------|
| SAFE FOR DEVELOPMENT | **OUI** (avec discipline + SQLite/tests) |
| SAFE FOR STAGING | **OUI AVEC CONDITIONS** (pas de restore HTTP plein-fichier ; offsite recommandé) |
| SAFE FOR PRODUCTION | **NON** tant que offsite + restore drill manquent |
| NOT READY | Non — les gates critiques anti-destruction passent |

---

## Incident Root Cause

Confirmée (confiance élevée) :

```text
scripts/measure-rbac-queries.php (temporaire, non versionné)
  → bootstrap Laravel + .env
  → DB_DATABASE=gestion
  → Artisan::call('migrate:fresh', ['--force' => true])
  → seeders / factories
  → perte des données métier
```

État actuel de `gestion` (lecture seule PRE-PROD 4) : `users=3`, `customers=0`, `products=0`, `sales=0` — **UNCHANGED**.

---

## Current Safety Architecture

| Couche | Mécanisme | Preuve code |
|--------|-----------|-------------|
| Config | `config/database-safety.php` | protected + restore allow-list |
| Guard | `DatabaseSafetyGuard` | exact match `gestion` |
| Artisan wrappers | `$app->extend` Fresh/Refresh/Reset/Wipe | `AppServiceProvider::register` |
| Event | `CommandStarting` → `DestructiveCommandGuard` | + `rerouteSymfonyCommandEvents` |
| Restore HTTP | phrase `RESTORE` + assertSafeForRestore/Drop | `BackupController` |
| Locks | `BackupConcurrencyGuard` | backup 2h / restore 30m |
| Tests | phpunit → SQLite `:memory:` | `phpunit.xml` + `.env.testing` |
| Backup | Spatie 9.3.x local + retention 30/12/12 | `config/backup.php` |
| Status/Verify | `backup:status` / `backup:verify` | inspecteur SHA-256 |

---

## Database Protection

| Commande | Sur `gestion` | Preuve |
|----------|---------------|--------|
| migrate:fresh | BLOCKED | wrappers + tests |
| migrate:refresh | BLOCKED | idem |
| migrate:reset | BLOCKED | idem |
| db:wipe | BLOCKED | idem |
| Artisan::call mêmes | BLOCKED | tests |
| APP_ENV local/testing/production | BLOCK indépendant de l’env | tests |

**Non couvert (WARNING) :**

- `php artisan migrate` (non-fresh) — peut altérer le schéma
- `db:seed` — non bloqué
- `composer setup` → `migrate --force` — WARNING opérationnel
- outils externes (`mysql` CLI, phpMyAdmin) — hors Laravel

---

## Restore Protection

| Cible | Restore app | DROP DATABASE app |
|-------|-------------|-------------------|
| `gestion` | BLOCKED | BLOCKED |
| `gestion_recovery` | ALLOWED (si config mysql pointe dessus) | ALLOWED |
| `gestion_test` | ALLOWED | ALLOWED |
| autre | FAIL-CLOSED | FAIL-CLOSED |

Confirmation backend obligatoire : phrase exacte `RESTORE`.  
Lock concurrent : OUI.

**Risques restants (CRITICAL / WARNING) :**

1. **CRITICAL (opérationnel)** — aucune sélection UI de la base cible : le restore utilise `config('database.connections.mysql.database')`. Pour restaurer dans `gestion_recovery`, il faut **changer la config/connexion** (humain). Risque d’erreur humaine si quelqu’un pointe temporairement `gestion` puis… non, `gestion` reste bloquée. Mais pour recovery, bascule `.env` = fragile.
2. **CRITICAL (surface)** — même sur allow-list, `restoreFiles()` écrase le code applicatif sous `base_path()` (`.env` exclu). Un restore « recovery DB » peut endommager l’app live.
3. **WARNING** — `proc_open` / password en ligne de commande pour gros dumps.
4. **WARNING** — interpolation `` `{$dbName}` `` après allow-list (pas d’échappement identifiant strict).

---

## Test Isolation

| Élément | Valeur vérifiée |
|---------|-----------------|
| `phpunit.xml` | `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` |
| `.env.testing` | sqlite `:memory:`, jamais `gestion` |
| Test nommé | `ProductionDatabaseMustNeverBeUsedByTests` |

---

## Migration Risk

| Classe | Exemples | Note |
|--------|----------|------|
| REVERSIBLE | `dropColumn` dans `down()` | Normal |
| DATA-BEARING | `UPDATE customers SET credit_limit…` | Historique, déjà joué |
| DESTRUCTIVE | `drop_credit_limit_from_customers` | Perte colonne si re-run |
| HIGH-RISK | aucune `DROP TABLE` dans `up()` hors creates | `down()` dropIfExists standard |

Ne pas réécrire les migrations historiques.

---

## Script Risk

| Script | Classe |
|--------|--------|
| `scripts/verify-expiration-status.ts` | READ_ONLY / SAFE_TEST |
| `scripts/detect-lan-ipv4.ts` | READ_ONLY |
| `scripts/measure-rbac-queries.php` | **ABSENT** (cause racine, non versionné) — FORBIDDEN pattern |

Règle : tout script de mesure doit cibler explicitement une base de test — jamais `.env` métier implicite.

---

## Backup Strategy

| Item | État |
|------|------|
| Package | spatie/laravel-backup ^9.3 |
| Destination | `local` uniquement |
| Retention | 30 / 30 / 12 / 12 — **confirmé dans config chargée via backup:status** |
| Exclusion `.env` | OUI (futurs backups) |
| Archives locales | 2 ZIP 2025-11-28 — SQL minime + CAUTION_CONTAINS_DOTENV |
| Schedule | 02:00 / 03:00 / 04:00 |
| Vérification | `backup:verify` SHA-256 |

---

## Offsite Status

```text
OFFSITE BACKUP = REQUIRED BEFORE REAL PRODUCTION
STATUS: NOT CONFIGURED
```

Manque : disque S3-compatible (OVH Object Storage / B2 / AWS) + secrets `.env` + `backup.destination.disks`.

---

## Restore Strategy

Procédure documentée (`docs/preprod-restore-procedure.md`) :

```text
backup → verify → create gestion_recovery (humain) → restore → integrity → smoke → validation humaine → cutover manuel
```

Cutover automatique : **interdit**.  
Restore drill réel : **NOT TESTED** (volontairement en PRE-PROD 4).

---

## Monitoring

| Signal | Statut |
|--------|--------|
| backup age (Spatie monitor) | PARTIALLY MONITORED (notifs Spatie via vides) |
| backup:status | MONITORED (CLI) |
| backup failed log | PARTIALLY MONITORED |
| offsite | NOT MONITORED |
| restore failed | PARTIALLY (logs) |
| disk full | NOT MONITORED |
| queue failed | PARTIALLY (Laravel failed_jobs) |
| DB inaccessible | NOT MONITORED (pas d’alerting dédié) |

---

## RPO / RTO

| Métrique | Cible | Classification |
|----------|-------|----------------|
| RPO ≤ 24h | documentée | **DOCUMENTED, NOT VERIFIED** |
| RTO ≤ 2–4h | documentée | **DOCUMENTED, NOT VERIFIED** |

Dernier ZIP local : 2025-11-28 → RPO réel historique **non respecté**.

---

## Git Security

- `.env` gitignoré — non listé comme tracked
- Modifications PRE-PROD nombreuses (guards, docs, build assets)
- `.env.testing` untracked (valeurs dummy OK)
- Aucun dump SQL / credentials AWS commités détectés par scan de noms
- Ne pas committer `.env` ni archives `storage/app/private`

---

## Test Results

```text
tests/Unit/Infrastructure → PASS (incl. nouveaux cas production + ProductionDatabaseMustNeverBeUsedByTests)
composer validate → OK
```

---

## Remaining Risks

1. Pas d’offsite  
2. Pas de restore drill MySQL  
3. Restore HTTP = fichiers app + DB  
4. Pas de sélecteur de base recovery dans l’UI  
5. `migrate` / `db:seed` / `composer setup` non couverts par le kill switch wipe  
6. Contournement hors Laravel (CLI mysql) toujours possible  
7. Backups locaux obsolètes / incomplets pour données août 2026  
8. Alerting Spatie désactivé  

---

## Production Blockers

Bloquants pour **PRODUCTION READY** (plein) :

- OFFSITE NOT CONFIGURED  
- RESTORE DRILL NOT TESTED  
- Restore files overwrite (doit être séparé DB-only pour recovery)

Non bloquants pour **DEVELOPMENT** si kill switch actif.

---

## Recommended Next Steps

1. Configurer offsite S3/OVH (humain, credentials)  
2. Ajouter mode restore **DB-only** + cible explicite `gestion_recovery`  
3. Créer `gestion_recovery` + drill documenté  
4. Activer alerting backup (mail/Slack)  
5. Commit PRE-PROD 1–4 sans secrets / sans dumps  
6. Interdire `composer setup` sur machine pointant `gestion`  

---

## Critical Gates (score)

| Gate | Résultat |
|------|----------|
| 1 Destroy via fresh/refresh/reset/wipe | **PASS** |
| 2 DROP gestion via restore HTTP | **PASS** |
| 3 Tests utilisant gestion | **PASS** |
| 4 Script auto destructif versionné | **PASS** (script incident absent) |
| 5 Secrets commités | **PASS** |
| 6 Backup totalement absent | **PASS** (local présent, qualité limitée) |
| 7 Restore non protégé | **PASS** (protégé ; limitations opérationnelles restantes) |
