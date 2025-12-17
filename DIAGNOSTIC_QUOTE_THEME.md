# Diagnostic approfondi - Thème devis en production

## Étapes de diagnostic

### 1. Vérifier que le fichier est bien déployé en production

```bash
# Se connecter au serveur
ssh user@votre-serveur
cd /chemin/vers/projet

# Vérifier que le fichier existe
ls -la resources/views/quotes/quote.blade.php

# Vérifier la date de modification (doit être récente)
stat resources/views/quotes/quote.blade.php

# Vérifier que le fichier contient les modifications
grep -n "invoice-document" resources/views/quotes/quote.blade.php
grep -n "f8f9fa" resources/views/quotes/quote.blade.php
grep -n "items-table thead" resources/views/quotes/quote.blade.php
```

**Résultat attendu :**
- Le fichier doit exister
- La date doit être récente (après vos modifications)
- Les grep doivent retourner des résultats

### 2. Vérifier le contenu exact du CSS dans le fichier

```bash
# Vérifier le style du thead (doit être #f8f9fa et #6c757d)
grep -A 2 "items-table thead" resources/views/quotes/quote.blade.php

# Vérifier le style du grand-total (doit être #f8f9fa et #495057)
grep -A 5 "grand-total" resources/views/quotes/quote.blade.php
```

**Résultat attendu :**
```css
.items-table thead {
    background: #f8f9fa;
    color: #6c757d;
}
```

Si vous voyez `background: #212529;` ou `color: white;`, le fichier n'a pas été mis à jour.

### 3. Vider TOUS les caches (méthode complète)

```bash
# Vider tous les caches Laravel
php artisan optimize:clear

# Vider le cache des vues spécifiquement
php artisan view:clear

# Supprimer manuellement le cache des vues compilées
rm -rf storage/framework/views/*

# Vérifier que le dossier est vide
ls -la storage/framework/views/
```

### 4. Vérifier et vider OPcache (si activé)

OPcache peut mettre en cache les fichiers PHP compilés.

```bash
# Vérifier si OPcache est activé
php -i | grep opcache

# Si OPcache est activé, redémarrer PHP-FPM
sudo systemctl restart php8.2-fpm
# ou selon votre configuration
sudo service php-fpm restart
# ou
sudo systemctl restart php-fpm
```

### 5. Vérifier les permissions

```bash
# Vérifier les permissions du fichier
ls -la resources/views/quotes/quote.blade.php

# Si nécessaire, corriger les permissions
chmod 644 resources/views/quotes/quote.blade.php
chown www-data:www-data resources/views/quotes/quote.blade.php
```

### 6. Test direct du template

Créer un script de test pour voir le HTML généré :

```bash
# Créer un script de test
cat > test_quote_template.php << 'EOF'
<?php
require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Charger un devis de test
$quote = \App\Models\Quote::first();
if (!$quote) {
    die("Aucun devis trouvé\n");
}

$quote->load(['customer', 'quoteItems.product']);
$company = \App\Models\Company::getInstance();

// Rendre la vue
$html = view('quotes.quote', compact('quote', 'company'))->render();

// Chercher les styles dans le HTML
if (strpos($html, 'background: #f8f9fa') !== false) {
    echo "✓ Style correct trouvé dans le HTML\n";
} else {
    echo "✗ Style incorrect - le template n'a pas été mis à jour\n";
}

if (strpos($html, 'background: #212529') !== false && strpos($html, 'items-table thead') !== false) {
    echo "✗ Ancien style trouvé - le cache n'a pas été vidé\n";
}

// Afficher un extrait du CSS
preg_match('/\.items-table thead\s*\{[^}]+}/', $html, $matches);
if (!empty($matches)) {
    echo "\nStyle trouvé:\n";
    echo $matches[0] . "\n";
}
EOF

# Exécuter le script
php test_quote_template.php

# Nettoyer
rm test_quote_template.php
```

### 7. Vérifier le cache du navigateur

Le problème peut aussi venir du cache du navigateur. Testez avec :

```bash
# Tester avec curl pour voir le PDF brut
curl -I "https://votre-domaine.com/quotes/1/print" \
  -H "Cookie: votre_cookie_de_session"
```

Ou testez en navigation privée/incognito.

### 8. Comparer avec le fichier local

```bash
# Sur votre machine locale, vérifier le hash du fichier
md5sum resources/views/quotes/quote.blade.php
# ou
sha256sum resources/views/quotes/quote.blade.php

# En production, vérifier le même hash
md5sum resources/views/quotes/quote.blade.php
# ou
sha256sum resources/views/quotes/quote.blade.php
```

Les hash doivent être identiques.

## Solution complète (à exécuter dans l'ordre)

```bash
# 1. Se connecter au serveur
ssh user@votre-serveur
cd /chemin/vers/projet

# 2. Vérifier que le fichier est bien déployé
git status
git log -1 --oneline resources/views/quotes/quote.blade.php

# 3. Si le fichier n'est pas à jour, faire un pull
git pull origin main

# 4. Vérifier le contenu du fichier
grep "f8f9fa" resources/views/quotes/quote.blade.php

# 5. Vider TOUS les caches
php artisan optimize:clear
rm -rf storage/framework/views/*

# 6. Redémarrer PHP-FPM (si OPcache est activé)
sudo systemctl restart php8.2-fpm

# 7. Vérifier les permissions
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# 8. Tester
# Ouvrir un devis en production et tester l'impression
```

## Si le problème persiste

### Option 1 : Forcer le rechargement en ajoutant un timestamp

Modifier temporairement le contrôleur pour forcer le rechargement :

```php
// Dans QuoteController.php, méthode printQuote ou downloadQuote
// Ajouter après le view()->render()
$html = str_replace('</head>', '<!-- Cache cleared at ' . now() . ' --></head>', $html);
```

### Option 2 : Vérifier s'il y a plusieurs fichiers template

```bash
# Chercher tous les fichiers quote.blade.php
find . -name "quote.blade.php" -type f

# Vérifier s'il y a des fichiers dans d'autres emplacements
find . -name "*quote*.blade.php" -type f
```

### Option 3 : Vérifier les logs

```bash
# Voir les logs récents
tail -n 100 storage/logs/laravel.log | grep -i quote

# Voir les erreurs
tail -n 100 storage/logs/laravel.log | grep -i error
```

## Commandes de vérification rapide

```bash
# Vérification complète en une commande
echo "=== Vérification du fichier ===" && \
ls -la resources/views/quotes/quote.blade.php && \
echo -e "\n=== Vérification du style ===" && \
grep -A 2 "items-table thead" resources/views/quotes/quote.blade.php && \
echo -e "\n=== Vérification du cache ===" && \
ls -la storage/framework/views/ | head -5 && \
echo -e "\n=== Vider le cache ===" && \
php artisan view:clear && \
echo "Cache vidé !"
```

