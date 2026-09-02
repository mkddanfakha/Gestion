# PRE-PROD 8.1.3 — INVENTORY FILTER ANALYSIS REPORT

**Date :** 2026-09-02  
**Mode :** READ-ONLY — diagnostic uniquement (aucune correction appliquée)

---

## 1. SCOPE

```text
2 inventory filter test failures
```

Périmètre exact :

- `tests/Feature/InventoryListFiltersTest.php`
  - `inventory index filters by created date range`
  - `inventory index combines multiple filters with AND logic`

```text
READ-ONLY ANALYSIS:     YES
NO PRODUCTION CHANGES:  YES
NO DATABASE WRITES:     YES (tests sqlite :memory: uniquement)
```

Les 12 autres tests du même fichier **PASS** (14 total, 12 PASS / 2 FAIL).

---

## 2. ENVIRONMENT SAFETY

```text
TEST ENVIRONMENT:        APP_ENV=testing (phpunit.xml)
DATABASE USED BY TESTS:  sqlite :memory:
GESTION TOUCHED:         NO
PRODUCTION TOUCHED:      NO
OVH TOUCHED:             NO
R2 TOUCHED:              NO
.env MODIFIED:           NO
```

| Paramètre | Valeur |
|-----------|--------|
| DB_CONNECTION | `sqlite` |
| DB_DATABASE | `:memory:` |
| Isolé de `gestion` | OUI |

---

## 3. TEST 1 ANALYSIS

```text
FILE:               tests/Feature/InventoryListFiltersTest.php
TEST:               inventory index filters by created date range
SCENARIO:           Lister les sessions d'inventaire filtrées par plage de dates de création
FILTER:             search + date_from + date_to
INPUT:              search=INV-DATE-AAA, date_from=2026-08-01, date_to=2026-08-31
                    Session cible : reference=INV-DATE-AAA, created_at=2026-08-15 10:00:00
                    Session décoy : reference=INV-DATE-BBB, created_at=2026-07-01 10:00:00
EXPECTED:           1 session (INV-DATE-AAA) dans sessions.data
ACTUAL:             0 session dans sessions.data
FAILING ASSERTION:  ->has('sessions.data', 1)  (ligne 218)
```

### Production code involved

| Fichier | Rôle |
|---------|------|
| `app/Http/Controllers/InventorySessionController.php` | `index()`, `applyIndexFilters()` — lignes 395–401 (`whereDate` sur `created_at`) |
| `app/Models/InventorySession.php` | Modèle, `$fillable` (sans `created_at`) |
| `app/Models/Company.php` | `Company::getInstance()` — isolation entreprise |
| Route `inventory.index` | GET avec query params |

### Factory / fixture involved

| Élément | Détail |
|---------|--------|
| `inventoryListSession()` | Helper test lignes 41–58 — `InventorySession::query()->create([...])` |
| `inventoryListPermissions()` | Utilisateur avec permission `inventory.view` |
| Pas de factory Eloquent dédiée | Données créées via `create()` direct |

### Root cause

Le helper de test passe `created_at` dans le tableau de création :

```php
inventoryListSession([
    'reference' => 'INV-DATE-AAA',
    'created_at' => '2026-08-15 10:00:00',
]);
```

Or `created_at` **n'est pas** dans `$fillable` du modèle `InventorySession` :

```13:36:app/Models/InventorySession.php
    protected $fillable = [
        'company_id',
        'store_id',
        'reference',
        // ... autres champs métier ...
        'application_summary',
    ];
```

