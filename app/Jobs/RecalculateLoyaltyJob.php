<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Services\LoyaltyEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Recalcule la fidélité par enseigne : clients touchés, ou toute l'entreprise (null). */
class RecalculateLoyaltyJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    /** @param list<int>|null $customerIds */
    public function __construct(public int $organizationId, public ?array $customerIds = null) {}

    public function handle(LoyaltyEngine $engine): void
    {
        if ($organization = Organization::find($this->organizationId)) {
            $engine->recalculate($organization, $this->customerIds);
        }
    }
}
