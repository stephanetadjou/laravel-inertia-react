<?php

namespace App\Console\Commands;

use App\Models\ClassSession;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Subscription;
use Database\Seeders\SportDemoSeeder;
use Illuminate\Console\Command;

/**
 * Charge (ou recharge) le club sportif fictif « Club Étoile Libreville »
 * (modules cours, abonnements, échéances, personnes suivies). Rejouable :
 * seule cette entreprise de démonstration est supprimée puis recréée.
 */
class SeedSportDemo extends Command
{
    protected $signature = 'demo:sport {--force : Autoriser l\'exécution en production}';

    protected $description = 'Charge le club sportif fictif « Club Étoile Libreville » (données de test)';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Données fictives : refusé en production sans --force.');

            return self::FAILURE;
        }

        $this->info('Chargement du club sportif fictif « Club Étoile Libreville »…');
        $this->callSilent('db:seed', ['--class' => SportDemoSeeder::class, '--force' => true]);

        $organization = Organization::where('slug', SportDemoSeeder::SLUG)->firstOrFail();
        $storeIds = $organization->stores()->pluck('id');
        $overdue = Installment::overdue()->whereHas('schedule', fn ($q) => $q->where('organization_id', $organization->id));

        $this->table(['Élément', 'Nombre'], [
            ['Parents (responsables)', Customer::where('organization_id', $organization->id)->whereNull('responsible_id')->count()],
            ['Enfants (personnes suivies)', Customer::where('organization_id', $organization->id)->whereNotNull('responsible_id')->count()],
            ['Abonnements', Subscription::where('organization_id', $organization->id)->count()],
            ['Paiements encaissés (ventes)', Purchase::whereIn('store_id', $storeIds)->count()],
            ['Tranches en retard', (clone $overdue)->count().' ('.number_format((int) (clone $overdue)->sum('amount'), 0, ',', ' ').' FCFA)'],
            ['Séances annulées (pluie)', ClassSession::where('organization_id', $organization->id)->where('status', 'cancelled')->count()],
        ]);
        $this->line('Connexion : directeur@demo-sport.test (direction) ; coachs : coach-judo@, coach-football@, coach-danse@, coach-natation@demo-sport.test.');
        $this->line('Mot de passe : celui de la variable DEMO_PASSWORD, sinon '.SportDemoSeeder::DEFAULT_PASSWORD.'.');

        return self::SUCCESS;
    }
}
