# PRE-PROD 12.4 — Niane Deployment Preflight & OVH Production Target Validation

**Date :** 2026-08-27  
**Mode :** **PREFLIGHT READ-ONLY** — aucun déploiement, aucune modification serveur / `.env` / MySQL / cron / code applicatif  
**Fichier modifié autorisé :** ce rapport uniquement

---

```text
PRE-PROD 12.4 — NIANE DEPLOYMENT PREFLIGHT

TARGET:
www.niane.mkd-pro.com

LARAVEL ROOT:
~/www/niane

DOCUMENT ROOT:
~/www/niane/public

ABSOLUTE ROOT:
/homez.2212/mkdproq/www/niane   (HUMAN-DECLARED TARGET — NOT SSH-VERIFIED IN THIS SESSION)

ABSOLUTE PUBLIC:
/homez.2212/mkdproq/www/niane/public   (HUMAN-DECLARED TARGET — NOT SSH-VERIFIED IN THIS SESSION)

SERVER:
ssh.cluster100.hosting.ovh.net / cluster100 (PTR 5.135.23.164)
SSH from Cursor: FAIL (Permission denied — no local private key)
User expected: mkdproq

PHP:
PHP/8.4 (HTTP header www.niane.mkd-pro.com — web only)
PHP CLI path/version: UNKNOWN (SSH required)

PHP EXTENSIONS:
UNKNOWN (SSH required)

PHP CA:
UNKNOWN (SSH required) — local WAMP historically NOT_CONFIGURED; OVH unknown

COMPOSER:
UNKNOWN (SSH required)

GIT:
UNKNOWN (SSH required)

NODE:
UNKNOWN (SSH required)

NPM:
UNKNOWN (SSH required)

MYSQL CLI:
UNKNOWN (SSH required)

CRON:
UNKNOWN (SSH required) — mechanism expected available for OVH mutualisé users

SERVER TIMEZONE:
Etc/UTC (HUMAN-DECLARED from prior SSH observation — re-confirm via SSH)

LARAVEL SCHEDULER TIMEZONE:
Africa/Dakar (CONFIRMED in local code: config/app.php)

NIANE DIRECTORY:
LIKELY EMPTY / DECOMMISSIONED (HTTP) — HUMAN DECLARED emptied; HTTPS / and /up return 404 (SSH filesystem listing still UNKNOWN)

MULTISITE ISOLATION:
WARNING → UNKNOWN filesystem (HTTP suggests separate docroots: niane 404 while mkd-pro /up = 200; paths not SSH-proven)

MKD-PRO.COM TOUCHED:
NO

MYSQL MODIFIED:
NO

ENV MODIFIED:
NO

CRON MODIFIED:
NO

CODE MODIFIED:
NO

DEPLOYMENT EXECUTED:
NO
```

---

## 1. Méthode d'audit

| Source | Statut |
|--------|--------|
| SSH authentifié Cursor → `mkdproq@ssh.cluster100.hosting.ovh.net` | **FAIL** — `Permission denied (publickey,password)` |
| Déclarations humaines (cible deployment) | Prises comme **TARGET**, pas comme preuve filesystem |
| Code local MKD-Pro (repo `gestion`) | **Inspecté** (scheduler, process-only, R2 policy) |
| HTTP read-only vers domaines | **Exécuté** |

**Règle appliquée :** aucune hypothèse OVH transformée en certitude. Les chemins absents de preuve SSH = `UNKNOWN`.

---

## 2. Identité serveur (partiel)

| Élément | Valeur | Confiance |
|---------|--------|-----------|
| Hôte SSH | `ssh.cluster100.hosting.ovh.net` | HIGH (PTR + tests TCP antérieurs) |
| IP | 5.135.23.164 | HIGH (DNS) |
| Utilisateur déclaré | `mkdproq` | HUMAN |
| HOME absolu déclaré | `/homez.2212/mkdproq` | HUMAN (dérivé du chemin niane) |
| OS / `uname` | UNKNOWN | — |
| Timezone serveur | `Etc/UTC` (déclaré) | MEDIUM — à reconfirmer |

