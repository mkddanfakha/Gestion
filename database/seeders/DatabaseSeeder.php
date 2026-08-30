<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Le jeu de démonstration complet NIANE est chargé via :
     *   php artisan demo:seed-local
     *
     * `db:seed` reste bloqué sur les bases protégées (ex. gestion).
     */
    public function run(): void
    {
        if (App::environment('production')) {
            throw new RuntimeException('DatabaseSeeder refusé en production.');
        }

        // Catalogue de permissions uniquement — données métier via demo:seed-local.
        $this->call(PermissionSeeder::class);

        $this->command?->warn(
            'Données métier de démonstration : exécutez `php artisan demo:seed-local` (local uniquement).',
        );
    }
}