Laravel ignore donc `created_at` lors du mass assignment. La session reçoit automatiquement `now()` (date d'exécution du test : **2026-09-02**), pas août 2026.

Le contrôleur applique ensuite :

```395:401:app/Http/Controllers/InventorySessionController.php
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }
```

La session avec `created_at` réel = 2026-09-02 est **exclue** de la plage 2026-08-01 → 2026-08-31, même si `search=INV-DATE-AAA` correspond.

**Chaîne causale :**

```text
EXPECTED: session créée le 2026-08-15
    ↓
TEST INPUT: created_at passé à create() mais non fillable
    ↓
APPLICATION: Eloquent assigne now() = 2026-09-02
    ↓
QUERY: whereDate created_at BETWEEN 2026-08-01 AND 2026-08-31
    ↓
ACTUAL: 0 résultat
```

### Classification

```text
TEST DATA / FACTORY PROBLEM
```

### Production impact

```text
NO
```

Le filtre date en production fonctionne sur les vraies valeurs `created_at` persistées en base. Le bug est limité à la **fixture de test** qui ne parvient pas à positionner la date souhaitée.

### Severity

```text
NONE
```

### Recommended future action

Corriger le helper de test (PRE-PROD 8.1.4) pour persister `created_at` après création, par exemple :

```php
$session = InventorySession::query()->create([...]);
$session->forceFill(['created_at' => $date, 'updated_at' => $date])->saveQuietly();
return $session->fresh();
```

**Ne pas** ajouter `created_at` à `$fillable` du modèle production (risque mass-assignment).

---

## 4. TEST 2 ANALYSIS

```text
FILE:               tests/Feature/InventoryListFiltersTest.php
TEST:               inventory index combines multiple filters with AND logic
SCENARIO:           Combiner recherche + statut + scope + catégorie + plage de dates (logique AND)
FILTER:             search + status + scope_type + category_id + date_from + date_to
INPUT:              search=riz, status=counting, scope_type=category,
                    category_id={id}, date_from=2026-08-01, date_to=2026-08-31
                    Session cible : name/description/status/scope/catégorie OK, created_at=2026-08-10
                    Session décoy : même catégorie/description mais status=Draft
EXPECTED:           1 session (Counting) dans sessions.data
ACTUAL:             0 session dans sessions.data
FAILING ASSERTION:  ->has('sessions.data', 1)  (ligne 256)
```

### Production code involved

Mêmes fichiers que Test 1, plus :

| Fichier | Rôle |
|---------|------|
| `applyIndexFilters()` lignes 339–401 | Recherche LIKE, status, scope_type, category_id (JSON `scope_value->category_id`), date_from/date_to |
| `app/Enums/InventorySessionStatus.php` | Enum statuts |
| `app/Enums/InventoryScopeType.php` | Enum périmètres |
| `inventoryListCategory()` | Helper test — `Category::create()` |

### Factory / fixture involved

| Élément | Détail |
|---------|--------|
| `inventoryListSession()` | Deux sessions avec `created_at => '2026-08-10 09:00:00'` |
| `inventoryListCategory('Riz')` | Catégorie pour scope_value |

### Root cause

**Même cause racine que Test 1** : `created_at` ignoré par mass assignment.

Les autres filtres (search `riz`, status `counting`, scope `category`, `category_id`) sont corrects et testés individuellement ailleurs dans le fichier (tests PASS). Seul le filtre date échoue car la date réelle des sessions est septembre 2026, hors de la plage août 2026.

Les 12 autres tests du fichier valident déjà :
- recherche (reference, name, description, store) ✓
- filtre status ✓
- filtre scope_type ✓
- filtre category_id ✓
- pagination avec filtres ✓
- isolation entreprise ✓

### Classification

```text
TEST DATA / FACTORY PROBLEM
```

### Production impact

```text
NO
```

La logique AND du contrôleur est correcte ; chaque filtre est appliqué séquentiellement sur le query builder. Le problème est exclusivement la donnée de test.

### Severity

```text
NONE
```

### Recommended future action

Même correction que Test 1 — adapter `inventoryListSession()` pour supporter un override de `created_at` via `forceFill` + `saveQuietly()` après `create()`.

Alternative : extraire un paramètre `$createdAt` dans le helper et l'appliquer post-création.

---

## 5. COMPARATIVE ANALYSIS

| Test | Cause | Classification | Production Impact | Severity | Production Blocker |
|------|-------|----------------|-------------------|----------|-------------------|
| inventory index filters by created date range | `created_at` non persisté (hors `$fillable`) | TEST DATA / FACTORY PROBLEM | NO | NONE | NO |
| inventory index combines multiple filters with AND logic | Idem — date filter exclut sessions à `now()` | TEST DATA / FACTORY PROBLEM | NO | NONE | NO |

---

## 6. PRODUCTION RISK

```text
Ces deux échecs révèlent-ils un bug réel en production ?
```

**NON.**

Les filtres date (`date_from`, `date_to`) sont implémentés correctement dans `InventorySessionController::applyIndexFilters()` via `whereDate`. Le frontend (`InventoryListView.vue`, `inventoryListFilters.ts`) expose les mêmes paramètres. Les sessions réelles en base ont des `created_at` authentiques définis par Eloquent à l'insertion.

Le dysfonctionnement est **uniquement** dans la couche test : tentative d'injecter une date historique via mass assignment sur un champ non fillable.

**Note historique :** Les tests ont été introduits dans le commit `3130476` avec la même approche `created_at` dans `create()`. `created_at` n'a jamais été dans `$fillable`. Ces tests sont probablement **jamais passés** hors d'une fenêtre calendaire accidentelle (exécution en août 2026 avec `now()` dans la plage filtrée).

---

## 7. REQUIRED FIXES

```text
NONE (production)
```

Corrections recommandées **test uniquement** (phase 8.1.4) :

1. Modifier `inventoryListSession()` pour appliquer `created_at` après création.
2. Rejouer `InventoryListFiltersTest` — attente 14/14 PASS.

```text
NO FIX APPLIED
```

---

## 8. TEST CHANGES REQUIRED

Modifications ultérieures possibles (PRE-PROD 8.1.4) :

| Fichier | Changement |
|---------|------------|
| `tests/Feature/InventoryListFiltersTest.php` | Helper `inventoryListSession()` — persister `created_at` post-création |

Exemple de pattern recommandé :

```php
function inventoryListSession(array $overrides = []): InventorySession
{
    $createdAt = $overrides['created_at'] ?? null;
    unset($overrides['created_at']);

    $session = InventorySession::query()->create(array_merge([...], $overrides));

    if ($createdAt !== null) {
        $session->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();
        $session = $session->fresh();
    }

    return $session;
}
```

```text
NO TEST MODIFICATION APPLIED
```

---

## 9. OUT OF SCOPE

Cette phase n'a pas traité :

- Pusher / Broadcast (~44 échecs)
- NotificationCenter (~15 échecs)
- Fortify / Registration (~9 échecs)
- RBAC test drift (~2 échecs)
- Autres échecs d'inventaire (aucun autre en échec)
- Backup / restore (188/188 PASS)
- Frontend (160/160 PASS baseline)
- OVH, production, R2

---

## 10. GIT / FILE INTEGRITY

```text
APPLICATION FILES MODIFIED:
EXPECTED: NO
ACTUAL:   NO

ENV FILES MODIFIED:     NO
GIT HISTORY MODIFIED:   NO
COMMIT CREATED:         NO
PUSH PERFORMED:         NO
```

Seul fichier créé dans cette phase :

```text
docs/preprod-phase-8.1.3-inventory-filter-analysis-report.md
```

---

## 11. FINAL VERDICT

```text
INVENTORY FILTER ANALYSIS COMPLETE — NO PRODUCTION BLOCKER
```

Les deux échecs sont causés par un problème de fixture de test (`created_at` non assignable), pas par un défaut du code de filtrage en production. Aucune action production requise. Correction test planifiable en PRE-PROD 8.1.4 après validation humaine.

---

## RÉSULTATS DES TESTS RÉEXÉCUTÉS

| Commande | Résultat |
|----------|----------|
| `--filter` (2 tests ciblés) | 2 FAIL |
| `InventoryListFiltersTest.php` (fichier complet) | 12 PASS / 2 FAIL |

Messages d'erreur identiques :

```text
Property [sessions.data] does not have the expected size.
Failed asserting that actual size 0 matches expected size 1.
```

---

## MESSAGE FINAL

```text
=== PRE-PROD 8.1.3 — INVENTORY FILTER ANALYSIS ===

TESTS ANALYZED:
2

PRODUCTION BUGS:
0 / 2

OBSOLETE TESTS:
0 / 2

TEST DATA / FACTORY ISSUES:
2 / 2

TEST ENVIRONMENT ISSUES:
0 / 2

INCONCLUSIVE:
0 / 2

GESTION:
UNCHANGED

PRODUCTION:
UNTOUCHED

OVH:
UNTOUCHED

R2:
UNTOUCHED

APPLICATION FILES:
UNCHANGED

REPORT:
docs/preprod-phase-8.1.3-inventory-filter-analysis-report.md

FINAL VERDICT:
INVENTORY FILTER ANALYSIS COMPLETE — NO PRODUCTION BLOCKER
```
