<?php

namespace App\Console\Commands;

use App\Models\BookableResource;
use App\Models\Organization;
use App\Models\Rental;
use App\Services\Fleet;
use App\Services\Occupation;
use Database\Seeders\VehiculesDemoSeeder;
use Illuminate\Console\Command;

/**
 * Charge (ou recharge) le loueur fictif « Ogooué Auto », cas pilote du
 * secteur Vente et location de véhicules. Rejouable : seule cette entreprise
 * de démonstration est supprimée puis recréée.
 */
class SeedVehiculesDemo extends Command
{
    protected $signature = 'demo:vehicules {--force : Autoriser l\'exécution en production}';

    protected $description = 'Charge le loueur fictif « Ogooué Auto » (vente et location de véhicules, données de test)';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Données fictives : refusé en production sans --force.');

            return self::FAILURE;
        }

        $this->info('Chargement du loueur fictif « Ogooué Auto »…');
        $this->callSilent('db:seed', ['--class' => VehiculesDemoSeeder::class, '--force' => true]);

        $organization = Organization::where('slug', VehiculesDemoSeeder::SLUG)->firstOrFail();
        $signals = collect((new Occupation($organization))->signals());

        $this->table(['Élément', 'Nombre'], [
            ['Véhicules en location', BookableResource::fleet($organization->id)->where('status', 'available')->count()],
            ['Véhicules à vendre', BookableResource::where('organization_id', $organization->id)->where('status', 'for_sale')->count()],
            ['Locations rendues (6 mois)', Rental::where('organization_id', $organization->id)->where('status', 'returned')->count()],
            ['Sortis en ce moment', Rental::where('organization_id', $organization->id)->where('status', 'ongoing')->count()],
            ['Libres demain', Fleet::availableOn($organization->id, today()->addDay())->count()],
            ['Signaux « À optimiser »', $signals->count()],
        ]);
        $this->line('Connexion : gerant@demo-ogooue-auto.test');
        $this->line('Mot de passe : celui de la variable DEMO_PASSWORD, sinon '.VehiculesDemoSeeder::DEFAULT_PASSWORD.'.');

        return self::SUCCESS;
    }
}
