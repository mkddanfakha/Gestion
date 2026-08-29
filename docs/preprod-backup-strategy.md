# PRE-PROD 3 — Stratégie de sauvegarde MKD-Pro

## 1. Architecture backup

| Élément | Valeur |
|---------|--------|
| Package | `spatie/laravel-backup` **9.3.7** |
| Commande | `php artisan backup:run` |
| Cleanup | `php artisan backup:clean` |
| Monitor Spatie | `php artisan backup:monitor` |
| Status MKD | `php artisan backup:status` |
| Verify MKD | `php artisan backup:verify {path}` |
| Destination locale | disque `local` → `storage/app/private/{APP_NAME}/` |
| Compression | ZIP |
| DB dump | MySQL via `mysqldump` (Spatie) |
| Fichiers | `base_path()` avec exclusions |
| Chiffrement archive | non (Spatie `password` = null) |
| Notifications Spatie | désactivées (`via()` vide) ; échec logué |

## 2. Fréquence

| Job | Horaire |
|-----|---------|
| `backup:run` | daily 02:00 |
| `backup:clean` | daily 03:00 |
| `backup:monitor` | daily 04:00 |

**RPO cible :** ≤ 24 h (idéal ≤ 12 h si offsite + 2×/jour).

## 3. Rétention

| Politique | Valeur |
|-----------|--------|
| keep all | 30 jours |
| keep daily | 30 jours |
| keep weekly | 12 semaines |
| keep monthly | 12 mois |

Ancienne valeur (7 jours) insuffisante — corrigée en PRE-PROD 3.

## 4. Stratégie 3-2-1

| Copie | Support | État |
|-------|---------|------|
| COPIE 1 | Disque local `storage/app/private/` | Configuré |
| COPIE 2 | Stockage externe (USB / NAS / second volume) | **NOT CONFIGURED** — NEXT ACTION humaine |
| COPIE 3 | Offsite S3-compatible (recommandé : OVH Object Storage / Backblaze B2) | **NOT CONFIGURED** |

Sans COPIE 2/3 : statut **PARTIALLY PROTECTED**.

### Offsite recommandé (DOCUMENT ONLY)

- **Fournisseur :** OVH Object Storage ou Backblaze B2 (S3-compatible)
- **Coût estimatif :** quelques € / mois pour < 50 Go
- **Config Laravel :** disque `s3` dans `config/filesystems.php` + `BACKUP_DISK=s3` / `backup.destination.disks`
- **Secrets :** `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_ENDPOINT` dans `.env` uniquement
- **Ne pas committer** les credentials

## 5. Encryption

- Transfert offsite : TLS (HTTPS S3)
- At-rest provider : activer côté bucket
- Archive Spatie password : optionnel (à évaluer) — clé hors Git

## 6. Manifest / checksum

`BackupArchiveInspector` + `backup:verify` produisent :

- SHA-256 de l’archive
- présence SQL / taille dump
- compteurs `INSERT` tables métier
- verdict (`HAS_BUSINESS_INSERTS`, `SCHEMA_ONLY`, `CAUTION_CONTAINS_DOTENV`, …)
- **sans** mots de passe / APP_KEY

## 7. Restore architecture

Voir `docs/preprod-restore-procedure.md`.

- Cible autorisée : `gestion_recovery`, `gestion_test`
- Cible interdite : `gestion`
- Confirmation : `RESTORE`
- Lock concurrent backend

## 8. Recovery database

Création de `gestion_recovery` : **ACTION REQUIRES HUMAN APPROVAL** (DDL MySQL).

## 9. Validation restore

Comparer volumes tables métier vs manifest inspecteur après import dans recovery uniquement.

## 10–12. RPO / RTO

| Métrique | Cible | Preuve actuelle |
|----------|-------|-----------------|
| RPO | ≤ 24 h | Schedule 02:00 documenté ; **dernier ZIP local = 2025-11-28** → RPO réel non respecté historiquement |
| RTO | ≤ 2–4 h | **Non prouvé** (restore test non exécuté sur MySQL) |

## 13. Emergency

Voir `docs/preprod-restore-procedure.md` § Incident.

## 14–15. Monitoring / alerting

- `php artisan backup:status`
- Spatie monitor (notifications via vides → renforcer)
- `BackupHasFailedNotification` → log `backup.failed`
- Alertes à brancher : failed / missing / too small / SCHEMA_ONLY / offsite fail / restore test fail

## 16. Risques restants

1. Pas d’offsite
2. Pas de restore test MySQL réel
3. Archives historiques contiennent `.env` (exclusion ajoutée pour futurs backups)
4. Dumps locaux nov. 2025 = jeu de données minimal (≠ août 2026)
5. `backup:run` non exécuté en PRE-PROD 3 (évite écrire pendant audit ; schedule inchangé)

## États distincts (ne pas confondre)

```text
BACKUP CREATED ≠ BACKUP VERIFIED ≠ BACKUP OFFSITE ≠ RESTORE TESTED ≠ RESTORE VERIFIED
```
