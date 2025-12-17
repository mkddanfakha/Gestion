# Checklist de déploiement après `git pull`

## Commandes essentielles après `git pull origin main`

### 1. Vider tous les caches Laravel (OBLIGATOIRE)
```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

Ou en une seule commande :
```bash
php artisan optimize:clear
```

### 2. Réinstaller les dépendances (si nécessaire)
Si des dépendances ont été ajoutées ou modifiées dans `composer.json` :
```bash
composer install --no-dev --optimize-autoloader
```

Si des dépendances frontend ont été modifiées dans `package.json` :
```bash
npm install --production
npm run build
```

### 3. Exécuter les migrations (si nécessaire)
Si de nouvelles migrations ont été ajoutées :
```bash
php artisan migrate --force
```

### 4. Optimiser l'application (recommandé)
Après avoir vidé les caches, optimisez pour la production :
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Ou en une seule commande :
```bash
php artisan optimize
```

### 5. Régénérer les conversions de médias (si nécessaire)
Si vous avez modifié la configuration des conversions d'images :
```bash
php artisan media-library:regenerate
```

## Script de déploiement complet

Vous pouvez créer un script `deploy.sh` pour automatiser tout ça :

```bash
#!/bin/bash

# Aller dans le dossier de l'application
cd /path/to/your/app

# Récupérer les dernières modifications
git pull origin main

# Installer les dépendances PHP
composer install --no-dev --optimize-autoloader

# Installer les dépendances Node.js (si nécessaire)
npm install --production
npm run build

# Vider tous les caches
php artisan optimize:clear

# Exécuter les migrations
php artisan migrate --force

# Optimiser l'application
php artisan optimize

# Régénérer les conversions de médias (optionnel)
# php artisan media-library:regenerate

echo "Déploiement terminé avec succès !"
```

## Commandes rapides (minimum requis)

Si vous êtes pressé, au minimum faites :
```bash
git pull origin main
php artisan optimize:clear
php artisan optimize
```

## Vérifications après déploiement

1. Vérifier que l'application fonctionne : visitez votre site
2. Vérifier les logs d'erreurs : `tail -f storage/logs/laravel.log`
3. Vérifier les permissions : `ls -la storage/` et `ls -la bootstrap/cache/`

## Notes importantes

- **Toujours** vider le cache après un `git pull` en production
- **IMPORTANT** : Si vous avez modifié des templates Blade (`.blade.php`), vous DEVEZ exécuter `php artisan view:clear` pour que les changements soient pris en compte
- Les commandes `optimize` créent des fichiers de cache pour améliorer les performances
- Utilisez `--force` avec `migrate` en production pour éviter les confirmations interactives
- Utilisez `--no-dev` avec `composer install` en production pour ne pas installer les dépendances de développement

## Cas spécifique : Modification de templates (factures, devis, etc.)

Si vous avez modifié un template Blade (par exemple `resources/views/quotes/quote.blade.php` ou `resources/views/invoices/sale.blade.php`), après le déploiement :

```bash
# Vider le cache des vues (OBLIGATOIRE)
php artisan view:clear

# Ou vider tous les caches
php artisan optimize:clear
```

Sans cette étape, l'ancienne version du template sera toujours utilisée en production.

