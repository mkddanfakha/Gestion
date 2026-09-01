# PRE-PROD 12.7.11 — FIX BOUCLE UI CRÉATION SAUVEGARDE

## STATUS

```text
PASS
```

---

## 1. Diagnostic

Cause exacte (12.7.10 confirmée et corrigée) :

1. `startCreatePolling` appelé depuis **`onSuccess`** et **`watch(flash)`** / `syncFlashFromPage`
2. chaque appel **réinitialisait** l’UI à `queued` / **5 %**
3. `setInterval` + polls **concurrents** sans mutex
4. `router.visit({ only: [...] })` **conservait** `flash.backup_job_id` côté client → risque de re-armement

Backend : **pas** de multi-dispatch (1 clic → 1 job → 1 ZIP).

---

## 2. Correction

### Fichiers créés

- `resources/js/utils/backupCreatePolling.ts` — contrôleur de polling idempotent + séquentiel
- `resources/js/utils/backupCreatePolling.test.ts` — 11 tests Vitest

### Fichiers modifiés

- `resources/js/pages/Admin/Backups/Index.vue` — utilise le contrôleur ; plus de `setInterval` concurrent
- `app/Http/Middleware/HandleInertiaRequests.php` — `session()->pull('backup_job_id')` (consommation unique serveur)

### Logique

| Règle | Implémentation |
|---|---|
| Single polling | 1 timer `setTimeout` chaîné ; `inFlight` bloque le chevauchement |
| Idempotent start | même `jobId` actif → no-op |
| Flash | `startFromFlash` + set `consumedFlashJobIds` ; `onSuccess` marque consommé avant start |
| Terminal stop | `completed` / `failed` / timeout → stop + `terminalJobIds` |
| Pas de retour à 5 % | `shouldInitializeQueuedProgress` refuse reset après `running`/`completed`/`failed` |
| Visit | 1 refresh liste (`createListRefreshPending`) ; flash serveur déjà `pull` |
| Pas de 2ᵉ POST | polling = GET `create-status` uniquement |

**Non modifié :** `backup:production`, lock, R2, credentials, manifeste, import, restore, scheduler, `.env`.

---

## 3. Polling

```text
single polling instance: PASS
idempotent start: PASS
terminal stop: PASS
flash re-trigger protection: PASS
```

---

## 4. Tests

```text
Vitest backupCreatePolling.test.ts : 11 passed / 11
Pest BackupManifestTest + BackupCriticalFixesTest : 30 passed / 30 (118 assertions)
```

```text
TESTS: 41 passed / 41 (11 Vitest + 30 Pest)
```

---

## 5. Build

```text
npm run build → PASS (✓ built in 1m 40s)
```

---

## 6. Test réel (DB-only, 1 dispatch)

Via `BackupCreationService::start(onlyDb=true)` + worker local :

| Mesure | Résultat |
|---|---|
| job_id | `0946fa1d-3a66-4914-a6c6-0a148cf1154d` |
| `backup.job.create.start` | **1** |
| `backup.job.create.success` | **1** |
| ZIP | `2026-08-31-20-41-28.zip` (zips 7 → **8**) |
| `BACKUP_CREATED` | audits 2 → **3** |
| failed CreateBackupJob | **6** (inchangé) |
| Progress final | `completed` / 100 % |

```text
1 click-equivalent dispatch
1 POST-equivalent (service start → 1 job)
1 job
1 ZIP
1 BACKUP_CREATED
polling controller: terminal stop (unit)
no duplicate creation
```

UI navigateur : validée par les tests d’idempotence flash/onSuccess + `pull` session (rechargement ne re-fournit plus le même `backup_job_id`).

---

## 7. Sécurité

```text
DATABASE SAFETY: PRESERVED
BACKUP LOCK: PRESERVED
PATH GUARD: PRESERVED
IMPORT QUARANTINE: PRESERVED
MANIFEST INTEGRITY: PRESERVED
RESTORE ALLOW-LIST: PRESERVED
R2/S3 TLS: UNCHANGED
```

Protections PRE-PROD 12.7.1 → 12.7.6 / 12.7.9 : **intactes**.

---

## 8. Environnement

```text
OVH: NOT TOUCHED
PRODUCTION: NOT TOUCHED
DATABASE PRODUCTION: NOT TOUCHED
ENV: NOT MODIFIED
SCHEDULER: NOT MODIFIED
R2: NOT MODIFIED
```

---

## Flux final attendu

```text
CLIC UTILISATEUR
  → 1 POST
  → 1 JOB
  → 1 POLLING (séquentiel, idempotent)
  → queued → running → completed
  → STOP POLLING
  → 1 ZIP
  → 1 BACKUP_CREATED
```

Aucun cycle automatique supplémentaire sans nouveau clic.

---

## STATUS

```text
PASS
WAITING HUMAN VALIDATION
NO NEXT PHASE AUTO
```

**STOP.**
