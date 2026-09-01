# PRE-PROD 7.1.1 — WARNING ANALYSIS REPORT

## 1. EXECUTION SAFETY

```text
READ ONLY: PASS
RESTORE EXECUTED: NO
WRITE SQL EXECUTED: NO
gestion MODIFIED: NO
gestion_recovery MODIFIED: NO
.env MODIFIED: NO
Git MODIFIED: NO
```

Preuves collectées via `storage/app/preprod-7.1.1-analysis-data.json` (SELECT / SHOW / DESCRIBE uniquement).

---

## 2. FOREIGN KEY ANALYSIS

### Synthèse

| Source | FK count (information_schema) | SHOW CREATE contient FOREIGN KEY | Cause |
|--------|------------------------------:|----------------------------------|-------|
| `gestion` | 0 | NON (8 tables testées) | **A** — pas de FK physiques |
| `gestion_recovery` | 0 | NON (identique) | **A** — copie fidèle du schéma source |
| Dump `2026-09-01-17-39-18.zip` | 0 occurrences `FOREIGN KEY` | 12 occurrences `REFERENCES` (hors contraintes) | **A/B** — dump sans contraintes FK |

### Classification officielle

```text
A — Les FK n'existent pas réellement dans le schéma source.
```

### Cause racine détaillée

Les migrations Laravel définissent des `foreignId()->constrained()` dans le code, mais le schéma MySQL réel **n'a aucune contrainte FOREIGN KEY** dans `information_schema` pour `gestion` ni `gestion_recovery`.

Facteur déterminant identifié : les tables métier utilisent le moteur **MyISAM** (ex. `quote_items`, `quotes`), qui **ne supporte pas les foreign keys**. Le protocole 7.1 a correctement mesuré `foreign_key_count = 0` ; ce n'est **pas** un échec du restore.

### Pourquoi les contrôles d'orphelins fonctionnent quand même

Les requêtes du protocole (`LEFT JOIN` logiques) testent les **relations métier attendues**, pas des contraintes `information_schema`. C'est le comportement correct pour ce schéma sans FK physiques.

### Détail tables inspectées (SHOW CREATE)

| Table | gestion FK | gestion_recovery FK |
|-------|:----------:|:-------------------:|
| quote_items | NON | NON |
| quotes | NON | NON |
| sale_items | NON | NON |
| sales | NON | NON |
| purchase_order_items | NON | NON |
| purchase_orders | NON | NON |
| delivery_note_items | NON | NON |
| delivery_notes | NON | NON |

---

## 3. quote_items ORPHANS

```text
BEFORE (gestion):     2 orphelins
AFTER (gestion_recovery): 2 orphelins (identiques)
DUMP:                 lignes présentes (ids 1 et 2)
SAME ROWS:            OUI
CAUSE:                PRE-EXISTING — anomalie données métier, pas introduite par le restore
```

### Lignes orphelines identifiées

Les deux lignes ont `quote_id = 1`, mais la table `quotes` ne contient **pas** l'id `1` (ids existants : 2, 3, 4, 5).

| id | quote_id | product_id | quantity | unit_price | gestion | recovery |
|----|----------|------------|----------|------------|:-------:|:--------:|
| 1 | 1 | 1 | 2 | 14500.00 | présent | présent (identique) |
| 2 | 1 | 4 | 2 | 1500.00 | présent | présent (identique) |

### Classification

```text
PRE-EXISTING
DUMP-CONSISTENT
```

Les `quote_items` orphelins existaient dans `gestion` avant le restore, sont présents dans le dump, et ont été reproduits à l'identique dans `gestion_recovery`. Probable cause : suppression du devis `quotes.id = 1` sans cascade (impossible sans FK physique sur MyISAM).

Note : écart d'affichage horaire `created_at` (+2h) entre gestion et recovery — différence de fuseau/session MySQL au moment du dump, pas une altération de données.

---

## 4. RESTORE ATTEMPTS

```text
LOGICAL ATTEMPTS:      2
SUBPROCESS LAUNCHES:   1
SQL IMPORTS:           1
SUCCESSFUL IMPORTS:    1
RETRY CONFIRMED:       NO (pas de double import)
VERDICT RETRY:         PASS
```

### Chronologie reconstruite (logs `laravel.log`)

