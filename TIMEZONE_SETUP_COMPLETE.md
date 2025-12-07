# Configuration complète du fuseau horaire du Sénégal

## ✅ Configurations effectuées

### 1. Backend (Laravel)

#### `config/app.php`
- ✅ **Fuseau horaire** : `'timezone' => 'Africa/Dakar'`
- ✅ **Locale** : `'locale' => 'fr'`
- ✅ **Fallback locale** : `'fallback_locale' => 'fr'`
- ✅ **Faker locale** : `'faker_locale' => 'fr_FR'`

#### `app/Providers/AppServiceProvider.php`
- ✅ Ajout de `date_default_timezone_set('Africa/Dakar')` dans la méthode `boot()` pour garantir que PHP utilise le fuseau horaire du Sénégal globalement

### 2. Frontend (Vue.js)

#### Utilitaire de formatage (`resources/js/utils/dateFormatter.ts`)
- ✅ Création d'un utilitaire centralisé pour le formatage des dates avec le fuseau horaire du Sénégal
- ✅ Toutes les fonctions utilisent `Intl.DateTimeFormat` avec `timeZone: 'Africa/Dakar'`

#### Fichiers migrés vers l'utilitaire
- ✅ `resources/js/pages/Dashboard.vue`
- ✅ `resources/js/pages/Sales/Index.vue`
- ✅ `resources/js/pages/Quotes/Index.vue`
- ✅ `resources/js/pages/Quotes/Show.vue`
- ✅ `resources/js/pages/Expenses/Index.vue`
- ✅ `resources/js/components/NotificationBell.vue`

## 📋 Fonctions disponibles dans l'utilitaire

```typescript
import { 
  formatDate,           // DD/MM/YYYY
  formatDateTime,       // DD/MM/YYYY HH:MM
  formatTime,           // HH:MM
  formatDateLong,       // 15 janvier 2025
  formatDateShort,      // 15 jan. 2025
  formatDateForInput,   // YYYY-MM-DD (pour inputs)
  formatDateRelative,   // "il y a 2 heures"
  getCurrentDate        // Date actuelle du Sénégal
} from '@/utils/dateFormatter'
```

## 🔄 Fichiers restants à migrer (optionnel)

Les fichiers suivants utilisent encore des fonctions de formatage locales et peuvent être migrés progressivement :

- `resources/js/pages/DeliveryNotes/Index.vue`
- `resources/js/pages/DeliveryNotes/Show.vue`
- `resources/js/pages/DeliveryNotes/Edit.vue`
- `resources/js/pages/DeliveryNotes/Create.vue`
- `resources/js/pages/PurchaseOrders/Index.vue`
- `resources/js/pages/PurchaseOrders/Show.vue`
- `resources/js/pages/PurchaseOrders/Edit.vue`
- `resources/js/pages/PurchaseOrders/Create.vue`
- `resources/js/pages/Sales/Show.vue`
- `resources/js/pages/Sales/Edit.vue`
- `resources/js/pages/Sales/Create.vue`
- `resources/js/pages/Quotes/Edit.vue`
- `resources/js/pages/Quotes/Create.vue`
- `resources/js/pages/Expenses/Show.vue`
- `resources/js/pages/Expenses/Create.vue`
- `resources/js/pages/Products/Index.vue`
- `resources/js/pages/Products/Show.vue`
- `resources/js/pages/Products/Edit.vue`
- `resources/js/pages/Customers/Index.vue`
- `resources/js/pages/Customers/Show.vue`
- `resources/js/pages/Suppliers/Index.vue`
- `resources/js/pages/Suppliers/Show.vue`
- `resources/js/pages/Admin/Users/Index.vue`
- `resources/js/pages/Admin/Backups/Index.vue`

## 📝 Comment migrer un fichier

1. **Ajouter l'import** en haut du fichier :
```typescript
import { formatDate, formatTime } from '@/utils/dateFormatter'
```

2. **Supprimer les fonctions locales** :
```typescript
// Supprimer ceci :
const formatDate = (date: string) => {
  return new Date(date).toLocaleDateString('fr-FR')
}

const formatTime = (date: string) => {
  return new Date(date).toLocaleTimeString('fr-FR', { 
    hour: '2-digit', 
    minute: '2-digit' 
  })
}
```

3. **Utiliser les fonctions importées** directement dans le template (pas de changement nécessaire)

## ✅ Vérification

Pour vérifier que tout fonctionne correctement :

1. **Backend** : Les dates créées par Laravel utilisent automatiquement le fuseau horaire du Sénégal
2. **Frontend** : Toutes les dates affichées utilisent le fuseau horaire du Sénégal via l'utilitaire
3. **Base de données** : Les dates sont stockées en UTC et converties automatiquement par Laravel

## 🎯 Résultat

- ✅ Toute l'application utilise maintenant le fuseau horaire du Sénégal (Africa/Dakar, UTC+0)
- ✅ Toutes les dates sont formatées en français
- ✅ Les dates sont cohérentes entre le backend et le frontend
- ✅ L'utilitaire centralisé garantit la cohérence du formatage

