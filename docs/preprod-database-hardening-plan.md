# PRE-PROD 0 — Database Hardening Plan

**Date :** 2026-08-23  
**Statut :** PROPOSITION — **en attente validation humaine**  
**Aucune implémentation automatique prévue sans accord explicite**

---

## 1. Objectifs

1. Empêcher toute commande destructive sur `gestion` sans confirmation explicite
2. Isoler tests/benchmarks sur `gestion_test` ou SQLite
3. Fiabiliser backups (rétention, offsite, pre-Cursor)
4. Rendre visible l’environnement DB avant toute opération risquée

---

## 2. Priorités

### P0 — CRITIQUE (bloquer immédiatement)

| # | Action | Détail |
| - | ------ | ------ |
| P0-1 | **Liste de bases protégées** | `gestion` (+ éventuellement alias prod) |
| P0-2 | **Garde-fou `migrate:fresh/refresh/reset`, `db:wipe`** | Refus si `DB_DATABASE` protégée |
| P0-3 | **Interdire scripts PHP bootstrap sans check** | Helper `assertDestructiveDatabaseAllowed()` |
| P0-4 | **Supprimer définitivement `measure-rbac-queries.php`** | Déjà absent — règle Cursor : jamais recréer sans DB test |
| P0-5 | **Créer `.env.testing`** | Voir section 3 |
| P0-6 | **Règle agent Cursor** | Interdiction `migrate:fresh`, `db:wipe`, scripts bootstrap destructifs sur `gestion` |

### P1 — IMPORTANT (avant pré-production)

| # | Action | Détail |
| - | ------ | ------ |
| P1-1 | Commande **`php artisan db:safety-check`** | Read-only — voir section 5 |
| P1-2 | Base **`gestion_test`** dédiée | Tous benchmarks / seeds dev destructifs |
| P1-3 | Backup pre-Cursor obligatoire | Script + checklist — section 6 |
| P1-4 | Rétention backup **30 jours** + copie externe | USB / cloud chiffré / second disque |
| P1-5 | Audit dump Spatie | Vérifier que backups contiennent INSERT métier |
| P1-6 | Garde-fou restauration admin | Refuser restore si `DB_DATABASE=gestion` sans token confirmation |
| P1-7 | Documenter `composer setup` | Avertissement migrate --force |

### P2 — AMÉLIORATION

| # | Action | Détail |
| - | ------ | ------ |
| P2-1 | Activer `log_bin` MySQL (local dev optionnel) | PITR si espace disque OK |
| P2-2 | Test restauration backup mensuel | Procédure documentée sur `gestion_test` |
| P2-3 | Hook pre-commit / CI | Détecter `migrate:fresh` dans scripts |
| P2-4 | Aligner `.env.example` sur pratique locale | Commentaires mysql + gestion_test |
| P2-5 | 6 tests sécurité automatisés | Section 7 |

---

## 3. Base de test dédiée

### 3.1 Création (manuelle, post-validation)

```sql
CREATE DATABASE gestion_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 3.2 `.env.testing` proposé

```dotenv
APP_ENV=testing
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=sqlite
DB_DATABASE=:memory:

# Alternative si tests MySQL nécessaires :
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=gestion_test
# DB_USERNAME=root
# DB_PASSWORD=[REDACTED]

CACHE_STORE=array
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
MAIL_MAILER=array
```

**Règle absolue :** jamais `DB_DATABASE=gestion` dans `.env.testing`.

---

## 4. Garde-fou `migrate:fresh` (conception)

### 4.1 Mécanisme proposé

Enregistrer un listener `CommandStarting` ou middleware custom sur commandes :

- `migrate:fresh`
- `migrate:refresh`
- `migrate:reset`
- `db:wipe`

**Si** `config('database.connections.mysql.database')` ∈ liste protégée :

```text
PROTECTED DATABASE

Database: gestion

Destructive command refused.

Use a dedicated test database (gestion_test).
Override only with: --database=gestion_test
Or set ALLOW_DESTRUCTIVE_DB=1 with typed confirmation.
```

### 4.2 Implémentation suggérée (fichiers)

- `config/database-safety.php` — liste protégée, allowlist env
- `app/Console/DatabaseSafetyGuard.php` — listener
- Enregistrement dans `AppServiceProvider` ou `bootstrap/app.php`

### 4.3 Flag escape hatch (dev uniquement)

```dotenv
# .env.local.dev-only — JAMAIS en prod
ALLOW_DESTRUCTIVE_DB=gestion_test
```

Refuser même avec flag si base = `gestion` en prod/preprod.

---

## 5. Commande `db:safety-check` (read-only)

### 5.1 Comportement

```bash
php artisan db:safety-check
```

**Sortie attendue :**

```text
MKD-Pro Database Safety Check

