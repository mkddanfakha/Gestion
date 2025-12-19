# Fix Notifications Temps Réel en Production

## Problèmes courants et solutions

### 1. Variables d'environnement VITE non compilées

**Problème** : Les variables `VITE_PUSHER_APP_KEY` et `VITE_PUSHER_APP_CLUSTER` ne sont pas disponibles dans les assets compilés.

**Solution** :
1. Vérifiez que les variables sont définies dans votre `.env` de production :
```env
PUSHER_APP_KEY=votre-key
PUSHER_APP_SECRET=votre-secret
PUSHER_APP_ID=votre-app-id
PUSHER_APP_CLUSTER=votre-cluster

VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
VITE_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"
```

2. **IMPORTANT** : Recompilez les assets en production avec ces variables :
```bash
npm run build
```

Les variables `VITE_*` sont compilées dans les assets au moment du build, pas au runtime !

### 2. Route de broadcasting non accessible

**Problème** : La route `/broadcasting/auth` retourne une erreur 404 ou 403.

**Solution** :
1. Vérifiez que la route existe :
```bash
php artisan route:list | grep broadcasting
```

2. Vérifiez que le middleware d'authentification est correctement configuré dans `routes/channels.php`

3. Vérifiez les permissions dans `routes/channels.php` :
```php
Broadcast::channel('user.{userId}.notifications', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
```

### 3. Configuration Pusher incorrecte

**Problème** : Les identifiants Pusher sont incorrects ou manquants.

**Solution** :
1. Vérifiez toutes les variables dans `.env` :
```env
BROADCAST_DRIVER=pusher
PUSHER_APP_ID=votre-app-id
PUSHER_APP_KEY=votre-app-key
PUSHER_APP_SECRET=votre-app-secret
PUSHER_APP_CLUSTER=votre-cluster
```

2. Vérifiez que le cluster correspond à celui de votre compte Pusher

3. Videz le cache de configuration :
```bash
php artisan config:clear
php artisan cache:clear
```

### 4. CORS ou problèmes de connexion WebSocket

**Problème** : Les connexions WebSocket sont bloquées.

**Solution** :
1. Vérifiez que votre serveur web (nginx/apache) permet les connexions WebSocket
2. Vérifiez les logs du navigateur (F12 > Console) pour les erreurs de connexion
3. Vérifiez que le port 443 (HTTPS) ou 80 (HTTP) est ouvert pour Pusher

### 5. Événements non broadcastés

**Problème** : Les événements sont créés mais ne sont pas broadcastés.

**Solution** :
1. Vérifiez que l'événement implémente `ShouldBroadcastNow` :
```php
class NotificationSent implements ShouldBroadcastNow
```

2. Vérifiez que le service de broadcasting est actif :
```bash
php artisan config:show broadcasting
```

3. Testez manuellement l'envoi d'une notification :
```bash
php artisan tinker
```
```php
event(new \App\Events\NotificationSent([
    'type' => 'test',
    'id' => 999,
    'message' => 'Test notification'
], auth()->id()));
```

## Checklist de déploiement

- [ ] Variables `VITE_PUSHER_APP_KEY` et `VITE_PUSHER_APP_CLUSTER` définies dans `.env`
- [ ] Assets recompilés avec `npm run build` après avoir défini les variables
- [ ] Variables `PUSHER_APP_*` correctement configurées dans `.env`
- [ ] `BROADCAST_DRIVER=pusher` dans `.env`
- [ ] Cache Laravel vidé : `php artisan config:clear && php artisan cache:clear`
- [ ] Route `/broadcasting/auth` accessible et fonctionnelle
- [ ] Permissions dans `routes/channels.php` correctes
- [ ] Serveur web configuré pour permettre les connexions WebSocket
- [ ] Pas d'erreurs dans la console du navigateur (F12)

## Diagnostic en production

### 1. Vérifier les variables compilées

Ouvrez la console du navigateur (F12) et tapez :
```javascript
console.log(import.meta.env.VITE_PUSHER_APP_KEY)
console.log(import.meta.env.VITE_PUSHER_APP_CLUSTER)
```

Si ces valeurs sont `undefined`, les assets n'ont pas été compilés avec les bonnes variables.

### 2. Vérifier la connexion Echo

Dans la console du navigateur :
```javascript
window.Echo.connector.pusher.connection.state
```

Devrait retourner `"connected"` ou `"connecting"`.

### 3. Vérifier la souscription au canal

Dans la console du navigateur, vous devriez voir :
- `Notifications: Tentative de souscription au canal user.X.notifications`
- `Notifications: Canal souscrit avec succès: user.X.notifications`

Si vous ne voyez pas "Canal souscrit avec succès", vérifiez :
- La route `/broadcasting/auth` (onglet Network dans les DevTools)
- Les permissions dans `routes/channels.php`
- Que vous êtes bien authentifié

### 4. Vérifier la réception des événements

Avec les nouveaux logs, vous devriez voir dans la console :
- `Notifications: Événement reçu sur le canal user.X.notifications Event: ... Data: ...`

Si vous voyez cette ligne mais pas "Événement notification.sent reçu", le nom de l'événement ne correspond pas.

### 5. Tester l'envoi d'une notification

Dans la console du navigateur :
```javascript
fetch('/notifications/test', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
        'Accept': 'application/json',
    },
})
```

Cela devrait déclencher une notification de test que vous devriez voir dans la console.

### 3. Vérifier les erreurs de connexion

Regardez la console du navigateur pour les erreurs :
- `Echo n'est pas disponible` → Variables VITE non compilées
- `Erreur de connexion Pusher` → Problème de configuration Pusher
- `401 Unauthorized` → Problème d'authentification sur `/broadcasting/auth`
- `404 Not Found` → Route `/broadcasting/auth` non trouvée

## Solution rapide

Si rien ne fonctionne, essayez cette séquence complète :

```bash
# 1. Vérifier les variables dans .env
cat .env | grep PUSHER
cat .env | grep VITE_PUSHER

# 2. Vider tous les caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# 3. Recompiler les assets avec les variables
npm run build

# 4. Redémarrer les services si nécessaire
# (PHP-FPM, Nginx, etc.)
```

## Support supplémentaire

Si le problème persiste, vérifiez :
1. Les logs Laravel : `storage/logs/laravel.log`
2. Les logs du serveur web (nginx/apache)
3. La console du navigateur pour les erreurs JavaScript
4. Le dashboard Pusher pour voir si les événements sont envoyés

