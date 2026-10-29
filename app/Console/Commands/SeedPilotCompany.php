<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Purchase;
use Database\Seeders\PilotCompanySeeder;
use Illuminate\Console\Command;

/**
 * Charge (ou recharge) l'entreprise pilote fictive « Institut Élégance »
 * pour les tests et les démonstrations. Rejouable : l'entreprise est
 * supprimée puis recréée, les autres entreprises ne sont pas touchées.
 */
class SeedPilotCompany extends Command
{
    protected $signature = 'demo:pilot {--force : Autoriser l\'exécution en production}';

    protected $description = 'Charge l’entreprise pilote fictive « Institut Élégance » (données de test)';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Données fictives : refusé en production sans --force.');

            return self::FAILURE;
        }

        $this->info('Chargement de l’entreprise pilote fictive « Institut Élégance »…');
        $this->callSilent('db:seed', ['--class' => PilotCompanySeeder::class, '--force' => true]);

        $organization = Organization::where('slug', PilotCompanySeeder::SLUG)->firstOrFail();
        $storeIds = $organization->stores()->pluck('id');

        $this->table(['Élément', 'Nombre'], [
            ['Fiches clients', Customer::where('organization_id', $organization->id)->count()],
            ['Ventes', Purchase::whereIn('store_id', $storeIds)->count()],
            ['Rendez-vous', Appointment::whereIn('store_id', $storeIds)->count()],
        ]);
        $this->line('Connexion : dg@institut-elegance.test (direction) ou owendo@institut-elegance.test (Owendo).');
        $this->line('Mot de passe : celui de la variable DEMO_PASSWORD, sinon celui indiqué dans database/seeders/PilotCompanySeeder.php.');
        $this->line('Réservation en ligne : '.route('booking.show', PilotCompanySeeder::SLUG));

        return self::SUCCESS;
    }
}
