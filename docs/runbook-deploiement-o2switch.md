# Runbook — déploiement MKD-Pro / Gestion sur o2switch

**Version :** 1.1 (documentaire)
**Date :** 2026-10-20 (révision procédure archives frontend : 2026-10-25)
**Référence fonctionnelle (dépôt Git) :** commit `3869017b1bbdb2fceb7297866559effe9772adb3`
**Périmètre :** procédure opérationnelle pour hébergement **o2switch** — **ne remplace pas** les rapports PRE-PROD historiques OVH.

---

## Légende des sources

| Tag | Signification |
|-----|----------------|
| **[DÉPÔT]** | Confirmé dans le dépôt Git (fichiers, commandes Artisan, tests). |
| **[OVH]** | Documentation ou rapports **historiques** ciblant OVH (`/homez.2212/mkdproq/...`) — **ne pas réutiliser** comme chemins o2switch. |
| **[O2S]** | Information **connue** pour l’environnement o2switch actuel (hors secrets) — à **reconfirmer** sur le serveur avant chaque déploiement. |
| **[SERVEUR]** | Point à vérifier **sur l’installation cible** avant d’exécuter une commande. |

**NIANE** (`niane.mkd-pro.com`) est citée comme **installation de démonstration / référence fonctionnelle**, pas comme modèle contractuel pour une installation cliente en production.

---

## Contexte o2switch (reconfirmer avant déploiement)

| Élément | Valeur connue **[O2S]** | À vérifier **[SERVEUR]** |
|---------|-------------------------|---------------------------|
| Hébergeur | o2switch | — |
| Serveur | oursin | `hostname` |
| Utilisateur SSH | `damo5182` | `whoami` |
| Répertoire personnel | `/home/damo5182` | `pwd` |
| Exemple d’installation (démo) | `/home/damo5182/niane.mkd-pro.com` | chemin réel du **client / domaine** |
| Racine document HTTP (typique Laravel) | `<racine-app>/public` | config vhost o2switch / cPanel |
| PHP CLI | `/opt/alt/php84/usr/bin/php` | `which php`, `php -v` |
| PHP (dernière vérif. rapportée) | 8.4.25 | `php -v` |
| Composer (dernière vérif. rapportée) | 2.10.3 | `composer --version` |
| Node (dernière vérif. rapportée) | v22.23.2 | `node -v` |
| npm (dernière vérif. rapportée) | 10.9.8 | `npm -v` |
| Redis | activé en mode socket (o2switch) | variables `REDIS_*` dans `.env` **sans les copier dans ce doc** |

**Alias SSH `niane` :** utilisé dans des opérations passées **[O2S]** — la configuration exacte (`~/.ssh/config`) doit être vérifiée localement **[SERVEUR local]**.

---

## Documents et fichiers du dépôt utiles

| Ressource | Rôle |
|-----------|------|
| `docs/database-safety-policy.md` | Interdictions `migrate:fresh`, bases protégées **[DÉPÔT]** |
| `docs/rbac-permission-catalog.md` | Catalogue RBAC, seeder, descriptions à la création **[DÉPÔT]** |
| `docs/preprod-backup-strategy.md` | Spatie Backup, commandes planifiées **[DÉPÔT]** |
| `docs/preprod-recovery-runbook.md` | Récupération (contexte général) **[DÉPÔT]** |
| `docs/preprod-phase-9.5*.md`, `12.3`, `12.4` | Déploiements **OVH** — chemins **`/homez.2212/...`** **[OVH]** |
| `composer.json` | PHP `^8.2`, scripts `setup`, `test` **[DÉPÔT]** |
| `package.json` | scripts `build` → `vite build` **[DÉPÔT]** |
| `vite.config.ts` | build prod via Vite ; certificats `.cert/` pour **dev LAN HTTPS** uniquement **[DÉPÔT]** |
| `app/Console/Commands/SyncPermissionCatalogCommand.php` | commande `rbac:sync-permission-catalog` **[DÉPÔT]** |
| `database/seeders/DatabaseSeeder.php` | refus en `production` ; appelle `PermissionSeeder` hors prod **[DÉPÔT]** |

Il **n’existe pas** de script shell de déploiement unique validé pour o2switch dans le dépôt **[DÉPÔT]** (seulement des scripts utilitaires sous `scripts/`).

---

## A. Préparation

### A.1 Git, branche et commit

**[DÉPÔT]** — Contrôles recommandés **en local** (machine de build / développeur) :

```bash
git fetch origin
git rev-parse HEAD
git rev-parse origin/main
git log -1 --oneline
git status -sb
```

- Fixer le **SHA exact** à déployer (ex. `3869017b1bbdb2fceb7297866559effe9772adb3`).
- Vérifier que `origin/main` (ou la branche release) contient ce commit **avant** de livrer.
- **Ne pas confondre** :
  - le **SHA Git** (identifiant du commit **code source** livré) ;
  - le **SHA-256** du `manifest.json` et de l’**archive frontend** (intégrité des **octets générés** — valeurs différentes, recalculées à chaque build).

### A.2 Working tree local et `public/build/`

**[DÉPÔT]** — Le répertoire `public/build/` est **suivi par Git**, mais plusieurs livraisons fonctionnelles (ex. créances + RBAC) n’incluent **pas** forcément un commit assets à jour.

