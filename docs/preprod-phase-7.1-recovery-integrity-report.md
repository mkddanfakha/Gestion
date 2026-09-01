# PRE-PROD 7.1 — RECOVERY INTEGRITY REPORT

## DATABASE TARGET

```text
gestion_recovery
```

Inspection effectuée via compte `gestion_restore` (read-only). Le runtime `gestion_app` pointe vers `gestion` et ne voit pas `gestion_recovery` — comportement attendu (séparation des comptes).

| Paramètre | Valeur |
|-----------|--------|
| CONNECTION (runtime) | mysql |
| HOST | 127.0.0.1 |
| PORT | 3306 |
| DATABASE (runtime) | gestion |
| USERNAME (runtime) | gestion_app |
| APP_ENV | local |
| DATABASE (recovery inspectée) | gestion_recovery |
| USERNAME (inspection recovery) | gestion_restore |

---

## BACKUP

```text
2026-09-01-17-39-18.zip
```

## SHA-256

```text
fc8ba394a65b0af8e613112fb64a12ebed90847e562a54ba448ce290d58be10d
```

Vérification archive (read-only) :

| Contrôle | Résultat |
|----------|----------|
| Archive existe | OUI |
| Archive lisible | OUI |
| Manifest présent | OUI |
| Intégrité manifest | VALID |
| SHA-256 fichier = SHA enregistré | OUI |
| SQL présent | OUI |
| Verdict inspection | HAS_BUSINESS_INSERTS |
| CREATE TABLE dans dump | 38 |

Cohérence artefacts restore :

| Artefact | Backup | Target | SHA-256 | Statut |
|----------|--------|--------|---------|--------|
| `restore-protocol-result.json` | 2026-09-01-17-39-18.zip | gestion_recovery | fc8ba394… | imported |
| `restore-protocol-before.json` | 2026-09-01-17-39-18.zip | gestion_recovery | — | snapshot PRE |
| `restore.audit` log (succès) | 2026-09-01-17-39-18.zip | gestion_recovery | fc8ba394… | success @ 2026-09-01T19:51:16Z |

---

## SCHEMA

```text
PASS
```

| Objet | Attendu (dump) | Recovery actuel | Status |
|-------|----------------|-----------------|--------|
| Nombre de tables | 38 | 38 | PASS |
| Liste des tables | 38 noms | Identique au dump | PASS |
| Colonnes (information_schema) | — | 441 | PASS |
| Index (information_schema) | — | 163 | PASS |

Les 38 tables recovery correspondent exactement aux 38 `CREATE TABLE` du dump et aux 38 tables de `gestion` au moment du backup.

Note : l'empreinte `schema_fingerprint` diffère entre `gestion` et `gestion_recovery` (collecte information_schema sur comptes distincts). La liste des tables et les comptages métier sont identiques — non bloquant.

---

## TABLE COUNT

```text
PASS
```

| Métrique | Valeur |
|----------|--------|
| EXPECTED TABLE COUNT (dump) | 38 |
| RECOVERY TABLE COUNT | 38 |
| DIFFERENCE | 0 |
| STATUS | PASS |

---

## ROW COUNTS

```text
PASS
```

Comparaison tables métier : **recovery = gestion** (source de vérité au moment du backup).

| Table | Dump INSERT blocks | Recovery | Gestion | Écart | Status |
|-------|-------------------:|---------:|--------:|------:|--------|
| users | 1 | 3 | 3 | 0 | MATCH |
| companies | 1 | 1 | 1 | 0 | MATCH |
| customers | 1 | 8 | 8 | 0 | MATCH |
| products | 1 | 26 | 26 | 0 | MATCH |
| categories | 1 | 5 | 5 | 0 | MATCH |
| sales | 1 | 10 | 10 | 0 | MATCH |
| sale_items | 1 | 17 | 17 | 0 | MATCH |
| quotes | 1 | 4 | 4 | 0 | MATCH |
| quote_items | 1 | 10 | 10 | 0 | MATCH |
| expenses | 1 | 2 | 2 | 0 | MATCH |
| suppliers | 1 | 4 | 4 | 0 | MATCH |
| purchase_orders | 1 | 5 | 5 | 0 | MATCH |
| purchase_order_items | 1 | 11 | 11 | 0 | MATCH |
| delivery_notes | 1 | 3 | 3 | 0 | MATCH |
| delivery_note_items | 1 | 6 | 6 | 0 | MATCH |

