# MKD-Pro NotificationCenter

Module Laravel + Vue.js **totalement découplé** du métier. Réutilisable dans tous les projets MKD-Pro (Gestion, Hôtel, CRM, ERP…).

> **Production** : voir [`PRODUCTION.md`](PRODUCTION.md) et le [`PRODUCTION_REPORT.md`](PRODUCTION_REPORT.md).

## Installation

1. Copier `app/Modules/NotificationCenter/` dans le projet cible
2. Enregistrer le provider dans `bootstrap/providers.php` :

```php
App\Modules\NotificationCenter\NotificationCenterServiceProvider::class,
```

3. Publier / copier la config :

```bash
php artisan vendor:publish --tag=notification-center
# ou copier config/notifications.php → config/notification-center.php
```

4. Lancer les migrations (`Database/Migrations/` ou celles du projet hôte)
5. Implémenter `GroupedEntityProviderInterface` pour votre métier
6. Monter `<NotificationCenter />` dans le layout Vue

## Architecture

```
app/Modules/NotificationCenter/
├── Contracts/          # Interfaces (Repository, Realtime, Audience, GroupedEntity)
├── DTO/                # CreateNotificationData
├── Enums/              # Priority, Status, Audience
├── Events/             # NotificationSent (broadcast)
├── Http/               # API REST + routes legacy
├── Models/             # Notification (table notification_reads)
├── Repositories/       # NotificationRepository
├── Services/           # NotificationService, NotificationSettingsService
│   ├── Channels/       # NotificationChannelInterface + stubs Email/SMS…
│   └── Realtime/       # PusherRealtimeProvider
├── Support/            # NotificationTypeConfig
├── Resources/js/       # Frontend Vue (Pinia, composants)
└── Tests/
```

### Couche application (ex. MKD-Pro Gestion)

```
app/Integrations/NotificationCenter/
├── GestionGroupedEntityProvider.php   # Produits, ventes…
app/Services/
├── NotificationService.php              # Façade métier + délégation module
```

## Utilisation backend

**Seul point d'entrée recommandé pour le métier :**

```php
use App\Modules\NotificationCenter\DTO\CreateNotificationData;
use App\Modules\NotificationCenter\Enums\NotificationPriority;
use App\Services\NotificationService;

app(NotificationService::class)->create(new CreateNotificationData(
    userId: $user->id,
    title: 'Titre',
    description: 'Description',
    type: 'system_info',
    priority: NotificationPriority::Info,
    url: '/dashboard',
    metadata: ['custom' => 'value'],
));
```

Les controllers métier **ne créent jamais** de notifications directement en base.

## API REST

| Méthode | Route | Action |
|---------|-------|--------|
| GET | `/api/notifications` | Liste |
| GET | `/api/notifications/search?q=` | Recherche |
| POST | `/api/notifications/read` | Marquer lue (legacy type+id) |
| POST | `/api/notifications/read/{id}` | Marquer lue par ID |
| POST | `/api/notifications/read-all` | Tout marquer lu |
| POST | `/api/notifications/archive/{id}` | Archiver |
| DELETE | `/api/notifications/{id}` | Supprimer |
| DELETE | `/api/notifications/read` | Supprimer les lues |

Routes legacy conservées : `/notifications/mark-as-read`, etc.

## Temps réel

Le module ne connaît pas Pusher directement.

```php
interface RealtimeProviderInterface {
    public function broadcast(array $payload, int $userId): void;
}
```

Implémentation par défaut : `PusherRealtimeProvider` → event `NotificationSent`.

Pour Reverb / Ably / Socket.io : créer une nouvelle implémentation et rebinder dans le provider :

```php
$this->app->singleton(RealtimeProviderInterface::class, ReverbRealtimeProvider::class);
```

## Frontend

```vue
<script setup>
import { NotificationCenterComponent as NotificationCenter } from '@notification-center'
import { useGestionNotificationCenter } from '@/integrations/notifications/useGestionNotificationCenter'

useGestionNotificationCenter()
</script>

<template>
  <NotificationCenter />
</template>
```

Alias Vite : `@notification-center` → `app/Modules/NotificationCenter/Resources/js`

