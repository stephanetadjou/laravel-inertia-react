<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Modules\Tunnels\Services\Funnels;
use Illuminate\Console\Command;

/**
 * Séquence des tunnels de vente : J0 récapitulatif, J+1 rappel avec un avis,
 * J+3 dernière chance ; arrêt dès l'achat ou au STOP. Envoi par l'API sur les
 * offres payantes (fenêtre de 24 h ouverte), sinon à faire depuis Tunnels.
 */
class SendFunnelFollowUps extends Command
{
    protected $signature = 'tunnels:relances {--organization= : ID d\'une seule organisation}';

    protected $description = 'Envoie les relances WhatsApp des tunnels de vente (J0, J+1, J+3)';

    public function handle(): int
    {
        $sent = 0;
        Organization::query()->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))->get()
            ->filter(fn (Organization $o) => $o->hasModule('tunnels'))
            ->each(function (Organization $organization) use (&$sent) {
                $sent += Funnels::sendAutomatic($organization);
            });
        $this->info("{$sent} relance(s) envoyée(s).");

        return self::SUCCESS;
    }
}
