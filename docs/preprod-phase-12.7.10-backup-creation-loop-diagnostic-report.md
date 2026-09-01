# PRE-PROD 12.7.10 — DIAGNOSTIC BOUCLE DE CRÉATION DES SAUVEGARDES

## STATUS

```text
DIAGNOSTIC COMPLETE
ROOT CAUSE IDENTIFIED
IMPLEMENTATION BLOCKED (diagnostic-only)
```

---

## Symptôme

Depuis `/admin/backups`, après **un** clic de création :

1. une sauvegarde est bien créée (ZIP + meta + audit) ;
2. l’UI / le terminal **semble** relancer la création en boucle ;
3. au final, **une seule** sauvegarde est enregistrée.

Comportement attendu : **1 action = 1 job = 1 sauvegarde**, puis arrêt définitif du polling et état `success`.

---

## Flux actuel

```text
UI runCreate()
  → POST admin.backups.store
  → BackupController::store
  → BackupCreationService::start  (1× CreateBackupJob::dispatch)
  → queue database
  → CreateBackupJob::handle
  → Artisan backup:production [--only-db]
  → ZIP (+ R2) → attachManualMetadata → BACKUP_CREATED
  → Progress Cache status=completed

Parallèle UI :
  flash.backup_job_id
  → startCreatePolling(jobId)
  → setInterval 2s → GET create-status/{jobId}  (read-only)
  → completed → stopCreatePolling + router.visit(only: backups/summary/operations_busy)
```

---

## Cause racine

**Pas de double création backend.**  
La « boucle » est principalement une **race / réentrance frontend autour du polling + flash `backup_job_id`**, qui **réaffiche l’état `queued` à 5 %** et peut **re-déclencher des cycles poll → completed → visit** pour le **même** `jobId`, sans nouveau dispatch métier.

Classification :

```text
Job success mal interprété / réinitialisé par l’UI
+ Race condition frontend (polling concurrent + double startCreatePolling)
+ Flash backup_job_id conservé côté client après visit partiel
```

**Ce n’est pas** :

- un retry Laravel du même job (`$tries` absent → 1 tentative) ;
- un dispatch depuis `create-status` ;
- un double appel à `BackupCreationService::start` pour un run UI réussi (preuve logs).

---

## Preuves

### 1. Endpoint `create-status` = read-only

`BackupController::createStatus` (L112–140) :

- lit uniquement `BackupCreationProgress::get($jobId)` ;
- **aucun** `dispatch`, **aucun** `BackupCreationService::start`, **aucun** `backup:production` ;
- 404 si cache absent ; 403 si autre user.

### 2. Un seul site de dispatch métier

| Fichier | Méthode | Ligne | Déclencheur |
|---|---|---|---|
| `app/Services/Backup/BackupCreationService.php` | `start` | 36 | `CreateBackupJob::dispatch(...)` |
| `app/Http/Controllers/Admin/BackupController.php` | `store` | 80 | `$creation->start(...)` (UI) |
| `app/Http/Controllers/Admin/BackupController.php` | `restore` | 383–391 | `Artisan::call('backup:production')` + `attachManualMetadata` (**pas** de `CreateBackupJob`) |
| Tests | `BackupCriticalFixesTest` | ~303 | tests uniquement |

Aucun autre `CreateBackupJob::dispatch` hors tests.

### 3. Logs : 1 clic UI → 1 job → 1 succès (run 20:18)

```text
20:18:29  backup.ui.create.queued
20:18:30  backup.job.create.start  job_id=863cb4ac-…
20:18:43  backup.job.create.success  filename=2026-08-31-20-18-34.zip
```

Audit : 1× `BACKUP_CREATED` pour ce ZIP.  
Pas de second `backup.ui.create.queued` ni second `job_id` pour cette action.

### 4. Frontend : double démarrage du polling + reset à 5 %

`resources/js/pages/Admin/Backups/Index.vue` :

| Mécanisme | Lignes | Effet |
|---|---|---|
| `onSuccess` → `startCreatePolling(flash.backup_job_id)` | ~1567–1571 | démarre le poll |
| `watch(flash, syncFlashFromPage)` | 1225–1236 + 1106–1107 | **re**appelle `startCreatePolling` sur le même flash |
| `onMounted` → `syncFlashFromPage` | 1215–1217 | 3ᵉ voie possible au montage |
| `startCreatePolling` | 1118–1131 | **réinitialise toujours** `status=queued`, `percentage=5`, message « file d’attente », `createPollAttempts=0`, **nouveau** `setInterval` |

Donc après un POST réussi, l’UI peut **repasser à 5 % « En file »** alors que le job tourne déjà — impression de « nouvelle création ».

### 5. Polling concurrent sans mutex

`pollCreateStatus` (1134–1199) :

- appelé immédiatement + toutes les 2 s ;
- **pas** de garde « requête déjà en cours » ;
- si latence > 2 s : plusieurs `fetch` en parallèle ;
- plusieurs réponses `completed` → plusieurs `router.visit(...)`.

### 6. `router.visit` partiel conserve `flash.backup_job_id`

Sur `completed` (1182–1190) :

```text
only: ['backups', 'summary', 'operations_busy']
```

→ prop `flash` **non rechargée** → `backup_job_id` **reste** dans `page.props` côté client.

Toute réexécution ultérieure de `syncFlashFromPage()` (watch / remount) peut **relancer le polling** pour le **même** job déjà `completed` :

```text
startCreatePolling → UI 5 % queued
  → poll → completed
  → visit again
  → (flash toujours là) → boucle visuelle
```

Sans nouveau `CreateBackupJob`.

### 7. Retry Laravel

