# Diagnostic de l'erreur 500 en production

## Étapes de diagnostic

### 1. Vérifier les logs Laravel

```bash
# Se connecter au serveur en SSH
ssh user@votre-serveur

# Aller dans le répertoire du projet
cd /chemin/vers/votre/projet

# Vérifier les logs Laravel
tail -n 50 storage/logs/laravel.log

# Ou voir les dernières erreurs
tail -f storage/logs/laravel.log
```

### 2. Vérifier les logs Apache/Nginx

```bash
# Logs Apache
tail -n 50 /var/log/apache2/error.log
# ou
tail -n 50 /var/log/httpd/error_log

# Logs Nginx
tail -n 50 /var/log/nginx/error.log
```

### 3. Vérifier la configuration PHP

```bash
# Vérifier si PHP peut exécuter le code
php artisan --version

# Vérifier les erreurs PHP
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

### 4. Vérifier les permissions

```bash
# Vérifier les permissions des dossiers
ls -la storage/
ls -la bootstrap/cache/

# Corriger les permissions si nécessaire
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### 5. Vérifier le fichier AppServiceProvider

```bash
# Vérifier la syntaxe PHP
php -l app/Providers/AppServiceProvider.php

# Vérifier le contenu du fichier
cat app/Providers/AppServiceProvider.php
```

### 6. Activer le mode debug temporairement

**ATTENTION : Ne faites cela que pour diagnostiquer, puis désactivez-le immédiatement !**

```bash
# Éditer le fichier .env
nano .env

# Changer APP_DEBUG=true temporairement
APP_DEBUG=true

# Puis vider le cache
php artisan config:clear
php artisan cache:clear
```

**IMPORTANT : Remettez APP_DEBUG=false après le diagnostic !**

### 7. Vérifier les dépendances

```bash
# Vérifier que toutes les dépendances sont installées
composer install --no-dev --optimize-autoloader

# Vérifier les dépendances npm (si nécessaire)
npm install
npm run build
```

### 8. Vérifier la syntaxe du fichier AppServiceProvider

Le fichier doit contenir :

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Event;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\CleanupWasSuccessful;
use Spatie\Backup\Events\HealthyBackupWasFound;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
        
        // Configurer le fuseau horaire du Sénégal globalement
        date_default_timezone_set('Africa/Dakar');
        
        // Ignorer silencieusement tous les événements de backup pour éviter les erreurs de notification
        Event::listen([
            CleanupHasFailed::class,
            CleanupWasSuccessful::class,
            HealthyBackupWasFound::class,
            UnhealthyBackupWasFound::class,
        ], function ($event) {
            // Ignorer silencieusement
            return true;
        });
    }
}
```

## Solutions courantes

### Solution 1 : Erreur de syntaxe dans AppServiceProvider

```bash
# Vérifier la syntaxe
php -l app/Providers/AppServiceProvider.php

# Si erreur, restaurer depuis Git
git checkout app/Providers/AppServiceProvider.php
```

### Solution 2 : Cache corrompu

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Solution 3 : Problème de permissions

```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### Solution 4 : Problème avec les dépendances

```bash
composer dump-autoload
php artisan optimize:clear
```

## Commandes rapides de diagnostic

```bash
# Diagnostic complet en une commande
cd /chemin/vers/projet && \
php artisan optimize:clear && \
php -l app/Providers/AppServiceProvider.php && \
tail -n 20 storage/logs/laravel.log
```

## Informations à fournir pour le support

Si le problème persiste, collectez ces informations :

1. **Dernières lignes du log Laravel** :
   ```bash
   tail -n 50 storage/logs/laravel.log
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

4. **Version Laravel** :
   ```bash
   php artisan --version
   ```

5. **Dernier commit** :
   ```bash
   git log -1
   ```
