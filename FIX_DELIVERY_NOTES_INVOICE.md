# Fix pour l'erreur 404 sur /delivery-notes/{id}/invoice

## Problème
L'URL `/delivery-notes/10/invoice` retourne une erreur 404 en production.

## Solution

### 1. Vider le cache des routes en production (OBLIGATOIRE)

```bash
php artisan route:clear
php artisan route:cache
```

Ou pour vider tous les caches :

```bash
php artisan optimize:clear
php artisan optimize
```

### 2. Vérifier que les routes sont bien enregistrées

```bash
php artisan route:list | grep delivery-notes.invoice
```

Vous devriez voir :
- `GET|HEAD delivery-notes/{deliveryNote}/invoice` → `showInvoice`
- `POST delivery-notes/{deliveryNote}/invoice` → `uploadInvoice`
- `DELETE delivery-notes/{deliveryNote}/invoice` → `deleteInvoice`

### 3. Vérifier les logs

Si le problème persiste, vérifiez les logs Laravel :

```bash
tail -f storage/logs/laravel.log
```

Les logs devraient indiquer si le DeliveryNote est trouvé ou non.

### 4. Vérifier que le DeliveryNote existe

En production, vérifiez que le DeliveryNote avec l'ID 10 existe :

```bash
php artisan tinker
>>> \App\Models\DeliveryNote::find(10)
```

### 5. Rebuild du frontend (si nécessaire)

Si vous avez modifié `resources/js/lib/routes.ts` :

```bash
npm run build
```

## Ordre des routes

Les routes spécifiques (`invoice`, `download`, `print`) doivent être définies **AVANT** la route resource pour éviter les conflits. C'est déjà fait dans `routes/web.php`.

## Si le problème persiste

1. Vérifiez que le fichier `routes/web.php` est bien déployé
2. Vérifiez que le cache des routes est bien vidé
3. Vérifiez les permissions sur le serveur
4. Vérifiez que le DeliveryNote existe dans la base de données

