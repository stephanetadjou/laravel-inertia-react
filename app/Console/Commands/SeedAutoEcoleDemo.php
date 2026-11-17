<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Installment;
use App\Models\LearnerExam;
use App\Models\LearnerTraining;
use App\Models\Organization;
use App\Services\Learners;
use Database\Seeders\AutoEcoleDemoSeeder;
use Illuminate\Console\Command;

/**
 * Charge (ou recharge) l'auto-école fictive « Auto-école Horizon », cas
 * pilote du secteur Centre de formation. Rejouable : seule cette entreprise
 * de démonstration est supprimée puis recréée.
 */
class SeedAutoEcoleDemo extends Command
{
    protected $signature = 'demo:auto-ecole {--force : Autoriser l\'exécution en production}';

    protected $description = 'Charge l’auto-école fictive « Auto-école Horizon » (centre de formation, données de test)';

    public function handle(Learners $learners): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Données fictives : refusé en production sans --force.');

            return self::FAILURE;
        }

        $this->info('Chargement de l’auto-école fictive « Auto-école Horizon »…');
        $this->callSilent('db:seed', ['--class' => AutoEcoleDemoSeeder::class, '--force' => true]);

        $organization = Organization::where('slug', AutoEcoleDemoSeeder::SLUG)->firstOrFail();
        $storeIds = $organization->stores()->pluck('id')->all();
        $overdue = Installment::overdue()->whereHas('schedule', fn ($q) => $q->where('organization_id', $organization->id));

        $this->table(['Élément', 'Nombre'], [
            ['Élèves en formation', LearnerTraining::where('organization_id', $organization->id)->active()->count()],
            ['Permis obtenus', LearnerTraining::where('organization_id', $organization->id)->where('status', 'completed')->count()],
            ['Leçons de conduite honorées', Appointment::whereIn('store_id', $storeIds)->where('status', 'done')->count()],
            ['Leçons à venir', Appointment::whereIn('store_id', $storeIds)->whereIn('status', Appointment::OPEN_STATUSES)->where('starts_at', '>=', now())->count()],
            ['Examens prévus', LearnerExam::where('organization_id', $organization->id)->where('result', 'scheduled')->count()],
            ['Élèves qui décrochent', $learners->dropouts($organization, $storeIds)->count()],
            ['Abandons à relancer', $learners->dropped($organization, $storeIds)->count()],
            ['Élèves formés (plus relancés)', Learners::trainedQuery($organization->id)->count()],
            ['Tranches en retard', (clone $overdue)->count().' ('.number_format((int) (clone $overdue)->sum('amount'), 0, ',', ' ').' FCFA)'],
        ]);
        $this->line('Connexion : directeur@demo-auto-ecole.test (direction) ; moniteurs : moniteur-jp@, moniteur-christelle@, moniteur-armand@demo-auto-ecole.test.');
        $this->line('Mot de passe : celui de la variable DEMO_PASSWORD, sinon '.AutoEcoleDemoSeeder::DEFAULT_PASSWORD.'.');

        return self::SUCCESS;
    }
}