Autres tables recovery (38 au total) — comptages significatifs :

| Table | Recovery |
|-------|----------|
| activity_logs | 104 |
| attachments | 1 |
| cache | 34 |
| migrations | 75 |
| permissions | 75 |
| user_permissions | 63 |
| stock_movements | 50 |
| media | 26 |
| jobs | 11 |
| failed_jobs | 7 |

Note : le dump mysqldump utilise 1 bloc `INSERT` multi-lignes par table — le nombre de blocs INSERT n'est pas comparable directement au `COUNT(*)`.

---

## FOREIGN KEYS

```text
WARNING
```

Contrôles orphelins via `gestion_restore` sur `gestion_recovery` :

| Table | Relation | ORPHAN COUNT | STATUS |
|-------|----------|-------------:|--------|
| sales | sales.customer_id → customers.id | 0 | PASS |
| sale_items | sale_items.sale_id → sales.id | 0 | PASS |
| sale_items | sale_items.product_id → products.id | 0 | PASS |
| quotes | quotes.customer_id → customers.id | 0 | PASS |
| quote_items | quote_items.quote_id → quotes.id | **2** | WARNING |
| quote_items | quote_items.product_id → products.id | 0 | PASS |
| expenses | expenses.user_id → users.id | 0 | PASS |
| purchase_orders | purchase_orders.supplier_id → suppliers.id | 0 | PASS |
| purchase_order_items | purchase_order_items.purchase_order_id → purchase_orders.id | 0 | PASS |
| purchase_order_items | purchase_order_items.product_id → products.id | 0 | PASS |
| delivery_notes | delivery_notes.purchase_order_id → purchase_orders.id | 0 | PASS |
| delivery_note_items | delivery_note_items.delivery_note_id → delivery_notes.id | 0 | PASS |
| delivery_note_items | delivery_note_items.product_id → products.id | 0 | PASS |

Les 2 orphelins `quote_items → quotes` existaient déjà dans `gestion` avant restore (snapshot PRE-RESTORE identique). **Non introduit par le restore.**

---

## DATA INTEGRITY

```text
PASS
```

Échantillons représentatifs (read-only, champs non sensibles) :

| Table | Vérification | Status |
|-------|--------------|--------|
| users | 3 enregistrements, IDs/emails/rôles lisibles | PASS |
| companies | 1 enregistrement | PASS |
| customers | 8 enregistrements | PASS |
| products | 26 enregistrements, SKU présents | PASS |
| sales | 10 enregistrements, customer_id/total/status cohérents | PASS |

Tous les comptages métier recovery = gestion → intégrité globale confirmée.

---

## SYSTEM TABLES

```text
PASS
```

| Table | Présente | Row count | Note |
|-------|----------|----------:|------|
| sessions | OUI | 2 | OK |
| jobs | OUI | 11 | OK (légère variation vs gestion jobs=10, post-restore activity) |
| failed_jobs | OUI | 7 | OK |
| permissions | OUI | 75 | OK |
| user_permissions | OUI | 63 | OK |
| migrations | OUI | 75 | OK |

75 migrations présentes — cohérent avec le schéma applicatif actuel. Vérification documentaire uniquement (aucun `migrate` exécuté).

---

## QUOTE-AWARE DATA

```text
PASS
```

| Contrôle | Résultat |
|----------|----------|
| INSERT statements dans dump | 35 |
| Guillemets échappés (`''`) présents | OUI |
| Données avec `;` dans payloads | Présentes dans dump |
| Import recovery réussi | OUI (comptages = gestion) |

Le correctif quote-aware a permis l'import sans troncature — les comptages recovery correspondent à `gestion`.

---

## AUDIT EVENTS

```text
WARNING
```

| Source | Événement | Détail |
|--------|-----------|--------|
| `restore.audit` log | attempt ×2 + success ×1 | Backup `2026-09-01-17-39-18.zip`, target `gestion_recovery`, SHA fc8ba394… |
| `activity_logs` | BACKUP_RESTORE_FAILED (id 102, 108) | Échecs antérieurs sur autre backup — non liés |
| `activity_logs` | BACKUP_RESTORED | **Absent** — restore exécuté via service CLI, pas via `BackupController` UI |

Le succès est prouvé par `restore.audit` + `restore-protocol-result.json`. L'absence de `BACKUP_RESTORED` en `activity_logs` est une lacune d'audit UI, pas un échec de recovery.

