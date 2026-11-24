<?php

namespace App\Console\Commands;

use App\Jobs\IndexKnowledgeEntryJob;
use App\Models\KnowledgeEntry;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importe une base de connaissances au format JSON (fiches : id, partie,
 * titre, questions, reponse, statut, mots_cles, interne) dans l'Assistant IA
 * d'une entreprise. Réimport sans doublon (par id). Les fiches « à compléter »
 * ou « à confirmer » arrivent en brouillon (inactives) : l'assistant ne les
 * envoie jamais tant que le gérant ne les a pas complétées et activées. Les
 * règles internes deviennent des consignes, jamais envoyées au client.
 */
class ImportAssistantKnowledge extends Command
{
    protected $signature = 'assistant:import {organization : ID de l\'entreprise} {file : fichier JSON} {--replace : supprime d\'abord les fiches du document (les fiches apprises sont gardées)}';

    protected $description = 'Importe une base de connaissances JSON dans l’Assistant IA d’une entreprise';

    private const SECTIONS = ['1' => 'presentation', '2' => 'prestations', '3' => 'horaires', '4' => 'regles', '5' => 'faq', '6' => 'consignes', '7' => 'consignes'];

    public function handle(): int
    {
        $organization = Organization::find($this->argument('organization'));
        $data = json_decode((string) @file_get_contents($this->argument('file')), true);

        if ($organization === null || ! is_array($data['fiches'] ?? null)) {
            $this->error($organization === null ? 'Entreprise introuvable.' : 'Fichier illisible : liste « fiches » attendue.');

            return self::FAILURE;
        }

        $counts = ['active' => 0, 'brouillon' => 0, 'consignes' => 0];

        $ids = DB::transaction(function () use ($organization, $data, &$counts) {
            if ($this->option('replace')) {
                KnowledgeEntry::where('organization_id', $organization->id)->where('source', 'document')->delete();
            }

            return collect($data['fiches'])->map(function (array $fiche) use ($organization, &$counts) {
                $section = ($fiche['interne'] ?? false) ? 'consignes' : (self::SECTIONS[substr((string) ($fiche['partie'] ?? ''), 0, 1)] ?? 'faq');
                $answer = trim((string) ($fiche['reponse'] ?? ''));
                $draft = in_array($fiche['statut'] ?? '', ['à compléter', 'à confirmer'], true)
                    || ! empty($fiche['a_completer']) || ! empty($fiche['a_confirmer'])
                    || str_contains($answer, '[À COMPL') || str_contains($answer, '[À CONF');

                $counts[$section === 'consignes' ? 'consignes' : ($draft ? 'brouillon' : 'active')]++;

                return KnowledgeEntry::updateOrCreate(
                    ['organization_id' => $organization->id, 'external_id' => mb_substr((string) ($fiche['id'] ?? md5($answer)), 0, 80)],
                    [
                        'section' => $section,
                        'title' => mb_substr(trim((string) ($fiche['titre'] ?? 'Fiche')), 0, 255),
                        'content' => mb_substr($answer, 0, 5000),
                        'keywords' => mb_substr(implode(' · ', [...($fiche['questions'] ?? []), ...($fiche['mots_cles'] ?? [])]), 0, 5000) ?: null,
                        'source' => 'document',
                        'active' => ! $draft,
                        'embedding' => null,
                        'embedding_model' => null,
                    ],
                )->id;
            });
        });

        $ids->each(fn ($id) => IndexKnowledgeEntryJob::dispatch($id));
        $this->info("{$ids->count()} fiche(s) importée(s) dans « {$organization->name} » : {$counts['active']} active(s), {$counts['brouillon']} en brouillon (à compléter / à confirmer), {$counts['consignes']} consigne(s) interne(s).");

        return self::SUCCESS;
    }
}
