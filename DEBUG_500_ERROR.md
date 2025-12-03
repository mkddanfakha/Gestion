# Résolution de l'erreur 500 (Internal Server Error)

## Erreur
```
500 (Internal Server Error)
```

## Causes possibles

### 1. Vérifier les logs Laravel (PRIORITÉ ABSOLUE)

La première chose à faire est de consulter les logs Laravel :

```bash
# Sur le serveur, exécutez :
tail -n 100 storage/logs/laravel.log

# Ou pour voir les dernières erreurs en temps réel :
tail -f storage/logs/laravel.log
```

Les logs vous indiqueront exactement quelle est l'erreur.

### 2. Vérifier les permissions

```bash
# Permissions pour les dossiers
chmod -R 755 storage bootstrap/cache
chmod -R 755 public

# Permissions pour les fichiers
find storage -type f -exec chmod 644 {} \;
find bootstrap/cache -type f -exec chmod 644 {} \;

# Propriétaire (remplacez www-data par votre utilisateur serveur)
chown -R www-data:www-data storage bootstrap/cache
chown -R www-data:www-data public
```

### 3. Vérifier le fichier .env

Assurez-vous que le fichier `.env` existe et contient toutes les variables nécessaires :

```bash
# Vérifier que .env existe
ls -la .env

# Vérifier les variables importantes
cat .env | grep -E "APP_KEY|APP_DEBUG|DB_|APP_URL"
```

**Variables critiques :**
- `APP_KEY` doit être défini (généré avec `php artisan key:generate`)
- `APP_DEBUG=false` en production
- `APP_URL` doit correspondre à votre domaine
- Toutes les variables `DB_*` doivent être correctes

### 4. Générer la clé d'application

```bash
php artisan key:generate
```

### 5. Vider tous les caches

```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan optimize:clear
```

### 6. Vérifier les migrations

```bash
# Vérifier l'état des migrations
php artisan migrate:status

# Si nécessaire, exécuter les migrations
php artisan migrate --force
```

### 7. Vérifier les logs du serveur web

**Pour Apache :**
```bash
tail -n 50 /var/log/apache2/error.log
# ou
tail -n 50 /var/log/httpd/error_log
```

**Pour Nginx :**
```bash
tail -n 50 /var/log/nginx/error.log
```

### 8. Vérifier les permissions PHP

Assurez-vous que PHP peut écrire dans les dossiers :

```bash
# Tester les permissions
touch storage/test.txt && rm storage/test.txt
touch bootstrap/cache/test.txt && rm bootstrap/cache/test.txt
```

### 9. Vérifier la version PHP

```bash
php -v
```

Laravel 12 nécessite PHP 8.2 ou supérieur.

### 10. Vérifier les extensions PHP requises

```bash
php -m | grep -E "pdo|mbstring|xml|openssl|tokenizer|json|bcmath|ctype|fileinfo"
```

### 11. Vérifier le mode debug (temporairement)

Dans `.env`, changez temporairement :
```env
APP_DEBUG=true
```

Cela affichera l'erreur directement dans le navigateur (à remettre à `false` après résolution).

### 12. Vérifier les logs PHP

```bash
# Vérifier où sont les logs PHP
php -i | grep error_log

# Consulter les logs
tail -n 50 /var/log/php_errors.log
# ou selon votre configuration
tail -n 50 /var/log/php-fpm/error.log
```

## Commandes complètes de diagnostic

```bash
# 1. Voir les dernières erreurs Laravel
tail -n 100 storage/logs/laravel.log

# 2. Vérifier les permissions
ls -la storage bootstrap/cache

# 3. Vérifier .env
cat .env | grep APP_KEY
cat .env | grep APP_DEBUG

# 4. Vider les caches
php artisan optimize:clear

# 5. Régénérer la clé si nécessaire
php artisan key:generate

# 6. Vérifier les migrations
php artisan migrate:status
```

## Solutions rapides courantes

### Solution 1 : Permissions incorrectes
```bash
chmod -R 755 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### Solution 2 : Clé d'application manquante
```bash
php artisan key:generate
php artisan config:clear
```

### Solution 3 : Cache corrompu
```bash
php artisan optimize:clear
rm -rf bootstrap/cache/*.php
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Solution 4 : Variables d'environnement manquantes
Vérifiez que toutes les variables dans `.env.example` sont présentes dans `.env`.

## Après résolution

Une fois le problème résolu :

1. Remettez `APP_DEBUG=false` dans `.env`
2. Videz les caches : `php artisan optimize:clear`
3. Vérifiez que l'application fonctionne correctement

## Si le problème persiste

1. Partagez les dernières lignes de `storage/logs/laravel.log`
2. Partagez la configuration de votre serveur web (Apache/Nginx)
3. Vérifiez les logs du serveur web pour plus de détails