Personnalisation : `notification.config.ts`, `NotificationApi`, `useNotificationRealtime()`.

## Configuration

Fichier `config/notification-center.php` (fusionné depuis `config/notifications.php`) :

- `types` — types, priorités par défaut, entity_type, realtime
- `audience_rules` — valeurs initiales (écrasées par la base une fois configuré)
- `groupable_types` — alertes groupées
- `priorities`, `statuses`, `realtime`, `user_model`

### Interface administrateur

Menu **Paramètres → Notifications** (`/admin/settings/notifications`) — réservé aux administrateurs.

Onglets disponibles :

| Onglet | Contenu |
|--------|---------|
| Général | Activation globale, temps réel, toasts, badge, regroupement… |
| Canaux | Application, Email, SMS, WhatsApp, Push (stubs prêts) |
| Destinataires | Rôles par type de notification |
| Priorités | Critique / Avertissement / Info par type |
| Sons | Profils : silent, discrete, classic, critical |
| Temps réel | État Pusher, test de broadcast |
| Maintenance | Statistiques, archivage, nettoyage planifié |

### API paramètres

| Méthode | Route | Accès |
|---------|-------|-------|
| GET | `/api/notifications/settings` | Admin |
| PUT | `/api/notifications/settings` | Admin |
| GET | `/api/user/notification-preferences` | Utilisateur connecté |
| PUT | `/api/user/notification-preferences` | Utilisateur connecté |

### Préférences utilisateur

Page **Paramètres → Notifications** (`/settings/notifications`).

Le store Pinia charge `GET /api/user/notification-preferences` au démarrage et applique `effective` (fusion admin + utilisateur). Aucune valeur comportementale n’est codée en dur dans le frontend module :

- Toasts, sons, badge, notifications navigateur
- Filtres `critical_only` / `hide_resolved`
- Marquage auto à l’ouverture, regroupement
- Position / durée des toasts, volume et profils sonores par priorité

Les métadonnées (positions, profils, durées par défaut) proviennent de `config/notification-center.php` → `user_preferences`.

```typescript
const store = useNotificationStore()
await store.updatePreferences({ critical_only: true }) // sans rechargement
```

### Maintenance planifiée

```bash
php artisan notifications:cleanup
```

Planifiée quotidiennement à 05:00 via `routes/console.php`.

## Ajouter un type

1. Ajouter dans `config/notification-center.php` → `types.mon_type` (label, priority, entity_type, realtime)
2. Optionnel : `audience_rules.mon_type` pour les valeurs par défaut au seed
3. Exécuter `php artisan migrate` si besoin — `ensureDefaults()` crée la ligne en base
4. Configurer destinataires / priorités / canaux depuis l’interface admin (aucun code requis ensuite)
5. Ajouter icône dans `notification.config.ts` (frontend)
6. Appeler `NotificationService::create()` ou `distribute()` depuis votre code métier

## Ajouter un canal

1. Créer une classe implémentant `NotificationChannelInterface` dans `Services/Channels/`
2. Enregistrer le canal dans le conteneur si nécessaire
3. Activer le canal pour un type via l’onglet **Canaux** de l’admin
4. Implémenter `send()` — la persistance app reste gérée par `NotificationService`

Canaux stubs existants : `EmailNotificationChannel`, `SmsNotificationChannel`, `WhatsAppNotificationChannel`, `PushNotificationChannel`.

## Modifier les destinataires

1. Interface admin → onglet **Destinataires**
2. Cocher Administrateur / Gestionnaire / Vendeur par type
3. `NotificationAudienceResolver` lit `notification_type_settings.recipients` — plus de rôles codés en dur

## Modifier les priorités

1. Interface admin → onglet **Priorités**
2. `NotificationSettingsService::getPriorityForType()` est utilisé par `NotificationService::create()`

## Ajouter un son

1. Ajouter la clé dans `NotificationSettingsService::SOUND_PROFILES`
2. Fournir le fichier audio côté frontend (`notification.config.ts` ou assets)
3. Sélectionner le profil par défaut (admin) ou par utilisateur (préférences)

## Tests

```bash
php vendor/bin/phpunit --filter=Notification
```

## Licence

Module interne MKD-Pro — réutilisation libre dans les projets de la suite.
