# PRE-PROD 12.7.7 — DIAGNOSTIC CREATEBACKUPJOB

## Verdict

```text
ROOT CAUSE IDENTIFIED
FIX REQUIRED
IMPLEMENTATION BLOCKED (diagnostic-only phase)
```

---

## 1. Symptôme

Depuis `/admin/backups` :

1. Clic « Créer une sauvegarde »
2. Progression UI ~ **5 %**
3. Message : « Création de sauvegarde en file d'attente… »
4. Progression semble bloquée
5. Aucun ZIP créé

Worker :

```text
App\Jobs\CreateBackupJob RUNNING → FAIL (≈ 20–136 ms)
```

Quatre entrées dans `failed_jobs` (UUIDs fournis).

---

## 2. Exception exacte

**Identique pour les 4 jobs :**

| Champ | Valeur |
|---|---|
| Classe | `RuntimeException` |
| Message | `DATABASE SAFETY BLOCK: backup credential file must define DB_USERNAME=gestion_backup.` |
| Fichier | `app/Database/PrivilegedCredentialLoader.php` |
| Ligne | **54** |
| Tentatives job | **1** (`maxTries` = null → défaut Laravel = 1) |
| Timeout job | 600 s (non atteint) |

### Détail par UUID (lecture seule)

| UUID failed_jobs | failed_at | jobId progress | onlyDb | userId |
|---|---|---|---|---|
| `bd6f9d7e-068e-48f5-a8e5-7e04d09c149a` | 2026-08-31 01:00:35 | `e259d971-…` | true | 1 |
| `9844f8b0-cba8-4b46-9187-873b698126b9` | 2026-08-31 01:00:35 | `7c6b0852-…` | true | 1 |
| `a4d2a6d2-258b-475c-8e8b-30b201f49205` | 2026-08-31 01:01:29 | `01371f2e-…` | true | 1 |
| `f73d57c3-0e10-41e9-8194-e00661f30bc4` | 2026-08-31 01:02:30 | `b3c891eb-…` | true | 1 |

**Aucun secret** dans l’exception (message de sécurité uniquement).

### Stack (abrégée, prouvée)

```text
CreateBackupJob::handle (Artisan::call backup:production) L65
  → RunProductionBackupCommand::handle
    → BackupConcurrencyGuard::runBackup
      → PrivilegedProcessRunner::runBackupRun
        → PrivilegedCredentialLoader::loadBackupCredentials L54  ← THROW
```

Logs Laravel (01:00–01:02) :

```text
backup.job.create.start { job_id, only_db:true, user_id:1 }
backup.job.create.exception { message: DATABASE SAFETY BLOCK: … DB_USERNAME=gestion_backup }
```

Spatie / ZIP / mysqldump : **jamais atteints**.

---

## 3. Chaîne d'exécution

```text
UI POST store (only_db)
 → BackupCreationService::start
    → progress Cache status=queued percentage=5
    → CreateBackupJob::dispatch
 → queue:work
 → CreateBackupJob::handle
    → progress running ~15 %
    → Artisan::call('backup:production', [--only-db])
       → PrivilegedProcessRunner::runBackupRun
          → PrivilegedCredentialLoader::loadBackupCredentials
             ✖ BREAK ICI
 → catch → progress failed percentage=0
 → rethrow → failed_jobs
```

**Point de rupture exact :** chargement des credentials backup, **avant** subprocess Spatie `backup:run`.

Étapes non exécutées : Spatie → ZIP → manifeste → sidecar succès → audit `BACKUP_CREATED`.

---

## 4. Cause racine

Fichier local présent :

```text
base_path('.mysql-gestion-backup.local')  → EXISTS, readable, 151 bytes, 3 lines
```

Contenu structurel (clés uniquement — **aucune valeur / secret exposé**) :