- **Ne jamais** `git add public/build/` en bloc sans revue : mélange fréquent d’anciens assets supprimés (index) et de nouveaux fichiers non suivis.
- **Préserver** les modifications locales non liées au déploiement en cours (docs, ZIP, tmp).
- Pour un build **isolé** (recommandé) : utiliser un **worktree Git détaché** au commit cible **[DÉPÔT — pratique validée en interne, non scriptée]** :

```bash
# Adapter COMMIT_SHA et le chemin du worktree
git worktree add --detach ../gestion-build-COMMIT_SHA COMMIT_SHA
```

**Build traçable — contrôles dans le worktree dédié [DÉPÔT — pratique validée]** :

1. `git rev-parse HEAD` dans le worktree = **COMMIT_SHA** attendu (ou contenu source **identique** au commit cible — voir comparaison de blobs si le worktree est resté sur un parent avec correctif copié).
2. `git status --short` : **aucune modification source parasite** (fichiers `app/`, `routes/`, `resources/` hors build, `config/`, etc.) non expliquée par la livraison. Les seuls écarts acceptables pour un build frontend sont typiquement : dépendances (`node_modules/`, `vendor/`), `.env` **local de build** (gitignoré), et `public/build/` **généré**.
3. **Ne pas** compiler dans le dépôt de travail principal si celui-ci contient des centaines d’entrées locales (docs, ZIP, `public/build/` bruyant) — préserver ce worktree intact.
4. **Ne jamais** `git add .`, `git add -A` ni `git commit -a` pour « embarquer » un build : les assets frontend se livrent par **archive**, pas par commit Git obligatoire.
5. **Ne pas supprimer automatiquement** un worktree existant (`git worktree remove`) sans décision humaine et sans archive validée ; plusieurs worktrees peuvent coexister.

### A.3 Périmètre applicatif à livrer

Distinguer **quatre flux** :

| Flux | Contenu | Livraison typique |
|------|---------|-------------------|
| **Code PHP / Vue source / routes** | `app/`, `routes/`, `resources/` (hors build), `config/`, etc. | archive Git, rsync/scp **ciblé**, ou `git pull` si dépôt sur serveur |
| **Assets compilés** | `public/build/manifest.json`, `public/build/assets/**` | archive dédiée **après** `npm run build` |
| **Dépendances PHP** | `vendor/` | `composer install` **sur le serveur** ou CI (ne pas écraser un vendor prod sans plan) |
| **Installation / secrets / données** | `.env`, `storage/`, base MySQL, sauvegardes | **ne jamais** écraser depuis Git ; protéger explicitement |

**Référence commit `3869017` + parent `4881b23` [DÉPÔT]** — fonctionnalités créances / RBAC couvertes par le code source (sans supposer que `public/build/` du commit contient le menu « Créances clients »).

### A.4 Versions requises (dépôt)

| Outil | Exigence **[DÉPÔT]** | Notes |
|-------|----------------------|--------|
| PHP | `^8.2` (`composer.json`) | o2switch : PHP 8.4 observé **[O2S]** |
| Composer | 2.x | lock file présent |
| Node / npm | lock `package-lock.json` ; pas de champ `engines` racine dans `package.json` | utiliser les versions **[SERVEUR]** ou celles du poste de build ; `@zxing/library` peut émettre un avertissement moteur Node **[DÉPÔT]** |
| Extensions PHP | Laravel 12 + packages (dompdf, mysql, etc.) | **[SERVEUR]** : `php -m` |

---

## B. Contrôles avant déploiement

### B.1 Cible, domaine, PHP

**[SERVEUR]** — Avant toute copie :

1. Noter la **racine application** (ex. `/home/damo5182/<domaine>.mkd-pro.com`) — **ne pas** supposer le chemin NIANE pour un autre client.
2. Confirmer que le **document root** du domaine pointe vers `<racine-app>/public`.
3. Exécuter avec le **même binaire PHP** que la cron / CLI :

```bash
/opt/alt/php84/usr/bin/php -v
/opt/alt/php84/usr/bin/php -m | head
```

(Adapter le chemin PHP si l’hébergeur a changé la version par domaine.)

### B.2 Sauvegarde exploitable

**[DÉPÔT]** — Sauvegardes :

- Planification : `backup:production` (voir `routes/console.php`), puis `backup:clean` / `backup:monitor`.
- Commandes de contrôle : `backup:status`, `backup:verify {path}` (voir `app/Console/Commands/`).
- Création manuelle possible via l’interface admin **Sauvegardes** (jobs applicatifs ; le sous-processus métier peut invoquer Spatie `backup:run` — ne pas confondre avec la commande planifiée `backup:production`).

**[SERVEUR]** — Avant déploiement sur une base **métier** :

- Vérifier qu’une sauvegarde **récente** existe (UI admin ou fichiers sous `storage/app/private/` selon config).
- Vérifier qu’elle est **téléchargeable ou testée** (restore drill hors prod si possible — voir `docs/preprod-recovery-runbook.md`).

**Ne pas** lancer de restauration automatique de base dans ce runbook.

### B.3 Permissions disque

**[SERVEUR]** — Contrôler (sans modifier aveuglément) :

