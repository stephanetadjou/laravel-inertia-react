<?php

namespace App\Console\Commands;

use App\Models\KnowledgeEntry;
use App\Models\Organization;
use App\Services\KnowledgeBase;
use Illuminate\Console\Command;

/** Calcule les vecteurs (recherche par le sens) des fiches d'une entreprise, après l'activation des embeddings. */
class IndexAssistantKnowledge extends Command
{
    protected $signature = 'assistant:indexer {organization : ID de l\'entreprise}';

    protected $description = 'Indexe par le sens les fiches de l’Assistant IA d’une entreprise';

    public function handle(): int
    {
        $organization = Organization::find($this->argument('organization'));
        $base = $organization ? new KnowledgeBase($organization) : null;

        if ($base === null || ! $base->embeddingsEnabled()) {
            $this->error($base === null ? 'Entreprise introuvable.' : 'Embeddings non configurés (CLIENTLYX_EMBEDDINGS_DRIVER et CLIENTLYX_EMBEDDINGS_KEY).');

            return self::FAILURE;
        }

        $done = 0;
        KnowledgeEntry::where('organization_id', $organization->id)->each(function (KnowledgeEntry $entry) use ($base, &$done) {
            $base->index($entry);
            $done += $entry->fresh()->embedding !== null ? 1 : 0;
        });

        $this->info("{$done} fiche(s) indexée(s).");

        return self::SUCCESS;
    }
}
