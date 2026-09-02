# PRE-PROD 8.1.7 — NOTIFICATION TESTS ANALYSIS REPORT

**Date :** 2026-09-02  
**Mode :** Diagnostic READ-ONLY — aucune correction appliquée

---

## BASELINE

```text
957 total (suite globale 8.1.5)
884 pass / 73 fail (avant correction Pusher 8.1.6)
40 fail attendus post-8.1.6 (73 - 33 Pusher)

Cible PRE-PROD 8.1.7 : 8 échecs
```

**Exécution ciblée (3 fichiers) :**

```text
TOTAL:   11
PASS:    3
FAIL:    8
SKIP:    0
```

Le nombre **8** est confirmé.

---

## TEST ISOLATION

```text
TEST DB HOST:        N/A
TEST DB PORT:        N/A
TEST DB DATABASE:    :memory: (sqlite)
TEST DB USER:        N/A
TEST ENVIRONMENT:    testing

gestion:             NOT USED
gestion_recovery:    NOT USED

STATUS: PASS
```

---

## TARGETED TESTS — LISTE EXACTE (8)

| # | Test | File | Line | Error | Component |
|---|------|------|------|-------|-----------|
| 1 | manager receives inventory alerts but not backup notifications audience | `NotificationDistributionTest.php` | 46 | `TypeError` — `resolveUserIds()` attend `string`, reçoit `NotificationType` enum | `NotificationAudienceResolver` |
| 2 | seller scoped notification targets only owning vendeur | idem | 68 | `TypeError` — `scopeByType()` attend `string`, reçoit enum | `Notification::scopeByType` |
| 3 | grouped low stock notification is not duplicated | idem | 105 | `TypeError` — idem `scopeByType` | `Notification` |
| 4 | stock replenishment resolves active low stock notification | idem | 133 | `TypeError` — idem `scopeByType` | `Notification` |
| 5 | paid invoice resolves invoice due grouped notification | idem | 147 | `QueryException` — `sales.user_id` NOT NULL | `Sale::create` (test) |
| 6 | legacy dismissed notifications still use read_at only | idem | 182 | `Error` — `isLegacyDismissed()` inexistant | `NotificationRepository` (legacy) |
| 7 | reactivates resolved grouped notification instead of inserting duplicate | `NotificationGroupedAlertTest.php` | 38 | `UniqueConstraintViolationException` | `notification_reads` |
| 8 | api notification resource includes refreshed products | `NotificationGroupedProductsTest.php` | 75 | `UniqueConstraintViolationException` | `notification_reads` |

---

## ROOT CAUSE ANALYSIS

| Groupe | Nombre | Cause racine | Classification | Prod impact | Blocker |
|--------|-------:|--------------|----------------|-------------|---------|
| NotificationDistribution — enum API | 4 | Tests passent `NotificationType` enum aux APIs/scopes qui exigent `string` depuis migration NC | OBSOLETE TEST | NO | NO |
| NotificationDistribution — fixture Sale | 1 | `Sale::create()` sans `user_id` (colonne NOT NULL) | TEST DATA | NO | NO |
| NotificationDistribution — legacy repo | 1 | Test appelle `isLegacyDismissed()` supprimé du module | OBSOLETE TEST | NO | NO |
| NC Grouped — ProductObserver | 2 | `ProductObserver` crée notification groupée avant seed manuel du test → doublon sur contrainte unique | TEST DATA | NO | NO |
| **Total** | **8** | | | | |

---

## NOTIFICATION DISTRIBUTION

### Test 1 — manager inventory alerts (ligne 41–48)

```text
QUE TESTE LE TEST ?
  Vérifier que le gestionnaire reçoit alertes inventaire/stock mais pas backup.

QUEL COMPORTEMENT ATTEND-IL ?
  resolveUserIds(NotificationType::LowStock) contient le manager.

QUEL COMPORTEMENT EST OBSERVÉ ?
  TypeError avant exécution — enum passé à resolveUserIds(string $type).

CE COMPORTEMENT EST-IL TOUJOURS VALIDE ?
  OUI pour la production — le module NC utilise des strings ('low_stock').
  NON pour le test — signature API a changé.
```

