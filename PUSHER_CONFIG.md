# Configuration Pusher pour les Notifications en Temps Réel

## Identifiants requis

Pour configurer Pusher, vous devez ajouter les variables suivantes dans votre fichier `.env` :

```env
BROADCAST_DRIVER=pusher

PUSHER_APP_ID=votre-app-id
PUSHER_APP_KEY=votre-app-key
PUSHER_APP_SECRET=votre-app-secret
PUSHER_APP_CLUSTER=votre-cluster

VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
VITE_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"
```

## Où trouver vos identifiants Pusher

1. Connectez-vous à votre compte Pusher : https://dashboard.pusher.com/
2. Sélectionnez votre application (ou créez-en une nouvelle)
3. Allez dans l'onglet "App Keys"
4. Vous trouverez :
   - **App ID** → `PUSHER_APP_ID`
   - **Key** → `PUSHER_APP_KEY`
   - **Secret** → `PUSHER_APP_SECRET`
   - **Cluster** → `PUSHER_APP_CLUSTER` (ex: eu, us, ap-southeast-1, etc.)

## Après configuration

1. Redémarrez votre serveur Laravel
2. Recompilez les assets : `npm run build` (ou `npm run dev` en développement)
3. Les notifications en temps réel fonctionneront automatiquement

## Fonctionnalités

Les notifications en temps réel fonctionnent pour :
- Ventes avec échéance aujourd'hui
- Produits en stock faible
- Produits expirés ou proches de l'expiration

