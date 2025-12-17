# Fix thème devis en production - Guide

## Problème
L'impression du devis prend le bon thème (thème facture vente) en local, mais pas en production.

## Causes possibles
1. Le cache des vues Blade en production utilise encore l'ancienne version du template
2. Le fichier n'a pas été déployé correctement en production
3. OPcache met en cache le fichier PHP compilé
4. Le cache du navigateur

## Solution rapide (à essayer en premier)

### Commande à exécuter en production (SSH)

```bash
# Se connecter au serveur
ssh user@votre-serveur

# Aller dans le répertoire de l'application
cd /chemin/vers/projet

# ÉTAPE 1 : Vérifier que le fichier est bien déployé
grep "f8f9fa" resources/views/quotes/quote.blade.php
# Si rien n'est retourné, le fichier n'est pas à jour - faites: git pull origin main

# ÉTAPE 2 : Vider TOUS les caches
php artisan optimize:clear
rm -rf storage/framework/views/*

# ÉTAPE 3 : Redémarrer PHP-FPM (si OPcache est activé)
sudo systemctl restart php8.2-fpm
# ou selon votre configuration:
# sudo service php-fpm restart

# ÉTAPE 4 : Vérifier les permissions
chmod -R 775 storage bootstrap/cache
```

**OU utilisez le script automatique :**
```bash
# Télécharger le script sur le serveur
# Puis exécuter:
bash fix_quote_theme.sh
```

### Commandes complètes recommandées

```bash
# 1. Vider tous les caches
php artisan optimize:clear

# 2. Vérifier que le fichier a bien été déployé
ls -la resources/views/quotes/quote.blade.php

# 3. Vérifier le contenu du fichier (chercher "invoice-document")
grep -n "invoice-document" resources/views/quotes/quote.blade.php

# 4. Si le fichier est correct, recréer le cache
php artisan view:cache
```

## Vérification

Après avoir vidé le cache, testez l'impression d'un devis en production. Le thème devrait maintenant correspondre à celui des factures de vente.

## Si le problème persiste

### 1. Vérifier que le fichier est bien déployé

```bash
# Vérifier la date de modification du fichier
ls -la resources/views/quotes/quote.blade.php

# Vérifier le contenu (doit contenir "invoice-document" et "f8f9fa")
grep "invoice-document\|f8f9fa" resources/views/quotes/quote.blade.php
```

### 2. Vérifier les permissions

```bash
# Donner les permissions au dossier storage et bootstrap/cache
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### 3. Vérifier le cache opcache (si activé)

Si vous utilisez OPcache, vous devrez peut-être le redémarrer :

```bash
# Redémarrer PHP-FPM (selon votre configuration)
sudo systemctl restart php8.2-fpm
# ou
sudo service php-fpm restart
```

### 4. Vérifier les logs

```bash
# Voir les dernières erreurs
tail -n 50 storage/logs/laravel.log
```

## Script de déploiement complet

Pour éviter ce problème à l'avenir, ajoutez cette commande à votre script de déploiement :

```bash
#!/bin/bash

# Aller dans le dossier de l'application
cd /path/to/your/app

# Récupérer les dernières modifications
git pull origin main

# Installer les dépendances
composer install --no-dev --optimize-autoloader

# Vider TOUS les caches (IMPORTANT après modification de vues)
php artisan optimize:clear

# Exécuter les migrations si nécessaire
php artisan migrate --force

# Recréer les caches optimisés
php artisan optimize

echo "Déploiement terminé !"
```

## Note importante

**Toujours vider le cache des vues (`php artisan view:clear`) après avoir modifié un template Blade en production.**

Laravel met en cache les vues compilées dans `storage/framework/views/` pour améliorer les performances. Si vous modifiez un template sans vider le cache, l'ancienne version sera toujours utilisée.