- `storage/` et `bootstrap/cache/` : écriture par l’utilisateur PHP / web.
- `.env` : lecture seule pour le compte web si politique de sécurité locale.

### B.4 Prérequis spécifiques installation

| Prérequis | Vérification |
|-----------|--------------|
| `.env` présent et **non versionné** | **[SERVEUR]** : fichier existe ; **ne pas** le remplacer par `.env.example` |
| Compte DB runtime **non root** | **[DÉPÔT]** politique `gestion_app` — voir `docs/mysql-production-accounts.md` |
| Base protégée `gestion` | **[DÉPÔT]** `DatabaseSafetyGuard` — pas de commandes destructives |
| Redis / queue / cron | **[SERVEUR]** : aligné avec `.env` |

### B.5 Conditions d’arrêt immédiat

**Stopper** et demander une décision humaine si :

- Le SHA Git ou l’archive source **ne correspond pas** au commit validé.
- **Pas de sauvegarde** récente sur une installation avec données métier.
- `git status` local mélange `public/build/` non maîtrisé avec le livrable.
- `php artisan db:safety-check` (lecture seule) signale une anomalie **[DÉPÔT]**.
- Le manifest frontend référence des **fichiers absents** sur disque.
- Tentative de déploiement sur la **mauvaise** base ou le **mauvais** répertoire.
- Confusion **OVH vs o2switch** sur les chemins.

---

## C. Construction frontend

### C.1 Prérequis build **[DÉPÔT]**

- Scripts npm : `"build": "vite build"` (`package.json`).
- Plugin `@laravel/vite-plugin-wayfinder` dans `vite.config.ts` — le build prod génère types/routes (**observé en build** : message « Types generated for actions, routes »).
- Certificats `.cert/` (mkcert) : requis pour **`npm run dev`** en mode HTTPS LAN sur le réseau local ; **le build de production ne doit pas** lire les certificats du poste **[DÉPÔT]** (depuis le commit `a94bf25b5264574186d75dc77e0e1340feade4f1`, lorsque Vite n’est pas en mode serveur de développement (`command !== 'serve'`) dans `vite.config.ts`). Ne pas copier `.cert/` dans le worktree uniquement pour compiler.
- Avant build : noter `node -v` et `npm -v` (à reporter dans la fiche de traçabilité, section C.4.3). `@zxing/library` peut émettre un avertissement moteur Node — documenter sans le masquer.

**Environnement de build recommandé :** worktree au **COMMIT_SHA** cible + dépendances verrouillées.

**Ordre typique (worktree uniquement)** :

```powershell
# PowerShell — adapter les chemins
cd C:\chemin\vers\gestion-build-COMMIT_SHA
node -v
npm -v
npm ci
```

**Préparation PHP pour Wayfinder (worktree, sans base métier)** — si `npm run build` échoue sur `php artisan wayfinder:generate` :

1. `[DÉPÔT]` Copier `.env.example` → `.env` **dans le worktree** (jamais commiter ; ne pas copier le `.env` du dépôt principal).
2. `[DÉPÔT]` Pour le hook `package:discover`, aligner le broadcast de build : ajouter `BROADCAST_DRIVER=log` si `config/broadcasting.php` utilise ce nom (`.env.example` documente surtout `BROADCAST_CONNECTION` — les deux peuvent coexister pour le bootstrap Laravel).
3. `[DÉPÔT]` **Ne pas** lancer `composer run setup` (migrations, `key:generate` automatique, build npm).
4. `composer install --no-dev --optimize-autoloader --no-interaction` puis, si besoin, `composer dump-autoload --no-dev --optimize --no-interaction`.
5. **Ne pas** exécuter `php artisan migrate` ni pointer vers la base `gestion` métier.

**Build frontend :**

```powershell
# Optionnel pour cette session — ne pas persister dans le profil Windows
$env:VITE_DEV_SERVER_HOST = "localhost"
npm run build
echo $LASTEXITCODE   # doit être 0 avant toute archive
```

```bash
# Linux / macOS — équivalent
cd /chemin/vers/worktree-au-commit
npm ci
npm run build
```

- **Ne pas** improviser d’autres correctifs si le build échoue : consigner la sortie complète et demander une décision.
- `[DÉPÔT]` Commande Wayfinder manuelle (dernier recours, worktree seulement) : `php artisan wayfinder:generate --with-form` — voir `vendor/laravel/wayfinder/README.md` ; **pas** en production métier sans plan.

### C.2 Vérification manifest et assets

**[DÉPÔT]** — Après `npm run build`, contrôler :

1. Existence de `public/build/manifest.json`.
2. **Tous** les fichiers référencés (clés `file`, `css`, `assets` des entrées) existent sous `public/build/`.

Exemple de contrôle (Node.js, à exécuter **dans la racine du build**) :

```javascript
// tmp-verify-manifest.cjs — à créer temporairement puis supprimer
const fs = require('fs');
const path = require('path');
const m = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
const files = new Set();
for (const e of Object.values(m)) {
  if (e.file) files.add(path.join('public/build', e.file.replace(/^\//, '')));
  if (e.css) for (const c of e.css) files.add(path.join('public/build', c.replace(/^\//, '')));
  if (e.assets) for (const a of e.assets) files.add(path.join('public/build', a.replace(/^\//, '')));
}
const missing = [...files].filter((f) => !fs.existsSync(f));
console.log('entries:', Object.keys(m).length, 'referenced:', files.size, 'missing:', missing.length);
if (missing.length) { console.log(missing.slice(0, 20)); process.exit(1); }
```