**Code production :** `App\Modules\NotificationCenter\Services\NotificationAudienceResolver::resolveUserIds(string $type)` — correct.

**Classification :** OBSOLETE TEST

---

### Tests 2–4 — seller scoped / grouped / replenishment (lignes 50–140)

```text
QUE TESTE LE TEST ?
  Distribution notifications par rôle, déduplication groupée, résolution après réappro.

QUEL COMPORTEMENT ATTEND-IL ?
  Notification::query()->byType(NotificationType::X) ...

QUEL COMPORTEMENT EST OBSERVÉ ?
  TypeError — scopeByType(Builder, string $type) reçoit enum.

ARCHITECTURE ACTUELLE :
  App\Models\Notification étend le module et cast 'type' => NotificationType::class.
  Le scope hérité attend string en paramètre explicite (PHP 8.4 strict).
  Le code production utilise des strings ('low_stock', 'sale_completed', etc.).
```

**Classification :** OBSOLETE TEST — remplacer `NotificationType::X` par `NotificationType::X->value` ou string littérale.

---

### Test 5 — paid invoice (lignes 142–167)

```text
TABLE: sales
COLUMN: user_id
CONSTRAINT: NOT NULL

VALUE: NULL (omis dans Sale::create)

CREATED BY: test ligne 147-156

EXPECTED: handleSaleInvoiceDue + résolution notification

ACTUAL: QueryException avant logique métier
```

**Classification :** TEST DATA — ajouter `user_id` au `Sale::create()`.

---

### Test 6 — legacy dismissed (lignes 169–187)

```text
QUE TESTE LE TEST ?
  Mécanisme legacy isLegacyDismissed() basé sur read_at.

ARCHITECTURE ACTUELLE :
  App\Repositories\NotificationRepository extends module — @deprecated
  Méthode isLegacyDismissed() N'EXISTE PAS dans le module NC.

OBSERVÉ :
  Call to undefined method isLegacyDismissed()
```

**Classification :** OBSOLETE TEST — architecture legacy remplacée par module NotificationCenter.

---

## NOTIFICATION CENTER — GROUPED TESTS

### Test 7 — NotificationGroupedAlertTest

```text
TABLE: notification_reads
CONSTRAINT: UNIQUE (user_id, notification_type, notification_id) — 'notif_reads_unique'

VALUE DUPLIQUÉE: user_id=1, notification_type='low_stock', notification_id=0

SÉQUENCE DU TEST :
  1. Admin créé
  2. Product créé (stock 3, min 5) → ProductObserver::created
     → handleProductStockChange → syncGroupedAlert('low_stock')
     → INSERT notification groupée (low_stock, id=0) — ACTIVE
  3. Test tente Notification::create() manuel resolved (mêmes clés) → VIOLATION

EXPECTED: syncGroupedAlert réactive notification resolved sans doublon
ACTUAL: échec à l'étape 3 — seed manuel en conflit avec observer
```

**La contrainte unique est intentionnelle** (`database/migrations/2025_11_14_144351_create_notification_reads_table.php`).

**Le code production `syncGroupedAlert()`** utilise `findGrouped()` puis `update()` si existant — comportement correct (lignes 186–207 de `NotificationService.php`).

**Classification :** TEST DATA — le test ne tient pas compte de `ProductObserver`.

---

### Test 8 — NotificationGroupedProductsTest API

```text
Même mécanisme :
  1. Product stock_quantity=0 créé
  2. ProductObserver → syncGroupedAlert('stock_out') → notification existante
  3. Notification::create() manuel ligne 75 → UNIQUE violation

INTENTION DU TEST :
  Vérifier que GET /api/notifications enrichit metadata.products via NotificationResource
  (buildPreviews sur entity_ids) — fonctionnalité production VALIDÉE dans le code :
  NotificationResource.php lignes 20-26
```

**Classification :** TEST DATA — conflit observer + seed manuel.

---

## UNIQUE CONSTRAINTS — SYNTHÈSE

