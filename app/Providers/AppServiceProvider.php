<?php

namespace App\Providers;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Observers\ProductObserver;
use App\Observers\SaleObserver;
use App\Policies\ActivityLogPolicy;
use App\Repositories\NotificationRepository;
use App\Services\ActivityLogger;
use App\Services\Audit\ChangeDetector;
use App\Services\NotificationService;
use App\Services\StockService;
use App\Services\Notifications\NotificationAudienceResolver;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\CleanupWasSuccessful;
use Spatie\Backup\Events\HealthyBackupWasFound;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActivityLogger::class);
        $this->app->singleton(ChangeDetector::class);
        $this->app->singleton(StockService::class);
    }

    public function boot(): void
    {
        Gate::policy(ActivityLog::class, ActivityLogPolicy::class);

        $this->registerAuditListeners();

        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user) {
                ActivityLogger::logLogout($event->user, request());
            }
        });

        Schema::defaultStringLength(191);

        Product::observe(ProductObserver::class);
        Sale::observe(SaleObserver::class);

        @date_default_timezone_set('Africa/Dakar');

        if ($this->app->environment('local') && ! $this->app->runningInConsole()) {
            $rootUrl = request()->getSchemeAndHttpHost();
            if ($rootUrl) {
                URL::forceRootUrl($rootUrl);
            }
        }

        Event::listen([
            CleanupHasFailed::class,
            CleanupWasSuccessful::class,
            HealthyBackupWasFound::class,
            UnhealthyBackupWasFound::class,
        ], function ($event) {
            return true;
        });
    }

    private function registerAuditListeners(): void
    {
        foreach (glob(app_path('Models/*.php')) as $modelFile) {
            $modelClass = 'App\\Models\\' . basename($modelFile, '.php');

            if (!class_exists($modelClass) || !is_subclass_of($modelClass, Model::class)) {
                continue;
            }

            if ($modelClass === ActivityLog::class) {
                continue;
            }

            $modelClass::updating(function (Model $model) {
                ChangeDetector::rememberOriginal($model);
            });
        }
    }
}
