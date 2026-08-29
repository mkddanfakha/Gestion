# PRE-PROD 9.4 — Schema Baseline (post-migrate)

**Date :** 2026-08-26  
**Approbation :** `OUI — RUN MIGRATE ON gestion`  
**Compte utilisé :** `gestion_migration` (process-only ; `.env` reste `gestion_app`)

---

## EXPECTED TABLES

Schéma issu des **75** migrations actuelles (incl. NotificationCenter).

## ACTUAL TABLES

**38 tables** dans `gestion` :

```text
activity_logs
attachments
cache
cache_locks
categories
companies
customers
delivery_note_items
delivery_notes
expenses
failed_jobs
form_drafts
inventory_items
inventory_sessions
job_batches
jobs
media
migrations
notification_global_settings
notification_reads
notification_type_settings
password_reset_tokens
permissions
product_stocks
products
purchase_order_items
purchase_orders
quote_items
quotes
sale_items
sales
sessions
stock_movements
stores
suppliers
user_notification_preferences
user_permissions
users
```

## MISSING

```text
NONE (vs migrations exécutées — 75/75 DONE)
```

## EXTRA

```text
NONE
```

## MISMATCHES

```text
NONE
```

## DATA

Toutes les tables métier : **0 lignes** (sauf `migrations` = 75).  
Aucune donnée historique importée.  
`gestion_recovery` : **27 tables, inchangée**.

## Domaines couverts

| Domaine | Tables |
|---------|--------|
| Auth / users | users, password_reset_tokens, sessions, permissions, user_permissions |
| Entreprise | companies, stores |
| Clients / fournisseurs | customers, suppliers |
| Catalogue | categories, products, product_stocks |
| Ventes / devis | sales, sale_items, quotes, quote_items |
| Achats / BL | purchase_orders, purchase_order_items, delivery_notes, delivery_note_items |
| Dépenses | expenses |
| Stock / inventaire | stock_movements, inventory_sessions, inventory_items |
| Attachments / media | attachments, media |
| Notifications | notification_* , user_notification_preferences |
| Audit | activity_logs |
| Drafts | form_drafts |
| Queue / cache | jobs, job_batches, failed_jobs, cache, cache_locks |

```text
SCHEMA: PASS
```
