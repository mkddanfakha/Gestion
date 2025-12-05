# Diagnostic et résolution du problème 404 sur /delivery-notes/{id}/invoice

## Problème
L'URL `/delivery-notes/10/invoice` retourne une erreur 404 en production, même si le fichier existe.

## Étapes de diagnostic

### 1. Vérifier que les routes sont bien enregistrées

```bash
php artisan route:clear
php artisan route:list | grep delivery-notes.invoice
```

Vous devriez voir :
```
GET|HEAD  delivery-notes/{deliveryNote}/invoice  ... showInvoice
POST      delivery-notes/{deliveryNote}/invoice  ... uploadInvoice
DELETE    delivery-notes/{deliveryNote}/invoice  ... deleteInvoice
```

### 2. Vérifier les logs Laravel

```bash
tail -f storage/logs/laravel.log
```

Puis essayez d'accéder à `/delivery-notes/10/invoice`. Vous devriez voir des logs comme :
- `showInvoice appelé`
- `DeliveryNote trouvé`
- `Permission vérifiée avec succès`
- `Recherche du fichier`
- etc.

Si vous ne voyez **aucun log**, cela signifie que la route n'est pas atteinte (problème de routage).

### 3. Vérifier le cache des routes

Le problème vient souvent du cache des routes qui n'a pas été vidé :

```bash
# Vider le cache des routes
php artisan route:clear

# Vérifier que les routes sont bien chargées
php artisan route:list | grep invoice

# Recréer le cache (optionnel, mais recommandé en production)
php artisan route:cache
```

### 4. Vérifier que le DeliveryNote existe

```bash
php artisan tinker
>>> \App\Models\DeliveryNote::find(10)
>>> \App\Models\DeliveryNote::find(10)->invoice_file_path
```

### 5. Vérifier que le fichier existe physiquement

```bash
# Vérifier sur le disque media
ls -la public/storage/delivery-notes/10/

# Vérifier sur le disque public (ancien emplacement)
ls -la storage/app/public/delivery-notes/10/
```

### 6. Test direct de la route

Testez directement la route avec curl :

```bash
curl -I https://www.niane.mkd-pro.com/delivery-notes/10/invoice \
  -H "Cookie: votre_cookie_de_session"
```

## Solution complète

Si le problème persiste après avoir vidé le cache, exécutez ces commandes en production :

```bash
# 1. Vider tous les caches
php artisan optimize:clear

# 2. Vérifier les routes
php artisan route:list | grep invoice

# 3. Si les routes ne sont pas là, vérifier routes/web.php
cat routes/web.php | grep -A 5 "delivery-notes.*invoice"

# 4. Recréer le cache
php artisan optimize

# 5. Vérifier à nouveau
php artisan route:list | grep invoice
```

## Si le problème persiste

1. Vérifiez que le fichier `routes/web.php` est bien déployé sur le serveur
2. Vérifiez que les modifications ont bien été poussées sur GitHub
3. Vérifiez les permissions sur le serveur
4. Vérifiez les logs Apache/Nginx pour voir si la requête arrive bien au serveur

## Note importante

Les logs détaillés ont été ajoutés dans `showInvoice()`. Si vous ne voyez **aucun log** dans `laravel.log` quand vous accédez à l'URL, cela signifie que la route n'est pas trouvée du tout, probablement à cause du cache des routes.

