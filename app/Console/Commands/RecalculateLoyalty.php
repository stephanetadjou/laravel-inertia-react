<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\LoyaltyEngine;
use Illuminate\Console\Command;

/**
 * Recalcule la fidélité par enseigne. Planifiée chaque nuit : les
 * expirations arrivent avec le temps, sans aucun ticket.
 */
class RecalculateLoyalty extends Command
{
    protected $signature = 'fidelite:recalculer {--organization= : Identifiant d’une seule organisation}';

    protected $description = 'Recalcule les cartes de fidélité par enseigne à partir des tickets';

    public function handle(LoyaltyEngine $engine): int
    {
        Organization::query()
            ->whereHas('loyaltyPrograms')
            ->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))
            ->each(function (Organization $organization) use ($engine) {
                $this->info("{$organization->name} : {$engine->recalculate($organization)} carte(s) recalculée(s).");
            });

        return self::SUCCESS;
    }
}