- Le nombre de **clés** du manifest et de **fichiers référencés** **varie** à chaque build — ne pas utiliser de constante universelle.
- Vérifier l’absence de chemins absolus locaux ou de contenu sensible dans `public/build/` (pas de `.env`, secrets, clés privées).
- **Contrôles fonctionnels (exemples)** : pour une livraison incluant les factures fournisseurs, chercher dans le manifest les entrées source `resources/js/pages/SupplierInvoices/Index.vue`, `Create.vue`, `Show.vue` et un chunk du type `supplierInvoiceUi-*.js`. Adapter la liste aux pages réellement modifiées.

### C.3 Cohérence source ↔ build

Pour une livraison RBAC / navigation (réf. `3869017`) **[DÉPÔT]** :

- Vérifier dans le manifest la présence d’entrées source du type :
  - `resources/js/layouts/BootstrapLayout.vue`
  - `resources/js/pages/Admin/Users/Create.vue` / `Edit.vue`
  - `resources/js/pages/Customers/Receivables.vue`
- **Interdit** : déployer un `manifest.json` d’un commit **A** avec des fichiers `assets/` d’un commit **B**.

Les chunks compilés (ex. `BootstrapLayout.vue_vue_type_script_setup_true_lang-*.js`) peuvent apparaître sans clé source exacte — contrôler la **cohérence manifest ↔ fichiers sur disque**, pas uniquement une clé source précise.

### C.4 Archive frontend (pratique validée, non scriptée dans le dépôt)

Contenu **autorisé** dans l’archive de transfert :

- `public/build/manifest.json`
- `public/build/assets/**`

**Interdit** dans l’archive : `.env`, `vendor/`, `node_modules/`, `.cert/`, code source PHP/Vue, rapports d’audit, fichiers temporaires.

**Création (depuis la racine du worktree où le build a réussi)** — choisir un répertoire de stockage **hors** du dépôt principal (ex. dossier dédié sur le poste de build ; le chemin exact n’est pas imposé) :

```powershell
# PowerShell — COMMIT_SHA = SHA Git source déjà identifié ; OUTPUT_DIR = dossier existant
$COMMIT_SHA = "86718366efafc568a42e732b2bf2cb0ecdab4e45"   # exemple : remplacer par le SHA livré
$OUTPUT_DIR  = "C:\chemin\vers\archives-frontend-livraison"  # adapter
$ARCHIVE     = Join-Path $OUTPUT_DIR "niane-public-build-$COMMIT_SHA.tar.gz"
if (Test-Path $ARCHIVE) { throw "Archive déjà présente — ne pas écraser." }
cd C:\chemin\vers\gestion-build-COMMIT_SHA
tar -czf $ARCHIVE public/build/manifest.json public/build/assets
```

```bash
# Linux — COMMIT_SHA identifié avant la commande
tar -czf "/chemin/staging/niane-public-build-${COMMIT_SHA}.tar.gz" \
  public/build/manifest.json public/build/assets
```

Le suffixe `niane-public-build-` est un **exemple** de nommage ; le segment `COMMIT_SHA` désigne le **commit source Git**, pas le SHA-256 de l’archive.

#### C.4.1 Vérification des entrées (avant transfert)

**Allow-list** — chaque entrée listée par `tar -tzf` (sans extraction) doit être **exactement** `public/build/manifest.json` ou un chemin sous `public/build/assets/`. Chemins absolus, `..` et tout autre préfixe → **STOP**.

```powershell
# PowerShell — $ARCHIVE déjà défini ; ne pas extraire l’archive
$listing = @(tar -tzf $ARCHIVE 2>&1)
if ($LASTEXITCODE -ne 0) { throw "tar -tzf a échoué." }

$lines = @($listing | ForEach-Object { "$_".Trim() } | Where-Object { $_ -ne "" })
if ($lines.Count -eq 0) { throw "Listing vide — archive illisible ou vide." }

$manifestSeen = $false
$disallowed = [System.Collections.Generic.List[string]]::new()

foreach ($raw in $lines) {
  $p = ("$raw" -replace '\\', '/').Trim()
  if ($p.StartsWith('./')) { $p = $p.Substring(2) }
  if ($p -match '(^/|^[A-Za-z]:|\.\.)') {
    $disallowed.Add($raw)
    continue
  }
  if ($p -eq 'public/build/manifest.json') {
    $manifestSeen = $true
    continue
  }
  if ($p -eq 'public/build/assets' -or $p -eq 'public/build/assets/') {
    continue
  }
  if ($p -match '^public/build/assets/.+') {
    continue
  }
  $disallowed.Add($raw)
}

if (-not $manifestSeen) { throw "Entrée obligatoire absente : public/build/manifest.json" }
if ($disallowed.Count -gt 0) {
  throw ("Entrées hors allow-list :`n" + ($disallowed -join "`n"))
}

$lines.Count   # reporter dans la fiche C.4.3 (nombre d’entrées tar)
```

