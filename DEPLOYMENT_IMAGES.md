# Résolution du problème d'affichage des images en production

## Problème
Les images des produits ne s'affichent pas après le déploiement sur le serveur.

## Causes possibles

### 1. Lien symbolique manquant (le plus courant)
MediaLibrary stocke les images dans `storage/app/public` mais elles doivent être accessibles via `public/storage`.

### 2. Permissions incorrectes
Les fichiers et dossiers n'ont pas les bonnes permissions.

### 3. Configuration du disque
Le disque utilisé par MediaLibrary n'est pas correctement configuré.

## Solutions

### Solution 1 : Créer le lien symbolique (OBLIGATOIRE)

Sur le serveur, exécutez :

```bash
php artisan storage:link
```

Cette commande crée le lien symbolique `public/storage` qui pointe vers `storage/app/public`.

**Vérification :**
```bash
ls -la public/ | grep storage
```

Vous devriez voir quelque chose comme :
```
lrwxrwxrwx 1 user user   49 Nov 28 20:00 storage -> /path/to/app/storage/app/public
```

### Solution 2 : Vérifier les permissions

Assurez-vous que les permissions sont correctes :

```bash
# Donner les permissions au dossier storage
chmod -R 775 storage
chmod -R 775 bootstrap/cache

# Donner les permissions au propriétaire du serveur web (remplacez www-data par votre utilisateur)
chown -R www-data:www-data storage
chown -R www-data:www-data bootstrap/cache
```

### Solution 3 : Vérifier la configuration

Vérifiez que `APP_URL` dans votre `.env` est correct :

```env
APP_URL=https://votre-domaine.com
```

### Solution 4 : Vérifier que les fichiers existent

Vérifiez que les fichiers sont bien présents :

```bash
ls -la storage/app/public/
```

Vous devriez voir un dossier `media` avec les images.

### Solution 5 : Régénérer les conversions (si nécessaire)

Si les miniatures ne s'affichent pas :

```bash
php artisan media-library:regenerate
```

## Commandes complètes pour le déploiement

```bash
# 1. Aller dans le dossier de l'application
cd /path/to/your/app

# 2. Créer le lien symbolique
php artisan storage:link

# 3. Vérifier les permissions
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# 4. Vider le cache
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# 5. Régénérer les conversions si nécessaire
php artisan media-library:regenerate
```

## Vérification finale

1. Vérifiez que le lien symbolique existe : `ls -la public/storage`
2. Testez une URL d'image directement : `https://votre-domaine.com/storage/media/1/filename.jpg`
3. Vérifiez les logs Laravel pour d'éventuelles erreurs : `tail -f storage/logs/laravel.log`

## Si le problème persiste

1. Vérifiez les logs du serveur web (Apache/Nginx)
2. Vérifiez que le module `mod_rewrite` est activé (Apache)
3. Vérifiez la configuration Nginx pour les liens symboliques
4. Vérifiez que `APP_URL` correspond exactement à votre domaine

