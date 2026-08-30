<?php

namespace App\Console\Commands;

use App\Database\DatabaseSafetyGuard;
use Database\Seeders\DemoLocalSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;

/**
 * Charge le jeu de données de démonstration LOCAL uniquement.
 *
 * Ne passe pas par `db:seed` (bloqué sur les bases protégées).
 * Sur `gestion`, exige la phrase d'approbation explicite.
 */
class SeedDemoLocalCommand extends Command
{
    public const CONFIRMATION_GESTION = 'OUI — SEED TEST DATA ON gestion';

    protected $signature = 'demo:seed-local
                            {--confirmation= : Phrase d\'approbation si la base cible est protégée (gestion)}
                            {--images-only : Attacher uniquement les images aux produits de démonstration existants}
                            {--force-images : Régénérer les images de démonstration déjà présentes}
                            {--force : Autoriser l\'exécution hors local/testing (jamais en production)}';

    protected $description = 'Seed le jeu de données de démonstration NIANE (local uniquement)';

    public function handle(): int
    {
        if (App::environment('production')) {
            $this->error('REFUSÉ : demo:seed-local est interdit en production (APP_ENV=production).');

            return self::FAILURE;
        }

        if (! App::environment(['local', 'testing', 'development']) && ! $this->option('force')) {
            $this->error('REFUSÉ : environnement non autorisé ('.App::environment().'). Utilisez --force uniquement hors production.');

            return self::FAILURE;
        }

        $database = DatabaseSafetyGuard::resolveDatabaseName();
        $this->line('BASE CIBLE: '.$database);
        $this->line('UTILISATEUR: '.(string) config('database.connections.'.config('database.default').'.username'));
        $this->line('COMMANDE: demo:seed-local');
        $this->line('IMPACT: insertion/mise à jour de données de démonstration (users, catalogue, ventes, etc.)');
        $this->line('ENV: '.App::environment());

        if (DatabaseSafetyGuard::isProtectedDatabase($database)) {
            $confirmation = (string) $this->option('confirmation');

            if ($confirmation !== self::CONFIRMATION_GESTION) {
                $this->error('BASE PROTÉGÉE ('.$database.'). Approbation humaine requise.');
                $this->line('APPROBATION REQUISE: '.self::CONFIRMATION_GESTION);
                $this->line('Exemple: php artisan demo:seed-local --confirmation="'.self::CONFIRMATION_GESTION.'"');

                return self::FAILURE;
            }
        }

        $this->info('Démarrage du seeding démo local…');

        $seeder = new DemoLocalSeeder;
        $seeder->setCommand($this);
        $seeder->run(
            imagesOnly: (bool) $this->option('images-only'),
            forceImages: (bool) $this->option('force-images'),
        );

        $this->info('Seeding démo local terminé.');

        return self::SUCCESS;
    }
}