APP_ENV: local
DB_CONNECTION: mysql
DB_HOST: 127.0.0.1
DB_DATABASE: gestion

STATUS: PROTECTED

Destructive database commands: BLOCKED
```

### 5.2 Implémentation

- Lecture `config()` + `DB::connection()->getDatabaseName()` (SELECT 1 seulement)
- **Aucune** migration, seed, truncate
- Option `--json` pour scripts

---

## 6. Backup Safety Strategy

### 6.1 Minimum viable MKD-Pro

| Exigence | Cible |
| -------- | ----- |
| Fréquence | Quotidien 02:00 (existant) + **manuel pre-Cursor** |
| Rétention locale | **30 jours** (vs 7 actuel) |
| Copie externe | Hebdomadaire minimum (OneDrive chiffré / disque USB) |
| Pre-migration | Backup DB obligatoire |
| Pre-phase Cursor risquée | Backup + vérification taille |
| Vérification | `backup:monitor` + alerte si > 24h sans backup |
| Test restauration | Mensuel sur `gestion_test` |

### 6.2 Pre-Cursor Safety Check

```text
PRE-CURSOR SAFETY CHECK
        ↓
php artisan db:safety-check
        ↓
php artisan backup:run --only-db   (sur gestion — lecture seulement dump)
        ↓
Vérifier ZIP / taille > seuil
        ↓
Documenter horodatage
        ↓
Autoriser dev destructif sur gestion_test uniquement
```

### 6.3 Script proposé (post-validation)

`scripts/pre-cursor-check.php` :

- SAFE : vérifie présence backup récent, affiche DB active, **refuse** si opération destructive demandée sur `gestion`
- Ne pas implémenter avant validation

---

## 7. Garde-fou scripts Laravel

### 7.1 Helper proposé

```php
// app/Support/DatabaseSafety.php (conceptuel)
public static function assertNotProtected(string $operation): void
{
    $db = config('database.connections.'.config('database.default').'.database');
    if (in_array($db, config('database-safety.protected', []), true)) {
        throw new RuntimeException(
            "Refusing {$operation} on protected database [{$db}]. Use gestion_test."
        );
    }
}
```

### 7.2 Usage obligatoire

Tout script `scripts/*.php` bootstrap Laravel doit appeler ce helper **avant** tout `Artisan::call` DB.

---

## 8. Protection restauration admin

`BackupController::restoreDatabase()` exécute :

```php
$pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
$pdo->exec("CREATE DATABASE `{$dbName}` ...");
```

**Renforcement proposé :**

1. Vérifier `$dbName` ∉ protected **sauf** mode restore explicite
2. Exiger confirmation textuelle `RESTORE gestion` + backup < 24h
3. Logger audit immuable

---

## 9. Tests de sécurité à implémenter (post-validation)

| # | Test | DB |
| - | ---- | -- |
| T1 | `migrate:fresh` refusé si `DB_DATABASE=gestion` | SQLite ou mock config |
| T2 | `migrate:fresh` autorisé si `DB_DATABASE=gestion_test` | SQLite / MySQL test |
| T3 | Script mesure refuse DB protégée | Unit test helper |
| T4 | Contexte testing ne peut pas résoudre `gestion` | Config test |
| T5 | Benchmark script refuse `APP_ENV=local` + `gestion` | Unit |
| T6 | `db:safety-check` retourne PROTECTED | Feature test |

**Interdit :** exécuter ces tests contre la vraie base `gestion`.

---

## 10. Règles Cursor / développement

Ajouter règle projet (`.cursor/rules` ou AGENTS.md) :

1. Ne jamais exécuter `migrate:fresh`, `db:wipe`, `db:seed` sans validation humaine
2. Ne jamais bootstrap Laravel + destructive sur `gestion`
3. Benchmarks RBAC → `gestion_test` ou SQLite uniquement
4. Pre-Cursor backup obligatoire pour phases touchant DB/tests/scripts

---

## 11. Plan d'implémentation séquentiel

| Phase | Contenu | Prérequis |
| ----- | ------- | --------- |
| H1 | `.env.testing` + doc | Validation humaine |
| H2 | `config/database-safety.php` + listener | H1 |
| H3 | `db:safety-check` | H2 |
| H4 | Tests sécurité (SQLite) | H2-H3 |
| H5 | Backup rétention 30j + procédure pre-Cursor | Validation ops |
| H6 | Garde-fou restore admin | H2 |

---

## 12. Ce qui ne doit PAS être fait sans validation recovery

- Restaurer `gestion` depuis `gestion.sql` ou exports
- Recréer données métier
- Lancer `migrate:fresh` même sur `gestion_test` sans backup
- Modifier `.env` production/local sans snapshot

---

## 13. NEXT STEP

**WAITING FOR HUMAN APPROVAL**

Valider P0 items avant toute implémentation code.