| Élément | Détail |
|---------|--------|
| **TABLE** | `notification_reads` |
| **CONSTRAINT** | `UNIQUE(user_id, notification_type, notification_id)` |
| **Correcte en production ?** | **OUI** — évite doublons métier |
| **Violation cause test ?** | **OUI** — double création (observer + seed manuel) avec `notification_id=0` pour alertes groupées |
| **Bug production ?** | **NON** |

---

## PRODUCTION IMPACT

```text
PRODUCTION BUG:        NO
DATA LOSS:             NO
DATA CORRUPTION:       NO
SECURITY RISK:         NO
PRODUCTION BLOCKER:    NO
```

| Question | Réponse |
|----------|---------|
| Les 8 échecs empêchent MKD-Pro en production ? | **NON** |
| Notifications fonctionnent en production ? | **OUI** — module NC actif, settings tests PASS |
| Bug applicatif confirmé ? | **NON** |
| Contrainte unique violée en prod ? | **NON** — comportement voulu |

**Note :** `NotificationDistributionTest` test 1 (`admin receives stock alerts`) **PASS** — preuve que le flux production notification stock fonctionne.

---

## CORRECTION

```text
NO CORRECTION APPLIED
```

### Corrections proposées (PRE-PROD 8.1.8 — après validation humaine)

#### Groupe A — NotificationDistributionTest (6 tests)

| Test | Action proposée |
|------|-----------------|
| manager inventory | `resolveUserIds(NotificationType::LowStock->value)` |
| seller/grouped/replenishment | `byType(NotificationType::X->value)` |
| paid invoice | Ajouter `'user_id' => User::factory()->create()->id` au Sale |
| legacy dismissed | Réécrire vers module NC ou supprimer (méthode legacy absente) |

**Fichier :** `tests/Feature/Notifications/NotificationDistributionTest.php` uniquement

#### Groupe B — NC Grouped tests (2 tests)

| Test | Action proposée |
|------|-----------------|
| GroupedAlertTest | Créer notification manuelle **avant** le produit, OU supprimer notification auto après product, OU `Event::fake` sur observer |
| GroupedProductsTest API | Idem — éviter double insert sur `(user_id, type, 0)` |

**Fichiers :** tests module NC uniquement

```text
CORRECTION JUSTIFICATION (si appliquée ultérieurement)

ROOT CAUSE: Tests non alignés avec API string du module NC + ProductObserver side effects
CLASSIFICATION: OBSOLETE TEST + TEST DATA
FILES TO MODIFY: 3 fichiers test uniquement
PRODUCTION IMPACT: NONE
DATABASE IMPACT: NONE
```

---

## REGRESSION

Aucune correction appliquée — pas de re-test post-correction.

Tests de référence PASS (hors les 8) :

- `NotificationDistributionTest` — admin stock alerts ✓
- `NotificationGroupedProductsTest` — sync grouped + preview provider ✓
- `NotificationSettingsTest` — realtime disabled ✓

---

## DATABASE SAFETY

```text
gestion:           UNCHANGED
gestion_recovery:  UNCHANGED
PRODUCTION:        UNTOUCHED
```

---

## EXTERNAL SYSTEMS

```text
OVH:         UNTOUCHED
R2:          UNTOUCHED
PUSHER:      UNTOUCHED
PRODUCTION:  UNTOUCHED
```

---

## GIT

```text
COMMIT:                NO
PUSH:                  NO
UNAUTHORIZED CHANGES:  NO
```

Seul fichier créé : `docs/preprod-phase-8.1.7-notification-tests-analysis-report.md`

---

## VERDICT

```text
NOTIFICATION TESTS VERIFIED — NO PRODUCTION BLOCKER
```

Les 8 échecs sont intégralement expliqués par des **tests obsolètes** (API enum vs string, méthode legacy supprimée) ou des **données de test incorrectes** (Sale sans user_id, conflit ProductObserver + seed manuel). Aucun défaut production démontré.

---

```text
STOP — WAITING FOR HUMAN VALIDATION
```
