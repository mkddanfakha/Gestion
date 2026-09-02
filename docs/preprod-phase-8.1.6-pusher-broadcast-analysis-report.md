# PRE-PROD 8.1.6 — PUSHER / BROADCAST ANALYSIS REPORT

**Date :** 2026-09-02  
**Phases :** Diagnostic READ-ONLY → correction contrôlée configuration test

---

## BASELINE

```text
957 total
884 pass
73 fail
41 Pusher/Broadcast failures (classification 8.1.5)
```

---

## TEST ISOLATION

```text
TEST DB HOST:        N/A (sqlite in-memory)
TEST DB PORT:        N/A
TEST DB DATABASE:    :memory:
TEST DB USER:        N/A
TEST ENVIRONMENT:    testing (phpunit.xml)
```

```text
gestion:             NOT USED
gestion_recovery:    NOT USED
gestion_test:        NOT USED (no writes)

STATUS: PASS — isolation confirmée
```

---

## 41 FAILURES — LISTE EXHAUSTIVE

| # | Test | File | Error | Root component |
|---|------|------|-------|----------------|
| 1–11 | SalePaymentTest (11) | `tests/Feature/SalePaymentTest.php` | `assertRedirect` false (HTTP 500) | SaleObserver |
| 12–21 | SaleStockTest (10) | `tests/Feature/SaleStockTest.php` | idem | SaleObserver |
| 22–23 | ProductStockTest (2) | `tests/Feature/ProductStockTest.php` | idem | SaleObserver / stock |
| 24–26 | CustomerCrmTest (3) | `tests/Feature/CustomerCrmTest.php` | `BroadcastException` | NotificationService |
| 27–28 | InventoryConcurrencyTest (2) | `tests/Feature/InventoryConcurrencyTest.php` | `BroadcastException` | SaleObserver |
| 29–30 | PurchaseOrderDeliveryTest (2) | `tests/Feature/PurchaseOrderDeliveryTest.php` | `BroadcastException` | Stock notifications |
| 31–33 | SaleVisibilityTest (3) | `tests/Feature/SaleVisibilityTest.php` | `BroadcastException` | SaleObserver |
| 34 | SensitiveRoutesTest notification admin | `tests/Feature/Rbac/SensitiveRoutesTest.php` | HTTP 500 | Notification test route |
| 35 | ND admin stock alerts | `NotificationDistributionTest.php` | `BroadcastException` | NotificationService |
| 36–38 | ND seller/grouped/replenishment (3) | idem | `BroadcastException` | NotificationService |
| 39 | NotificationGroupedAlertTest | NC module | `BroadcastException` | PusherRealtimeProvider |
| 40–41 | NotificationGroupedProductsTest (2) | NC module | `BroadcastException` | PusherRealtimeProvider |

**Total vérifié : 41**

---

## ROOT CAUSES

### RC1 — Décalage configuration broadcast test (33 tests) — **CORRIGÉ**

| Champ | Détail |
|-------|--------|
| **Cause** | `phpunit.xml` définit `BROADCAST_CONNECTION=log` (convention Laravel 12) mais `config/broadcasting.php` du projet lit `env('BROADCAST_DRIVER', 'pusher')`. La variable `BROADCAST_CONNECTION` est ignorée → driver effectif = `pusher` en test. |
| **Classification** | TEST CONFIGURATION |
| **Production impact** | NO |
| **Blocker** | NO |

**Preuve :** Framework Laravel 12 vendor utilise `BROADCAST_CONNECTION` ; projet MKD-Pro utilise encore `BROADCAST_DRIVER` dans `config/broadcasting.php` ligne 18.

### RC2 — Tests NotificationDistribution obsolètes (6 tests) — **HORS CORRECTION 8.1.6**

| Erreur | Cause |
|--------|-------|
| `TypeError` (4) | Test passe `NotificationType` enum là où le module attend `string` |
| `QueryException` (1) | `sales.user_id` NULL — fixture incomplète |
| `Error` (1) | `NotificationRepository::isLegacyDismissed()` inexistant |

