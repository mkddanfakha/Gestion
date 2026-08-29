# PRE-PROD 7.1 — RECOVERY INTEGRITY REPORT

**Date :** 2026-08-24  
**Mode :** READ ONLY — aucune écriture, aucun DROP/CREATE, aucun restore  
**Inspection target :** `gestion_recovery` uniquement  
**Backup source (drill) :** `2025-11-28-19-59-37.zip`  
**Note :** dump de **novembre 2025** — ce n’est pas une recovery des données d’août 2026.

---

## 1. DATABASE TARGET

| Champ | Valeur |
|-------|--------|
| Environnement app | `local` |
| Connexion défaut app | `mysql` |
| Host | `127.0.0.1` |
| Port | `3306` |
| Utilisateur | `root` |
| `DB_DATABASE` / mysql.database (app) | **`gestion`** (inchangé) |
| Base inspectée | **`gestion_recovery`** (explicite dans toutes les requêtes SQL) |

```text
CONFIRMATION: inspection target = gestion_recovery (exact)
App .env still points to gestion — no switch performed.
```

---

## 2. SCHEMA

| Contrôle | Résultat |
|----------|----------|
| Tables | **27** présentes |
| PK | Présentes sur toutes les tables métier/système listées |
| Index | **40** index distincts |
| FK déclarées (`information_schema`) | **0** |
| Engine | **MyISAM** (ne supporte pas les FOREIGN KEY InnoDB) |

### Écart vs schéma MKD-Pro actuel (WARNING)

Le dump restauré s’arrête aux migrations **~novembre 2025** (`migrations` = 44 batches).  
L’application courante a **72** fichiers de migration.

Tables **absentes** dans recovery (présentes dans le code actuel) notamment :

- `stores`, `product_stocks`, `stock_movements`
- `inventory_sessions`, `inventory_items`
- `attachments`
- extensions notification / form_drafts récentes, etc.

```text
SCHEMA: WARNING
Raison: schéma cohérent avec le backup 2025-11, incomplet vs app 2026-08.
Pas de FK InnoDB (MyISAM) — intégrité référentielle non enforceée au niveau moteur.
```

**Correction proposée (ne pas appliquer sans validation) :**  
après validation humaine, envisager dump InnoDB + migrations manquantes **uniquement sur `gestion_recovery`**, jamais sur `gestion`.

---

## 3. FOREIGN KEYS (logiques / orphelins)

Aucune contrainte FK MySQL. Vérification **logique** LEFT JOIN :

| TABLE | RELATION | ORPHAN COUNT | STATUS |
|-------|----------|-------------:|--------|
| sales | → customers | 0 | PASS |
| sale_items | → sales | 0 | PASS |
| sale_items | → products | 0 | PASS |
| quotes | → customers | 0 | PASS |
| quote_items | → quotes | 0 | PASS |
| quote_items | → products | 0 | PASS |
| expenses | → users | 0 | PASS |
| purchase_orders | → suppliers | 0 | PASS |
| purchase_order_items | → purchase_orders | **1** | **WARNING** |
| purchase_order_items | → products | 0 | PASS |
| delivery_notes | → purchase_orders | 0 | PASS |
| delivery_note_items | → delivery_notes | **7** | **WARNING** |
| delivery_note_items | → products | 0 | PASS |

### Détail orphelins (source dump, pas corruption d’import)

| Orphelin | Preuve |
|----------|--------|
| `purchase_order_items.id=11` → `purchase_order_id=3` | `purchase_orders` IDs = 1,2,4 — **pas de PO #3** |
| `delivery_note_items` (7 lignes) → DN ids 1,3,5,11 | `delivery_notes` IDs = 2,4,6,7,8,9,10 — absents 1,3,5,11 |

```text
FOREIGN KEYS: WARNING
Raison: orphelins présents dans le dump d’origine (données historiques incohérentes).
Counts dump ≈ recovery → pas un défaut d’import.
```

**Correction proposée (ne pas appliquer) :** nettoyage manuel des lignes orphelines **dans `gestion_recovery` uniquement**, après validation métier.

---

## 4. DATA INTEGRITY (échantillons)

Données lisibles et cohérentes (extraits) :

| Table | Exemple |
|-------|---------|
| users | id=1 Mohamed DANFAKHA / `dmohamedkounta@gmail.com` |
| companies | id=1 ABOUBACRY NIANE |
| customers | Marie Martin, Pierre Durand, Badioss, … |
| products | EL0002 Casque Bluetooth Sony 199.99 ; EL0003 HP 899.99 |
| categories | Electronique, Vêtements, Alimentation, … |
| sales | ids 52,53,55… customer_id 14/2, dates nov. 2025 |
| sale_items | sale_id 52 → product 11 qty 6 @ 89.99 |
| quotes / quote_items | présents |
| expenses | présents (user_id lié) |
| suppliers / PO / BL | présents |

