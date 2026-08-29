# PRE-PROD 0 — Root Cause Analysis

**Date :** 2026-08-23  
**Base concernée :** `gestion` @ `127.0.0.1:3306`  
**Classification globale :** **HIGH CONFIDENCE** (chaîne causale quasi complète, script non versionné)

---

## 1. Executive Summary

> **Pourquoi les données ont-elles disparu ?**

Les données métier de `gestion` ont été détruites par un **`migrate:fresh --force`** exécuté **hors PHPUnit**, via un script temporaire **`scripts/measure-rbac-queries.php`** lancé depuis un terminal Cursor. Ce script bootstrappe Laravel avec **`.env` local** (`DB_DATABASE=gestion`), appelle `Artisan::call('migrate:fresh')`, puis seed/factories RBAC. La corrélation temporelle avec la recréation de la base (users factory `@example.net` à `01:31:41 UTC`) est **extrêmement forte**.

PHPUnit n’est **pas** la cause directe : `phpunit.xml` force SQLite `:memory:`.

---

## 2. Root Cause

**Commande effective (TRÈS PROBABLE — quasi confirmée) :**

```bash
php scripts/measure-rbac-queries.php
```

**Contenu destructif du script (CONFIRMÉ — historique Cursor, jamais commité Git) :**

```php
Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionSeeder', '--force' => true]);
// + User::factory()->create(...) multiples
```

**Exécution documentée :**

| Terminal | Commande | Début (UTC) | Fin (UTC) | Résultat observé |
| -------- | -------- | ----------- | --------- | ---------------- |
| `216654.txt` | `php scripts/measure-rbac-queries.php` | 2026-08-23 01:24:31 | 01:25:18 | JSON `BEFORE_INTRA_CACHE` |
| `216656.txt` | `php artisan test ...AuthorizationCache...`; `php scripts/measure-rbac-queries.php` | 2026-08-23 01:30:53 | 01:31:44 | Tests OK + JSON `AFTER_INTRA_CACHE` |

**État DB post-incident (CONFIRMÉ — forensic précédent) :**

- 75 migrations, **toutes batch 1**
- Tables métier vides
- 3 users `@example.net` créés **2026-08-23 01:31:41–42 UTC**
- 75 permissions seedées **01:31:40 UTC**

---

## 3. Evidence Chain

| Étape | Niveau | Preuve |
| ----- | ------ | ------ |
| Agent Cursor Phase J RBAC cache | **CONFIRMÉ** | Transcript agent `b35a09ad…` : création explicite du script |
| Script contient `migrate:fresh --force` | **CONFIRMÉ** | Contenu `Write` dans transcript (ligne ~4649) |
| Script jamais versionné Git | **CONFIRMÉ** | `git log -S "measure-rbac-queries"` → vide ; fichier absent du repo |
| Exécution terminal Cursor | **CONFIRMÉ** | `terminals/216656.txt`, `216654.txt` |
| Bootstrap Laravel → `.env` → `gestion` | **TRÈS PROBABLE** | `.env` : `APP_ENV=local`, `DB_CONNECTION=mysql`, `DB_DATABASE=gestion` ; script utilise `bootstrap/app.php` |
| `--force` bypass confirmation | **TRÈS PROBABLE** | Flag explicite dans le script |
| `PermissionSeeder` exécuté | **TRÈS PROBABLE** | Appel explicite + 75 permissions à 01:31:40 |
| Factories users créées | **TRÈS PROBABLE** | `User::factory()->create(...)` dans script + users `@example.net` |
| PHPUnit responsable | **NON PROUVÉ / exclu** | `phpunit.xml` : `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` |
| `php -r` ad-hoc avec `migrate:fresh` | **POSSIBLE** | Proposé dans transcript avant création du script ; impact exact non horodaté séparément |

---

## 4. Timeline

| Heure (UTC) | Événement | Confiance |
| ----------- | --------- | --------- |
| Phase J (session RBAC cache) | Agent mesure requêtes SQL RBAC | CONFIRMÉ |
| ~01:24:31 | 1ère exécution `measure-rbac-queries.php` | CONFIRMÉ |
| ~01:25:18 | Fin — label `BEFORE_INTRA_CACHE` | CONFIRMÉ |
| ~01:30:53 | Tests RBAC cache + 2ème exécution script | CONFIRMÉ |
| ~01:31:40 | Permissions RBAC seedées (batch 1) | TRÈS PROBABLE |
| ~01:31:41–42 | 3 users factory `@example.net` | CONFIRMÉ |
| ~01:31:44 | Fin terminal — label `AFTER_INTRA_CACHE` | CONFIRMÉ |
| ~03:31 (locale UTC+2) | Dossier MySQL `data/gestion` modifié | CONFIRMÉ (forensic) |

---

## 5. Chaîne causale

```text
Agent Cursor (Phase J — cache RBAC)
        ↓  CONFIRMÉ
Terminal : php scripts/measure-rbac-queries.php
        ↓  CONFIRMÉ
Bootstrap Laravel (vendor/autoload + bootstrap/app.php)
        ↓  TRÈS PROBABLE
Chargement .env local (APP_ENV=local, DB_DATABASE=gestion)
        ↓  TRÈS PROBABLE
Connexion MySQL 127.0.0.1:3306 → base gestion
        ↓  TRÈS PROBABLE
Artisan::call('migrate:fresh', ['--force' => true])
        ↓  CONFIRMÉ (code script)
DROP ALL TABLES + re-migrate (75 migrations batch 1)
        ↓  TRÈS PROBABLE
Artisan::call('db:seed', PermissionSeeder)
        ↓  TRÈS PROBABLE
User::factory()->create(...) × N
        ↓  TRÈS PROBABLE
Base vide métier + users factory + permissions seed
```

---

## 6. Pourquoi PHPUnit n’a pas isolé

| Facteur | Détail |
| ------- | ------ |
| Script hors PHPUnit | Exécution directe `php scripts/...` charge `.env`, pas `phpunit.xml` |
| Pas de `.env.testing` | Fichier **absent** (`Test-Path .env.testing` → False) |
| Tests RBAC avant script | `216656.txt` : tests passent en SQLite, **puis** script touche MySQL |
| `RefreshDatabase` | Utilisé massivement dans tests, mais limité à SQLite en contexte PHPUnit |

---

## 7. Seeders / factories impliqués

| Composant | Rôle dans l’incident |
| --------- | -------------------- |
| `PermissionSeeder` | Appel explicite post-`migrate:fresh` |
| `User::factory()` | Création users `@example.net` observés |
| `DatabaseSeeder` | **Non appelé** directement par le script |
| `CustomerSeeder` / `ProductSeeder` | **Non appelés** par le script |

---

## 8. Autres exécutions du même script

Le script pouvait être lancé directement :

```bash
php scripts/measure-rbac-queries.php
```

Aucun autre script `scripts/measure-*` présent aujourd’hui. Scripts restants :

- `scripts/verify-expiration-status.ts` — SAFE (TypeScript pur)
- `scripts/detect-lan-ipv4.ts` — SAFE (Node pur)

---

## 9. Classification finale

| Question | Réponse |
| -------- | ------- |
| Cause unique identifiée ? | Oui — script temporaire + bootstrap `.env` |
| Preuve au niveau code versionné ? | Non — script **non commité** |
| Preuve au niveau terminal / transcript ? | Oui — **HIGH CONFIDENCE** |
| Accident vs malveillance | Accident opérationnel (mesure perf Phase J) |

**Verdict : HIGH CONFIDENCE** — en attente de validation humaine avant implémentation des garde-fous.
