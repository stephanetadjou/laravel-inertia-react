<?php

namespace App\Console\Commands;

use Database\Seeders\OptiqueDemoSeeder;
use Illuminate\Console\Command;

/** Charge (ou recharge) l'opticien fictif « Optique Lumière » : devis, factures, caisse, dépenses, stock. */
class SeedOptiqueDemo extends Command
{
    protected $signature = 'demo:optique {--force : Autoriser l\'exécution en production}';

    protected $description = 'Charge l\'opticien fictif « Optique Lumière » (factures, caisse, dépenses, stock ; données de test)';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Données fictives : refusé en production sans --force.');

            return self::FAILURE;
        }

        $this->callSilent('db:seed', ['--class' => OptiqueDemoSeeder::class, '--force' => true]);
        $this->info('Opticien fictif « Optique Lumière » chargé.');
        $this->line('Connexion : gerante@demo-optique.test');
        $this->line('Mot de passe : celui de la variable DEMO_PASSWORD, sinon '.OptiqueDemoSeeder::DEFAULT_PASSWORD.'.');

        return self::SUCCESS;
    }
}
