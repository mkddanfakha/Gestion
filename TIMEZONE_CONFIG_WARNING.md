# ⚠️ ATTENTION : Ne pas configurer le fuseau horaire dans .user.ini ou .htaccess

## ❌ Problème

Si vous avez ajouté une ligne pour configurer le fuseau horaire dans :
- `public/.user.ini` (ex: `date.timezone = "Africa/Dakar"`)
- `public/.htaccess` (ne peut pas configurer PHP directement)

Cela peut causer une **erreur 500** car :
1. Cela entre en conflit avec la configuration Laravel
2. `.htaccess` ne peut pas configurer le fuseau horaire PHP
3. `.user.ini` peut causer des erreurs si la syntaxe est incorrecte ou si le fuseau horaire n'est pas disponible

## ✅ Solution

**Le fuseau horaire est déjà configuré correctement dans Laravel :**

1. **`config/app.php`** : `'timezone' => 'Africa/Dakar'`
2. **`app/Providers/AppServiceProvider.php`** : `@date_default_timezone_set('Africa/Dakar')`

**Vous n'avez PAS besoin de le configurer ailleurs !**

## 🔧 Correction

### 1. Vérifier `.user.ini`

Le fichier `public/.user.ini` doit contenir **UNIQUEMENT** :

```ini
; Configuration PHP pour permettre l'upload de gros fichiers (sauvegardes)
; Ces valeurs sont lues par PHP au démarrage et s'appliquent à ce dossier et ses sous-dossiers

upload_max_filesize = 10240M
post_max_size = 10240M
max_execution_time = 600
memory_limit = 512M
```

**❌ NE PAS ajouter** :
- `date.timezone = "Africa/Dakar"` (causera des conflits)

### 2. Vérifier `.htaccess`

Le fichier `public/.htaccess` doit contenir **UNIQUEMENT** les règles de réécriture Laravel :

```apache
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Handle X-XSRF-Token Header
    RewriteCond %{HTTP:x-xsrf-token} .
    RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]

    # Redirect Trailing Slashes If Not A Folder...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # Send Requests To Front Controller...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
```

**❌ NE PAS ajouter** :
- Des directives PHP dans `.htaccess` (cela ne fonctionne pas pour le fuseau horaire)

## 📝 Commandes pour corriger en production

```bash
# 1. Vérifier le contenu de .user.ini
cat public/.user.ini

# 2. Si vous voyez "date.timezone", supprimez cette ligne
# Éditer le fichier et retirer la ligne date.timezone

# 3. Vérifier .htaccess
cat public/.htaccess

# 4. Vider les caches Laravel
php artisan optimize:clear

# 5. Tester
curl -I https://www.niane.mkd-pro.com/
```

## ✅ Configuration correcte du fuseau horaire

Le fuseau horaire est configuré dans **3 endroits** (dans Laravel uniquement) :

1. **`config/app.php`** (ligne 68) :
   ```php
   'timezone' => 'Africa/Dakar',
   ```

2. **`app/Providers/AppServiceProvider.php`** (ligne 36) :
   ```php
   @date_default_timezone_set('Africa/Dakar');
   ```

3. **Frontend** : `resources/js/utils/dateFormatter.ts` utilise `timeZone: 'Africa/Dakar'`

**C'est suffisant ! Ne pas ajouter ailleurs.**

## 🎯 Résumé

- ✅ **Configurer dans** : `config/app.php` et `AppServiceProvider.php` (déjà fait)
- ❌ **NE PAS configurer dans** : `.user.ini` ou `.htaccess`
- 🔧 **Si vous l'avez fait** : Supprimez la ligne et videz le cache

