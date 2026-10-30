<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Services\BrandMetrics;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recalcule les chiffres et le RFM par marque d'une organisation (après un
 * import de ventes, une annulation ou une fusion de fiches).
 */
class RefreshBrandMetricsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(public int $organizationId) {}

    public function uniqueId(): string
    {
        return (string) $this->organizationId;
    }

    public function handle(BrandMetrics $metrics): void
    {
        if ($organization = Organization::find($this->organizationId)) {
            $metrics->refreshOrganization($organization);
        }
    }
}
