# PRE-PROD 12.7.12 — BACKUP CREATION LOOP DIAGNOSTIC

## STATUS

```text
DIAGNOSTIC ONLY
ROOT CAUSE IDENTIFIED — WAITING FOR 12.7.13
```

**Aucun code modifié. Aucune sauvegarde réelle lancée par ce diagnostic.**

---

## SYMPTOM

Cycle UI observé après un seul clic :

```text
SUCCESS (Swal / bannière)
→ 5 %
→ « Création de sauvegarde en file d'attente… » (queued)
→ progression jusqu'à 100 %
→ SUCCESS à nouveau
→ repeat indéfiniment
```

Côté données : **un seul ZIP** / typiquement **un seul** `BACKUP_CREATED` pour ce clic.

---

## BACKEND TRACE

Preuve logs (ex. clic UI 20:46) :

```text
20:46:38  backup.ui.create.queued          ×1
20:46:39  backup.job.create.start          ×1  job_id=b4d60c3e-…
20:46:52  backup.job.create.success        ×1  ZIP=2026-08-31-20-46-44.zip
BACKUP_CREATED audit id=93                 ×1
```

```text
CREATE REQUESTS:     1  (backup.ui.create.queued)
DISPATCHES:          1  (BackupCreationService::start → CreateBackupJob::dispatch)
JOB EXECUTIONS:      1  (backup.job.create.start/success)
ZIP CREATED:         1
BACKUP_CREATED:      1
```

`create-status` : **READ ONLY** (`BackupController::createStatus` — lecture cache uniquement, aucun dispatch).

`CreateBackupJob` : pas de `$tries` / retry / dispatch secondaire.

```text
BACKEND = 1
FRONTEND LOOP > 1
```

---

## FRONTEND TRACE

Mécanisme unique de polling : `resources/js/utils/backupCreatePolling.ts` + `Index.vue`  
(aucun autre `startCreatePolling` / `create-status` hors Backups).

### Appels à `beginCreatePolling` (seul lanceur après 12.7.11)

| FILE | LINE | CALLER | TRIGGER | POSSIBLE REPEAT |
|---|---|---|---|---|
| `Index.vue` | ~1247 | `syncFlashFromPage` | `flash.backup_job_id` présent | YES |
| `Index.vue` | ~1265 | `onMounted` → `syncFlashFromPage` | montage page | YES after remount |
| `Index.vue` | ~1284 | `watch(flash)` → `syncFlashFromPage` | changement flash | YES |
| `Index.vue` | ~1622 | `runCreate` / `router.post` `onSuccess` | après POST create | once per click |

`pollCreateStatus` n’existe plus comme fonction libre : polling via `BackupCreatePollingController.tick` → `fetchStatus` (GET create-status).

### Retour à 5 % — seule écriture

| FILE | LINE | FUNCTION | TRIGGER | AFTER SUCCESS? |
|---|---|---|---|---|
| `Index.vue` | **1214–1219** | `beginCreatePolling` | `shouldInitializeQueuedProgress === true` (souvent `createProgress === null` après remount) | **YES** si polling redémarre |

### Second message de succès

| FILE | LINE | FUNCTION | TRIGGER |
|---|---|---|---|
| `Index.vue` | **1143** | `buildCreatePollingHooks().onCompleted` → `showSuccessNotice` | GET create-status renvoie encore `status=completed` (cache TTL 1800s) après un **nouveau** start de polling |

---

## JOB ID TRACE

Scénario logique du bug (même UUID) :

```text
CLICK
jobId = ABC
POST create → flash.backup_job_id = ABC (puis pull serveur une fois)

POLL … status=completed (cache)
SUCCESS Swal #1

router.visit(index, { only: backups/summary/operations_busy })  // preserveState ABSENT
→ Inertia Vue3: preserveState default false
→ key = Date.now()  → REMOUNT Index.vue
→ nouveau BackupCreatePollingController (terminal/consumed VIDES)
→ page.props.flash.backup_job_id TOUJOURS ABC
   (non inclus dans only: → conservé côté client)

onMounted → syncFlashFromPage → beginCreatePolling(ABC, flash)
→ createProgress null → UI 5 % queued
→ poll create-status(ABC) → completed (cache)
→ SUCCESS Swal #2
→ visit again → REMOUNT → repeat
```

```text
jobId reste IDENTIQUE (ABC)
pas de nouveau dispatch backend
```

---

## ROOT CAUSE

**Combinaison FRONTEND + INERTIA (pas multi-création backend).**

### Cause principale

```text
FILE:     resources/js/pages/Admin/Backups/Index.vue
LINE:     ~1146–1152
FUNCTION: onCompleted → router.visit(...)
TRIGGER:  succès du job → refresh liste SANS preserveState: true
```

Dans `@inertiajs/vue3` / core :

```text
key.value = args.preserveState ? key.value : Date.now();
```

Donc **`preserveState: false` (défaut GET) ⇒ remount forcé** de la page.

### Chaîne après remount

1. Nouveau `BackupCreatePollingController` → sets `terminalJobIds` / `consumedFlashJobIds` **perdus**  
2. `flash.backup_job_id` **encore présent** dans `page.props` (visit `only:` ne le rafraîchit / ne le purge pas)  
3. `onMounted` → `syncFlashFromPage` → `beginCreatePolling(..., 'flash')`  
4. L1214–1219 : reset **5 % / queued**  
5. Poll READ-ONLY → `completed` en cache → `onCompleted` → **nouveau Swal** + **nouveau visit** → boucle

