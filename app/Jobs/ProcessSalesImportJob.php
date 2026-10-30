<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\SalesImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Traite un lot d'import de ventes confirmé (cf. SalesImport::process()) :
 * hors de la requête HTTP, rejouable sans créer deux fois le même ticket.
 */
class ProcessSalesImportJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 1800;

    public function __construct(public int $batchId) {}

    public function handle(SalesImport $import): void
    {
        $batch = ImportBatch::find($this->batchId);

        if ($batch) {
            $import->process($batch);
        }
    }
}
