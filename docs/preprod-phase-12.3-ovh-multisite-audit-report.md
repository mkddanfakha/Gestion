# PRE-PROD 12.3 — Audit OVH Multisite `niane.mkd-pro.com`

**Date :** 2026-08-27  
**Mode :** **AUDIT READ-ONLY STRICT** — aucune modification serveur, `.env`, MySQL, cron, backup, migration

---

```text
========================================
PRE-PROD 12.3 — OVH MULTISITE AUDIT
========================================

TARGET:
www.niane.mkd-pro.com

PROTECTED:
www.mkd-pro.com

READ-ONLY AUDIT:
FAIL (PARTIAL — SSH non authentifié)

MULTISITE ISOLATION:
UNKNOWN

SSH:
FAIL

PHP:
WARNING (HTTP 8.4 confirmé ; CLI/extensions/CA non audités sur serveur)

COMPOSER:
UNKNOWN

LARAVEL:
WARNING (niane déjà déployé ; chemins serveur inconnus)

MYSQL:
UNKNOWN

STORAGE:
WARNING (niane OK HTTP ; mkd-pro.com expositions détectées)

R2 COMPATIBILITY:
UNKNOWN (extensions serveur non vérifiées ; HTTPS OK)

CRON:
UNKNOWN

CA:
UNKNOWN

SECRETS:
WARNING

GIT:
WARNING

DEPLOYMENT READY:
NO

DATABASE MODIFIED:
NO

FILES MODIFIED:
REPORT ONLY

ENV MODIFIED:
NO

MYSQL MODIFIED:
NO

CRON MODIFIED:
NO

BACKUP EXECUTED:
NO

RESTORE EXECUTED:
NO

MIGRATION EXECUTED:
NO

SEED EXECUTED:
NO
```

---

## 1. Résumé exécutif

L'audit a été exécuté depuis le poste de développement Windows avec :

- résolution DNS ;
- sondes HTTP/HTTPS read-only ;
- tentatives SSH non destructives (BatchMode, sans mot de passe) ;
- audit du dépôt local Laravel.

**Blocage principal :** aucune authentification SSH valide n'est disponible dans cet environnement (`Permission denied (publickey,password)` sur les hôtes OVH). **Aucune commande serveur n'a pu être exécutée.**

Conséquence : les chemins absolus multisite, PHP CLI, Composer, MySQL, cron et permissions disque **ne sont pas prouvés**.

**Constats distants importants :**

| Fait | Détail |
|------|--------|
| Même hébergement physique | `www.niane.mkd-pro.com` et `www.mkd-pro.com` → **5.135.23.164** / **2001:41d0:301::100** |
| PTR | **cluster100.hosting.ovh.net** |
| SSH OVH | Hôtes joignables : `ssh.cluster100.hosting.ovh.net`, `ssh.cluster031.hosting.ovh.net` |
| PHP web | **PHP/8.4** (header `X-Powered-By`) sur les deux sites |
| niane | Laravel/Inertia actif, `/up` → **200 Application up**, cookie `gestion_session` |
| mkd-pro.com | Application distincte, cookie `mkd-pro-session`, `/up` → **200** |
| niane sécurité HTTP | `/.env` **403**, `/storage/` **403**, `/vendor/` **404** |
| mkd-pro.com (ZONE PROTÉGÉE) | `/vendor/` **200**, `/storage/` **200** — **ne pas modifier** ; risque docroot différent |

**Verdict :** déploiement **NON PRÊT** tant qu'un audit SSH authentifié read-only n'a pas cartographié les chemins et prouvé l'isolation filesystem.

---

## 2. Serveur OVH (Phase A)

### 2.1 Identifié à distance

| Élément | Valeur | Source |
|---------|--------|--------|
| IP publique | 5.135.23.164 | DNS A |
| IPv6 | 2001:41d0:301::100 | DNS AAAA |
| PTR / cluster | cluster100.hosting.ovh.net | Reverse DNS |
| Serveur HTTP | Apache | Header `Server` |
| PHP web | 8.4 | Header `X-Powered-By` |
| Hôte SSH OVH (candidats) | ssh.cluster100.hosting.ovh.net | PTR + test TCP |
| | ssh.cluster031.hosting.ovh.net | Test TCP (répond aussi) |