`CreateBackupJob` : pas de `$tries` / `$backoff` / `failed()` / `retryUntil()`.  
Payload failed historiques : `maxTries=null` → **1 tentative**.  
Les multi-UUID passés = **dispatches distincts** (clics / tests), pas des retries.

### 8. Lock

Toujours uniquement dans `backup:production`.  
`BackupCreationService::start` refuse seulement si lock déjà tenu (exception → flash error, pas de boucle dispatch).  
Pas de preuve de « job B/C lock refusé → re-création UI » sur le run réussi 20:18.

---

## Nombre de dispatches / jobs (scénario réussi documenté)

| Mesure | Valeur (20:18 UI) |
|---|---|
| Clics utilisateur | 1 (déclaré) |
| `backup.ui.create.queued` | **1** |
| `CreateBackupJob` UUID / job_id | **1** (`863cb4ac-…`) |
| Tentatives Laravel | **1** |
| ZIP créés | **1** (`2026-08-31-20-18-34.zip`) |
| `BACKUP_CREATED` | **1** |
| Sauvegardes visibles ajoutées | **1** |

---

## Polling

- Intervalle : **2 s** (`setInterval`).
- Arrêt prévu sur `completed` / `failed` / timeout 180 tentatives / `onUnmounted`.
- Problèmes : **double `startCreatePolling`**, **reset agressif à 5 %**, **polls concurrent**, **flash conservé après `visit` partiel**.
- Les appels `GET create-status` sont **sans effet de création**.

---

## Retry

```text
Laravel job retry = ABSENT (1 try)
```

---

## Lock

```text
Ownership = backup:production uniquement
État post-succès observé = unlocked (runs précédents)
```

---

## Impact

| Couche | Impact réel |
|---|---|
| Backend création | **1 job / 1 ZIP** (pas de boucle métier prouvée) |
| UI | **Réaffiche « création en file / 5 % »** et peut **re-poller / re-visiter** |
| Terminal worker | Peut montrer **plusieurs lignes** (polls, visits, autres jobs queue, sous-process Spatie) sans multi-ZIP |
| Liste sauvegardes | **Une** entrée pour l’action réussie |

---

## Correction recommandée (NE PAS APPLIQUER EN 12.7.10)

Cible principale : **frontend** (+ flash session optionnel).

1. **Idempotence `startCreatePolling(jobId)`**  
   - si `createJobId === jobId` et timer déjà actif → no-op ;  
   - ne **jamais** réinitialiser à `queued/5%` si status courant est `running`/`completed` pour ce job.

2. **Une seule source de démarrage**  
   - soit `onSuccess`, soit `syncFlashFromPage`, pas les deux ;  
   - ou flag `pollingStartedForJobId`.

3. **Consommer `backup_job_id`** après démarrage du poll  
   - frontend : ignorer flash déjà traité ;  
   - backend optionnel : `session()->pull('backup_job_id')` dans le share Inertia, ou ne plus le re-partager après lecture.

4. **Mutex sur `pollCreateStatus`** (`pollingInFlight`) pour éviter doubles `completed` → doubles `visit`.

5. **Sur `completed`**  
   - clear local `flash.backup_job_id` / ne pas dépendre du flash ;  
   - `router.visit` avec `only` **ou** remplacer par `router.reload({ only: [...] })` après avoir vidé le job flash ;  
   - ne pas rappeler `startCreatePolling` après succès.

6. **Ne pas** réintroduire de lock dans Controller/Job.  
7. **Ne pas** changer `backup:production` / R2 / credentials pour ce bug.

### Risques de la correction

| Risque | Niveau |
|---|---|
| Régression : poll qui ne démarre plus | Moyen — couvrir par test manuel 1 clic |
| Flash success perdu | Faible |
| Double Swal succès si mauvais garde | Faible — unifier notice |

### Test nécessaire après correction (12.7.11)

```text
1 clic UI
→ 1× backup.ui.create.queued
→ 1× CreateBackupJob (1 job_id)
→ UI : queued → running → completed (sans retour à 5 %)
→ polling arrêté
→ 1 ZIP / 1 sidecar / 1 BACKUP_CREATED
→ pas de second visit/poll loop
```

Mesurer aussi le nombre de `GET create-status` (attendu : borné, s’arrête net).

---

## Sécurité / non-modification (cette phase)

```text
R2 : UNCHANGED
Credentials : UNCHANGED
.env : UNCHANGED
Database schema : UNCHANGED
OVH : UNCHANGED
Production : UNCHANGED
Scheduler : UNCHANGED
backup:production : UNCHANGED
CODE_MODIFIED : NO
FAILED_JOBS_DELETED : NO
BACKUP_EXECUTED_BY_DIAGNOSTIC : NO
```

Seul livrable écrit : ce rapport.

---

## Verdict (synthèse)

1. **Cause racine** : réentrance UI du polling + reset à 5 % + flash `backup_job_id` conservé ; **pas** de multi-dispatch backend sur succès.  
2. **Preuve** : logs 20:18 (1 queued / 1 start / 1 success) + code `Index.vue` L1106–1131, L1182–1190, L1225–1236, L1567–1571 + `createStatus` read-only.  
3. **Fichiers** : `Index.vue`, `HandleInertiaRequests.php` (share flash), `BackupController::store` / `createStatus`, `BackupCreationService`, `CreateBackupJob`.  
4. **Correction** : idempotence polling + consommation flash + mutex poll (phase 12.7.11).  
5. **Risque** : régression démarrage poll — test 1 clic obligatoire.  
6. **Rapport** : `docs/preprod-phase-12.7.10-backup-creation-loop-diagnostic-report.md`.

```text
STOP — WAITING FOR HUMAN APPROVAL FOR 12.7.11
```
