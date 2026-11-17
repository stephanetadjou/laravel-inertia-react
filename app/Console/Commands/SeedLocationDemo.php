<?php

namespace App\Console\Commands;

use Database\Seeders\LocationDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Charge (ou recharge) le loueur fictif « Libreville Location Auto » : véhicules, demandes, réservations, une vente. */
class SeedLocationDemo extends Command
{
    protected $signature = 'demo:location-vehicules {--force : Autoriser l\'exécution en production}';

    protected $description = 'Charge le loueur fictif « Libreville Location Auto » (location et vente de véhicules ; données de test)';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Données fictives : refusé en production sans --force.');

            return self::FAILURE;
        }

        try {
            $this->callSilent('db:seed', ['--class' => LocationDemoSeeder::class, '--force' => true]);
        } finally {
            Carbon::setTestNow();
        }

        $this->info('Loueur fictif « Libreville Location Auto » chargé.');
        $this->line('Connexion : directeur@demo-location.test');
        $this->line('Mot de passe : celui de la variable DEMO_PASSWORD, sinon '.LocationDemoSeeder::DEFAULT_PASSWORD.'.');

        return self::SUCCESS;
    }
}