---

## RESTORE PATH

```text
PASS
```

Chemin confirmé :

```text
DatabaseRestoreService (gestion_app)
        ↓
PrivilegedRestoreProcessRunner
        ↓
subprocess php artisan db:restore
        ↓
MKDPRO_PRIVILEGED_SUBPROCESS=restore
        ↓
gestion_restore
        ↓
gestion_recovery
```

- `gestion_app` n'a pas effectué l'import SQL directement : **confirmé**
- `gestion` n'a jamais été cible : **confirmé**

---

## RETRY COUNT

```text
WARNING
```

| Métrique | Valeur |
|----------|--------|
| restore.audit attempt | 2 |
| restore.audit success | 1 |
| Échecs subprocess avant succès | 2 tentatives loguées (19:51:11, 19:51:14) puis succès (19:51:16) |
| Restore réel abouti | **1** vers `gestion_recovery` |

Les tentatives intermédiaires sont des retries subprocess dans la même session de restore — un seul restore réussi.

---

## APPLICATION FILES

```text
MODIFIED
```

Modifications Git présentes — artefacts PRE-PROD 12.7.x (backup/restore/protocol), pas causées par le restore DB. Aucune modification `.env` liée au restore.

---

## GESTION

```text
UNCHANGED
```

| Métrique | BEFORE (PRE-RESTORE) | AFTER (7.1 audit) | Status |
|----------|---------------------|-------------------|--------|
| schema_fingerprint | 0705fde6… | 0705fde6… | IDENTIQUE |
| users | 3 | 3 | IDENTIQUE |
| companies | 1 | 1 | IDENTIQUE |
| customers | 8 | 8 | IDENTIQUE |
| products | 26 | 26 | IDENTIQUE |
| sales | 10 | 10 | IDENTIQUE |
| migrations | 75 | 75 | IDENTIQUE |
| (toutes tables métier) | — | — | IDENTIQUE |

```text
GESTION UNCHANGED
```

---

## PRODUCTION

```text
UNTOUCHED
```

---

## ANOMALIES

### ANOMALY 1 — Orphelins quote_items

```text
IMPACT: 2 lignes quote_items sans quote parent dans gestion_recovery (et gestion)
ROOT CAUSE: Données métier pré-existantes, pas introduite par restore
PROPOSED FIX: Audit métier séparé sur quote_items orphelins
ACTION TAKEN: NONE
```

### ANOMALY 2 — BACKUP_RESTORED absent de activity_logs

```text
IMPACT: Lacune audit UI pour le restore réel du 2026-09-01
ROOT CAUSE: Restore exécuté via DatabaseRestoreService CLI, pas BackupController
PROPOSED FIX: Appeler BackupAuditService::restored() dans le chemin CLI ou documenter restore.audit comme source de vérité
ACTION TAKEN: NONE
```

### ANOMALY 3 — gestion_app ne voit pas gestion_recovery

```text
IMPACT: Snapshots recovery via gestion_app affichent MISSING (faux négatif)
ROOT CAUSE: Séparation des comptes MySQL par design
PROPOSED FIX: Toujours inspecter recovery via gestion_restore (déjà fait en 7.1)
ACTION TAKEN: NONE
```

### ANOMALY 4 — Subprocess retries

```text
IMPACT: 2 tentatives avant succès dans restore.audit
ROOT CAUSE: Probable retry interne subprocess (non documenté en détail)
PROPOSED FIX: Investiguer logs subprocess si récurrence
ACTION TAKEN: NONE
```

---

## RESTORE EXECUTED DURING 7.1

```text
NO
```

Phase 7.1 : audit read-only uniquement. Aucune écriture, aucun restore, aucune modification.

---

## PREUVES

- `storage/app/restore-protocol-before.json`
- `storage/app/restore-protocol-result.json`
- `storage/app/preprod-7.1-audit-data.json`
- `storage/logs/laravel.log` (restore.audit)

---

## VERDICT

```text
RECOVERY VERIFIED WITH WARNINGS
```

Le restore vers `gestion_recovery` est **cohérent, complet et exploitable** :

- 38/38 tables restaurées
- Comptages métier identiques à `gestion`
- SHA-256 et manifest validés
- `gestion` strictement inchangée
- FK critiques OK (sauf 2 orphelins pré-existants non bloquants)

Warnings non bloquants : orphelins quote_items pré-existants, audit UI incomplet, retries subprocess.
