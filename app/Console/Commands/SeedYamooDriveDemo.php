<?php

namespace App\Console\Commands;

use Database\Seeders\YamooDriveDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Charge (ou recharge) le loueur fictif « Yamoo Drive » (Douala, Yaoundé, Bafoussam), sans toucher aux autres démos. */
class SeedYamooDriveDemo extends Command
{
    protected $signature = 'demo:yamoo-drive {--force : Autoriser l\'exécution en production}';

    protected $description = 'Charge le loueur fictif « Yamoo Drive » (Cameroun, 3 agences ; données de test)';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Données fictives : refusé en production sans --force.');

            return self::FAILURE;
        }

        try {
            $this->callSilent('db:seed', ['--class' => YamooDriveDemoSeeder::class, '--force' => true]);
        } finally {
            Carbon::setTestNow();
        }

        $this->info('Loueur fictif « Yamoo Drive » chargé.');
        $this->line('Connexion : directeur@yamoo-drive.test');
        $this->line('Mot de passe : '.YamooDriveDemoSeeder::DEFAULT_PASSWORD.' (ou la variable DEMO_YAMOO_PASSWORD).');

        return self::SUCCESS;
    }
}