---

## 3. Chemins cibles (déclarés vs vérifiés)

### 3.1 Cibles exclusives (humain)

```text
NIANE_ROOT   = $HOME/www/niane
             = /homez.2212/mkdproq/www/niane

NIANE_PUBLIC = $HOME/www/niane/public
             = /homez.2212/mkdproq/www/niane/public
```

### 3.2 Vérification SSH cette session

```text
ls -ld ~/www ~/www/niane ~/www/niane/public
readlink -f ~/www/niane
find ~/www/niane ...
```

**NON EXÉCUTÉ** (SSH non authentifié).

```text
PATH_CONFIRMATION = SSH REQUIRED
DOCUMENT_ROOT_CONFIRMATION = OVH PANEL REQUIRED (+ SSH ls)
```

---

## 4. Contenu niane / HTTP

### 4.1 Déclaration humaine

Le dossier `www/niane` a été **vidé volontairement** avant réinstallation.

### 4.2 Observation HTTP (2026-08-27, cette session)

| URL | Résultat |
|-----|----------|
| `https://www.niane.mkd-pro.com/` | **404** |
| `https://www.niane.mkd-pro.com/up` | **404** |
| `https://www.mkd-pro.com/up` | **200** (PHP/8.4, Apache) — site protégé intact |

**Cohérence :** HTTP niane **404** est compatible avec un Document Root vidé / sans `public/index.php`. Le site protégé répond toujours → forte indication de **docroots séparés** (preuve HTTP, pas encore preuve filesystem SSH).

```text
NIANE DIRECTORY (filesystem) = UNKNOWN (SSH ls required)
NIANE HTTP STATE = 404 (decommissioned / empty-looking)
MKD-PRO HTTP STATE = LIVE
```

**Reste à confirmer via SSH :** `find ~/www/niane -maxdepth 2` réellement vide + chemins absolus.

---

## 5. Document root

Architecture cible (sûre) :

```text
~/www/niane/          ← racine Laravel (hors HTTP)
~/www/niane/public/   ← Document Root OVH UNIQUEMENT
```

**Ne doit PAS être :**

```text
~/www
~/www/niane
```

Confirmation panneau OVH : **REQUISE** (multisite → chemin absolu `.../www/niane/public`).

Indices locaux `.ovhconfig` / `.htaccess` : **non inspectés sur serveur** (SSH FAIL).

---

## 6. Protection www.mkd-pro.com

| Check | Résultat |
|-------|----------|
| Commandes exécutées dans le tree mkd-pro.com | **AUCUNE** |
| Lecture `.env` mkd-pro | **NON** |
| Artisan / Composer sur mkd-pro | **NON** |
| HTTP `/up` mkd-pro.com | **200** (observation seule — site intact après 404 niane) |

```text
PROTECTED_SITE = www.mkd-pro.com
PROTECTED_SITE_TOUCHED = NO
```

Isolation : **HTTP PASS partiel** (niane 404 ≠ mkd-pro 200). Isolation filesystem : **UNKNOWN** jusqu'à preuve SSH (`SAME_ROOT=NO`, storage/vendor/`.env` distincts).

---

## 7. PHP / extensions / CA (serveur)

| Item | Statut |
|------|--------|
| PHP web 8.4 | PASS (HTTP) |
| PHP CLI | UNKNOWN |
| Extensions (PDO, pdo_mysql, curl, openssl, mbstring, xml, zip, fileinfo, tokenizer, bcmath, gd) | UNKNOWN |
| `curl.cainfo` / `openssl.cafile` / `openssl.capath` | UNKNOWN |

```text
PHP_EXTENSIONS = UNKNOWN
CA_CONFIGURATION = UNKNOWN
```

**Note R2 :** PRE-PROD 10 a exigé un CA valide ; sans CA CLI OVH, le backup offsite planifié peut échouer (cURL 60). Action humaine post-preflight.

---

## 8. Composer / Git / Node / MySQL CLI / Cron

Tous **UNKNOWN** sans SSH.

Stratégie de déploiement alternative si Composer/Node absents sur OVH (à valider) :