### 2.2 Non identifiable sans SSH

| Élément | Statut |
|---------|--------|
| hostname exact | UNKNOWN |
| OS / architecture | UNKNOWN |
| utilisateur SSH courant | Tentative `dmoha` → refusée |
| HOME / PWD | UNKNOWN |
| PHP CLI + chemin | UNKNOWN |
| Composer / Git / Node | UNKNOWN |
| MySQL client | UNKNOWN |
| espace disque / mémoire | UNKNOWN |

### 2.3 Tentatives SSH (read-only, non destructives)

```text
ssh -o BatchMode=yes 5.135.23.164           → timeout port 22
ssh -o BatchMode=yes dmoha@ssh.cluster100.hosting.ovh.net  → Permission denied
ssh -o BatchMode=yes dmoha@ssh.cluster031.hosting.ovh.net  → Permission denied
```

**Aucun mot de passe saisi. Aucune clé privée disponible localement (`~/.ssh/config` absent).**

`REQUIRES HUMAN APPROVAL` : fournir accès SSH read-only (clé ou session interactive) pour compléter l'audit.

---

## 3. Cartographie multisite (Phase B)

### 3.1 DNS

| Domaine | Résolution |
|---------|------------|
| www.niane.mkd-pro.com | 5.135.23.164 |
| niane.mkd-pro.com | 5.135.23.164 |
| www.mkd-pro.com | 5.135.23.164 |
| mkd-pro.com | 5.135.23.164 |
| ftp.mkd-pro.com | CNAME → mkd-pro.com |

**Conclusion :** multisites sur **un même compte / cluster OVH mutualisé**.

### 3.2 Chemins filesystem (UNKNOWN — SSH requis)

```text
OVH cluster100.hosting.ovh.net
├── www.mkd-pro.com
│   └── chemin réel : UNKNOWN (SSH requis)
│       └── ZONE PROTÉGÉE — NE PAS MODIFIER
│
└── www.niane.mkd-pro.com
    └── chemin réel : UNKNOWN (SSH requis)
        └── CIBLE DÉPLOIEMENT MKD-Pro
```

Structure OVH **typique** (non confirmée) :

```text
/home/<login>/
├── www/                          → site principal possible
├── niane.mkd-pro.com/            → multisite possible
│   └── public/                   → document root cible Laravel
└── www.mkd-pro.com/ ou www/      → autre site (mkd-pro.com)
```

**Ne pas supposer** — à confirmer via SSH :

```bash
# READ-ONLY — à exécuter par l'humain avec approbation
pwd
echo "$HOME"
ls -la ~
ls -la ~/www 2>/dev/null
find ~ -maxdepth 3 -type d \( -name '*niane*' -o -name '*mkd-pro*' \) 2>/dev/null
```

---

## 4. Protection explicite www.mkd-pro.com (Phase C)

### 4.1 Preuves HTTP (sans SSH)

| Critère | niane.mkd-pro.com | www.mkd-pro.com |
|---------|-------------------|-----------------|
| Application | Laravel/Inertia (MKD-Pro gestion) | Application Laravel distincte |
| Cookie session | `gestion_session` | `mkd-pro-session` |
| `/up` health | 200 | 200 |
| `/.env` | **403** | **403** |
| `/storage/` | **403** | **200** ⚠️ |
| `/vendor/` | **404** | **200** ⚠️ |
| Manifest build | Présent (build différent du repo local) | N/A (app différente) |

### 4.2 Analyse isolation

| Question | Réponse |
|----------|---------|
| Chemins absolus connus ? | **NON** |
| Séparation physique prouvée ? | **NON** (même IP/cluster) |
| Applications distinctes ? | **OUI** (HTTP : sessions, assets, contenu) |
| Partage `.env` / `storage` / `vendor` ? | **NON PROUVÉ** filesystem ; HTTP suggère docroots différents |
| Partage base de données ? | **NON PROUVÉ** |
| Déploiement niane peut-il toucher mkd-pro.com ? | **NON DÉMONTRABLE** sans chemins absolus |

```text
MULTISITE ISOLATION: UNKNOWN
BLOCKED — MULTISITE ISOLATION NOT PROVEN (filesystem)
```

