# NotificationCenter

Module **réutilisable** de centre de notifications pour **Laravel + Vue 3 + Pinia**.

Aucune logique métier (produit, facture, vente, etc.). Copiez le dossier `components/notifications/` dans n'importe quel projet et branchez uniquement :

- `notification.config.ts`
- `services/NotificationApi.ts` (routes)
- `composables/useNotificationRealtime.ts` + votre provider (Pusher, Reverb, SSE…)

---

## Architecture

```
components/notifications/
├── NotificationCenter.vue      # Point d'entrée (cloche + drawer + toasts)
├── NotificationBell.vue
├── NotificationDrawer.vue
├── NotificationHeader.vue
├── NotificationFooter.vue
├── NotificationList.vue
├── NotificationItem.vue
├── NotificationFilters.vue
├── NotificationSearch.vue
├── NotificationBadge.vue
├── NotificationPriorityBadge.vue
├── NotificationIcon.vue
├── NotificationEmptyState.vue
├── NotificationLoading.vue
├── NotificationToast.vue
├── NotificationToastContainer.vue
├── notification.config.ts      # Personnalisation (couleurs, textes, routes…)
├── types.ts                    # Interfaces TypeScript
├── index.ts                    # Exports publics
├── store/
│   └── notificationStore.ts    # État Pinia (seule source de vérité UI)
├── services/
│   ├── NotificationApi.ts      # HTTP découplé
│   └── NotificationSound.ts    # Sons optionnels
├── composables/
│   └── useNotificationRealtime.ts
├── utils/
│   ├── animations.ts
│   ├── dateGroups.ts
│   └── search.ts
└── styles/
    └── notification-center.css
```

### Principes SOLID

| Couche | Responsabilité |
|--------|----------------|
| **Composants Vue** | Affichage, props/emits, lecture Pinia |
| **Store Pinia** | État, filtres, pagination, toasts |
| **NotificationApi** | Appels HTTP uniquement |
| **notification.config.ts** | Thème, textes, icônes, animations |
| **useNotificationRealtime** | Pont générique vers le temps réel |
| **Projet hôte** | Mapper métier, provider Pusher, routes Laravel |

Les composants **n'appellent jamais d'API directement**.

---

## Modèle de données

```typescript
interface Notification {
  id: string
  title: string
  description: string
  type: string           // identifiant générique (ex. "alert", "invoice_due")
  priority: 'critical' | 'warning' | 'info'
  icon?: string          // clé dans notification.config.iconMap
  color?: string
  created_at: string
  read_at?: string | null
  resolved_at?: string | null
  status: 'active' | 'resolved' | 'archived'
  url?: string           // navigation au clic
  metadata?: Record<string, unknown>
  favorite?: boolean
}
```

Le backend Laravel transforme vos entités métier en ce format **avant** d'envoyer au frontend.

---

## Installation dans un nouveau projet

### 1. Copier le module

```bash
cp -r resources/js/components/notifications /chemin/nouveau-projet/resources/js/components/
```

### 2. Dépendances npm

```bash
npm install pinia lucide-vue-next @vueuse/core
```

### 3. Pinia

```typescript
// app.ts
import { createPinia } from 'pinia'
const app = createApp(...)
app.use(createPinia())
```

### 4. Styles

```css
/* app.css */
@import '../js/components/notifications/styles/notification-center.css';
```

### 5. Configuration

```typescript
import { configureNotificationCenter, setNotificationApi, NotificationApi } from '@/components/notifications'

configureNotificationCenter({
  navigate: (url) => router.visit(url), // ou window.location.href
  getCsrfToken: () => document.querySelector('meta[name="csrf-token"]')?.content ?? '',
  toastPosition: 'bottom-right',
  settings: { soundEnabled: true, toastEnabled: true, desktopEnabled: false },
})

setNotificationApi(new NotificationApi({
  resolveRoute: (key) => ({
    list: '/api/notifications',
    markAsRead: '/api/notifications/read',
    markAllAsRead: '/api/notifications/read-all',
    archive: '/api/notifications/archive',
    delete: '/api/notifications',
    deleteRead: '/api/notifications/read',
    search: '/api/notifications/search',
  }[key]),
}))
```

### 6. Monter le composant

```vue
<script setup lang="ts">
import { NotificationCenter } from '@/components/notifications'
import { useMyNotificationIntegration } from '@/integrations/notifications'

useMyNotificationIntegration()
</script>

<template>
  <NotificationCenter />
</template>
```

---

## Store Pinia