1. Build + `composer install --no-dev` **en local / CI**
2. Déployer un artefact (rsync/FTP/SFTP) vers `~/www/niane` **uniquement**
3. Sur serveur : permissions `storage` / `bootstrap/cache` + `.env` + cron seulement

**Ne pas exécuter** cette stratégie dans PRE-PROD 12.4.

---

## 9. `.env` et credentials backup (emplacements attendus)

| Fichier | Emplacement attendu | HTTP |
|---------|---------------------|------|
| `.env` | `/homez.2212/mkdproq/www/niane/.env` | hors `public/` |
| `.mysql-gestion-backup.local` | `/homez.2212/mkdproq/www/niane/.mysql-gestion-backup.local` (`base_path()` — code PRE-PROD 12.2) | hors `public/` |

```text
ENV_FILE_TARGET = ~/www/niane/.env
BACKUP_CREDENTIAL_FILE = ~/www/niane/.mysql-gestion-backup.local
```

**NON CRÉÉS** dans cette phase. Contenu jamais affiché.

Code : `PrivilegedCredentialLoader::BACKUP_CREDENTIAL_FILE = '.mysql-gestion-backup.local'` → `base_path(...)`.

---

## 10. Timezone

| Couche | Valeur | Source |
|--------|--------|--------|
| SERVER_TIMEZONE | Etc/UTC (déclaré) | humain / SSH antérieur |
| LARAVEL_SCHEDULER_TIMEZONE | **Africa/Dakar** | `config/app.php` + commentaire `routes/console.php` |

**Verdict timezone :** serveur UTC **OK** si Laravel Schedule utilise `config('app.timezone')` = `Africa/Dakar` (confirmé code). Les horaires 02:00 / 03:00 / 04:00 s'interprètent en Dakar.

---

## 11. Scheduler local (PRE-PROD 12.2) — PASS

Fichier `routes/console.php` :

| Heure | Commande | Mutex |
|-------|----------|-------|
| 02:00 | **`backup:production`** (pas `backup:run`) | withoutOverlapping(180) |
| 03:00 | `backup:clean` | withoutOverlapping(120) |
| 04:00 | `backup:monitor` | withoutOverlapping(60) |

```text
SCHEDULER_BACKUP_ENTRY = backup:production
DIRECT_BACKUP_RUN_IN_SCHEDULE = NO
```

---

## 12. Process-only backup — PASS (code local)

Chaîne confirmée :

```text
schedule:run
  → backup:production
  → PrivilegedProcessRunner
  → env: DB_USERNAME=gestion_backup, CACHE_STORE=file, BACKUP_LOCK_CACHE_STORE=file
  → argv: php artisan backup:run  (sans password)
  → PrivilegedCommandGuard exige gestion_backup
```

| Contrôle | Statut |
|----------|--------|
| Mot de passe hors argv | PASS (`assertCommandLineContainsNoSecret`) |
| Credentials via env subprocess | PASS |
| `gestion_app` + `backup:run` direct | BLOQUÉ (guard) |
| Runtime reste `gestion_app` | PASS (parent process) |

**Aucun backup exécuté dans 12.4.**

---

## 13. R2 / SSL (policy code — pas de test OVH)

