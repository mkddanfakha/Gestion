# NotificationCenter — Guide production

Documentation technique pour l'exploitation du module en environnement production (MKD-Pro).

## Architecture production

```
NotificationService (création / broadcast)
    ├── NotificationRepository (persistance)
    ├── NotificationSettingsService (settings + cache + monitoring meta)
    ├── NotificationCacheService (prefs user, compteur unread)
    ├── SendNotificationJob (broadcast non-critique via queue)
    └── PusherRealtimeProvider → NotificationSent (ShouldBroadcastNow)

Scheduler (routes/console.php)
    ├── notifications:optimize-tables (quotidien)
    ├── notifications:archive-resolved (quotidien)
    ├── notifications:delete-archived (mensuel)
    ├── notifications:cleanup-orphans (quotidien)
    └── notifications:cleanup (quotidien)

Monitoring / Health
    ├── NotificationMonitoringService → GET /api/notifications/settings/monitoring
    └── NotificationHealthService → GET /api/notifications/settings/health
```

## Cache

| Clé | TTL | Invalidation |
|-----|-----|--------------|
| `notification-center.settings.global` | 10 min (configurable) | `updateGlobal()`, `clearCache()` |
| `notification-center.settings.types` | 10 min | `updateTypeSettings()`, `clearCache()` |
| `notification-center.user_prefs.{id}` | 10 min | `updateUserPreferences()` |
| `notification-center.unread_count.{id}` | 60 s | création notification, mark as read |

Variables d'environnement :

```env
NOTIFICATION_CACHE_SETTINGS_TTL=10
NOTIFICATION_CACHE_USER_PREFS_TTL=10
NOTIFICATION_CACHE_UNREAD_TTL=60
```

## Files d'attente

- **Critiques** (`priority: critical`) et **tests** (`type: test`) : broadcast Pusher immédiat
- **Autres** : `SendNotificationJob` sur la queue `notifications`

```env
NOTIFICATION_QUEUE_ENABLED=true
NOTIFICATION_QUEUE_CONNECTION=redis
NOTIFICATION_QUEUE_NAME=notifications
```

Jobs disponibles :

- `SendNotificationJob` — envoi temps réel différé
- `ArchiveNotificationJob` — archivage batch
- `CleanupNotificationJob` — suppression / orphelins

## Scheduler

Configurable via `config/notifications.php` → `scheduler` :

| Tâche | Fréquence | Commande |
|-------|-----------|----------|
| Optimisation tables | Quotidien 03:00 | `notifications:optimize-tables` |
| Archivage résolues | Quotidien 03:30 | `notifications:archive-resolved` |
| Suppression archivées | Mensuel jour 1 | `notifications:delete-archived` |
| Orphelins | Quotidien 04:30 | `notifications:cleanup-orphans` |
| Expiration | Quotidien 05:00 | `notifications:cleanup` |

```env
NOTIFICATION_SCHEDULER_ENABLED=true
NOTIFICATION_ARCHIVE_RESOLVED_DAYS=30
NOTIFICATION_DELETE_ARCHIVED_DAYS=90
NOTIFICATION_SCHEDULER_USE_QUEUE=true
```

Exécution manuelle synchrone : `--sync`

## Logging

Canal dédié : `storage/logs/notification.log`

```php
NotificationLogger::pusherError('...');
NotificationLogger::queueError('...');
NotificationLogger::apiError('...');
```

Erreurs client (son, navigateur) : `POST /api/notifications/client-log`

## Monitoring admin

Interface : **Paramètres → Notifications → Monitoring / Health Check**

API (admin uniquement) :

- `GET /api/notifications/settings/monitoring`
- `GET /api/notifications/settings/performance`
- `GET /api/notifications/settings/health`

## Health Check

Vérifie : base de données, paramètres, cache, queue, scheduler, Pusher.

Statuts : `ok`, `warn`, `fail`.

## Sécurité

- `NotificationPolicy` : un utilisateur ne voit/modifie que ses notifications
- Endpoints admin protégés par `EnsureUserIsAdmin`
- Validation sur tous les endpoints API

## Tests

```bash
php artisan test app/Modules/NotificationCenter/Tests
```

Couverture production : `NotificationProductionTest.php`

## Installation production

1. `php artisan migrate`
2. Configurer Pusher (inchangé)
3. Configurer queue worker : `php artisan queue:work --queue=notifications`
4. Configurer cron : `* * * * * php artisan schedule:run`
5. Vérifier health check admin

## Extension multi-projets

Le module est autonome sous `app/Modules/NotificationCenter/`. Pour réutiliser :

1. Copier le module + `config/notifications.php`
2. Enregistrer `NotificationCenterServiceProvider`
3. Publier le frontend via alias `@notification-center`
4. Implémenter `GroupedEntityProviderInterface` pour le métier hôte

## Maintenance

- Consulter `notification.log` en cas d'incident Pusher/queue
- Monitoring admin pour métriques temps réel
- Archivage automatique évite la croissance illimitée de `notification_reads`
