# PRE-PROD 12.7.13 — BACKUP CREATION LOOP FIX

## STATUS

```text
PASS
```

---

## ROOT CAUSE

```text
router.visit()
→ preserveState=false (Inertia Vue 3 défaut)
→ Index.vue remount (key=Date.now())
→ onMounted + flash.backup_job_id encore côté client
→ polling restart
→ create-status completed
→ success
→ router.visit()
→ loop
```

Diagnostic confirmé en 12.7.12. Le backend ne créait qu’**une** sauvegarde ; la boucle était purement frontend.

---

## FIX

| Champ | Valeur |
|---|---|
| FILE | `resources/js/utils/backupCreatePolling.ts` |
| LINE | export `backupCreateSuccessListRefreshOptions` (~L36–40) |
| FILE | `resources/js/pages/Admin/Backups/Index.vue` |
| LINE | `onCompleted` ~L1140–1158 |

```text
BEFORE
router.visit(route('admin.backups.index'), {
  preserveScroll: true,
  only: ['backups', 'summary', 'operations_busy'],
})

AFTER
router.visit(route('admin.backups.index'), {
  ...backupCreateSuccessListRefreshOptions,  // preserveState: true, preserveScroll: true
  only: [...backupCreateSuccessListRefreshOptions.only],
  onFinish: () => { createListRefreshPending = false },
})
```

Défense complémentaire dans `onCompleted` : `flash.backup_job_id = null` côté props client pour qu’un éventuel watcher/onMounted ne réarme pas le même job.

`preserveScroll: true` conservé (inchangé).

`onMounted` / reprise flash pour un job **encore** `queued`/`running` : **conservés**.

---

## POLLING

```text
START: 1
COMPLETED: 1
STOP: 1
RESTART AFTER SUCCESS: 0
```

Vitest : `startCreatePolling` équivalent (`controller.start`) reste à **1** après `completed` + tentatives flash/start.

---

## REAL TEST

Une seule création DB-only via `BackupCreationService::start(true, user1)` (équivalent POST create) :

| Mesure | Avant | Après |
|---|---|---|
| ZIP locaux | 9 | **10** |
| `BACKUP_CREATED` | 4 | **5** |
| `failed_jobs` CreateBackupJob | 6 | **6** (inchangé) |

```text
job_id: 4df5350b-c512-4b53-967b-683545c426cd
filename: 2026-08-31-21-36-41.zip
progress: completed / 100 %
backup.job.create.start: 1
backup.job.create.success: 1
```

```text
POST: 1 (équivalent service start)
JOB: 1
ZIP: 1
BACKUP_CREATED: 1
SUCCESS_MESSAGES: 1 (message progress completed ; UI navigateur non rejouée dans cette session)
SECONDARY_5_PERCENT_RESTART: 0 (garanti par preserveState + terminalJobIds ; Vitest)
```

R2 (lecture seule) : objets existants inchangés (`Gestion/2026-08-26-…`, `Gestion/2026-08-31-20-06-45.zip`, …). Le ZIP de ce run est présent en **local** (`Gestion/2026-08-31-21-36-41.zip` + meta). Aucune config R2 modifiée, aucun nettoyage.

---

## TESTS

```text
Vitest resources/js/utils/backupCreatePolling.test.ts : 12 passed
  - backupCreateSuccessListRefreshOptions.preserveState === true
  - preserveScroll === true
  - after completed : start === 1, flash restart === false
  - failed : onFailed === 1, onCompleted === 0, no restart loop

Pest BackupManifestTest + BackupCriticalFixesTest : 30 passed
```

---

## BUILD

```text
PASS (npm run build)
```

---

## SECURITY

```text
DATABASE: UNCHANGED
ENV: UNCHANGED
OVH: UNCHANGED
R2: UNCHANGED (config)
SCHEDULER: UNCHANGED
QUEUE CONFIG: UNCHANGED
RESTORE LOGIC: UNCHANGED
BACKUP COMMAND: UNCHANGED
SAFETY LOCK: UNCHANGED
```

---

## FILES MODIFIED

```text
resources/js/pages/Admin/Backups/Index.vue
resources/js/utils/backupCreatePolling.ts
resources/js/utils/backupCreatePolling.test.ts
docs/preprod-phase-12.7.13-backup-creation-loop-fix-report.md
```

(+ artefacts `public/build/*` générés par `npm run build` — non listés comme correctif fonctionnel)

Non modifiés (interdit phase) : `CreateBackupJob`, `BackupCreationService`, `backup:production`, guards, import, manifest, R2, `.env`, scheduler, audit.

---

## FINAL VERDICT

```text
BACKUP CREATION LOOP FIXED
```

Cause du remount corrigée (`preserveState: true`). Flux attendu :

```text
POST → job → polling → completed → SUCCESS → refresh liste (Index monté) → STOP
```

Aucune reprise automatique `SUCCESS → 5 %` dans le flux normal.

---

**STOP** — attendre validation humaine avant toute nouvelle phase.
