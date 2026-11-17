<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Modules\Location\Services\LocationFollowUps;
use Illuminate\Console\Command;

/**
 * Relances du loueur (module « location ») : devis sans réponse à 24 h et
 * 72 h, rappel la veille du départ, remerciement et demande d'avis. Envoyées
 * par l'API WhatsApp sur les offres payantes quand la fenêtre de 24 h du
 * client est ouverte ; sinon elles restent dans Location › Tableau de bord
 * avec leur lien wa.me.
 */
class SendLocationFollowUps extends Command
{
    protected $signature = 'location:relances {--organization= : ID d\'une seule organisation}';

    protected $description = 'Envoie les relances WhatsApp du loueur (devis 24 h / 72 h, rappel la veille, remerciement)';

    public function handle(): int
    {
        $sent = 0;

        Organization::query()
            ->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))
            ->get()
            ->filter(fn (Organization $o) => $o->hasModule('location'))
            ->each(function (Organization $organization) use (&$sent) {
                $sent += LocationFollowUps::sendAutomatic($organization);
            });

        $this->info("{$sent} relance(s) envoyée(s).");

        return self::SUCCESS;
    }
}