| Timestamp (UTC) | Événement | Processus | Import SQL ? |
|-----------------|-----------|-----------|:------------:|
| 2026-09-01 19:51:11 | `restore.audit attempt` | Parent (`gestion_app`) — `DatabaseRestoreService::restore()` ligne 35 | NON |
| 2026-09-01 19:51:14 | `restore.audit attempt` | Subprocess (`gestion_restore`) — même méthode, enfant | NON (début) |
| 2026-09-01 19:51:16 | `restore.audit success` | Subprocess — `executeRestoreImport()` ligne 155 | OUI (terminé, 2194 ms) |

### Chaîne d'exécution confirmée

```text
Script CLI / service (gestion_app)
        ↓
RestoreAuditLogger::log('attempt')          ← attempt #1 (parent)
        ↓
PrivilegedRestoreProcessRunner::runRestore()
        ↓
subprocess php artisan db:restore
        ↓
RestoreAuditLogger::log('attempt')          ← attempt #2 (enfant)
        ↓
SqlDumpImporter::import()                   ← UN SEUL import SQL
        ↓
RestoreAuditLogger::log('success')          ← 1 succès
        ↓
Parent reçoit JSON rapport (pas de log success parent)
```

Le parent **ne logue jamais** `success` — seul le subprocess le fait après import réel. Les deux `attempt` sont **attendus par l'architecture** (parent + enfant), pas deux imports.

### Preuve code

- `DatabaseRestoreService::restore()` ligne 35 : `attempt` à chaque entrée (parent et subprocess)
- Ligne 48-54 : subprocess exécute `executeRestoreImport()` avec `SqlDumpImporter`
- Ligne 155 : `success` uniquement après import terminé dans le subprocess
- Parent `restoreViaPrivilegedSubprocess()` : pas de `RestoreAuditLogger::log('success')`

---

## 5. AUDIT LOG ANALYSIS

### Pourquoi `metadata` échoue

Le protocole 7.1 exécutait :

```sql
SELECT id, action, description, created_at, metadata FROM gestion.activity_logs
```

La colonne `metadata` **n'existe pas**. Structure réelle :

| Colonne | Type | Usage |
|---------|------|-------|
| id | bigint | PK |
| user_id | bigint | utilisateur |
| action | varchar(50) | ex. `BACKUP_RESTORED` |
| module | varchar(100) | ex. `backups` |
| description | text | résumé lisible |
| subject_type / subject_id | morphs | entité liée |
| old_values | json | valeurs avant |
| **new_values** | json | **métadonnées restore** |
| created_at | timestamp | horodatage |

### Source correcte des événements restore

| Mécanisme | Rôle | Restore 2026-09-01 |
|-----------|------|-------------------|
| `restore.audit` (Log Laravel) | Audit technique subprocess | **attempt ×2 + success ×1** — preuve principale |
| `activity_logs` via `BackupAuditService` | Audit UI métier | **Absent** — restore via CLI, pas `BackupController` |
| `BackupAuditService::restored()` | Écrit `BACKUP_RESTORED` dans `activity_logs.new_values` | Non appelé hors UI |

### Correction proposée (non appliquée)

Requête correcte :

```sql
SELECT id, action, description, created_at, new_values
FROM gestion.activity_logs
WHERE action IN ('BACKUP_RESTORED', 'BACKUP_RESTORE_FAILED')
```

---

## 6. PROTOCOL ANALYSIS

Défauts du protocole 7.1 identifiés (limitations, pas échecs restore) :

| # | Défaut | Impact | Sévérité |
|---|--------|--------|----------|
| 1 | Colonne `metadata` inexistante dans `activity_logs` | Échec requête audit UI | Faible — protocole |
| 2 | `foreign_key_count = 0` interprété comme anomalie possible | Fausse alerte — normal sur MyISAM | Faible — documentation |
| 3 | Snapshot `gestion_recovery` via `gestion_app` → `MISSING` | Faux négatif si compte runtime utilisé | Moyen — protocole |
| 4 | Deux `attempt` comptés comme retries potentiels | Ambiguïté sans contexte parent/enfant | Faible — documentation |
| 5 | Pas de distinction moteur de stockage (MyISAM vs InnoDB) | FK toujours 0 non expliqué | Faible — amélioration future |
| 6 | Orphelins logiques sans vérifier absence FK physiques | WARNING correct mais cause mal documentée | Faible |

Aucune modification de code effectuée.

---

## 7. GESTION PROTECTION