Colonne ventes = `total_amount` (pas `total`) — aligné schéma dump.

```text
DATA INTEGRITY: PASS (échantillon métier cohérent avec un dump nov.2025)
```

---

## 5. ROW COUNTS

Comparaison dump (estimation tuples `INSERT`) vs `COUNT(*)` recovery :

| Table | Dump attendu | Recovery actuel | Écart | Status |
| ----- | -----------: | --------------: | ----: | ------ |
| users | 4 | 4 | 0 | PASS |
| companies | 1 | 1 | 0 | PASS |
| customers | 7 | 7 | 0 | PASS |
| products | 20 | 20 | 0 | PASS |
| categories | 7 | 7 | 0 | PASS |
| sales | 9 | 9 | 0 | PASS |
| sale_items | 19 | 19 | 0 | PASS |
| quotes | 1 | 1 | 0 | PASS |
| quote_items | 2 | 2 | 0 | PASS |
| expenses | 2 | 2 | 0 | PASS |
| suppliers | 2 | 2 | 0 | PASS |
| purchase_orders | 3 | 3 | 0 | PASS |
| purchase_order_items | 7 | 7 | 0 | PASS |
| delivery_notes | 7 | 7 | 0 | PASS |
| delivery_note_items | 26 | 26 | 0 | PASS |

```text
ROW COUNTS: PASS
```

---

## 6. SYSTEM TABLES

| Table | Recovery | Note |
|-------|---------:|------|
| sessions | 3 | Payloads base64 sérialisés importés → prouve le split **quote-aware** (`;` dans payload) |
| jobs | 0 | OK |
| failed_jobs | 0 | OK |
| permissions | 65 | OK (= dump) |
| user_permissions | 64 | OK (= dump) |
| migrations | 44 | OK pour dump 2025 ; < 72 migrations app actuelles |

```text
SYSTEM TABLES: PASS
```

---

## 7. RETRY SAFETY (statique + tests isolés)

Code `MysqlPdoDumpImporter::import()` :

1. `DatabaseSafetyGuard::assertExplicitRestoreTarget($explicitTarget)` **avant** tout DROP  
2. Puis seulement `DROP DATABASE IF EXISTS \`{$target}\`` / `CREATE` / `USE`

| Cible | Statut |
|-------|--------|
| `gestion` | BLOCKED (tests + guard) |
| `gestion_recovery` | ALLOWED |
| `gestion_test` | ALLOWED |
| autre / vide | BLOCKED |

Aucun DROP/CREATE exécuté pendant PRE-PROD 7.1.  
Tests isolés (filtre architecture) : **10 PASS**.

```text
RETRY SAFETY: PASS
```

---

## 8. APPLICATION FILES

| Contrôle | Résultat |
|----------|----------|
| `.env` | `DB_DATABASE=gestion` — **inchangé** |
| Hash spot `.env` / `composer.json` / `bootstrap/app.php` / `config/database.php` | inchangés vs post-drill |
| Restore DB-only | n’écrit pas dans `app/`, `routes/`, `resources/`, `public/` |

Git : working tree déjà modifié par PRE-PROD 1–5 (guards, docs, build) — **aucune modification provoquée par cette mission de validation** (lecture seule).

```text
APPLICATION FILES: UNCHANGED (par le restore / par 7.1)
```

---

## 9. GESTION

```text
GESTION BEFORE (baseline connu / pré-7.1)
users=3 customers=0 products=0 sales=0 quotes=0 expenses=0

GESTION AFTER (SELECT only, 7.1)
users=3 customers=0 products=0 sales=0 quotes=0 expenses=0

GESTION UNCHANGED: YES
```

---

## FINAL VERDICT

```text
# PRE-PROD 7.1 — RECOVERY INTEGRITY REPORT

## DATABASE TARGET
gestion_recovery

## SCHEMA
WARNING

## ROW COUNTS
PASS

## FOREIGN KEYS
WARNING

## DATA INTEGRITY
PASS

## SYSTEM TABLES
PASS

## RETRY SAFETY
PASS

## APPLICATION FILES
UNCHANGED

## GESTION
UNCHANGED

## FINAL VERDICT
RECOVERY VERIFIED WITH WARNINGS
```

### Warnings (documentés, non corrigés)

1. Schéma recovery = snapshot **2025-11** (MyISAM, sans tables stock/inventaire 2026).  
2. Orphelins logiques dans le dump source (1 POI, 7 DNI) — pas une perte à l’import.  
3. Pas de FK moteur — intégrité uniquement applicative.

### Prochaines actions possibles (validation humaine requise)

- Accepter le drill comme preuve que **restore DB-only + allow-list + quote-aware** fonctionne.  
- Ou, sur `gestion_recovery` uniquement : audit/nettoyage orphelins + alignement migrations (hors scope 7.1).  
- Ne **jamais** appliquer ces corrections sur `gestion` sans procédure de cutover humaine.