### Pourquoi 12.7.11 n’a pas suffi

L’idempotence / `pull('backup_job_id')` / sets terminaux fonctionnent **dans une même instance Vue**.  
Le remount Inertia **recrée l’instance** ⇒ protections mémoire perdues, alors que le flash client survit.

---

## SECONDARY CAUSE

```text
FILE:     Index.vue
LINE:     ~1246–1247 + ~1264–1265
FUNCTION: syncFlashFromPage / onMounted
TRIGGER:  réarme le polling dès que flash.backup_job_id est encore dans les props
```

```text
FILE:     HandleInertiaRequests.php
LINE:     ~190  session()->pull('backup_job_id')
```

Le `pull` empêche une **nouvelle** injection serveur, mais **ne retire pas** la valeur déjà présente dans le state Inertia client lors d’un `only:` reload.

---

## WHY 5% RETURNS

Remount ⇒ `createProgress` redevient `null` ⇒ `shouldInitializeQueuedProgress` = true ⇒ écriture L1214–1219 (`percentage: 5`, `status: 'queued'`, message file d’attente).

---

## WHY SUCCESS RETURNS

Chaque redémarrage de polling interroge `create-status/{sameJobId}` ; le cache `BackupCreationProgress` garde `completed` (TTL 1800s) ⇒ `onCompleted` ⇒ `showSuccessNotice` (L1143) à chaque cycle.

---

## WHY ONLY ONE ZIP IS CREATED

Le cycle post-succès **ne rappelle pas** `POST store` / `BackupCreationService::start`.  
Il ne fait que **re-poller** un job déjà terminé. Aucun nouveau `backup:production`.

---

## WATCHERS

| WATCHED | FILE | WHEN | CALL | START POLLING? | AFTER SUCCESS? | >1×? |
|---|---|---|---|---|---|---|
| `page.props.flash` (deep) | Index.vue ~1274 | flash change | `syncFlashFromPage` | YES if `backup_job_id` | YES if flash still set | YES |
| `page.url` | BootstrapLayout | URL change | closeSidebar only | NO | — | — |

Pas de `watchEffect` backup. Pas d’autre composable polling backups.

---

## QUESTIONS (réponses obligatoires)

### Q1 — Backend crée plusieurs sauvegardes ?

```text
NO
```

### Q2 — CreateBackupJob exécuté plusieurs fois pour un clic ?

```text
NO
```

### Q3 — Frontend démarre plusieurs polling loops ?

```text
YES  (séquentiellement, via remount + re-start — pas des setInterval parallèles post-12.7.11)
```

### Q4 — Polling redémarre après completed ?

```text
YES
```

### Q5 — flash.backup_job_id réinjecté après consommation ?

```text
YES (côté client Inertia props conservées) / NO (côté session serveur après pull)
```

### Q6 — Autre fonction que startCreatePolling peut lancer le polling ?

```text
YES — beginCreatePolling (remplace startCreatePolling) via syncFlashFromPage / onMounted / onSuccess
```

### Q7 — Ligne exacte retour à 5 % ?

```text
FILE:     resources/js/pages/Admin/Backups/Index.vue
LINE:     1214–1219
FUNCTION: beginCreatePolling
TRIGGER:  redémarrage polling après remount (createProgress null)
```

### Q8 — Ligne exacte second succès ?

```text
FILE:     resources/js/pages/Admin/Backups/Index.vue
LINE:     1143
FUNCTION: onCompleted → showSuccessNotice
TRIGGER:  create-status retourne completed pour le même jobId
```

### Q9 — job ID identique ?

```text
YES
```

### Q10 — Nature du problème ?

```text
MIXED (FRONTEND + INERTIA/FLASH)
```

(pas QUEUE, pas multi-création backend)

---

## PROPOSED FIX (ne PAS appliquer ici — phase 12.7.13)

1. **Sur le refresh post-succès** :  
   `router.reload({ only: [...], preserveScroll: true, preserveState: true })`  
   **ou** `router.visit(..., { preserveState: true })`  
   pour **éviter** `key = Date.now()` / remount.

2. **Purger le flash client** après consommation :  
   `page.props.flash.backup_job_id = null` (ou équivalent Inertia) dans `onSuccess` / après `beginCreatePolling` / dans `onCompleted`.

3. **Ne pas réarmer depuis `onMounted`** si le job est déjà `completed` en cache :  
   optionnellement GET create-status avant d’afficher 5 %, ou ignorer flash si status terminal.

4. **Persister les jobIds terminaux** (`sessionStorage`) pour survivre à un remount accidentel.

5. **Test Vitest / Inertia** :  
   `completed → visit without preserveState → onMounted + flash still set → must NOT reset to 5% / must NOT second Swal`  
   (le test 12.7.11 ne couvrait pas le remount Inertia).

6. Ne pas toucher `backup:production`, lock, R2, credentials, scheduler.

---

## SAFETY

```text
DATABASE: UNCHANGED
ENV: UNCHANGED
OVH: UNCHANGED
R2: UNCHANGED
SCHEDULER: UNCHANGED
BACKUP: NO NEW REAL BACKUP
RESTORE: NOT EXECUTED
CODE_MODIFIED: NO
```

---

## FINAL VERDICT

```text
ROOT CAUSE IDENTIFIED — WAITING FOR 12.7.13
```

**STOP.** Aucun correctif appliqué. Attendre validation humaine explicite avant PRE-PROD 12.7.13.
