# Fix erreur 500 en production - Guide complet

## Problème
Erreur 500 sur toutes les pages, y compris la page d'accueil (`/`) et le favicon.

## Diagnostic immédiat

### 1. Vérifier les logs Laravel (PRIORITAIRE)

```bash
# Se connecter au serveur
ssh user@votre-serveur

# Aller dans le répertoire
cd /chemin/vers/projet

# Voir les dernières erreurs
tail -n 100 storage/logs/laravel.log
```

**C'est la première chose à faire** - les logs vous diront exactement quelle erreur se produit.

### 2. Vérifier la syntaxe PHP de AppServiceProvider

```bash
php -l app/Providers/AppServiceProvider.php
```

### 3. Vérifier que le fuseau horaire est disponible

```bash
php -r "var_dump(date_default_timezone_set('Africa/Dakar'));"
```

Si cela retourne `false`, le fuseau horaire n'est pas disponible sur le serveur.

### 4. Solution temporaire : Désactiver date_default_timezone_set

Si le problème vient de `date_default_timezone_set('Africa/Dakar')`, vous pouvez temporairement le commenter :

```php
// Dans app/Providers/AppServiceProvider.php, ligne 33
// date_default_timezone_set('Africa/Dakar');
```

Le fuseau horaire est déjà configuré dans `config/app.php`, donc cette ligne est redondante.

### 5. Vider tous les caches

```bash
php artisan optimize:clear
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

### 6. Vérifier les permissions

```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### 7. Vérifier les dépendances

```bash
composer install --no-dev --optimize-autoloader
```

### 8. Vérifier la version PHP

```bash
php -v
```

Laravel 11 nécessite PHP 8.2 ou supérieur.

## Solution rapide (si vous avez accès SSH)

Exécutez ces commandes dans l'ordre :

```bash
cd /chemin/vers/projet

# 1. Voir les logs
tail -n 50 storage/logs/laravel.log

# 2. Vérifier la syntaxe
php -l app/Providers/AppServiceProvider.php

# 3. Vider les caches
php artisan optimize:clear

# 4. Vérifier les permissions
chmod -R 775 storage bootstrap/cache

# 5. Reconstruire le cache
php artisan config:cache
```

## Solution alternative : Commenter date_default_timezone_set

Si le problème persiste, modifiez `app/Providers/AppServiceProvider.php` :

```php
public function boot(): void
{
    Schema::defaultStringLength(191);
    
    // Configurer le fuseau horaire du Sénégal globalement
    // Commenté temporairement - le fuseau horaire est déjà dans config/app.php
    // try {
    //     date_default_timezone_set('Africa/Dakar');
    // } catch (\Exception $e) {
    //     \Log::warning('Impossible de définir le fuseau horaire Africa/Dakar: ' . $e->getMessage());
    // }
    
    Event::listen([
        CleanupHasFailed::class,
        CleanupWasSuccessful::class,
        HealthyBackupWasFound::class,
        UnhealthyBackupWasFound::class,
    ], function ($event) {
        return true;
    });
}
```

## Informations à collecter

Si le problème persiste, collectez :

1. **Dernières lignes du log Laravel** :
   ```bash
   tail -n 100 storage/logs/laravel.log
   ```

2. **Erreur PHP** :
   ```bash
   php artisan --version
   php -l app/Providers/AppServiceProvider.php
   ```

3. **Version PHP** :
   ```bash
   php -v
   ```

4. **Logs Apache/Nginx** :
   ```bash
   tail -n 50 /var/log/apache2/error.log
   # ou
   tail -n 50 /var/log/nginx/error.log
   ```

