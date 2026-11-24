<?php

namespace App\Http\Controllers;

use App\Jobs\IndexKnowledgeEntryJob;
use App\Models\AiUsage;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeQuestion;
use App\Models\Store;
use App\Models\Task;
use App\Services\Assistant;
use App\Services\AssistantNeeds;
use App\Services\AssistantStats;
use App\Services\Audit;
use App\Services\KnowledgeBase;
use App\Services\VoiceNotes;
use App\Support\AssistantQuestionnaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Discussions › Assistant IA (module assistant) : le document de
 * l'entreprise (fiches par rubrique, import Excel), les questions à
 * apprendre (réponse de l'équipe validée par le gérant), les réglages
 * (interrupteur de l'IA externe) et un essai sans envoi.
 */
class KnowledgeController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->organization;

        return Inertia::render('Assistant/Index', [
            'entries' => KnowledgeEntry::where('organization_id', $organization->id)->orderBy('section')->orderBy('title')->get()
                ->map(fn (KnowledgeEntry $e) => [...$e->only(['id', 'section', 'title', 'content', 'source', 'active']), 'indexed' => $e->embedding !== null]),
            'questions' => KnowledgeQuestion::where('organization_id', $organization->id)->whereIn('status', ['pending', 'answered'])
                ->with(['contact:id,name,phone_e164', 'answerer:id,name'])->latest()->limit(100)->get()
                ->map(fn (KnowledgeQuestion $q) => [
                    'id' => $q->id,
                    'question' => $q->question,
                    'answer' => $q->answer,
                    'status' => $q->status,
                    'contact' => $q->contact?->name ?: $q->contact?->phone_e164,
                    'contact_id' => $q->contact_id,
                    'answered_by' => $q->answerer?->name,
                    'created_at' => $q->created_at->toIso8601String(),
                ]),
            'sections' => collect(KnowledgeEntry::SECTIONS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'settings' => $organization->assistantSettings(),
            'readiness' => Assistant::readiness($organization),
            'facts' => (new KnowledgeBase($organization))->businessEntries()->map(fn (KnowledgeEntry $e) => ['section' => $e->section, 'title' => $e->title, 'content' => $e->content])->values(),
            'questionnaire' => collect(AssistantQuestionnaire::questions($organization))->map(fn ($q, $key) => [
                'key' => $key, 'question' => $q['question'], 'help' => $q['help'] ?? null, 'choices' => $q['choices'] ?? [],
                'answer' => AssistantQuestionnaire::answers($organization)[$key] ?? ['choices' => [], 'text' => ''],
            ])->values(),
            'initialTab' => $request->query('tab'),
            'stats' => AssistantStats::for($organization),
            'outcomes' => collect(AssistantNeeds::OUTCOMES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'status' => [
                'ai_global' => (bool) config('clientlyx_ai.enabled') && filled(config('clientlyx_ai.anthropic_key')),
                'ai_allowed' => Assistant::aiAllowed($organization),
                'embeddings' => (new KnowledgeBase($organization))->embeddingsEnabled(),
                'voice_global' => VoiceNotes::configured(),
                'voice_minutes' => AiUsage::voiceMinutesThisMonth($organization),
                'used' => AiUsage::questionsThisMonth($organization),
                'quota' => Assistant::monthlyQuota($organization),
                'api_enabled' => $organization->usesWhatsAppApi(),
                'booking' => $organization->hasAgenda() && $organization->bookingSettings()['enabled'],
                'learned' => KnowledgeQuestion::where('organization_id', $organization->id)->where('status', 'learned')->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $entry = KnowledgeEntry::create([...$this->validatedEntry($request), 'organization_id' => $request->user()->organization_id, 'source' => 'document']);
        IndexKnowledgeEntryJob::dispatch($entry->id);

        return back()->with('success', 'Fiche ajoutée à la base.');
    }

    public function update(Request $request, KnowledgeEntry $entry): RedirectResponse
    {
        $this->authorizeOwn($request, $entry->organization_id);
        $entry->update([...$this->validatedEntry($request), 'embedding' => null, 'embedding_model' => null]);
        IndexKnowledgeEntryJob::dispatch($entry->id);

        return back()->with('success', 'Fiche enregistrée.');
    }

    public function destroy(Request $request, KnowledgeEntry $entry): RedirectResponse
    {
        $this->authorizeOwn($request, $entry->organization_id);
        $entry->delete();

        return back()->with('success', 'Fiche supprimée.');
    }

    /** Import du document structuré (Excel ou CSV). */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv,txt'],
            'replace' => ['boolean'],
        ]);
        $organizationId = $request->user()->organization_id;
        $result = KnowledgeBase::readDocument($request->file('file')->getRealPath());

        if ($result['rows'] === []) {
            return back()->with('error', 'Aucune fiche lue. '.implode(' ', array_slice($result['errors'], 0, 3)));
        }

        $ids = DB::transaction(function () use ($request, $organizationId, $result) {
            if ($request->boolean('replace')) {
                KnowledgeEntry::where('organization_id', $organizationId)->where('source', 'document')->delete();
            }

            return collect($result['rows'])->map(fn ($row) => KnowledgeEntry::create([...$row, 'organization_id' => $organizationId, 'source' => 'document'])->id);
        });
        $ids->each(fn ($id) => IndexKnowledgeEntryJob::dispatch($id));
        Audit::record('assistant.import', null, 'Document de l’Assistant IA importé', ['fiches' => $ids->count(), 'erreurs' => count($result['errors'])]);

        $message = $ids->count().' fiche(s) importée(s).';

        return back()->with($result['errors'] === [] ? 'success' : 'error', $result['errors'] === [] ? $message : $message.' Ignorées : '.implode(' ', array_slice($result['errors'], 0, 5)));
    }

    /** Modèle Excel du document à remplir. */
    public function template(): StreamedResponse
    {
        $sheet = new Spreadsheet;
        $ws = $sheet->getActiveSheet();
        $ws->setTitle('Document');
        $ws->fromArray([
            ['Rubrique', 'Titre ou question', 'Contenu ou réponse'],
            ['Présentation', 'Qui sommes-nous ?', 'Studio photo professionnel à Libreville depuis 2018 : portraits, familles, mariages, photos produits.'],
            ['Présentation', 'Adresse et accès', 'Quartier Louis, face à la pharmacie du Bord de mer. Parking gratuit devant le studio.'],
            ['Prestations', 'Shooting famille', '45 000 FCFA, 1 h en studio, 15 photos retouchées livrées sous 5 jours.'],
            ['Horaires', 'Équipe', 'Deux photographes : Awa (portraits, familles) et Junior (événements, produits).'],
            ['Règles', 'Acompte et annulation', 'Acompte de 50 % pour réserver. Annulation gratuite jusqu’à 48 h avant, sinon l’acompte est gardé.'],
            ['Règles', 'Moyens de paiement', 'Espèces, Airtel Money et Moov Money.'],
            ['FAQ', 'Peut-on venir avec un animal ?', 'Oui, les animaux sont les bienvenus pour les shootings famille.'],
            ['À ne pas dire', 'Remises', 'Ne jamais promettre de remise : passer la main à l’équipe.'],
        ]);
        foreach (['A' => 18, 'B' => 36, 'C' => 90] as $col => $width) {
            $ws->getColumnDimension($col)->setWidth($width);
        }
        $ws->getStyle('A1:C1')->getFont()->setBold(true);
        $ws->getStyle('C:C')->getAlignment()->setWrapText(true);

        $help = $sheet->createSheet();
        $help->setTitle('Rubriques');
        $help->fromArray([['Rubrique', 'À mettre'], ...collect(KnowledgeEntry::SECTIONS)->map(fn ($label) => [$label, ''])->values()->all()]);
        $help->getColumnDimension('A')->setWidth(45);
        $sheet->setActiveSheetIndex(0);

        return response()->streamDownload(fn () => (new Xlsx($sheet))->save('php://output'), 'document-assistant-clientlyx.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Valide la réponse de l'équipe : la question entre dans la base. */
    public function learn(Request $request, KnowledgeQuestion $question): RedirectResponse
    {
        $this->authorizeOwn($request, $question->organization_id);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:5000'],
            'section' => ['nullable', Rule::in(array_keys(KnowledgeEntry::SECTIONS))],
        ]);

        $entry = DB::transaction(function () use ($question, $data) {
            $entry = KnowledgeEntry::create([
                'organization_id' => $question->organization_id,
                'section' => $data['section'] ?? 'faq',
                'title' => $data['title'],
                'content' => $data['content'],
                'source' => 'human',
            ]);
            $question->update(['status' => 'learned', 'knowledge_entry_id' => $entry->id, 'answer' => $question->answer ?? $data['content']]);
            $this->closeTask($question);

            return $entry;
        });
        IndexKnowledgeEntryJob::dispatch($entry->id);
        Audit::record('assistant.learned', $entry, 'Question apprise par l’Assistant IA', ['question_id' => $question->id]);

        return back()->with('success', 'Appris : l’assistant répondra désormais à cette question.');
    }

    public function dismiss(Request $request, KnowledgeQuestion $question): RedirectResponse
    {
        $this->authorizeOwn($request, $question->organization_id);
        $question->update(['status' => 'dismissed']);
        $this->closeTask($question);

        return back()->with('success', 'Question ignorée.');
    }

    /** Réponse à une question du questionnaire guidé : elle devient une fiche. */
    public function answer(Request $request, string $key): RedirectResponse
    {
        $organization = $request->user()->organization;
        abort_unless(array_key_exists($key, AssistantQuestionnaire::questions($organization)), 404);
        $data = $request->validate([
            'choices' => ['present', 'array'],
            'choices.*' => ['string', 'max:120'],
            'text' => ['nullable', 'string', 'max:2000'],
        ]);

        $entry = AssistantQuestionnaire::save($organization, $key, $data['choices'], (string) ($data['text'] ?? ''));
        if ($entry !== null) {
            IndexKnowledgeEntryJob::dispatch($entry->id);
        }

        return back();
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'use_ai' => ['required', 'boolean'],
            'voice' => ['required', 'boolean'],
            'unknown_reply' => ['required', 'string', 'max:500'],
            'outcomes' => ['present', 'array'],
            'outcomes.*' => [Rule::in(array_keys(AssistantNeeds::OUTCOMES))],
        ]);
        $data['outcomes'] = array_values(array_unique($data['outcomes']));
        $organization = $request->user()->organization;
        // Les réponses du questionnaire restent.
        $organization->update(['assistant_settings' => [...($organization->assistant_settings ?? []), ...$data]]);
        Audit::record('settings.assistant', $organization, 'Réglages de l’Assistant IA modifiés', ['enabled' => $data['enabled'], 'use_ai' => $data['use_ai']]);

        return back()->with('success', 'Réglages enregistrés.');
    }

    /** Essai : la réponse que recevrait le client (rien n'est envoyé ni appris). */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:1000']]);
        $organization = $request->user()->organization;
        $store = Store::where('organization_id', $organization->id)->orderBy('id')->first();

        if ($store === null) {
            return response()->json(['error' => 'Créez d’abord un établissement.'], 422);
        }

        $decision = Assistant::decide($organization, $store, $data['message'], [], $organization->hasAgenda() && $organization->bookingSettings()['enabled']);

        return response()->json([
            'reply' => $decision['text'] ?? $organization->assistantSettings()['unknown_reply'],
            'via' => $decision['via'],
            'matches' => $decision['matches'],
        ]);
    }

    private function closeTask(KnowledgeQuestion $question): void
    {
        $open = KnowledgeQuestion::where('contact_id', $question->contact_id)->whereIn('status', ['pending', 'answered'])->exists();
        if (! $open) {
            Task::whereNull('done_at')->where('contact_id', $question->contact_id)->where('kind', 'learn_question')->update(['done_at' => now()]);
        }
    }

    /** @return array{section: string, title: string, content: string, active: bool} */
    private function validatedEntry(Request $request): array
    {
        return $request->validate([
            'section' => ['required', Rule::in(array_keys(KnowledgeEntry::SECTIONS))],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:5000'],
            'active' => ['boolean'],
        ]);
    }

    private function authorizeOwn(Request $request, int $organizationId): void
    {
        if ($organizationId !== $request->user()->organization_id) {
            throw new HttpException(403, "Cet élément n'appartient pas à votre entreprise.");
        }
    }
}
