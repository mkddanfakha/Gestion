# NotificationCenter — Module frontend

Module Vue 3 + Pinia + TypeScript **totalement autonome et réutilisable**.

## Architecture

```
resources/js/modules/NotificationCenter/
├── NotificationCenter.vue      # Point d'entrée UI
├── index.ts                    # API publique du module
├── components/                 # Présentation uniquement (composables)
├── composables/                # Point d'accès unique pour l'UI
├── stores/                     # État Pinia (notifications ≠ préférences)
├── services/                   # HTTP + temps réel + son + navigateur
├── types/                      # Contrats TypeScript
├── config/                     # Configuration injectable
├── constants/                  # Constantes (store ids, labels par défaut)
├── utils/                      # Helpers purs (dates, recherche)
└── styles/                     # CSS du module
```

## Responsabilités

| Couche | Rôle |
|--------|------|
| **NotificationStore** | Liste, filtres, pagination, drawer, toasts UI, CRUD |
| **NotificationPreferencesStore** | Préférences DB, fusion `effective` |
| **useNotifications()** | API unique notifications + orchestration effets |
| **useNotificationPreferences()** | API unique préférences |
| **useNotificationRealtime()** | Connexion temps réel → `handleIncoming()` |
| **useNotificationSound()** | Sons (volume, priorité) |
| **useBrowserNotifications()** | Notification API navigateur |
| **NotificationApi** | HTTP notifications |
| **NotificationPreferenceApi** | HTTP préférences |
| **NotificationRealtimeService** | Abstraction Pusher/Reverb/SSE |

## Flux de données

```
NotificationCenter.vue
    → useNotifications() → NotificationStore → NotificationApi

NotificationCenter.vue
    → useNotificationPreferences() → NotificationPreferencesStore → NotificationPreferenceApi

Pusher (projet hôte)
    → RealtimeProvider → NotificationRealtimeService
    → useNotificationRealtime() → useNotifications().handleIncoming()
```

## Intégration dans un projet Laravel

### 1. Copier le dossier

```
resources/js/modules/NotificationCenter/
```

### 2. Alias Vite

```typescript
'@notification-center': path.resolve(__dirname, 'resources/js/modules/NotificationCenter'),
```

### 3. CSS

```css
@import '../js/modules/NotificationCenter/styles/notification-center.css';
```

### 4. Configurer les adapters projet

```typescript
import {
  configureNotificationCenter,
  setNotificationApi,
  NotificationApi,
} from '@/modules/NotificationCenter'
import { useGestionNotificationCenter } from '@/integrations/notifications/useGestionNotificationCenter'

configureNotificationCenter({
  getCsrfToken: () => document.querySelector('meta[name="csrf-token"]')?.content ?? '',
  navigate: (url) => router.visit(url),
  routes: { preferences: '/api/user/notification-preferences' },
})

setNotificationApi(new NotificationApi({ resolveRoute: (key) => routes[key] }))
```

### 5. Monter le composant

```vue
<script setup>
import NotificationCenter from '@notification-center/NotificationCenter.vue'
import { useGestionNotificationCenter } from '@/integrations/notifications/useGestionNotificationCenter'

useGestionNotificationCenter()
</script>

<template>
  <NotificationCenter />
</template>
```

## Remplacer Pusher

Le module ne connaît **jamais** Pusher. Créez un `RealtimeProvider` dans votre projet :

```typescript
import type { RealtimeProvider } from '@/modules/NotificationCenter/types'
import { setRealtimeProvider } from '@/modules/NotificationCenter'

export function createMyRealtimeProvider(userId: number): RealtimeProvider {
  return {
    subscribe(onMessage) {
      // Reverb, Ably, Socket.io, SSE…
      return () => { /* cleanup */ }
    },
    normalize(payload) {
      return mapToNotification(payload)
    },
  }
}

setRealtimeProvider(createMyRealtimeProvider(userId))
```

Demain : remplacez uniquement ce fichier — **aucun composant à modifier**.

## Ajouter une nouvelle préférence

1. Colonne + validation backend (`user_notification_preferences`)
2. Mettre à jour `NotificationUserPreferences` / `NotificationEffectivePreferences` (types)
3. Fusion dans `NotificationSettingsService::buildEffectivePreferences()`
4. Appliquer dans `useNotifications().handleIncoming()` ou getters si filtre liste
5. Exposer via `useNotificationPreferences()` si l'UI doit l'afficher

## Composables — usage composants

Les composants **n'importent jamais** Pinia ni les services :

```vue
<script setup lang="ts">
import { useNotifications } from '../composables/useNotifications'
import { useNotificationUi } from '../composables/useNotificationUi'

const { unreadCount, openDrawer } = useNotifications()
const { texts } = useNotificationUi()
</script>
```

## API publique

```typescript
import {
  NotificationCenter,
  configureNotificationCenter,
  useNotifications,
  useNotificationPreferences,
  useNotificationRealtime,
} from '@/modules/NotificationCenter'
```

## Compatibilité

L'ancien chemin `@/components/notifications` réexporte le module (deprecated).