| Ligne | Observation |
|---|---|
| 1 | COMMENT (en-tête PRE-PROD 9.3.1) |
| 2 | clé `DB_BACKUP_USERNAME` — **non autorisée** → **ignorée** |
| 3 | clé `DB_BACKUP_PASSWORD` — **non autorisée** → **ignorée** |

`PrivilegedCredentialLoader::ALLOWED_KEYS` n’accepte que :

```text
DB_USERNAME
DB_PASSWORD
DB_HOST
DB_PORT
DB_DATABASE
```

Après parse : **aucune clé retenue** → `DB_USERNAME` vide → exception L54.

Format attendu (doc 12.2 / code) :

```ini
DB_USERNAME=gestion_backup
DB_PASSWORD=<secret>
DB_HOST=127.0.0.1
DB_DATABASE=gestion
```

**Cause précise :** mauvaises clés dans `.mysql-gestion-backup.local` (`DB_BACKUP_*` au lieu de `DB_*`). Ce n’est ni Spatie, ni la queue, ni Xdebug, ni CreateBackupJob en soi.

Note : longueur de la valeur username observée = 14 caractères (compatible avec `gestion_backup`) — le compte semble déjà renseigné sous le **mauvais nom de clé**.

---

## 5. Causes secondaires éventuelles

### B. UI bloquée à ~5 %

- **5 % + message « file d’attente »** = état initial écrit par `BackupCreationService::start` / UI optimistic (`Index.vue`).
- Le job **a bien démarré** : logs `backup.job.create.start` + progress cache actuel pour les 4 `jobId` :

```text
status=failed, percentage=0,
message="La sauvegarde n'a pas pu être créée. Veuillez réessayer."
```

- Donc le backend progress **passe bien en failed** ; le symptôme « reste à 5 % » est soit :
  - observation de l’état initial avant le poll (2 s),
  - et/ou message d’échec **générique** peu explicite (ne mentionne pas le credential file),
  - et/ou poll qui ignore silencieusement 404/!ok (`Index.vue` : `return` sans maj).

Ce n’est **pas** la cause du FAIL worker.

### C. Xdebug

```text
Failed loading E:/wamp64/.../php_xdebug-3.4.0beta1-8.4-x86_64.dll
```

Avertissement local indépendant. Exception prouvée = credential keys. **Xdebug non responsable.**

### Retries / 4 failed jobs

- `CreateBackupJob` : **pas** de `$tries` / `$backoff` custom.
- Payload : `maxTries=null` → **1 tentative** par job.
- **4 `jobId` distincts** → **4 dispatches distincts** (clics / démarrages UI), **pas** des retries Laravel d’un même job.
- Table `jobs` : 10 jobs pending = `SendNotificationJob` (hors sujet), **aucun** `CreateBackupJob` en attente.

---

## 6. Pourquoi les tests 12.7.4 étaient PASS

- `BackupCriticalFixesTest` / `ProductionBackupRunnerTest` **mockent** `PrivilegedProcessRunner::runBackupRun` ou utilisent un fichier temporaire **avec les bonnes clés** `DB_USERNAME=gestion_backup`.
- Ils **ne lisent pas** le vrai `.mysql-gestion-backup.local` du poste.
- Aucun test d’intégration ne valide le format réel du fichier secret local contre `ALLOWED_KEYS`.
- Différence test vs réel : mock / fixture OK ≠ fichier local mal nommé.

---

## 7. Impact

| Domaine | Impact |
|---|---|
| DB `gestion` | **NON touchée** (échec avant dump) |
| Backups existants | **Intacts** (aucun ZIP créé par ces jobs) |
| Import | Non concerné |
| Restore | Non concerné |
| Scheduler | Non modifié ; même chemin `backup:production` échouerait localement avec le même fichier |
| OVH / production | **Non touchés** |
| Queue failed_jobs | 4 entrées conservées (non supprimées) |

---

## 8. Correction recommandée (NE PAS IMPLÉMENTER ICI)

