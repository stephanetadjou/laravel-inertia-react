<?php

namespace App\Jobs;

use App\Models\KnowledgeEntry;
use App\Services\KnowledgeBase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Vecteur d'une fiche de la base de connaissances (seulement si un service d'embeddings est configuré). */
class IndexKnowledgeEntryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $entryId) {}

    public function handle(): void
    {
        $entry = KnowledgeEntry::with('organization')->find($this->entryId);
        if ($entry !== null) {
            (new KnowledgeBase($entry->organization))->index($entry);
        }
    }
}
