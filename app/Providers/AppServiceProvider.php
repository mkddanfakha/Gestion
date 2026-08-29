<?php

namespace App\Providers;

use App\Database\DatabaseAccountGuard;
use App\Database\DestructiveCommandGuard;
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
use Illuminate\Console\Events\CommandStarting;
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
        $this->app->bind(
            \App\Services\Restore\SqlDumpImporter::class,
            \App\Services\Restore\MysqlPdoDumpImporter::class,
        );

        // Wrap framework singletons (MigrationServiceProvider registers AFTER app providers
        // when deferred). extend() applies on every resolve.
        $this->app->extend(
            \Illuminate\Database\Console\Migrations\FreshCommand::class,
            function ($command, $app) {
                return $command instanceof \App\Database\Console\ProtectedMigrateFreshCommand
                    ? $command
                    : new \App\Database\Console\ProtectedMigrateFreshCommand($app['migrator']);
            },
        );

        $this->app->extend(
            \Illuminate\Database\Console\Migrations\RefreshCommand::class,
            function ($command, $app) {
                return $command instanceof \App\Database\Console\ProtectedMigrateRefreshCommand
                    ? $command
                    : $app->make(\App\Database\Console\ProtectedMigrateRefreshCommand::class);
            },
        );

        $this->app->extend(
            \Illuminate\Database\Console\Migrations\ResetCommand::class,
            function ($command, $app) {
                return $command instanceof \App\Database\Console\ProtectedMigrateResetCommand
                    ? $command
                    : new \App\Database\Console\ProtectedMigrateResetCommand($app['migrator']);
            },
        );

        $this->app->extend(
            \Illuminate\Database\Console\WipeCommand::class,
            function ($command, $app) {
                return $command instanceof \App\Database\Console\ProtectedDbWipeCommand
                    ? $command
                    : $app->make(\App\Database\Console\ProtectedDbWipeCommand::class);
            },
        );

        $this->app->extend(
            \Illuminate\Database\Console\Seeds\SeedCommand::class,
            function ($command, $app) {
                return $command instanceof \App\Database\Console\ProtectedSeedCommand
                    ? $command
                    : $app->make(\App\Database\Console\ProtectedSeedCommand::class);
            },
        );
    }

    public function boot(): void
    {
        Gate::policy(ActivityLog::class, ActivityLogPolicy::class);

        $this->registerAuditListeners();
        $this->registerDatabaseSafetyGuard();
        $this->registerRuntimeAccountGuard();

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

    /**
     * Block migrate:fresh / refresh / reset / db:wipe on protected databases
     * for both CLI and programmatic Artisan::call (including --force).
     */
    /**
     * Fail-closed: privileged MySQL accounts must never serve as Laravel runtime (.env).
     * Skipped inside MKDPRO_PRIVILEGED_SUBPROCESS (isolated backup/restore/migrate child).
     */
    private function registerRuntimeAccountGuard(): void
    {
        if (DatabaseAccountGuard::isPrivilegedSubprocess()) {
            return;
        }

        if (config('database.default') !== 'mysql') {
            return;
        }

        DatabaseAccountGuard::assertRuntimeUsernameAllowed();
    }

    private function registerDatabaseSafetyGuard(): void
    {
        Event::listen(CommandStarting::class, DestructiveCommandGuard::class);
        Event::listen(CommandStarting::class, \App\Database\PrivilegedCommandGuard::class);

        // PHPUnit skips Kernel::rerouteSymfonyCommandEvents(); re-enable so CLI-path
        // CommandStarting also fires under tests (defense in depth with command binds).
        $this->app->booted(function () {
            $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);

            if (method_exists($kernel, 'rerouteSymfonyCommandEvents')) {
                $kernel->rerouteSymfonyCommandEvents();
            }
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
