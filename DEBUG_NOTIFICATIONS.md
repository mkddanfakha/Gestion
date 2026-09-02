# Guide de Débogage des Notifications en Temps Réel

## Vérifications à faire

### 1. Vérifier la configuration Pusher dans .env

Assurez-vous que votre fichier `.env` contient (sans guillemets) :

```env
BROADCAST_DRIVER=pusher

PUSHER_APP_ID=<your-pusher-app-id>
PUSHER_APP_KEY=<your-pusher-app-key>
PUSHER_APP_SECRET=<your-pusher-app-secret>
PUSHER_APP_CLUSTER=<your-pusher-app-cluster>

VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
VITE_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"
```

**Important** : Les valeurs ne doivent PAS avoir de guillemets autour.

### 2. Redémarrer les serveurs

Après avoir modifié le `.env`, vous DEVEZ :

1. **Redémarrer le serveur Laravel** (arrêtez et relancez `php artisan serve`)
2. **Redémarrer le serveur Vite** (arrêtez et relancez `npm run dev`)

Les variables `VITE_*` ne sont chargées qu'au démarrage de Vite.

### 3. Vérifier la console du navigateur

Ouvrez la console du navigateur (F12) et vérifiez :

#### Messages attendus au chargement :

```
✅ Configuration Pusher détectée: { key: "<your-key-prefix>...", cluster: "<your-cluster>" }
✅ Pusher connecté avec succès
🔔 Tentative de connexion au canal privé pour l'utilisateur: X
✅ Canal privé souscrit avec succès: user.X.notifications
```

#### Messages d'erreur possibles :

- `❌ VITE_PUSHER_APP_KEY n'est pas défini` → Redémarrez Vite
- `❌ Erreur de connexion Pusher` → Vérifiez vos clés Pusher
- `❌ Erreur lors de la souscription au canal` → Vérifiez l'authentification

### 4. Tester la connexion Pusher

Dans la console du navigateur, tapez :

```javascript
// Vérifier que Pusher est connecté
window.Echo.connector.pusher.connection.state

// Devrait retourner : "connected"
```

### 5. Tester l'envoi d'une notification

Dans la console du navigateur, tapez :

```javascript
testRealtimeNotification()
```

Cela devrait :
1. Envoyer une notification de test au serveur
2. La notification devrait être reçue en temps réel
3. Un son devrait se jouer
4. La cloche de notification devrait se mettre à jour

### 6. Vérifier l'authentification des canaux

Ouvrez l'onglet "Network" (Réseau) dans les outils de développement :
- Filtrez par "broadcasting"
- Vous devriez voir une requête POST vers `/broadcasting/auth` avec un statut 200

Si vous voyez un statut 401 ou 403, vérifiez que vous êtes bien authentifié.

### 7. Vérifier les routes de broadcast

Vérifiez que `routes/web.php` contient :

```php
use Illuminate\Support\Facades\Broadcast;

Broadcast::routes(['middleware' => ['web', 'auth']]);
```

### 8. Vérifier les canaux de broadcast

Vérifiez que `routes/channels.php` contient :

```php
Broadcast::channel('user.{userId}.notifications', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
```

### 9. Tester le son de notification

Dans la console du navigateur, tapez :

```javascript
testNotificationSound()
```

Cela devrait jouer le son de notification immédiatement.

### 10. Vérifier le dashboard Pusher

1. Allez sur https://dashboard.pusher.com/
2. Sélectionnez votre application
3. Allez dans l'onglet "Debug console"
4. Vous devriez voir les connexions et les événements en temps réel

## Problèmes courants

### Le son ne se joue pas

1. Vérifiez que le contexte audio est activé (cliquez quelque part sur la page)
2. Vérifiez la console pour les erreurs audio
3. Testez avec `testNotificationSound()` dans la console

### Les notifications ne sont pas reçues

1. Vérifiez que Pusher est connecté (`window.Echo.connector.pusher.connection.state === "connected"`)
2. Vérifiez que le canal est souscrit (vous devriez voir "✅ Canal privé souscrit avec succès")
3. Vérifiez que l'événement est bien dispatché côté serveur
4. Vérifiez le dashboard Pusher pour voir si les événements sont envoyés

### Erreur 401/403 sur /broadcasting/auth

1. Vérifiez que vous êtes bien authentifié
2. Vérifiez que le middleware `auth` est bien appliqué
3. Vérifiez que le token CSRF est présent dans la page

## Commandes utiles

```bash
# Vider le cache de configuration
php artisan config:clear

# Vider le cache de routes
php artisan route:clear

# Vider tous les caches
php artisan optimize:clear
```


