# Solution définitive - Thème devis en production

## Problème
Le thème du devis ne s'applique pas en production malgré les modifications.

## Solution étape par étape

### ÉTAPE 1 : Vérifier que le fichier est bien déployé

**Sur le serveur en production, exécutez :**

```bash
cd /chemin/vers/votre/projet

# Vérifier que le fichier existe et sa date
ls -la resources/views/quotes/quote.blade.php

# Vérifier le contenu (doit afficher des résultats)
grep -n "background: #f8f9fa" resources/views/quotes/quote.blade.php | grep "items-table thead"

# Si rien n'est retourné, le fichier n'est pas à jour
# Faites un git pull
git pull origin main
```

### ÉTAPE 2 : Vider TOUS les caches (méthode complète)

```bash
# Méthode 1 : Via artisan
php artisan optimize:clear

# Méthode 2 : Supprimer manuellement les fichiers de cache
rm -rf storage/framework/views/*
rm -rf bootstrap/cache/*.php
rm -rf storage/framework/cache/data/*

# Vérifier que les dossiers sont vides
ls -la storage/framework/views/
ls -la bootstrap/cache/
```

### ÉTAPE 3 : Redémarrer PHP-FPM (CRUCIAL)

OPcache peut mettre en cache les fichiers PHP compilés. **C'est souvent la cause principale du problème.**

```bash
# Trouver votre version de PHP
php -v

# Redémarrer PHP-FPM selon votre version
sudo systemctl restart php8.2-fpm
# ou
sudo systemctl restart php8.1-fpm
# ou
sudo systemctl restart php-fpm
# ou
sudo service php-fpm restart

# Vérifier que PHP-FPM a redémarré
sudo systemctl status php8.2-fpm
```

### ÉTAPE 4 : Vérifier OPcache

```bash
# Vérifier si OPcache est activé
php -i | grep opcache.enable

# Si OPcache est activé, vous DEVEZ redémarrer PHP-FPM (voir étape 3)
```

### ÉTAPE 5 : Test de vérification

Créez un fichier de test pour vérifier que le template est bien utilisé :

```bash
cat > test_quote.php << 'EOF'
<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$quote = \App\Models\Quote::first();
if (!$quote) die("Aucun devis\n");

$quote->load(['customer', 'quoteItems.product']);
$company = \App\Models\Company::getInstance();

$html = view('quotes.quote', compact('quote', 'company'))->render();

if (strpos($html, 'background: #f8f9fa') !== false && strpos($html, 'items-table thead') !== false) {
    echo "✓ Style CORRECT trouvé\n";
} else {
    echo "✗ Style INCORRECT\n";
    echo "Le template n'a pas été mis à jour ou le cache n'a pas été vidé\n";
}

preg_match('/\.items-table thead\s*\{[^}]+}/', $html, $matches);
if (!empty($matches)) {
    echo "\nStyle trouvé dans le HTML:\n";
    echo $matches[0] . "\n";
}
EOF

php test_quote.php
rm test_quote.php
```

### ÉTAPE 6 : Si ça ne marche toujours pas - Solution de contournement

Modifiez temporairement le contrôleur pour forcer le rechargement :

**Dans `app/Http/Controllers/QuoteController.php`, méthode `printQuote` ou `downloadQuote` :**

```php
// Après la ligne : $html = view('quotes.quote', compact('quote', 'company'))->render();

// Ajouter cette ligne pour forcer le rechargement
$html = str_replace('</head>', '<!-- Force reload: ' . time() . ' --></head>', $html);
```

Puis videz le cache et redémarrez PHP-FPM.

## Script complet à exécuter

```bash
#!/bin/bash
cd /chemin/vers/votre/projet

echo "=== Étape 1 : Vérification du fichier ==="
if grep -q "background: #f8f9fa" resources/views/quotes/quote.blade.php; then
    echo "✓ Fichier OK"
else
    echo "✗ Fichier non à jour - faites: git pull origin main"
    exit 1
fi

echo ""
echo "=== Étape 2 : Vidage des caches ==="
php artisan optimize:clear
rm -rf storage/framework/views/*
rm -rf bootstrap/cache/*.php
echo "✓ Caches vidés"

echo ""
echo "=== Étape 3 : Redémarrage PHP-FPM ==="
# Décommentez la ligne correspondant à votre version PHP
# sudo systemctl restart php8.2-fpm
# sudo systemctl restart php8.1-fpm
# sudo service php-fpm restart
echo "⚠ Redémarrez PHP-FPM manuellement selon votre configuration"

echo ""
echo "=== Terminé ==="
echo "Testez maintenant l'impression d'un devis"
```

## Vérification finale

1. Ouvrez un devis en production
2. Cliquez sur "Imprimer" ou "Télécharger"
3. Vérifiez que :
   - L'en-tête du tableau est gris clair (#f8f9fa) et non noir
   - Le texte de l'en-tête est gris (#6c757d) et non blanc
   - Le grand total a un fond gris clair (#f8f9fa) et non noir

## Si le problème persiste

1. **Vérifiez les logs** : `tail -f storage/logs/laravel.log`
2. **Vérifiez que le fichier est bien commité** : `git log resources/views/quotes/quote.blade.php`
3. **Vérifiez les permissions** : `chmod 644 resources/views/quotes/quote.blade.php`
4. **Contactez votre hébergeur** pour vérifier s'il y a un cache au niveau du serveur web

## Note importante

**Le redémarrage de PHP-FPM est souvent la solution** car OPcache met en cache les fichiers PHP compilés. Même si vous videz le cache Laravel, OPcache peut toujours servir l'ancienne version.