```text
GESTION BEFORE: schema_fingerprint = 0705fde61d40f2de627ef213574d8576ac389d4ef61e4d6fb34b890edea212b2
GESTION AFTER:  schema_fingerprint = 0705fde61d40f2de627ef213574d8576ac389d4ef61e4d6fb34b890edea212b2
SCHEMA UNCHANGED: YES
ROW COUNTS UNCHANGED: YES (15 tables métier + tables système)
```

Verdict :

```text
GESTION UNCHANGED
```

---

## 8. RECOVERY STATUS

`gestion_recovery` reste cohérente avec le restore précédent :

| Métrique | Snapshot 7.1 | Audit 7.1.1 | Status |
|----------|-------------|-------------|--------|
| Tables | 38 | 38 (via `gestion_restore`) | MATCH |
| users | 3 | 3 | MATCH |
| customers | 8 | 8 | MATCH |
| products | 26 | 26 | MATCH |
| quote_items | 10 | 10 | MATCH |
| migrations | 75 | 75 | MATCH |

Aucune modification détectée pendant l'analyse 7.1.1.

---

## 9. ANOMALIES

### ANOMALY 1 — FK count = 0

```text
Severity: INFO (faux positif protocolaire)
Evidence: information_schema FK = 0 sur gestion ET gestion_recovery; dump sans FOREIGN KEY
Root cause: Tables MyISAM — pas de contraintes FK physiques
Impact: Aucun sur le restore; contrôles logiques d'orphelins restent valides
Recommended correction: Documenter MyISAM dans protocole; ne pas alerter sur FK=0 si source=0
Correction applied: NO
```

### ANOMALY 2 — 2 orphelins quote_items

```text
Severity: LOW (données métier préexistantes)
Evidence: ids 1,2 quote_id=1 absent de quotes; identique gestion/recovery/dump
Root cause: Devis id=1 supprimé sans nettoyage quote_items; pas de FK MyISAM
Impact: Intégrité référentielle logique non garantie au niveau DB
Recommended correction: Audit métier séparé; éventuellement migration InnoDB+FK (hors scope)
Correction applied: NO
```

### ANOMALY 3 — Deux restore.audit attempt

```text
Severity: INFO
Evidence: Logs 19:51:11 parent + 19:51:14 subprocess + 19:51:16 success unique
Root cause: Architecture parent/enfant logue chacun un attempt
Impact: Aucun — un seul import SQL
Recommended correction: Protocole doit distinguer parent_attempt / subprocess_attempt
Correction applied: NO
```

### ANOMALY 4 — activity_logs.metadata

```text
Severity: LOW (défaut protocole 7.1)
Evidence: SQLSTATE 42S22 colonne metadata absente
Root cause: Métadonnées dans new_values (json), pas metadata
Impact: Audit UI non consultable par protocole 7.1; restore.audit reste valide
Recommended correction: Corriger requête protocole vers new_values
Correction applied: NO
```

### ANOMALY 5 — BACKUP_RESTORED absent activity_logs

```text
Severity: LOW
Evidence: activity_logs ne contient que BACKUP_RESTORE_FAILED historiques
Root cause: Restore exécuté via DatabaseRestoreService CLI, pas BackupController
Impact: Lacune audit métier UI uniquement
Recommended correction: Appeler BackupAuditService::restored() dans chemin CLI ou documenter restore.audit
Correction applied: NO
```

---

## 10. VERDICT

```text
WARNING ANALYSIS VERIFIED
```

Tous les warnings du rapport 7.1 sont expliqués :

| Warning 7.1 | Conclusion 7.1.1 |
|-------------|------------------|
| FK count = 0 | Comportement attendu (MyISAM, pas de FK physiques) — **pas un défaut restore** |
| 2 orphelins quote_items | **Préexistants** dans gestion et dump — reproduits fidèlement |
| 2 restore.audit attempt | **Parent + subprocess** — **1 seul import SQL** |
| activity_logs.metadata | **Erreur protocole** — colonne inexistante |

Aucun défaut critique du restore identifié. `gestion` inchangée. `gestion_recovery` cohérente.

---

## PREUVES

- `storage/app/preprod-7.1.1-analysis-data.json`
- `storage/app/preprod-7.1-audit-data.json`
- `storage/app/restore-protocol-before.json`
- `storage/app/restore-protocol-result.json`
- `storage/logs/laravel.log` (lignes 81980-81982)
