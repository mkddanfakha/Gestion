<?php

namespace App\Providers;

use App\Models\ActivityLog;
use App\Policies\ActivityLogPolicy;
use App\Services\ActivityLogger;
use App\Services\Audit\ChangeDetector;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\CleanupWasSuccessful;
use Spatie\Backup\Events\HealthyBackupWasFound;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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

        @date_default_timezone_set('Africa/Dakar');

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