### Correctif principal (ops / secret local)

Réécrire `.mysql-gestion-backup.local` avec les clés attendues par `PrivilegedCredentialLoader` :

```ini
DB_USERNAME=gestion_backup
DB_PASSWORD=<même secret déjà présent sous l’ancienne clé>
DB_HOST=127.0.0.1
DB_DATABASE=gestion
```

- Conserver le fichier **gitignored**.
- **Ne pas** modifier `.env` runtime (`DB_USERNAME=gestion_app`).
- **Ne pas** committer le secret.

### Correctifs applicatifs optionnels (phase 12.7.8+)

1. Message d’erreur loader plus précis si des clés inconnues (`DB_BACKUP_*`) sont détectées.
2. UI : exposer un message failed plus informatif (sans secret) quand progress=`failed`.
3. Test de smoke (env local / CI isolé) vérifiant le parse du format attendu.
4. Documenter un exemple `.mysql-gestion-backup.local.example` **sans** mots de passe.

Après correctif secret : retester UI create + worker (sans `queue:retry` des anciens jobs si on préfère un nouveau clic propre).

---

## 9. Risques de la future correction

| Risque | Niveau |
|---|---|
| Mauvais `DB_DATABASE` / host → dump mauvaise cible | Moyen — vérifier allow-list / config Spatie |
| Mot de passe incorrect → échec mysqldump (autre erreur) | Moyen |
| Édition accidentelle de `.env` runtime | Élevé si non discipliné — à éviter |
| Retry massif des failed_jobs sans fix fichier | Faible — mêmes FAIL |
| Fuite secret dans chat/git | Élevé — ne jamais coller le password |

---

## 10. Safety

```text
DATABASE_MODIFIED=NO
ENV_MODIFIED=NO
OVH_MODIFIED=NO
SCHEDULER_MODIFIED=NO
BACKUP_EXECUTED=NO
RESTORE_EXECUTED=NO
FAILED_JOBS_DELETED=NO
QUEUE_RETRIED=NO
CODE_MODIFIED=NO
```

Seule écriture autorisée : ce rapport.

---

## 11. Compléments d’audit (lecture)

### CreateBackupJob

- Props : `$timeout=600`, `$onlyDb`, `$userId`, `$jobId`
- Pas de `$tries` / `$backoff` / `failed()` / middleware
- `handle` : progress → `Artisan::call('backup:production'[`--only-db`])` → metadata/audit si succès → rethrow si erreur
- Compatible Windows (Artisan in-process + Symfony Process dans le runner) — **non bloqué** ici

### BackupCreationService

- `start` : lock check → progress 5 % → dispatch
- `attachManualMetadata` : non atteint (échec avant ZIP)

### backup:production

- Signature : `backup:production {--only-db}`
- Lock via `BackupConcurrencyGuard`
- Appelle `PrivilegedProcessRunner::runBackupRun` → Spatie `backup:run` en subprocess
- Spatie `spatie/laravel-backup` **9.3.7** — non atteint

### Type transmis

- UI `only_db` → controller → service → job `onlyDb=true` sur les 4 échecs
- Cohérent (DB only)

### Cache progress

- Store app : `cache.default=database`
- TTL 1800 s
- États finaux des 4 jobs : **failed / 0 %** (vérifié en lecture)

### Process / chemins

- Pas de chemin OVH Linux hardcodé dans ce flux
- Destination Spatie via config disk locale (non exercée)

---

## 12. Conclusion

**Cause exacte connue.**  
Échec à ~5 % côté UI = job queueé puis échec immédiat de `backup:production` faute de credentials parseables.  
Corriger le fichier secret local (noms de clés), puis seulement autoriser une phase de fix/validation (12.7.8).

**Aucune implémentation dans cette phase.**

---

## STATUS

DIAGNOSIS COMPLETE — WAITING HUMAN VALIDATION FOR 12.7.8