**Classification :** OBSOLETE TEST / TEST DATA  
**Révélés après correction Pusher** (masqués par HTTP 500 avant)

### RC3 — Unique constraint notification_reads (2 tests) — **HORS CORRECTION 8.1.6**

| Test | Erreur |
|------|--------|
| `NotificationGroupedAlertTest` | `UNIQUE constraint notification_reads` |
| `NotificationGroupedProductsTest` api | idem |

**Classification :** TEST DATA / obsolete test wiring  
**Révélés après correction Pusher**

---

## SALE OBSERVER — FLUX BROADCAST

```text
TEST (POST sales.store)
   ↓
Sale::create() / update()
   ↓
SaleObserver::created() / updated()
   ↓
App\Services\NotificationService::handleSaleInvoiceDue() / handleSaleCompleted()
   ↓
Module NotificationService::create() / dispatch()
   ↓
broadcast() — si realtime_enabled
   ↓
dispatchImmediateBroadcast() (critical/grouped/immediate) OU SendNotificationJob (sync queue)
   ↓
PusherRealtimeProvider::broadcast()
   ↓
event(NotificationSent) — ShouldBroadcastNow
   ↓
Laravel BroadcastManager → driver config('broadcasting.default')
   ↓
AVANT FIX: pusher → PusherBroadcaster → API réseau → BroadcastException
APRÈS FIX: log → LogBroadcaster → aucun réseau
```

| Étape | Fichier |
|-------|---------|
| Observer | `app/Observers/SaleObserver.php` |
| Façade métier | `app/Services/NotificationService.php` |
| Module | `app/Modules/NotificationCenter/Services/NotificationService.php` |
| Realtime | `PusherRealtimeProvider.php` |
| Event | `NotificationSent.php` (ShouldBroadcastNow) |
| Config | `config/broadcasting.php`, `phpunit.xml` |

---

## BROADCAST CONFIGURATION (sans secrets)

| Variable | phpunit.xml (avant fix) | phpunit.xml (après fix) | Effectif avant | Effectif après |
|----------|-------------------------|-------------------------|----------------|----------------|
| `BROADCAST_CONNECTION` | `log` | `log` | ignoré | ignoré |
| `BROADCAST_DRIVER` | non défini | **`log`** | **`pusher`** (défaut config) | **`log`** |
| `QUEUE_CONNECTION` | `sync` | `sync` | sync | sync |
| `PUSHER_APP_KEY` (.env.testing) | `testing` (dummy) | idem | utilisé si pusher | non utilisé |

```text
PUSHER_APP_ID:     <REDACTED> (dummy "testing" in .env.testing)
PUSHER_APP_KEY:    <REDACTED>
PUSHER_APP_SECRET: <REDACTED>
```

---

## PUSHER

```text
Real Pusher call (before fix):     YES — during tests
Real Pusher call (after fix):      NO — log driver

Production credentials used:       NO (dummy "testing" keys)

External network call (before):    YES — api.pusher.com rejected invalid key
External network call (after):     NO
```

---

## PRODUCTION IMPACT

| Question | Réponse | Justification |
|----------|---------|---------------|
| Les 41 échecs empêchent-ils MKD-Pro en production ? | **NON** | Problème configuration test uniquement |
| Broadcast fonctionne en production ? | **OUI** (si Pusher configuré) | `BROADCAST_DRIVER=pusher` en prod |
| Bug applicatif confirmé ? | **NON** | Code broadcast intentionnel |
| Risque perte de données ? | **NON** | |
| Risque corruption de données ? | **NON** | |
| Appel Pusher incorrect en production ? | **INCONNU** — probablement NON | Prod utilise credentials réels |
| Blocker production ? | **NON** | |

**Note résilience (P2, hors scope correction) :** `dispatchImmediateBroadcast()` relance l'exception (`throw $e` ligne 415). Si Pusher est indisponible en production, une vente pourrait échouer. Comportement à évaluer séparément — non corrigé en 8.1.6.

---

## CORRECTION