Le nombre d’**entrées** dans le tarball (manifest + fichiers assets) **n’est pas** égal au nombre de **clés** du manifest JSON.

#### C.4.2 Empreintes SHA-256 (manifest et archive)

**[DÉPÔT]** — Calculer et **archiver dans la fiche de traçabilité** (C.4.3) :

```powershell
Get-FileHash -Path public/build/manifest.json -Algorithm SHA256
Get-FileHash -Path $ARCHIVE -Algorithm SHA256
(Get-Item $ARCHIVE).Length
```

```bash
sha256sum public/build/manifest.json
sha256sum niane-public-build-COMMIT_SHA.tar.gz
```

- Recontrôler le **SHA-256 de l’archive** après copie USB, sync ou upload **[SERVEUR / local]**.
- Une empreinte identique prouve l’**intégrité des octets** comparés ; elle ne prouve **pas** à elle seule l’origine ou la sécurité du contenu (revue humaine + SHA Git source requis).

**Exemple historique (oct. 2026 — build worktree parent `8671836`, correctif Vite ensuite commité en `a94bf25`)** — *valeurs de référence uniquement, pas attendues pour les prochains builds* :

| Élément | Exemple observé |
|---------|-----------------|
| SHA-256 manifest | `9451E6D3ECD4CBF365B73D39578613A58CC43D91C7705522F7D555156398D999` |
| SHA-256 archive | `792E194A29F08E7DD27F47BEAB9BEBDB9ACE757200C819284101958B05C811E6` |
| Entrées archive | 187 (1 manifest + 185 assets référencés à l’époque) |
| Clés manifest | 171 ; fichiers référencés : 185 ; manquants : 0 |

#### C.4.3 Fiche de traçabilité minimale (par archive)

Remplir **sans secrets** (pas de contenu `.env`, pas de mots de passe) :

| Champ | Exemple |
|-------|---------|
| SHA Git source (complet) | `a94bf25b5264574186d75dc77e0e1340feade4f1` |
| Date/heure génération (UTC ou locale, préciser) | |
| Node / npm | |
| Résultat `npm run build` (code sortie) | `0` |
| SHA-256 `manifest.json` | |
| SHA-256 archive `.tar.gz` | |
| Taille archive (octets) | |
| Nombre d’entrées `tar -tzf` | |
| Contrôles manifest (0 manquant ?) | oui / non |
| Pages / chunks fonctionnels vérifiés | ex. SupplierInvoices si applicable |
| Cible prévue | domaine / client **[SERVEUR]** |
| Statut | `générée` → `vérifiée` → `transférée` → `déployée` (étapes distinctes) |

**`générée` ou `vérifiée` ne signifie pas `transférée` ni `déployée`.**

#### C.4.4 Conservation

- Conserver l’archive et la fiche dans un **emplacement contrôlé**, hors du tarball livré au serveur (copie locale, stockage d’équipe — **politique de rétention non fixée ici**).
- Conserver une copie **tant que** traçabilité, audit ou reprise est nécessaire.
- Les répertoires temporaires de **test d’extraction** ne remplacent pas une conservation durable.

**Extraction de test (répertoire distinct, sans modifier l’archive source)** :

```powershell
$VERIFY_DIR = "C:\chemin\vers\verify-frontend-COMMIT_SHA"
New-Item -ItemType Directory -Force -Path $VERIFY_DIR | Out-Null
tar -xzf $ARCHIVE -C $VERIFY_DIR
Get-FileHash "$VERIFY_DIR\public\build\manifest.json" -Algorithm SHA256
# Rejouer le script de contrôle manifest (section C.2) depuis $VERIFY_DIR
```

### C.5 `public/build/` suivi dans Git

- Un `git pull` sur le serveur **peut** mettre à jour le manifest sans tous les assets si l’historique Git est incohérent.
- **Préférer** une archive assets **complète** post-build plutôt que de se fier uniquement à Git pour le frontend en production.

---

## D. Livraison sur o2switch

### D.1 Stratégie prudente (staging)

**[SERVEUR]** — Workflow recommandé :

1. Transférer archives vers un répertoire de staging **[SERVEUR]** (ex. `$HOME/staging/` — le `$HOME` du compte SSH n’est pas forcément celui de la démo NIANE **[O2S]**).
2. Lister le contenu **sans extraire** :

```bash
tar -tzf archive-source.tar.gz | head
tar -tzf archive-frontend.tar.gz | head
```

3. Comparer SHA-256 local / distant (archive frontend : voir **C.4.2** ; mettre à jour le statut de la fiche **C.4.3** à `transférée` seulement après preuve).
4. Copie de **secours horodatée** de la racine app (ou au minimum `app/`, `resources/`, `routes/`, `public/build/`) **vers un répertoire de backup** `[SERVEUR — chemins à définir]`.

> **Transfert ≠ déploiement** : une archive frontend **vérifiée** en staging (sections **C.4**, **D.1**) n’est **pas** déployée tant que **D.4** (extraction ciblée vers `public/build/`) n’a pas été exécutée avec backup préalable et contrôles **D.6**.

### D.2 Éléments à protéger (ne jamais écraser depuis Git)

