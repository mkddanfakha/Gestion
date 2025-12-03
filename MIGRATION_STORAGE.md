# Migration : Stockage des médias dans public/storage

## Changement effectué

Les médias sont maintenant stockés directement dans `public/storage` au lieu de `storage/app/public` (via lien symbolique).

## Avantages

- Plus besoin de créer un lien symbolique (`php artisan storage:link`)
- Accès direct aux fichiers sans configuration supplémentaire
- Moins de problèmes de permissions avec les liens symboliques
- Configuration plus simple pour les serveurs partagés

## Migration sur le serveur

### 1. Supprimer le lien symbolique existant

```bash
# Supprimer le lien symbolique (si il existe)
rm public/storage
# ou sur Windows
rmdir public\storage
```

### 2. Créer le dossier réel

```bash
# Créer le dossier public/storage
mkdir -p public/storage
# ou sur Windows
mkdir public\storage
```

### 3. Déplacer les médias existants (si nécessaire)

Si vous avez déjà des médias dans `storage/app/public/media`, déplacez-les :

```bash
# Créer le dossier media dans public/storage
mkdir -p public/storage/media

# Déplacer les fichiers (si vous avez des médias existants)
# ATTENTION : Sauvegardez d'abord vos fichiers !
mv storage/app/public/media/* public/storage/media/
```

### 4. Vérifier les permissions

```bash
# Permissions pour les fichiers
find public/storage -type f -exec chmod 644 {} \;

# Permissions pour les dossiers
find public/storage -type d -exec chmod 755 {} \;

# Propriétaire (remplacez www-data par votre utilisateur)
chown -R www-data:www-data public/storage
```

### 5. Vider le cache

```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

### 6. Vérifier que tout fonctionne

1. Testez l'upload d'une nouvelle image de produit
2. Vérifiez que l'image s'affiche correctement
3. Vérifiez que les miniatures (thumb) sont générées

## Notes importantes

- Les nouveaux médias seront automatiquement stockés dans `public/storage/media/`
- Les anciens médias dans `storage/app/public/media/` ne seront plus accessibles
- Si vous avez des médias existants, vous devez les déplacer manuellement
- Le dossier `public/storage` doit avoir les permissions d'écriture pour le serveur web

## Configuration

Le disque `media` est configuré dans `config/filesystems.php` :

```php
'media' => [
    'driver' => 'local',
    'root' => public_path('storage'),
    'url' => env('APP_URL').'/storage',
    'visibility' => 'public',
],
```

Le modèle `Product` utilise automatiquement ce disque pour la collection `images`.

