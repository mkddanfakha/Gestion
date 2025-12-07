<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema; // Import Schema
use Illuminate\Support\Facades\Event;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\CleanupWasSuccessful;
use Spatie\Backup\Events\HealthyBackupWasFound;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191); // Set a shorter default string length
        
        // Configurer le fuseau horaire du Sénégal globalement
        // Le fuseau horaire est déjà configuré dans config/app.php ('timezone' => 'Africa/Dakar')
        // Cette ligne est redondante mais garantit que PHP utilise aussi ce fuseau horaire
        // Utilisation de @ pour supprimer les warnings si le fuseau horaire n'est pas disponible
        @date_default_timezone_set('Africa/Dakar');
        
        // Ignorer silencieusement tous les événements de backup pour éviter les erreurs de notification
        Event::listen([
            CleanupHasFailed::class,
            CleanupWasSuccessful::class,
            HealthyBackupWasFound::class,
            UnhealthyBackupWasFound::class,
        ], function ($event) {
            // Ignorer silencieusement
            return true;
        });
    }
}