Tant que les chemins absolus ne sont pas cartographiés, **toute commande de déploiement doit être considérée à risque** pour `www.mkd-pro.com`.

---

## 5. Audit projet MKD-Pro local (Phase D)

**Emplacement code source (développement) :** `C:\Users\dmoha\Documents\laravelia\gestion`

| Élément | Statut |
|---------|--------|
| `artisan` | PRESENT |
| `composer.json` / `composer.lock` | PRESENT |
| `package.json` / `vite.config.ts` | PRESENT |
| `public/index.php` | PRESENT |
| `storage/` | PRESENT |
| `bootstrap/` | PRESENT |
| `config/` | PRESENT |
| `routes/` | PRESENT |
| `vendor/` | PRESENT |

**Architecture Laravel recommandée OVH (compatible multisite) :**

```text
Type B — racine projet HORS document root

/home/<login>/niane.mkd-pro.com/     ← racine Laravel (privée)
├── app/
├── bootstrap/
├── config/
├── storage/
├── vendor/
├── .env                               ← hors web
└── public/                            ← DOCUMENT ROOT OVH
    └── index.php
```

**Preuve HTTP :** niane se comporte comme un docroot `public/` correct (vendor/storage non servis).

**État distant :** une version MKD-Pro est **déjà en ligne** sur niane (manifest build différent du workspace local). Le prochain déploiement sera une **mise à jour**, pas une installation vierge.

---

## 6. PHP (Phase E)

### 6.1 Serveur OVH (partiel)

| Élément | Statut |
|---------|--------|
| PHP web | **8.4** (confirmé HTTP) |
| PHP CLI | UNKNOWN |
| Extensions (PDO, OpenSSL, cURL, ZIP, etc.) | UNKNOWN |
| `curl.cainfo` / `openssl.cafile` | UNKNOWN |

### 6.2 Poste dev local (référence — non production)

| Extension | Local |
|-----------|-------|
| pdo_mysql, openssl, curl, zip, mbstring, xml, fileinfo, gd, bcmath | PRESENT |
| curl.cainfo | **vide** |
| openssl.cafile | **vide** |

**CA : WARNING** — en PRE-PROD 10, un bundle Mozilla process-only était requis pour R2. Vérifier sur OVH CLI avant premier backup offsite.

---

## 7. SSH / Composer / Artisan (Phase F)

| Capacité | Serveur OVH | Local |
|----------|-------------|-------|
| `php artisan` | UNKNOWN | OK |
| `composer install` | UNKNOWN | OK |
| `php artisan schedule:run` | UNKNOWN (cron aussi) | OK |
| `php artisan migrate` | **NON EXÉCUTÉ** | — |
| `backup:production` | **NON EXÉCUTÉ** | Implémenté PRE-PROD 12.2 |

---

## 8. Base de données production (Phase G)

| Information | Statut |
|-------------|--------|
| Nom base production OVH | UNKNOWN (probable `gestion` — policy projet) |
| Hostname MySQL OVH | UNKNOWN (typique `<login>.mysql.db` ou `cluster100.hosting.ovh.net`) |
| Port | UNKNOWN (3306 probable) |
| Utilisateur runtime attendu | `gestion_app` (policy) |
| Base déjà créée | UNKNOWN |

```text
SECRET REQUIRED — CONFIGURATION HUMAN REQUIRED
```

Aucun mot de passe lu, testé ou affiché.

---

## 9. Comptes MySQL (Phase H) — policy uniquement

Architecture cible **inchangée** (aucune vérification GRANT sur OVH) :

| Compte | Rôle |
|--------|------|
| `gestion_app` | Runtime CRUD |
| `gestion_backup` | Backup process-only |
| `gestion_restore` | Restore allow-list |
| `gestion_migration` | Migrations contrôlées |

**Aucun compte créé. Aucun GRANT modifié.**

---

## 10. Secrets (Phase I)

### 10.1 Emplacements prévus sur OVH (recommandation)

| Secret | Emplacement recommandé | Exposition |
|--------|-------------------------|------------|
| `.env` production | Racine Laravel (**hors** `public/`) | GITIGNORED |
| `.mysql-gestion-backup.local` | Racine Laravel, mode **600** | GITIGNORED |
| Credentials R2 | `.env` uniquement | GITIGNORED |
| SMTP | `.env` | GITIGNORED |