| Élément | Raison |
|---------|--------|
| `.env` | secrets, DB, Redis, domaines |
| `storage/` | logs, cache, fichiers uploadés, sauvegardes locales |
| `vendor/` | préférer `composer install` maîtrisé plutôt qu’écrasement partiel |
| Sauvegardes / exports / `gestion_recovery` | politique de sécurité projet |
| Données MySQL | hors périmètre « copie fichiers » |

### D.3 Livraison du code source

**[DÉPÔT]** — Extraction depuis Git (machine de build), **pas** depuis un working tree sale :

```bash
git archive --format=tar.gz -o niane-source-COMMIT_SHA.tar.gz COMMIT_SHA -- \
  chemin/fichier1 chemin/fichier2 ...
```

Pour un déploiement **complet** au commit, lister explicitement les chemins ou utiliser une stratégie `git archive` de l’arborescence applicative **en excluant** `.env`, `vendor`, `node_modules`, `storage` — **les exclusions doivent être vérifiées** avant exécution.

**[SERVEUR]** — Extraction dans un répertoire temporaire, puis **copie fichier par fichier** ou rsync avec exclusions explicites.

> **Pas de commande rsync/scp destructive « prête à l’emploi »** : les chemins source/destination et `--exclude` dépendent de chaque installation. Toute commande du type `rsync --delete` est **déconseillée** sans backup et sans revue humaine.

### D.4 Livraison des assets

**[SERVEUR]** — Étape **distincte** du transfert staging (**D.1**) et de la génération locale (**C.4**). Nécessite autorisation / fenêtre de maintenance selon l’installation.

Après backup de `public/build/` :

- Vérifier que le SHA Git source de l’archive (**fiche C.4.3**) correspond au code PHP déjà déployé ou livré en parallèle (**D.3**).
- Extraire **uniquement** `public/build/manifest.json` et `public/build/assets/` de l’archive frontend validée (**C.4.1**) vers la racine application.
- Supprimer **manuellement** et **avec prudence** les anciens hashes obsolètes si l’espace disque l’exige — risque de 404 si manifest et assets divergent.
- Mettre à jour la fiche **C.4.3** : statut `déployée` **uniquement** après **D.6** et smoke test **F.3** / **F.5**.

### D.5 Dépendances PHP sur le serveur

**[DÉPÔT]** — Après mise à jour du code :

```bash
cd /chemin/racine-app
COMPOSER_BIN="$(command -v composer)"   # [SERVEUR] — ne pas supposer un chemin fixe
/opt/alt/php84/usr/bin/php "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction
```

**[SERVEUR]** — Confirmer `composer` et PHP CLI (`which php`, `which composer`) avant exécution.

### D.6 Intégrité post-transfert

- SHA-256 des archives (source + frontend) — identique à la fiche **C.4.3** / calcul local pré-transfert.
- `tar -tzf archive-frontend.tar.gz` vs fichiers présents sous `public/build/` (manifest + assets).
- SHA-256 de `public/build/manifest.json` sur le serveur = valeur enregistrée avant transfert (**C.4.2**).
- Taille et date de `public/build/manifest.json`.

---

## E. Base de données et permissions

### E.1 Trois notions distinctes

| Action | Effet | Production |
|--------|-------|------------|
| **Migrations** `php artisan migrate` | Schéma SQL (tables/colonnes) | **Uniquement** après analyse + **autorisation humaine explicite** ; jamais `migrate:fresh` / `refresh` / `reset` / `db:wipe` sur `gestion` **[DÉPÔT]** |
| **Sync catalogue RBAC** | Crée lignes **manquantes** dans `permissions` | Commande dédiée avec confirmation |
| **Attribution utilisateurs** | `user_permissions`, rôles, extras gestionnaire | Interface admin / données métier — **hors** sync catalogue |

### E.2 Commande RBAC **[DÉPÔT]**

Nom exact :

```text
php artisan rbac:sync-permission-catalog
```

Options vérifiées dans `SyncPermissionCatalogCommand.php` :

| Option | Rôle |
|--------|------|
| `--status` | Lecture seule — état catalogue ↔ base |
| `--dry-run` | Simulation — **aucune écriture** |
| `--confirm=` | Phrase **exacte** requise pour écrire |

Phrase de confirmation **[DÉPÔT]** (`PermissionCatalogSynchronizer::confirmationPhraseForDatabase`) :

```text
SYNC PERMISSION CATALOG ON <nom_base>
```

où `<nom_base>` est le nom résolu de la base pour la connexion Laravel (affiché par la commande : lignes `Database : ...`).

Exemple **générique** (adapter `<nom_base>` après `--status` ; exécuter depuis la racine Laravel avec le PHP CLI retenu pour l’installation) :

```bash
/opt/alt/php84/usr/bin/php artisan rbac:sync-permission-catalog --status
/opt/alt/php84/usr/bin/php artisan rbac:sync-permission-catalog --dry-run
/opt/alt/php84/usr/bin/php artisan rbac:sync-permission-catalog --confirm="SYNC PERMISSION CATALOG ON <nom_base>"
```

Sans option `--status` ou `--dry-run`, une exécution **sans** `--confirm` exacte **échoue** et **n’écrit rien** **[DÉPÔT]** (`SyncPermissionCatalogCommandTest` : « execution reelle sans confirm est refusee »).