| Élément | Local `.env.example` | Production attendue (humain, hors phase) |
|---------|----------------------|------------------------------------------|
| `BACKUP_DISKS` | `local` (exemple) | `local,s3` (validé PRE-PROD 10) |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` (exemple) | `true` pour R2 |
| `BACKUP_LOCK_CACHE_STORE` | `file` | `file` |
| Upload R2 cette phase | **NON** | — |

---

## 14. Security layout cible

```text
Document Root     = ~/www/niane/public
.env              = ~/www/niane/.env
.mysql-gestion-backup.local = ~/www/niane/.mysql-gestion-backup.local
storage           = ~/www/niane/storage
vendor            = ~/www/niane/vendor
```

Partage avec `www.mkd-pro.com` : **UNKNOWN** (SSH).

```text
MULTISITE_ISOLATION = UNKNOWN
```

---

## BLOCKERS

1. **P0 — SSH non authentifié depuis Cursor** → chemins, PHP CLI, Composer, cron, permissions, listing niane **non prouvés**.
2. **P0 — Isolation multisite filesystem UNKNOWN** → déploiement bloqué tant que `SAME_ROOT=NO` n'est pas démontré (HTTP seul insuffisant).
3. **P1 — Document Root OVH panel** non confirmé (`DOCUMENT_ROOT_CONFIRMATION = OVH PANEL REQUIRED`).
4. **P1 — Contenu filesystem niane** non listé (HTTP 404 compatible avec vide, mais `find` SSH requis).
5. **P2 — CA PHP CLI OVH** UNKNOWN (risque R2 scheduler).

---

## HUMAN ACTIONS REQUIRED

Avant tout déploiement :

1. Exécuter le script SSH READ-ONLY PRE-PROD 12.3.1 / commandes §2–11 de 12.4 **en session interactive** (`ssh mkdproq@ssh.cluster100.hosting.ovh.net`).
2. Coller la sortie (sans secrets) pour lever `UNKNOWN`.
3. Dans le panneau OVH Multisite : confirmer que `www.niane.mkd-pro.com` → `/homez.2212/mkdproq/www/niane/public`.
4. Confirmer que `www.mkd-pro.com` pointe vers un autre chemin absolu.
5. Réconcilier l'état HTTP live vs dossier déclaré vide.
6. Préparer (hors chat) : `.env` production, comptes MySQL, `.mysql-gestion-backup.local`, secrets R2 — **ne pas les coller ici**.
7. Stabiliser le dépôt Git local (nombreux fichiers non commités) avant artefact de release.

**Ne pas encore :** clone, composer, npm, migrate, cron, backup, toucher mkd-pro.com.

---

## Commandes SSH READ-ONLY recommandées (humain)

```bash
ssh mkdproq@ssh.cluster100.hosting.ovh.net

hostname; whoami; echo "HOME=$HOME"; pwd; uname -a; date
cat /etc/timezone 2>/dev/null

ls -ld "$HOME/www" "$HOME/www/niane" "$HOME/www/niane/public"
readlink -f "$HOME/www/niane"
readlink -f "$HOME/www/niane/public"
find "$HOME/www/niane" -maxdepth 2 -mindepth 1 -print 2>/dev/null | head -200
ls -la "$HOME/www" 2>/dev/null

which php; php -v; php --ini | head -20
php -m | egrep -i '^(PDO|pdo_mysql|curl|openssl|mbstring|xml|zip|fileinfo|tokenizer|bcmath|gd)$'
php -i 2>/dev/null | egrep -i '^(curl\.cainfo|openssl\.cafile|openssl\.capath)\s*=>'

which composer; composer --version 2>/dev/null
which git; git --version
which node; node --version 2>/dev/null
which npm; npm --version 2>/dev/null
which mysql; mysql --version 2>/dev/null
which crontab; crontab -l 2>&1
```

---

## VERDICT

```text
DEPLOYMENT BLOCKED
```

**Motifs :** chemins absolus et isolation filesystem **non confirmés par SSH authentifié** dans cette session ; Document Root panneau OVH non confirmé.  
**Points favorables (non suffisants seuls) :** HTTP niane **404** (cohérent avec vidage) ; mkd-pro **200** intact ; code local scheduler/process-only prêt.

**Code local PRE-PROD 12.2 (scheduler / process-only) :** prêt pour un déploiement **ultérieur** une fois les blockers levés.

---

```text
PRE-PROD 12.4 — AUDIT COMPLETE

NO DEPLOYMENT
NO DATABASE CHANGE
NO ENV CHANGE
NO CRON CHANGE
NO BACKUP
NO RESTORE
NO MIGRATION

Protected site:
www.mkd-pro.com

Target:
www.niane.mkd-pro.com

Laravel root:
~/www/niane

Document root:
~/www/niane/public

STATUS:
DEPLOYMENT BLOCKED

HUMAN REVIEW REQUIRED
```

**STOP** — aucune autre modification effectuée.