### 10.2 Vérifications distantes niane

| Chemin HTTP | Statut | Verdict |
|-------------|--------|---------|
| `/.env` | 403 | PASS (non public) |
| `/storage/` | 403 | PASS |
| `/vendor/` | 404 | PASS |

**SECRETS : WARNING** — bonnes pratiques HTTP sur niane ; audit filesystem/cron non fait.

---

## 11. Storage (Phase J)

### 11.1 Architecture code (local)

```text
storage/
├── app/
│   └── private/          ← backups locaux Spatie (disk local)
├── logs/
└── framework/
```

Config : `config/filesystems.php` → disk `local` root = `storage/app/private`, `serve=false`.

### 11.2 Serveur niane

- `/storage/` HTTP → **403** : les ZIP backup ne sont **pas** exposés publiquement via ce chemin.
- Backups locaux attendus : `storage/app/private/<APP_NAME>/` (hors web).

### 11.3 Risque mkd-pro.com (ZONE PROTÉGÉE — observation seule)

- `/storage/` HTTP → **200** sur www.mkd-pro.com
- **Ne pas modifier** ce site ; documenter comme risque sur l'autre multisite, pas sur niane.

---

## 12. R2 (Phase K)

| Prérequis | Serveur OVH |
|-----------|---------------|
| HTTPS | OK (sites en HTTPS) |
| cURL / OpenSSL | UNKNOWN (CLI) |
| CA bundle PHP | UNKNOWN |
| Flysystem S3 / R2 | Compatible côté code (PRE-PROD 10 validé en local) |

**Aucun upload, aucun backup exécuté.**

---

## 13. Scheduler / Cron (Phase L)

| Élément | Statut |
|---------|--------|
| Cron OVH configuré | UNKNOWN |
| crontab lu | **NON** (SSH requis) |
| Chemin PHP CLI absolu | UNKNOWN |
| Chemin projet absolu | UNKNOWN |

### Commande proposée (phase ultérieure — NON INSTALLÉE)

```cron
* * * * * cd /CHEMIN/ABSOLU/VERS/PROJET-NIANE && /CHEMIN/ABSOLU/VERS/PHP artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

Architecture cible (PRE-PROD 12.2) :

```text
OVH CRON → schedule:run (gestion_app)
         → backup:production → subprocess gestion_backup → local + R2
