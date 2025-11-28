# Débogage des Notifications de Produits Expirés

## Problème
Le son joue mais la cloche ne change pas pour les produits expirés ou proches de l'expiration.

## Logs ajoutés

### Côté serveur
- Logs dans `NotificationService::notifyExpiringProduct()` pour voir si la notification est envoyée
- Logs dans `HandleInertiaRequests` pour voir quels produits sont détectés comme expirés

### Côté client
- Logs dans le computed `notificationCount` pour voir le calcul
- Logs dans le watch pour voir les changements de notifications
- Logs dans le handler d'événement pour voir quand les notifications sont reçues

## Vérifications à faire

### 1. Vérifier les logs Laravel

Regardez le fichier `storage/logs/laravel.log` après avoir créé/modifié un produit expiré :

```bash
tail -f storage/logs/laravel.log | grep -i "expir"
```

Vous devriez voir :
- `Envoi notification produit expiré` - si la notification est envoyée
- `Notification déjà lue, non envoyée` - si le produit est déjà marqué comme lu
- `Produit expiré/proche expiration` - les produits détectés dans HandleInertiaRequests

### 2. Vérifier la console du navigateur

Après avoir créé/modifié un produit expiré, vérifiez dans la console :

1. **Message de notification reçue** :
   ```
   🔔 Notification reçue en temps réel: {notification: {...}}
   ```

2. **Calcul du compteur** :
   ```
   📊 Calcul du compteur de notifications: {expiringProducts: X, ...}
   ```

3. **Changement de notifications** :
   ```
   🔄 Notifications ont changé: {old: {...}, new: {...}}
   ```

4. **Rechargement** :
   ```
   ✅ Notifications rechargées avec succès: {expiringProducts: X, ...}
   ```

### 3. Vérifier si le produit est déjà marqué comme lu

Dans la console du navigateur, créez un produit expiré, puis vérifiez :

```javascript
// Vérifier les notifications actuelles
console.log('Notifications actuelles:', window.$page?.props?.notifications)

// Vérifier le compteur
console.log('Compteur:', document.querySelector('.notification-badge')?.textContent)
```

### 4. Test manuel

1. Créez un produit avec une date d'expiration passée (hier ou avant)
2. Ou modifiez un produit existant pour mettre une date d'expiration passée
3. Vérifiez les logs dans la console
4. Vérifiez les logs Laravel

### 5. Vérifier la base de données

Vérifiez si le produit est déjà marqué comme lu :

```sql
SELECT * FROM notification_reads 
WHERE notification_type = 'expiring_product' 
AND notification_id = [ID_DU_PRODUIT];
```

Si le produit est déjà marqué comme lu, il n'apparaîtra pas dans les notifications.

## Solutions possibles

### Si le produit est déjà marqué comme lu

Le produit a peut-être été marqué comme lu automatiquement. Vérifiez s'il y a un code qui marque automatiquement les notifications comme lues.

### Si le produit n'est pas détecté comme expiré

Vérifiez :
1. Que `expiration_date` n'est pas null
2. Que `is_active` est true
3. Que `isExpired()` ou `isExpiringSoon()` retourne true
4. Que `alert_threshold_value` et `alert_threshold_unit` sont configurés pour `isExpiringSoon()`

### Si le rechargement ne fonctionne pas

Vérifiez :
1. Que `router.reload()` est bien appelé
2. Que les props sont bien mises à jour
3. Que le computed property se recalcule

## Commandes utiles

```bash
# Voir les logs en temps réel
tail -f storage/logs/laravel.log

# Vider le cache
php artisan config:clear
php artisan cache:clear

# Vérifier les produits expirés
php artisan tinker
>>> App\Models\Product::whereNotNull('expiration_date')->get()->filter(fn($p) => $p->isExpired() || $p->isExpiringSoon())->pluck('id', 'name')
```