```text
CORRECTION JUSTIFICATION

ROOT CAUSE:
phpunit.xml set BROADCAST_CONNECTION=log but project config/broadcasting.php
reads BROADCAST_DRIVER. Tests used pusher driver with invalid dummy credentials.

FILES TO MODIFY:
phpunit.xml

EXPECTED CHANGE:
Add <env name="BROADCAST_DRIVER" value="log"/>

PRODUCTION IMPACT:
NONE — phpunit.xml affects tests only

DATABASE IMPACT:
NONE

EXTERNAL API IMPACT:
NONE

ROLLBACK:
Remove BROADCAST_DRIVER line from phpunit.xml
```

### FILES MODIFIED

| File | Change |
|------|--------|
| `phpunit.xml` | Ajout `BROADCAST_DRIVER=log` |
| `docs/preprod-phase-8.1.6-pusher-broadcast-analysis-report.md` | Ce rapport |

**Code production modifié : NON**

### TESTS BEFORE / AFTER (famille Pusher — 11 fichiers)

| Métrique | Avant fix | Après fix |
|----------|-----------|-----------|
| Tests exécutés (famille) | 117 | 117 |
| PASS | 76 | **109** |
| FAIL | 41 | **8** |
| Pusher résolus | — | **33** |

Les 8 échecs restants ne sont **pas** des échecs Pusher (TypeError, QueryException, Error, UniqueConstraint).

### Projection suite globale

```text
BEFORE (8.1.5):  884 PASS / 73 FAIL
EXPECTED AFTER:  917 PASS / 40 FAIL  (73 - 33 = 40)
```

*(Suite complète non relancée — conformément au périmètre)*

---

## REGRESSION

Tests exécutés post-correction :

| Fichier / domaine | Résultat |
|-------------------|----------|
| SalePaymentTest | 13/13 PASS |
| SaleStockTest | 15/15 PASS |
| ProductStockTest | 15/15 PASS |
| CustomerCrmTest | 10/10 PASS |
| InventoryConcurrencyTest | 2/2 PASS |
| PurchaseOrderDeliveryTest | PASS (tous) |
| SaleVisibilityTest | PASS (tous) |
| SensitiveRoutesTest | 19/19 PASS |
| NotificationSettingsTest `realtime_disabled` | 1/1 PASS (référence) |

Aucune régression observée dans les domaines Sales / Broadcast / Observer.

---

## GIT

```text
COMMIT:                  NO
PUSH:                    NO
UNAUTHORIZED FILE CHANGES: NO (phpunit.xml + report uniquement — autorisés)
```

---

## DATABASE SAFETY

```text
gestion:              UNCHANGED
gestion_recovery:     UNCHANGED
PRODUCTION DATABASE:  UNTOUCHED
```

---

## EXTERNAL SYSTEMS

```text
OVH:         UNTOUCHED
R2:          UNTOUCHED
PUSHER:      NO REAL CALL (after fix)
PRODUCTION:  UNTOUCHED
```

---

## TABLEAU GROUPES (41)

| Groupe | Nombre | Cause | Classification | Prod Impact | Blocker |
|--------|-------:|-------|----------------|-------------|---------|
| Sales/Payment/Stock/CRM/Inventory/Delivery/Visibility/RBAC | 33 | BROADCAST_DRIVER manquant en test | TEST CONFIG | NO | NO |
| NotificationDistribution (non-broadcast) | 6 | Tests obsolètes / test data | OBSOLETE / TEST DATA | NO | NO |
| NC Grouped alert/products | 2 | Unique constraint test data | TEST DATA | NO | NO |
| **Total** | **41** | | | | |

---

## VERDICT

```text
PUSHER/BROADCAST VERIFIED WITH TEST WARNINGS
```

- Cause Pusher **démontrée techniquement** et **corrigée** (configuration test)
- **33/41** échecs Pusher résolus
- **8** échecs restants dans les mêmes fichiers — causes **non-Pusher** (tests obsolètes / données)
- **Aucun bloqueur production** identifié
- **Aucun appel Pusher réel** après correction

---

```text
STOP — WAITING FOR HUMAN VALIDATION
```