```

**CRON MODIFIED : NO**

---

## 14. Domain / Document root (Phase M)

| Domaine | Document root confirmé | Preuve |
|---------|------------------------|--------|
| www.niane.mkd-pro.com | **Probablement `.../public`** | vendor 404, storage 403, Laravel /up OK |
| www.mkd-pro.com | **UNKNOWN** (possible racine projet) | vendor 200, storage 200 |

**Recommandation :** conserver / imposer docroot = `public/` pour niane uniquement.

**Aucune modification effectuée.**

---

## 15. Isolation www.mkd-pro.com (Phase N)

**Question :** une future commande sur niane peut-elle modifier mkd-pro.com ?

**Réponse audit :** **NON DÉMONTRABLE** sans chemins absolus et sans SSH.

**Mesures obligatoires avant déploiement :**

1. Cartographier les deux chemins absolus.
2. Vérifier qu'ils ne partagent pas le même répertoire parent writable par erreur.
3. Interdire tout script deploy global (`~/www/` sans cible explicite).
4. Utiliser `cd` explicite vers le chemin niane **uniquement**.
5. Ne jamais exécuter `git`, `composer`, `artisan` depuis le répertoire mkd-pro.com.

---

## 16. Git / déploiement (Phase O)

| Élément | Valeur |
|---------|--------|
| Branche | `main` |
| Dernier commit | `b8b45b9` — feat(rbac): centraliser l'autorisation... |
| Fichiers modifiés non commités | **341** entrées git status |
| Diff stat | 148 files, +1395 / -2064 |
| `.env` dans Git | **NON** (gitignore) |
| `.mysql-*.local` dans Git | **NON** (gitignore) |
| Dumps SQL dans Git | **NON** (`/*.sql` gitignore) |

**GIT : WARNING** — volume important de changements locaux non commités ; stabiliser avant déploiement production.

---

## 17. Risques

| ID | Risque | Sévérité |
|----|--------|----------|
| R1 | SSH non audité — chemins inconnus | **P0** |
| R2 | Isolation filesystem non prouvée | **P0** |
| R3 | Même cluster/IP pour les deux sites | **P1** |
| R4 | mkd-pro.com : vendor/storage HTTP 200 | **P1** (autre site — ne pas toucher) |
| R5 | CA PHP CLI OVH inconnue → R2 backup | **P2** |
| R6 | Cron non configuré | **P2** |
| R7 | 341 fichiers non commités | **P2** |
| R8 | niane déjà en prod — mise à jour à planifier | **P1** |

---

## 18. Architecture de déploiement proposée

```text
                    OVH cluster100
                           |
         +-----------------+------------------+
         |                                    |
  www.mkd-pro.com                    www.niane.mkd-pro.com
  CHEMIN: UNKNOWN                    CHEMIN: UNKNOWN
  ZONE PROTÉGÉE                      CIBLE MKD-Pro
  NE PAS MODIFIER                    docroot → public/
         |                                    |
         |                                    +→ Laravel gestion
         |                                    +→ .env (gestion_app)
         |                                    +→ .mysql-gestion-backup.local
         |                                    +→ storage/app/private/ (backups)
         |                                    +→ cron → schedule:run
         |                                    +→ backup:production → R2
         |
         +→ Application séparée (mkd-pro-session)
```

**Principe :** déploiement **uniquement** dans le répertoire niane identifié par SSH, jamais via wildcard `~/www/*`.

---

## 19. Commandes nécessaires — phase suivante (REQUIRES HUMAN APPROVAL)

### 19.1 Audit SSH read-only (priorité P0)

```bash
ssh <login>@ssh.cluster100.hosting.ovh.net
hostname
whoami
echo "$HOME"
pwd
uname -a
php -v
which php
composer -V
git --version
df -h
free -m 2>/dev/null || true
crontab -l 2>/dev/null || echo "NO_CRONTAB"
ls -la ~
ls -la ~/www 2>/dev/null
find ~ -maxdepth 4 -type d \( -iname '*niane*' -o -iname '*mkd-pro*' \) 2>/dev/null
php -m | egrep 'pdo_mysql|openssl|curl|zip|mbstring|xml|fileinfo|gd|bcmath'
php -i | egrep 'curl.cainfo|openssl.cafile'
```

### 19.2 Déploiement niane (après cartographie + approbation)

```bash
cd /CHEMIN/CONFIRMÉ/NIANE-SEULEMENT
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan optimize:clear
# migrate: REQUIRES EXPLICIT HUMAN APPROVAL — gestion_migration process-only
php artisan optimize
php artisan db:safety-check
php artisan backup:status
```

### 19.3 Cron (après approbation séparée)

```bash
crontab -e
# Ajouter schedule:run avec chemins absolus confirmés
```

---

## 20. Commandes explicitement interdites à ce stade

```text
migrate / migrate:fresh / migrate:refresh / db:wipe
db:seed / db:restore
backup:run / backup:production
Modification .env (local ou OVH)
Modification MySQL / GRANT / CREATE DATABASE
Modification crontab
Modification fichiers www.mkd-pro.com
rm / mv / cp vers chemins non confirmés
Installation / mise à jour paquets serveur
Affichage de mots de passe dans le terminal
```

---

## 21. Actions requises pour débloquer

1. **Fournir accès SSH read-only** (clé dédiée audit ou session supervisée).
2. **Exécuter le script d'inspection §19.1** et compléter ce rapport avec les chemins absolus.
3. **Confirmer** que le déploiement niane ne partage aucun répertoire avec mkd-pro.com.
4. **Stabiliser Git** (commit/tag de release) avant push production.
5. **Préparer secrets OVH** (`.env`, `.mysql-gestion-backup.local`) — copie manuelle hors chat.

Approbations suggérées :

```text
OUI — COMPLETE PRE-PROD 12.3 SSH READ-ONLY AUDIT
OUI — DEPLOY TO www.niane.mkd-pro.com ONLY
```

---

```text
STOP — HUMAN REVIEW REQUIRED
```

**Aucune modification n'a été effectuée après génération de ce rapport.**