```typescript
import { useNotificationStore } from '@/components/notifications'

const store = useNotificationStore()

store.setNotifications([...])  // Sync initiale
store.add(notification)          // Temps réel
store.markAsRead(notification)
store.markAllAsRead()
store.archive(notification)
store.remove(notification)
store.openDrawer()
store.setFilter('unread')
store.setSearchQuery('...')
store.loadMore()                 // Infinite scroll
```

---

## NotificationApi

Méthodes disponibles :

| Méthode | Description |
|---------|-------------|
| `getNotifications(params?)` | Liste paginée |
| `markAsRead(notification)` | Marquer lue |
| `markAllAsRead()` | Tout marquer lu |
| `archive(notification)` | Archiver |
| `delete(notification)` | Supprimer |
| `deleteRead()` | Supprimer les lues |
| `search(query)` | Recherche serveur |

Personnalisez les corps de requête pour des APIs legacy :

```typescript
new NotificationApi({
  markAsReadBody: (n) => ({ id: n.id }),
  markAllAsReadBody: () => ({ all: true }),
})
```

---

## Temps réel (sans couplage Pusher)

Le module expose `RealtimeProvider` :

```typescript
interface RealtimeProvider {
  subscribe: (onMessage: (payload: unknown) => void) => () => void
  normalize?: (payload: unknown) => Notification | null
}
```

### Exemple Pusher (dans votre projet, pas dans le module)

```typescript
import { useNotificationRealtime } from '@/components/notifications'

const provider = {
  subscribe(onMessage) {
    const channel = echo.private(`user.${userId}.notifications`)
    channel.listen('.notification.sent', (data) => onMessage(data.notification))
    return () => echo.leave(`user.${userId}.notifications`)
  },
  normalize(payload) {
    return mapBackendPayloadToNotification(payload)
  },
}

useNotificationRealtime(provider)
```

Remplacez Pusher par **Reverb**, **Ably**, **Socket.io** ou **SSE** en changeant uniquement le provider.

---

## Personnalisation

### Couleurs & priorités

```typescript
configureNotificationCenter({
  priorityColors: {
    critical: { bg: 'bg-red-50', text: 'text-red-700', border: 'border-red-200', badge: 'bg-red-100 text-red-800' },
    // ...
  },
})
```

### Icônes

```typescript
import { Package, Receipt } from 'lucide-vue-next'

configureNotificationCenter({
  iconMap: {
    invoice: Receipt,
    inventory: Package,
    my_custom_type: MyIcon,
  },
  defaultIcon: Bell,
})
```

Le backend envoie `icon: 'invoice'` ou le composant résout via `type`.

### Ajouter un type de notification

1. **Backend** : renvoyer un objet `Notification` avec `type`, `title`, `description`, `priority`, `url`.
2. **Config** : ajouter l'icône dans `iconMap`.
3. Aucune modification des composants Vue.

### Animations

Noms configurables dans `notification.config.ts` → `animations`. Classes CSS dans `styles/notification-center.css` :

- `nc-fade` — fondu
- `nc-slide` — glissement
- `nc-scale` — zoom

### Sons

```typescript
configureNotificationCenter({
  settings: { soundEnabled: true, toastEnabled: true, desktopEnabled: false },
  soundFrequencies: { critical: 880, warning: 740, info: 620 },
})
```

---

## Filtres

| Filtre | Comportement |
|--------|--------------|
| Toutes | Toutes les actives |
| Non lues | `read_at` null |
| Critiques / Warnings / Infos | Par `priority` |
| Archivées | `status === 'archived'` |
| Résolues | `status === 'resolved'` ou lues |
| Favoris | `favorite` ou marquées localement |

---

## Accessibilité

- Navigation clavier (`Escape` ferme le drawer)
- Attributs ARIA (`role="dialog"`, `aria-live`, `aria-label`)
- Focus visible sur les onglets et boutons
- Texte masqué `.nc-sr-only` pour lecteurs d'écran

---

## Intégration projet Gestion (exemple)

Voir `resources/js/integrations/notifications/` :

- `notificationMapper.ts` — transforme le payload Inertia legacy → `Notification[]`
- `pusherProvider.ts` — provider Pusher
- `useGestionNotificationCenter.ts` — branche tout au démarrage

---

## Backend Laravel (recommandations)

Exposez une API REST standard :

```
GET    /api/notifications
POST   /api/notifications/read
POST   /api/notifications/read-all
POST   /api/notifications/archive
DELETE /api/notifications/{id}
GET    /api/notifications/search?q=
```

Ou continuez avec Inertia + `onRefresh` :

```typescript
configureNotificationCenter({
  onRefresh: () => router.reload({ only: ['notifications'] }),
})
```

---

## Licence

Module interne — réutilisable librement dans vos projets Laravel.