**Comportement confirmé [DÉPÔT]** (`PermissionCatalogSynchronizer`, tests `SyncPermissionCatalogCommandTest`, `PermissionSeederTest`) :

- Crée les permissions **absentes** (`firstOrCreate` sur `resource` + `action`).
- **Ne modifie pas** les pivots `user_permissions` (aucun `sync` / attach sur les utilisateurs ; commentaire de classe du synchronizer).
- **Ne supprime pas** les permissions « orphelines ».
- **Ne met pas à jour** les `description` (ni autres champs) des permissions **déjà existantes** — test « permission existante n est pas modifiee ».
- Idempotent si exécutée plusieurs fois.

**Libellés FR affichés dans l’UI** : proviennent surtout de `PermissionCatalog.php` / presenters **[DÉPÔT]** — une description SQL obsolète n’empêche pas les libellés catalogue en admin si le code déployé est à jour.

### E.3 PermissionSeeder et DatabaseSeeder **[DÉPÔT]**

- `PermissionSeeder` appelle le même synchronizer (`sync()`).
- `DatabaseSeeder` **refuse** l’exécution en `production` (`RuntimeException`).
- **Ne jamais** lancer `php artisan db:seed` en production sans procédure approuvée.

En **local / test** : `PermissionSeeder` alimente le catalogue ; données métier via `demo:seed-local` (confirmations séparées — voir docs PRE-PROD).

### E.4 Migrations en production

Procédure **minimale** (sans exécution automatique) :

1. `[DÉPÔT]` Lire les migrations nouvelles dans `database/migrations/`.
2. `[SERVEUR]` `php artisan migrate --pretend` (si disponible) ou revue SQL.
3. Sauvegarde base **validée**.
4. Approbation humaine explicite (phrase projet si applicable).
5. `php artisan migrate --force` **uniquement** si toutes les conditions sont remplies.

---

## F. Vérifications après livraison

### F.1 Fichiers et cache **[SERVEUR]**

```bash
/opt/alt/php84/usr/bin/php artisan config:clear
/opt/alt/php84/usr/bin/php artisan route:clear
/opt/alt/php84/usr/bin/php artisan view:clear
# optionnel si opcache pose problème — selon hébergeur
```

**[DÉPÔT]** — `php artisan db:safety-check` (lecture seule) pour confirmer environnement DB attendu.

### F.2 Routes utiles (réf. créances / RBAC) **[DÉPÔT]**

| Route nommée | Chemin HTTP |
|--------------|-------------|
| `customers.receivables` | `GET /customers/receivables` |

Contrôle CLI (ne prouve **pas** l’UI complète) :

```bash
/opt/alt/php84/usr/bin/php artisan route:list --name=customers.receivables
```

### F.3 Frontend

- Charger une page authentifiée et vérifier dans les outils développeur que les assets `public/build/assets/` répondent **200** (pas 404).
- Vérifier que le manifest servi correspond aux fichiers présents.

### F.4 Journaux

**[SERVEUR]** — `storage/logs/laravel.log` (rotation selon config) après un parcours utilisateur test.

### F.5 Contrôles fonctionnels (navigateur — installation distante)

À exécuter **connecté** avec des comptes de test dédiés (ne pas utiliser des comptes clients réels sans accord) :

| Contrôle | Attendu (réf. `3869017`) |
|--------|---------------------------|
| Connexion | OK |
| Menu **Créances clients** | Visible si permission effective `customers.view` |
| Page créances + recherche | Données cohérentes avec périmètre |
| Admin → Rôles et permissions | Libellé FR visible (ex. « Voir les clients et les créances clients ») ; clé technique `customers.view` |
| Gestionnaire sans `customers.view` | Pas de menu / accès créances |
| Gestionnaire avec extra `customers.view` | Accès conservé après enregistrement |
| Vendeur | Périmètre **propres** ventes / créances |

**Insuffisant :** une réponse HTTP **302** vers `/login` seule sur une URL protégée — ne valide ni Inertia, ni assets, ni permissions.

### F.6 Tests automatisés **[DÉPÔT]** — local vs distant

Exécutables **en local / CI** (SQLite `:memory:` / base de test), **pas** sur NIANE métier sans base isolée :

```bash
php artisan test tests/Feature/CustomerReceivablesTest.php
php artisan test tests/Feature/Rbac/RolePresetUserTest.php
php artisan test tests/Feature/Rbac/RbacPermissionLabelsTest.php
php artisan test tests/Feature/Rbac/SyncPermissionCatalogCommandTest.php
php artisan test tests/Feature/Rbac/PermissionSeederTest.php
```

---

## G. Retour arrière

### G.1 Déclencheurs rollback

- 404 massifs sur `public/build/assets/*`.
- Erreur 500 généralisée après déploiement.
- Incohérence manifest / assets.
- Régression RBAC avérée (accès non autorisés ou blocage total métier).

### G.2 Rollback fichiers

**[SERVEUR]** — Restaurer depuis la **copie horodatée** prise en section D.1 :

