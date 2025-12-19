#!/bin/bash

echo "=== Vérification des Notifications en Temps Réel en Production ==="
echo ""

# Couleurs pour l'affichage
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 1. Vérifier les variables d'environnement
echo "1. Vérification des variables d'environnement..."
if grep -q "VITE_PUSHER_APP_KEY" .env; then
    echo -e "${GREEN}✓ VITE_PUSHER_APP_KEY trouvé dans .env${NC}"
else
    echo -e "${RED}✗ VITE_PUSHER_APP_KEY manquant dans .env${NC}"
    echo "  Ajoutez: VITE_PUSHER_APP_KEY=\"\${PUSHER_APP_KEY}\""
fi

if grep -q "VITE_PUSHER_APP_CLUSTER" .env; then
    echo -e "${GREEN}✓ VITE_PUSHER_APP_CLUSTER trouvé dans .env${NC}"
else
    echo -e "${RED}✗ VITE_PUSHER_APP_CLUSTER manquant dans .env${NC}"
    echo "  Ajoutez: VITE_PUSHER_APP_CLUSTER=\"\${PUSHER_APP_CLUSTER}\""
fi

if grep -q "PUSHER_APP_KEY" .env; then
    echo -e "${GREEN}✓ PUSHER_APP_KEY trouvé dans .env${NC}"
else
    echo -e "${RED}✗ PUSHER_APP_KEY manquant dans .env${NC}"
fi

if grep -q "PUSHER_APP_SECRET" .env; then
    echo -e "${GREEN}✓ PUSHER_APP_SECRET trouvé dans .env${NC}"
else
    echo -e "${RED}✗ PUSHER_APP_SECRET manquant dans .env${NC}"
fi

if grep -q "PUSHER_APP_ID" .env; then
    echo -e "${GREEN}✓ PUSHER_APP_ID trouvé dans .env${NC}"
else
    echo -e "${RED}✗ PUSHER_APP_ID manquant dans .env${NC}"
fi

if grep -q "BROADCAST_DRIVER=pusher" .env; then
    echo -e "${GREEN}✓ BROADCAST_DRIVER=pusher configuré${NC}"
else
    echo -e "${YELLOW}⚠ BROADCAST_DRIVER n'est pas défini sur 'pusher'${NC}"
    echo "  Ajoutez: BROADCAST_DRIVER=pusher"
fi

echo ""

# 2. Vérifier que les assets sont compilés
echo "2. Vérification des assets compilés..."
if [ -f "public/build/manifest.json" ]; then
    echo -e "${GREEN}✓ Assets compilés trouvés${NC}"
    
    # Vérifier si les variables VITE sont dans les assets
    if grep -r "VITE_PUSHER" public/build/assets/*.js 2>/dev/null | head -1 > /dev/null; then
        echo -e "${GREEN}✓ Variables VITE trouvées dans les assets${NC}"
    else
        echo -e "${RED}✗ Variables VITE non trouvées dans les assets${NC}"
        echo "  Les assets doivent être recompilés avec: npm run build"
    fi
else
    echo -e "${RED}✗ Assets non compilés${NC}"
    echo "  Exécutez: npm run build"
fi

echo ""

# 3. Vérifier les routes de broadcasting
echo "3. Vérification des routes de broadcasting..."
if php artisan route:list | grep -q "broadcasting/auth"; then
    echo -e "${GREEN}✓ Route /broadcasting/auth trouvée${NC}"
else
    echo -e "${RED}✗ Route /broadcasting/auth non trouvée${NC}"
    echo "  Vérifiez routes/web.php pour Broadcast::routes()"
fi

echo ""

# 4. Vérifier les canaux
echo "4. Vérification des canaux de broadcasting..."
if [ -f "routes/channels.php" ]; then
    if grep -q "user.*userId.*notifications" routes/channels.php; then
        echo -e "${GREEN}✓ Canal user.{userId}.notifications configuré${NC}"
    else
        echo -e "${RED}✗ Canal user.{userId}.notifications non trouvé${NC}"
    fi
else
    echo -e "${RED}✗ Fichier routes/channels.php non trouvé${NC}"
fi

echo ""

# 5. Vérifier le cache
echo "5. Vérification du cache..."
echo "  Pour vider le cache, exécutez:"
echo "    php artisan config:clear"
echo "    php artisan cache:clear"
echo "    php artisan route:clear"

echo ""
echo "=== Résumé ==="
echo "Si des erreurs sont présentes, suivez les instructions ci-dessus."
echo "Après correction, exécutez:"
echo "  1. npm run build"
echo "  2. php artisan config:clear && php artisan cache:clear"
echo "  3. Redémarrez votre serveur web si nécessaire"

