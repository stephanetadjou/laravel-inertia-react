<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Organization;
use App\Services\CustomerLifecycle;
use App\Services\CustomerScoring;
use Illuminate\Console\Command;

/**
 * Recalcule les compteurs et l'étape du parcours de toutes les fiches
 * clients. Planifiée chaque nuit (routes/console.php) : les passages
 * « À risque » et « Inactif » arrivent avec le temps, sans aucun événement.
 * À lancer aussi une fois après la migration qui crée les fiches clients.
 */
class RefreshCustomerLifecycle extends Command
{
    protected $signature = 'crm:refresh-lifecycle {--organization= : ID d\'une seule organisation}';

    protected $description = "Recalcule les fiches clients (compteurs d'achats, étape du parcours, RFM, score et risque de churn)";

    public function handle(CustomerLifecycle $lifecycle, CustomerScoring $scoring): int
    {
        // Filet de sécurité : tout contact sans fiche (créé hors modèle) est rattaché.
        Contact::whereNull('customer_id')->each(function (Contact $contact) use ($lifecycle) {
            $lifecycle->linkContact($contact);
            $contact->saveQuietly();
        });

        $organizations = Organization::query()
            ->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($organizations as $organization) {
            $changes = $lifecycle->refreshOrganization($organization);
            // Après l'étape : le risque de churn en dépend.
            $scoring->refreshOrganization($organization);
            $this->info("{$organization->name} : {$organization->customers()->count()} fiche(s), {$changes} changement(s) d'étape.");
        }

        return self::SUCCESS;
    }
}
