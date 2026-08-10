<?php

namespace App\Modules\NotificationCenter;

use App\Integrations\NotificationCenter\GestionGroupedEntityProvider;
use App\Integrations\NotificationCenter\GestionGroupedPreviewProvider;
use App\Integrations\NotificationCenter\NotificationAlertItemService;
use App\Modules\NotificationCenter\Contracts\AudienceResolverInterface;
use App\Modules\NotificationCenter\Contracts\GroupedEntityProviderInterface;
use App\Modules\NotificationCenter\Contracts\GroupedPreviewProviderInterface;
use App\Modules\NotificationCenter\Contracts\NotificationRepositoryInterface;
use App\Modules\NotificationCenter\Contracts\RealtimeProviderInterface;
use App\Modules\NotificationCenter\Models\Notification;
use App\Modules\NotificationCenter\Policies\NotificationPolicy;
use App\Modules\NotificationCenter\Repositories\NotificationRepository;
use App\Modules\NotificationCenter\Services\NotificationAudienceResolver;
use App\Modules\NotificationCenter\Console\ArchiveResolvedNotificationsCommand;
use App\Modules\NotificationCenter\Console\CleanupOldNotificationsCommand;
use App\Modules\NotificationCenter\Console\CleanupOrphanNotificationsCommand;
use App\Modules\NotificationCenter\Console\DeleteArchivedNotificationsCommand;
use App\Modules\NotificationCenter\Console\OptimizeNotificationTablesCommand;
use App\Modules\NotificationCenter\Services\NotificationCacheService;
use App\Modules\NotificationCenter\Services\NotificationHealthService;
use App\Modules\NotificationCenter\Services\NotificationMonitoringService;
use App\Modules\NotificationCenter\Services\NotificationSettingsService;
use App\Modules\NotificationCenter\Services\Realtime\PusherRealtimeProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use App\Modules\NotificationCenter\Services\NotificationService as ModuleNotificationService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class NotificationCenterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/notifications.php'), 'notification-center');

        $this->app->singleton(GroupedEntityProviderInterface::class, GestionGroupedEntityProvider::class);
        $this->app->singleton(GroupedPreviewProviderInterface::class, GestionGroupedPreviewProvider::class);
        $this->app->singleton(NotificationAlertItemService::class);
        $this->app->singleton(NotificationRepositoryInterface::class, NotificationRepository::class);
        $this->app->singleton(AudienceResolverInterface::class, \App\Services\Notifications\NotificationAudienceResolver::class);
        $this->app->singleton(RealtimeProviderInterface::class, PusherRealtimeProvider::class);
        $this->app->singleton(NotificationCacheService::class);
        $this->app->singleton(NotificationHealthService::class);
        $this->app->singleton(NotificationMonitoringService::class);
        $this->app->singleton(NotificationSettingsService::class);
        $this->app->singleton(ModuleNotificationService::class);
        $this->app->singleton(\App\Services\NotificationService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/Database/Migrations');

        Gate::policy(Notification::class, NotificationPolicy::class);

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ArchiveResolvedNotificationsCommand::class,
                CleanupOldNotificationsCommand::class,
                CleanupOrphanNotificationsCommand::class,
                DeleteArchivedNotificationsCommand::class,
                OptimizeNotificationTablesCommand::class,
            ]);
        }

        if (Schema::hasTable('notification_global_settings')) {
            $this->app->make(NotificationSettingsService::class)->ensureDefaults();
        }

        $this->publishes([
            __DIR__ . '/Config/notifications.php' => config_path('notification-center.php'),
            __DIR__ . '/Resources/js' => resource_path('js/vendor/notification-center'),
        ], 'notification-center');
    }

    protected function registerRoutes(): void
    {
        Route::middleware('web')
            ->group(__DIR__ . '/Http/Routes/notifications.php');
    }
}
