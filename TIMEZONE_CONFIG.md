# Configuration du fuseau horaire du Sénégal

L'application est configurée pour utiliser le fuseau horaire du Sénégal (Africa/Dakar, UTC+0).

## Configuration Backend (Laravel)

### Fuseau horaire
Le fuseau horaire est configuré dans `config/app.php` :
```php
'timezone' => 'Africa/Dakar',
```

### Locale
La locale est configurée en français :
```php
'locale' => env('APP_LOCALE', 'fr'),
'fallback_locale' => env('APP_FALLBACK_LOCALE', 'fr'),
'faker_locale' => env('APP_FAKER_LOCALE', 'fr_FR'),
```

## Utilitaire de formatage des dates (Frontend)

Un utilitaire a été créé dans `resources/js/utils/dateFormatter.ts` pour standardiser le formatage des dates avec le fuseau horaire du Sénégal.

### Utilisation

```typescript
import { 
  formatDate, 
  formatDateTime, 
  formatTime, 
  formatDateLong, 
  formatDateShort,
  formatDateForInput,
  formatDateRelative,
  getCurrentDate
} from '@/utils/dateFormatter'
```

### Fonctions disponibles

#### `formatDate(date: string | Date): string`
Formate une date au format français (DD/MM/YYYY)
```typescript
formatDate('2025-01-15') // "15/01/2025"
```

#### `formatDateTime(date: string | Date): string`
Formate une date avec l'heure (DD/MM/YYYY HH:MM)
```typescript
formatDateTime('2025-01-15T14:30:00') // "15/01/2025 14:30"
```

#### `formatTime(date: string | Date): string`
Formate uniquement l'heure (HH:MM)
```typescript
formatTime('2025-01-15T14:30:00') // "14:30"
```

#### `formatDateLong(date: string | Date): string`
Formate une date au format long (ex: 15 janvier 2025)
```typescript
formatDateLong('2025-01-15') // "15 janvier 2025"
```

#### `formatDateShort(date: string | Date): string`
Formate une date au format court avec mois abrégé (ex: 15 jan. 2025)
```typescript
formatDateShort('2025-01-15') // "15 jan. 2025"
```

#### `formatDateForInput(date: string | Date | null | undefined): string`
Formate une date pour un input de type date (YYYY-MM-DD)
```typescript
formatDateForInput(new Date()) // "2025-01-15"
```

#### `formatDateRelative(date: string | Date): string`
Formate une date relative (ex: "il y a 2 heures", "dans 3 jours")
```typescript
formatDateRelative('2025-01-15T10:00:00') // "il y a 2 heures"
```

#### `getCurrentDate(): Date`
Obtient la date actuelle dans le fuseau horaire du Sénégal
```typescript
const now = getCurrentDate()
```

## Migration des fichiers existants

Pour migrer les fichiers existants vers l'utilitaire :

1. **Remplacer les imports locaux** :
```typescript
// Avant
const formatDate = (date: string) => {
  return new Date(date).toLocaleDateString('fr-FR')
}

// Après
import { formatDate } from '@/utils/dateFormatter'
```

2. **Utiliser les fonctions de l'utilitaire** :
```typescript
// Avant
const formatTime = (date: string) => {
  return new Date(date).toLocaleTimeString('fr-FR', { 
    hour: '2-digit', 
    minute: '2-digit' 
  })
}

// Après
import { formatTime } from '@/utils/dateFormatter'
```

## Notes importantes

- Toutes les dates sont automatiquement converties dans le fuseau horaire du Sénégal (Africa/Dakar)
- Le formatage utilise la locale française (fr-FR)
- Les dates sont formatées selon les conventions françaises (DD/MM/YYYY)
- L'utilitaire utilise `Intl.DateTimeFormat` avec l'option `timeZone: 'Africa/Dakar'` pour garantir la cohérence

## Vérification

Pour vérifier que le fuseau horaire est correctement configuré :

1. **Backend** : Vérifier `config/app.php` - `timezone` doit être `'Africa/Dakar'`
2. **Frontend** : Utiliser `getCurrentDate()` pour obtenir la date actuelle du Sénégal
3. **Base de données** : Les dates stockées en base sont en UTC, Laravel les convertit automatiquement selon le fuseau horaire configuré

