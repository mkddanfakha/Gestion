# Configuration des Notifications en Temps Réel avec Laravel Reverb

## Installation terminée ✅

Laravel Reverb a été installé et configuré avec succès pour les notifications en temps réel.

## Configuration

### Variables d'environnement (.env)

Les variables suivantes ont été configurées dans votre fichier `.env` :

```env
BROADCAST_DRIVER=reverb

REVERB_APP_ID=668770
REVERB_APP_KEY=jvbh1xy642jp88vqjj0g
REVERB_APP_SECRET=m9q1jbii3gbyjgzzflrm
REVERB_HOST="localhost"
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

## Démarrage du serveur Reverb

Pour que les notifications en temps réel fonctionnent, vous devez démarrer le serveur Reverb dans un terminal séparé :

```bash
php artisan reverb:start
```

Ou en mode développement avec auto-reload :

```bash
php artisan reverb:start --debug
```

## Utilisation

Les notifications en temps réel sont automatiquement déclenchées lorsque :
- Une vente a une date d'échéance aujourd'hui
- Un produit est en stock faible
- Un produit est expiré ou proche de l'expiration

Pour déclencher manuellement une notification, utilisez :

```php
use App\Services\NotificationService;

// Pour une vente avec échéance
NotificationService::notifySaleDueToday($sale);

// Pour un produit en stock faible
NotificationService::notifyLowStock($product);

// Pour un produit expiré
NotificationService::notifyExpiringProduct($product);
```

## Script de développement

Pour faciliter le développement, vous pouvez utiliser le script Composer qui démarre tous les services nécessaires :

```bash
composer dev
```

Ce script démarre :
- Le serveur Laravel (`php artisan serve`)
- La queue Laravel (`php artisan queue:listen`)
- Le serveur Vite (`npm run dev`)
- **Le serveur Reverb** (à ajouter manuellement si nécessaire)

## Vérification

1. Démarrez le serveur Reverb : `php artisan reverb:start`
2. Redémarrez votre serveur Laravel
3. Recompilez les assets : `npm run build` (ou `npm run dev` en développement)
4. Les notifications apparaîtront en temps réel dans la cloche de notification

## Dépannage

### Le serveur Reverb ne démarre pas

Vérifiez que le port 8080 n'est pas déjà utilisé :
```bash
netstat -ano | findstr :8080
```

Si le port est occupé, changez `REVERB_PORT` dans votre `.env`.

### Les notifications ne fonctionnent pas

1. Vérifiez que le serveur Reverb est démarré
2. Ouvrez la console du navigateur (F12) et vérifiez les messages de connexion
3. Vérifiez que `BROADCAST_DRIVER=reverb` dans votre `.env`
4. Vérifiez que les variables `VITE_REVERB_*` sont bien définies

### Erreur de connexion WebSocket

Assurez-vous que :
- Le serveur Reverb est démarré
- Le port configuré correspond au port du serveur Reverb
- Les variables `VITE_REVERB_HOST` et `VITE_REVERB_PORT` sont correctes

