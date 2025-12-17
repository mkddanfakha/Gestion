#!/bin/bash

# Script pour corriger le thème du devis en production
# Usage: ./fix_quote_theme.sh

echo "=== Diagnostic et correction du thème devis ==="
echo ""

# Couleurs pour les messages
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 1. Vérifier que le fichier existe
echo "1. Vérification du fichier template..."
if [ ! -f "resources/views/quotes/quote.blade.php" ]; then
    echo -e "${RED}✗ Fichier non trouvé: resources/views/quotes/quote.blade.php${NC}"
    exit 1
fi
echo -e "${GREEN}✓ Fichier trouvé${NC}"

# 2. Vérifier que le fichier contient les bonnes modifications
echo ""
echo "2. Vérification du contenu..."
if grep -q "background: #f8f9fa" resources/views/quotes/quote.blade.php; then
    echo -e "${GREEN}✓ Style correct trouvé (f8f9fa)${NC}"
else
    echo -e "${RED}✗ Style incorrect - le fichier n'a pas été mis à jour${NC}"
    echo "Le fichier doit contenir 'background: #f8f9fa' pour items-table thead"
    exit 1
fi

if grep -q "color: #6c757d" resources/views/quotes/quote.blade.php && grep -q "items-table thead" resources/views/quotes/quote.blade.php; then
    echo -e "${GREEN}✓ Style thead correct${NC}"
else
    echo -e "${YELLOW}⚠ Vérifiez manuellement le style items-table thead${NC}"
fi

# 3. Vider tous les caches
echo ""
echo "3. Vidage des caches..."
php artisan optimize:clear
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Cache Laravel vidé${NC}"
else
    echo -e "${RED}✗ Erreur lors du vidage du cache${NC}"
    exit 1
fi

# 4. Supprimer manuellement le cache des vues
echo ""
echo "4. Suppression du cache des vues compilées..."
if [ -d "storage/framework/views" ]; then
    rm -rf storage/framework/views/*
    echo -e "${GREEN}✓ Cache des vues supprimé${NC}"
else
    echo -e "${YELLOW}⚠ Dossier storage/framework/views non trouvé${NC}"
fi

# 5. Vérifier OPcache
echo ""
echo "5. Vérification d'OPcache..."
if php -i | grep -q "opcache.enable.*1"; then
    echo -e "${YELLOW}⚠ OPcache est activé - redémarrez PHP-FPM manuellement${NC}"
    echo "   Commande: sudo systemctl restart php8.2-fpm"
    echo "   ou: sudo service php-fpm restart"
else
    echo -e "${GREEN}✓ OPcache n'est pas activé${NC}"
fi

# 6. Vérifier les permissions
echo ""
echo "6. Vérification des permissions..."
if [ -w "resources/views/quotes/quote.blade.php" ]; then
    echo -e "${GREEN}✓ Permissions OK${NC}"
else
    echo -e "${YELLOW}⚠ Problème de permissions - correction en cours...${NC}"
    chmod 644 resources/views/quotes/quote.blade.php
    if [ -w "storage/framework/views" ]; then
        chmod -R 775 storage/framework/views
    fi
fi

# 7. Résumé
echo ""
echo "=== Résumé ==="
echo -e "${GREEN}✓ Fichier vérifié${NC}"
echo -e "${GREEN}✓ Caches vidés${NC}"
echo ""
echo "Prochaines étapes:"
echo "1. Si OPcache est activé, redémarrez PHP-FPM"
echo "2. Testez l'impression d'un devis en production"
echo "3. Si le problème persiste, vérifiez les logs: tail -f storage/logs/laravel.log"
echo ""
echo -e "${GREEN}Script terminé !${NC}"

