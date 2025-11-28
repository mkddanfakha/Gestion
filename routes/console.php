<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sauvegardes automatiques quotidiennes à 2h du matin
Schedule::command('backup:run')->daily()->at('02:00');

// Nettoyage automatique des anciennes sauvegardes quotidiennement à 3h du matin
Schedule::command('backup:clean')->daily()->at('03:00');

// Vérification de la santé des sauvegardes quotidiennement à 4h du matin
Schedule::command('backup:monitor')->daily()->at('04:00');
