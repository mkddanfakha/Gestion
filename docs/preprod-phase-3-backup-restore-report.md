# PRE-PROD 3 — Rapport Backup & Restore Strategy

**Date :** 2026-08-24  
**Base témoin :** `gestion` (READ ONLY)  
**Statut :** PARTIALLY READY

---

## Phase 1–3 — Audit

### Spatie

- Package `spatie/laravel-backup` **9.3.7**
- Destination : local `storage/app/private/Gestion/`
- Schedule console : run 02:00 / clean 03:00 / monitor 04:00
- Notifications Spatie : désactivées
- Ancienne rétention 7 j → **étendue** 30/30/12/12

### Backups locaux inspectés

| Backup | Date | Taille | SQL | Données métier | Fichiers | Verdict |
|--------|------|-------:|-----|----------------|----------|---------|
| 2025-11-28-19-50-02.zip | 2025-11-28 | ~3.4 Mo | oui (~68 Ko) | INSERT minimes (~1/table) | app code | CAUTION_CONTAINS_DOTENV |
| 2025-11-28-19-59-37.zip | 2025-11-28 | ~3.4 Mo | oui (~68 Ko) | INSERT minimes (~1/table) | app code | CAUTION_CONTAINS_DOTENV |

**Pourquoi août 2026 n’était pas récupérable :** pas de ZIP après nov. 2025 sur ce disque ; dumps trop petits / non représentatifs de la charge métier juillet–août 2026 ; pas d’offsite.

### `gestion` (lecture seule, après phase)

| Table | Count |
|-------|------:|
| users | 3 |
| customers | 0 |
| products | 0 |
| sales | 0 |
| quotes | 0 |

→ **UNCHANGED** vs PRE-PROD 1/2 (post-incident).

---

## Implémentations PRE-PROD 3

| Élément | Fichier / commande |
|---------|-------------------|
| Lock backup/restore | `app/Database/BackupConcurrencyGuard.php` |
| Inspecteur + SHA-256 | `app/Database/BackupArchiveInspector.php` |
| Status | `php artisan backup:status` |
| Verify | `php artisan backup:verify` |
| Job / controller sous lock | `CreateBackupJob`, `BackupController` |
| Rétention | `config/backup.php` |
| Exclusion `.env` | `config/backup.php` |
| Disque local non servi | `config/filesystems.php` `serve=false` |
| Tests | `tests/Unit/Infrastructure/BackupStrategyTest.php` |

### Non exécuté (volontaire)

- `backup:run` (pas de nouvelle archive pendant phase ; schedule documenté)
- CREATE `gestion_recovery`
- Restore MySQL réel
- Configuration credentials offsite

---

## Critères READY (checklist)

| Critère | État |
|---------|------|
| backup complet identifié | PARTIEL (anciens ZIP) |
| SQL présent | OUI (minime) |
| lisible | OUI |
| checksum | OUI (SHA-256) |
| manifest inspecteur | OUI |
| rétention documentée | OUI |
| offsite | **NOT CONFIGURED** |
| secrets non exposés (futurs) | exclusion `.env` |
| restore `gestion` impossible | OUI |
| restore recovery contrôlé | OUI (code) |
| locks | OUI |
| integrity check auto post-restore | PARTIEL (inspecteur archive ; pas rapport FK MySQL) |
| restore test | **NOT TESTED** |
| RPO/RTO docs | OUI |
| urgence docs | OUI |
| monitoring/alerting | PARTIEL |
| tests | PASS (isolés) |
| `gestion` inchangée | OUI |

---

## NEXT ACTION humaine

1. Configurer disque S3/OVH + secrets `.env`
2. Créer `gestion_recovery`
3. Exécuter `backup:run` puis `backup:verify`
4. Restore test → integrity → documenter PASS
5. Copie USB/NAS (COPIE 2)