- Code PHP / Vue source
- `public/build/` complet (manifest + assets **ensemble**) — ou redeployer une **archive frontend** conservée (**C.4.4**) dont le SHA-256 est connu
- **Ne pas** restaurer `.env`, `storage/` (uploads, logs, sauvegardes locales), ni une base MySQL depuis ce runbook.
- **Ne pas** écraser une sauvegarde Spatie ou un export métier par une archive Git ou un vieux tarball sans validation humaine.

### G.3 Base de données

- **Pas** de restauration automatique de dump dans ce runbook.
- Toute restauration MySQL = procédure dédiée, sauvegarde identifiée, validation humaine, bases allow-list recovery **[DÉPÔT]** (`gestion_recovery`, etc.).

### G.4 Interruption

Si une étape met en doute la base cible ou les chemins : **stop**, documenter l’état, demander décision **avant** sync RBAC ou migration.

---

## H. Liste de contrôle finale

### Avant transfert de l’archive frontend (obligatoire si livraison par tarball)

Checklist **C.4** — à cocher sur la machine de build **avant** tout upload vers staging **[SERVEUR]** :

- [ ] SHA Git source identifié et consigné (complet, pas seulement l’abréviation).
- [ ] Worktree isolé ; `git status` sans modification source parasite.
- [ ] `npm run build` terminé avec code de sortie `0` (Node/npm notés).
- [ ] `manifest.json` présent, JSON valide, **0** fichier référencé manquant (**C.2**).
- [ ] Contrôles fonctionnels pertinents (pages/chunks modifiés — ex. SupplierInvoices si applicable).
- [ ] Archive limitée à `public/build/manifest.json` + `public/build/assets/**` (**C.4.1**).
- [ ] SHA-256 manifest + archive + taille + nombre d’entrées `tar -tzf` enregistrés.
- [ ] Extraction de test dans un répertoire distinct ; manifest extrait = même SHA-256 (**C.4.4**).
- [ ] Fiche de traçabilité **C.4.3** remplie ; statut au plus `vérifiée`.
- [ ] **Pas** de statut `transférée` ou `déployée` sans preuve de transfert / déploiement réel.

### Avant déploiement (obligatoire)

- [ ] SHA Git validé et identique à `origin` (ou tag release).
- [ ] Working tree local : pas de mélange accidentel `public/build/`.
- [ ] Sauvegarde récente vérifiée (installation avec données).
- [ ] Chemins o2switch confirmés (**pas** chemins OVH).
- [ ] `.env` / `storage/` / sauvegardes exclus du livrable.
- [ ] Archives SHA-256 calculées (si livraison par archive).

### Pendant déploiement (obligatoire)

- [ ] Backup fichiers application avant écrasement.
- [ ] Transfert + contrôle SHA-256 distant.
- [ ] Extraction staging puis copie ciblée.
- [ ] `composer install --no-dev` si code PHP changé.
- [ ] Déploiement **paire** manifest + assets.

### Après déploiement (obligatoire)

- [ ] Clear caches Laravel (config/route/view).
- [ ] `db:safety-check` (lecture seule).
- [ ] `route:list` pour routes critiques.
- [ ] Assets 200 en navigation authentifiée.
- [ ] Parcours fonctionnel créances + RBAC (section F.5).
- [ ] Logs sans erreur critique récurrente.

### Conditionnel

- [ ] Build frontend validé (manifest, 0 asset manquant) **si** le déploiement inclut des changements `resources/js`, layouts ou pages Inertia.
- [ ] `rbac:sync-permission-catalog --status` puis `--dry-run` si permissions catalogue modifiées.
- [ ] Sync avec `--confirm` **seulement** si des permissions manquantes sont confirmées.
- [ ] Migrations **seulement** si autorisées explicitement et préflight OK.
- [ ] Attribution `customers.view` aux gestionnaires concernés (données admin, pas le sync).

---

## Encadré — Première installation client (o2switch)

> **Précautions supplémentaires**
>
> - Créer `.env` **sur le serveur** (ne jamais commiter) ; vérifier `DB_*`, `APP_URL`, Redis socket **[SERVEUR]**.
> - Utiliser un compte MySQL **application** dédié (CRUD), pas `root` **[DÉPÔT]**.
> - Premier schéma : migrations avec autorisation explicite ; **jamais** `migrate:fresh` sur une base métier nommée `gestion`.
> - Alimenter le catalogue : `rbac:sync-permission-catalog` avec `--status` / `--dry-run` puis `--confirm` adapté au **nom réel** de la base.
> - Build frontend obligatoire : le clone Git seul ne garantit pas un menu à jour.
> - Ne pas confondre démo NIANE et contraintes contractuelles client (sauvegardes, RPO, comptes).
> - Documenter le chemin exact de l’installation dans la fiche client (hors dépôt public si sensible).

---

## Références croisées

- Politique sécurité base : `docs/database-safety-policy.md`
- Catalogue RBAC : `docs/rbac-permission-catalog.md`
- Architecture RBAC : `docs/rbac-architecture.md`
- Sauvegardes : `docs/preprod-backup-strategy.md`
- Rapports OVH / NIANE historiques : `docs/preprod-phase-12.4-niane-deployment-preflight-report.md` **[OVH]**

---

*Document créé pour o2switch. Les commandes Artisan citées existent dans le dépôt au moment de la rédaction ; toute commande impliquant une base ou un chemin serveur doit être adaptée et validée sur site.*
