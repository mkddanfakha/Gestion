# PRE-PROD 12.7.15 — RESTORE 403 FALSE SUCCESS FIX REPORT

## OBJECTIVE

Corriger le bug où une restauration refusée avec **HTTP 403** affichait malgré tout un SweetAlert « Succès » et déclenchait un refresh de liste, alors que la restauration n'avait pas eu lieu.

Comportement attendu :

```text
POST restore → 403 → erreur claire → pas de succès → pas de router.visit post-succès
```

## ROOT CAUSE

Inertia v2 traite une réponse Inertia valide (`X-Inertia`) comme visite réussie dans `onSuccess`, même avec **HTTP 403**, tant qu'il n'y a pas de `props.errors` (validation 422).

Le handler `runRestore()` :

1. appelait `closeRestore(true)` avant toute vérification ;
2. ne lisait que `flash.error`, pas `props.error` ni `component === 'errors/403'` ;
3. utilisait un **fallback optimiste** sans `flash.success` :

```text
Restauration terminée. Aucun fichier applicatif n'a été modifié.
```

4. lançait `router.visit()` dans tous les cas non-`flash.error`.

## FILES MODIFIED

```text
resources/js/pages/Admin/Backups/Index.vue
resources/js/utils/backupRestoreResponse.ts          (new)
resources/js/utils/backupRestoreResponse.test.ts     (new)
docs/preprod-phase-12.7.15-restore-403-false-success-fix-report.md
```

(+ artefacts `public/build/*` générés par `npm run build`)

## FRONTEND FIX

Nouveau module `backupRestoreResponse.ts` :

- `resolveRestoreSuccessResponse(page)` — logique pure pour `onSuccess` ;
- `resolveRestoreErrorResponse(errors)` — logique pour `onError` ;
- détection `errors/*` et `props.error` → **erreur**, `refreshList: false` ;
- `flash.error` → erreur, pas de refresh ;
- `flash.success` → **seul** chemin succès + `refreshList: true` ;
- absence de `flash.success` → erreur générique, **pas de fallback optimiste**.

`runRestore()` mis à jour :

```text
onSuccess:
  resolution = resolveRestoreSuccessResponse(inertiaPage)
  Swal.close()
  si error → showErrorNotice + return (pas closeRestore, pas router.visit)
  si success → closeRestore(true) + showSuccessNotice + router.visit

onError:
  showErrorNotice(resolveRestoreErrorResponse(errors))

onFinish:
  processing = false, activeAction = null (inchangé)
```

## BACKEND

```text
UNCHANGED
```

`BackupController::restore`, guards, allow-list, `DatabaseAccountGuard` : non modifiés.

## TESTS

| Suite | Résultat |
|---|---|
| Vitest `backupRestoreResponse.test.ts` | **14/14 PASS** |
| Vitest `backupCreatePolling.test.ts` (régression) | **12/12 PASS** |
| Pest `BackupManifestTest` + `BackupCriticalFixesTest` | **30/30 PASS** |
| `npm run build` | **PASS** |

Couverture des scénarios demandés :

| Test | Statut |
|---|---|
| 403 + `errors/403` | PASS |
| `props.error` | PASS |
| `flash.success` réel | PASS |
| `flash.error` | PASS |
| `errors/409` | PASS |
| 422 via `onError` | PASS |
| 500 string via `onError` | PASS |
| pas de fallback sans `flash.success` | PASS |

## HTTP 403

```text
403 → ERROR
403 → NO SUCCESS
403 → NO SUCCESS REFRESH
```

Confirmé par résolution `refreshList: false` + absence d'appel `router.visit` sur le chemin erreur.

## REAL RESTORE

```text
NOT EXECUTED
```

Validation par tests automatisés uniquement (interdit phase 12.7.15).

## DATABASE

```text
UNCHANGED
```

## ENV

```text
UNCHANGED
```

## OVH

```text
UNCHANGED
```

## R2

```text
UNCHANGED
```

## SECURITY

```text
PathGuard → PRESERVED
Inspector → PRESERVED
DatabaseAccountGuard → PRESERVED
ProtectedDatabaseException → PRESERVED
Restore allow-list → PRESERVED
Double confirmation → PRESERVED
Safety backup → PRESERVED
gestion → BLOCKED (unchanged)
gestion_recovery / gestion_test → ALLOWED (unchanged)
```

## BUILD

```text
PASS
```

## FINAL VERDICT

```text
RESTORE 403 FALSE SUCCESS FIXED
```

---

**STOP** — en attente de validation humaine.
