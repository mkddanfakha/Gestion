# PRE-PROD 5 — Recovery runbook

## 1. Détecter une perte de données

Comptages métier anormaux, utilisateurs factory `@example.net`, logs `migrate:fresh`.

## 2. Protéger `gestion` immédiatement

- Ne pas lancer `migrate:fresh` / `db:wipe` / restore HTTP vers `gestion`
- `php artisan db:safety-check`
- Arrêter les scripts Cursor qui bootstrappent `.env`

## 3. Choisir un backup

```text
php artisan backup:status
php artisan backup:verify storage/app/private/{APP_NAME}/{file}.zip
```

Ne pas prétendre qu’un ZIP de 2025 restaure août 2026.

## 4. Créer `gestion_recovery` (humain)

```sql
-- UNIQUEMENT si absente. Ne pas DROP si elle existe.
CREATE DATABASE gestion_recovery CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Ne jamais créer `gestion`.

## 5. Restaurer vers `gestion_recovery`

Ne pas modifier `.env`.

```text
php artisan db:restore --backup=YYYY-mm-dd-HH-ii-ss.zip --target=gestion_recovery --confirmation=RESTORE
```

Ou UI Admin → restaurer → cible `gestion_recovery` → phrase RESTORE → mode database.

## 6. Vérifier l’intégrité

Counts tables métier sur **gestion_recovery** uniquement (client mysql `-D gestion_recovery`).

## 7. Comparer

Comparer recovery vs attentes métier / manifeste ZIP. `gestion` reste le témoin.

## 8. Bascule manuelle (humain uniquement)

1. Backup vérifié de l’état actuel
2. Décision humaine
3. Changer `DB_DATABASE` **manuellement** seulement après validation
4. Ne jamais automatiser le rename/drop de `gestion`

## 9. Conserver l’ancienne base

Renommer `gestion` en `gestion_pre_cutover_YYYYMMDD` **à la main**, jamais par l’app.

## 10. Rollback

Repointer `.env` vers l’ancienne base conservée. L’application ne doit pas DROP `gestion`.
