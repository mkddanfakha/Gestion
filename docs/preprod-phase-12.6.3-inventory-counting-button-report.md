```text
========================================
PRE-PROD 12.6.3 — INVENTORY COUNTING
========================================

STATUS: COMPLETE

BUG:
"Terminer le comptage" reste désactivé
après le dernier comptage jusqu'au reload.

BUTTON_DISABLED_CONDITION (avant):
!canSubmitNow || workflowLoading || !session.permissions?.submit || !session.can_submit

ROOT CAUSE:
Double source de vérité pour l'éligibilité au submit.

1. canSubmitNow = computed local depuis items (réactif) :
   progress.uncounted === 0 && status === 'counting'
2. session.can_submit = flag serveur figé au chargement / workflow

Après scan ou saisie manuelle :
- items.value est mis à jour via applyScanToItems → canSubmitNow passe à true
- session.can_submit reste false (endpoints inventory.scan / inventory.items.count
  ne renvoient que l'item, pas le payload session)
- le bouton reste disabled à cause de !session.can_submit

Au reload, le backend recalcule can_submit=true → bouton actif.

AFFECTED FILES:
- resources/js/pages/Inventory/InventoryDetailView.vue
- resources/js/utils/inventoryCounting.ts
- resources/js/utils/inventoryCounting.test.ts

FRONTEND FIX:
1. canSubmitNow utilise resolveInventoryCanSubmit(status, items)
   (même logique que InventorySessionService::canSubmit)
2. Bouton :disabled n'utilise plus le flag figé session.can_submit
   (conserve permissions.submit + workflowLoading + canSubmitNow)
3. applyCountedItemToLocalState() après scan / saisie manuelle :
   met à jour items + session.progress + session.can_submit

BACKEND MODIFIED:
NO

DATABASE:
UNCHANGED

ENV:
UNCHANGED

OVH:
NOT TOUCHED

PROTECTED SITE:
NOT TOUCHED

RELOAD WORKAROUND:
NOT USED

TIMEOUT WORKAROUND:
NOT USED

INCOMPLETE COUNT:
PASS (uncounted > 0 → canSubmitNow false)

LAST ITEM COUNT:
PASS (après applyCountedItemToLocalState → canSubmitNow true immédiatement)

ZERO QUANTITY:
PASS (isInventoryItemCounted(0) === true ; tests unitaires)

MOBILE:
PASS (même condition disabled ; pas de CSS spécifique au bug)

DARK MODE:
PASS (état enabled/disabled inchangé visuellement)

REGRESSION:
PASS (tests inventoryCounting 10/10 ; workflow postWorkflow met toujours
     à jour session complète côté transitions start/submit/etc.)

BUILD:
PASS (npm run build, exit code 0)

DEPLOYMENT:
NOT EXECUTED

========================================
END PRE-PROD 12.6.3
========================================
```
