<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\SubscriptionPlanning;
use Illuminate\Console\Command;

/**
 * Prolonge chaque nuit les séances planifiées des abonnements en cours
 * (horizon glissant de 8 semaines dans l'agenda). Rejouable.
 */
class ExtendSubscriptionPlanning extends Command
{
    protected $signature = 'crm:subscription-planning';

    protected $description = 'Prolonge dans l’agenda les séances planifiées des abonnements en cours';

    public function handle(): int
    {
        $created = 0;

        Subscription::where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('planning')
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', today()->toDateString()))
            ->with(['store.organization', 'plan', 'customer', 'beneficiary'])
            ->chunkById(200, function ($subscriptions) use (&$created) {
                foreach ($subscriptions as $subscription) {
                    $created += SubscriptionPlanning::sync($subscription);
                }
            });

        $this->info("{$created} séance(s) ajoutée(s) à l’agenda.");

        return self::SUCCESS;
    }
}
