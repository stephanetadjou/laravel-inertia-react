<?php

namespace App\Services;

use App\Jobs\AssistantReplyJob;
use App\Models\AiUsage;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeQuestion;
use App\Models\Organization;
use App\Models\Store;
use App\Models\Task;
use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppOutboundMessage;
use App\Support\AssistantQuestionnaire;
use App\Support\BookingRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Assistant IA ClientlyX (module assistant) : répond aux clients sur WhatsApp
 * à partir de la base de connaissances de l'entreprise.
 *
 * - Prise de RDV guidée par boutons (WhatsAppBooking), sans modèle externe.
 * - Question libre : recherche dans la base. Avec l'IA externe autorisée
 *   (interrupteur général + réglage de l'entreprise + plafond mensuel de
 *   l'offre), Haiku rédige la réponse uniquement à partir des fiches trouvées ;
 *   sans IA, la fiche la plus proche répond seule si elle est assez proche.
 * - Sinon : « Question à apprendre » pour l'équipe ; une fois la réponse
 *   humaine validée par le gérant, elle entre dans la base.
 */
class Assistant
{
    public const UNKNOWN = '[INCONNU]';

    public static function active(Contact $contact): bool
    {
        $organization = $contact->store->organization;

        return $organization->hasModule('assistant') && $organization->assistantSettings()['enabled'];
    }

    /**
     * Prend en charge le message entrant (appelé par AutoReplies). false =
     * laisser le robot à règles répondre (menu, prix, horaires, accueil…).
     *
     * @param  array<string, mixed>  $message
     */
    public static function intercept(Contact $contact, array $message, ?string $reply): bool
    {
        if ($reply !== null && str_starts_with($reply, WhatsAppBooking::PREFIX)) {
            (new WhatsAppBooking($contact))->handle($reply);

            return true;
        }

        if ($reply !== null && str_starts_with($reply, AssistantNeeds::PREFIX)) {
            (new AssistantNeeds($contact))->start(substr($reply, strlen(AssistantNeeds::PREFIX)));

            return true;
        }

        $text = trim((string) ($message['text']['body'] ?? ''));

        // Étape en cours (besoin, nom) : la réponse du client la complète.
        if ($reply === null && ($message['type'] ?? null) === 'text' && (new AssistantNeeds($contact))->continue($text)) {
            return true;
        }

        // Demande complète (« shooting famille samedi 10 h »), annulation ou décalage d'un RDV.
        if ($reply === null && ($message['type'] ?? null) === 'text' && $text !== '' && self::handleBookingRequest($contact, $text)) {
            return true;
        }

        $short = $text !== '' && str_word_count(Str::ascii($text)) <= 4;

        // Demande de devis, démo ou essai, même dans une phrase (« je peux avoir une démo ? »).
        if (str_word_count(Str::ascii($text)) <= 8 && ($need = AssistantNeeds::keywordOf($text)) !== null && array_key_exists($need, (new AssistantNeeds($contact))->outcomes())) {
            (new AssistantNeeds($contact))->start($need);

            return true;
        }

        $option = $reply !== null && str_starts_with($reply, 'menu:') ? substr($reply, 5) : ($short ? AutoReplies::keywordOf($text) : null);

        if ($option === 'rdv') {
            if (! WhatsAppBooking::available($contact)) {
                return false;
            }
            (new WhatsAppBooking($contact))->start();

            return true;
        }

        // Prix et horaires écrits par le client : la base (fiches + catalogue + horaires) répond mieux que le texte par défaut.
        if (in_array($option, ['prix', 'horaires'], true) && $reply === null) {
            $option = null;
        }

        // Boutons du menu, rappel, humain, salutations : le robot à règles.
        if ($reply !== null || ($message['type'] ?? null) !== 'text' || $option !== null || KnowledgeBase::tokens($text) === []) {
            return false;
        }

        AssistantReplyJob::dispatch($contact->id)->delay(now()->addSeconds(3));

        return true;
    }

    private static function handleBookingRequest(Contact $contact, string $text): bool
    {
        if (! $contact->store->organization->hasAgenda()) {
            return false;
        }

        $booking = new WhatsAppBooking($contact);
        $request = BookingRequest::parse($text, $booking->services());

        if (in_array($request->intent, [BookingRequest::CANCEL, BookingRequest::RESCHEDULE], true) && $booking->nextAppointment() !== null) {
            $request->intent === BookingRequest::CANCEL ? $booking->askCancel() : $booking->reschedule(null, $request);

            return true;
        }

        // Réservation : une phrase courte (« rdv samedi ? ») ou qui précise la prestation, le jour ou l'heure.
        $precise = $request->service !== null || $request->day !== null || $request->time !== null;
        if ($request->intent === BookingRequest::BOOK && ($precise || str_word_count(Str::ascii($text)) <= 4) && WhatsAppBooking::available($contact)) {
            $booking->fromRequest($request);

            return true;
        }

        return false;
    }

    /** Répond aux derniers messages écrits du contact (en file d'attente). */
    public static function respond(Contact $contact): void
    {
        $question = self::pendingQuestion($contact);
        if ($question === '') {
            return;
        }

        $decision = self::decide($contact->store->organization, $contact->store, $question, AiAgents::history($contact), WhatsAppBooking::available($contact));

        if ($decision['text'] !== null) {
            $partial = $decision['unanswered'] !== [];
            WhatsAppConversation::text($contact, $decision['text'].($partial ? "\n\nPour le reste de votre message, je transmets à l’équipe, qui vous répond très vite." : ''), 'assistant');
            if ($partial) {
                self::toLearn($contact, implode(' ? ', $decision['unanswered']), false);
            }
            (new AssistantNeeds($contact))->offer();

            return;
        }

        self::toLearn($contact, $question);
    }

    /**
     * Réponse à une question, sans rien envoyer (sert aussi à l'essai de
     * l'écran Assistant IA). text = null : question à apprendre.
     *
     * @param  list<array{role: string, content: string}>  $history
     *                                                               Plusieurs questions dans un même message (« vos prix ? et vous êtes
     *                                                               où ? ») : chacune est cherchée à part ; unanswered = celles qui restent
     *                                                               sans réponse (à apprendre par l'équipe).
     * @return array{text: ?string, via: 'ia'|'base'|'inconnu', matches: list<array{title: string, score: float}>, unanswered: list<string>}
     */
    public static function decide(Organization $organization, Store $store, string $question, array $history = [], bool $canBook = false): array
    {
        $base = new KnowledgeBase($organization);
        $parts = self::questions($question);
        $results = $base->search($question, $store, 6);

        // Fiches de chaque question, en plus de celles du message entier.
        $perPart = [];
        if (count($parts) > 1) {
            foreach ($parts as $part) {
                $perPart[$part] = $base->search($part, $store, 3);
            }
            $results = collect($results)->concat(collect($perPart)->flatten(1))
                ->sortByDesc('score')->unique(fn ($r) => $r['entry']->id)->take(10)->values()->all();
        }

        $matches = array_map(fn ($r) => ['title' => $r['entry']->title, 'score' => $r['score']], $results);
        $unanswered = [];

        if ($perPart !== []) {
            $answers = [];
            foreach ($perPart as $part => $found) {
                if (($found[0]['score'] ?? 0) >= KnowledgeBase::DIRECT_SCORE) {
                    $answers[$found[0]['entry']->id] = $found[0]['entry']->content;
                } else {
                    $unanswered[] = $part;
                }
            }
            $direct = $answers !== [] ? implode("\n\n", $answers) : null;
        } else {
            $direct = ($results[0]['score'] ?? 0) >= KnowledgeBase::DIRECT_SCORE ? $results[0]['entry']->content : null;
            $unanswered = $direct === null ? [$question] : [];
        }

        if (self::aiAllowed($organization)) {
            try {
                $text = AiProviders::reply('anthropic', (string) config('clientlyx_ai.model'), (string) config('clientlyx_ai.anthropic_key'),
                    self::systemPrompt($organization, $store, $results, $base->forbidden(), $canBook, $base->instructions()),
                    $history !== [] ? $history : [['role' => 'user', 'content' => $question]]);
                AiUsage::countQuestion($organization);

                // Réponse partielle : ce qui est connu part au client, le reste à l'équipe.
                $known = trim(str_replace(self::UNKNOWN, '', $text));
                if (! str_contains($text, self::UNKNOWN)) {
                    return ['text' => $text, 'via' => 'ia', 'matches' => $matches, 'unanswered' => []];
                }

                return mb_strlen($known) >= 20
                    ? ['text' => $known, 'via' => 'ia', 'matches' => $matches, 'unanswered' => [$question]]
                    : ['text' => null, 'via' => 'inconnu', 'matches' => $matches, 'unanswered' => [$question]];
            } catch (RuntimeException $e) {
                // Repli sur la recherche seule.
                Log::warning('Assistant IA : réponse IA impossible', ['organization_id' => $organization->id, 'error' => $e->getMessage()]);
            }
        }

        return $direct !== null
            ? ['text' => $direct, 'via' => 'base', 'matches' => $matches, 'unanswered' => $unanswered]
            : ['text' => null, 'via' => 'inconnu', 'matches' => $matches, 'unanswered' => $unanswered];
    }

    /**
     * Questions distinctes d'un message : coupé aux « ? », aux retours à la
     * ligne et aux « ; », chaque morceau gardé s'il contient un mot utile.
     *
     * @return list<string>
     */
    public static function questions(string $message): array
    {
        $parts = preg_split('/\?+|\n+|;/u', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($p) => mb_strlen($p) >= 4 && KnowledgeBase::tokens($p) !== []));
    }

    /**
     * « IA prête à X % » : ce que l'assistant sait déjà et ce qui manque,
     * chaque point menant à l'écran qui le règle.
     *
     * @return array{percent: int, checks: list<array{key: string, label: string, done: bool, url: string}>}
     */
    public static function readiness(Organization $organization): array
    {
        $services = CatalogItem::where('organization_id', $organization->id)->where('active', true);
        $progress = AssistantQuestionnaire::progress($organization);
        $fiches = KnowledgeEntry::where('organization_id', $organization->id)->where('active', true)->whereNotIn('section', ['interdit', 'consignes'])->count();

        $checks = [
            ['key' => 'catalog', 'label' => 'Prestations avec leurs prix', 'weight' => 15, 'url' => '/catalog',
                'done' => (clone $services)->exists() && (clone $services)->where('price', '>', 0)->exists()],
            ['key' => 'whatsapp', 'label' => 'WhatsApp connecté', 'weight' => 15, 'url' => '/settings/whatsapp',
                'done' => $organization->usesWhatsAppApi() && $organization->stores()->whereNotNull('whatsapp_phone_number_id')->exists()],
        ];
        if ($organization->hasModule('rdv')) {
            $checks[] = ['key' => 'booking', 'label' => 'Prise de RDV ouverte', 'weight' => 10, 'url' => '/settings/booking', 'done' => $organization->bookingSettings()['enabled']];
        }
        $checks[] = ['key' => 'questionnaire', 'label' => "Questionnaire ({$progress['answered']}/{$progress['total']})", 'weight' => 45, 'url' => '/assistant?tab=start',
            'done' => $progress['answered'] >= $progress['total'], 'ratio' => $progress['total'] > 0 ? $progress['answered'] / $progress['total'] : 1];
        $checks[] = ['key' => 'faq', 'label' => 'Au moins 5 fiches dans la base', 'weight' => 15, 'url' => '/assistant?tab=base', 'done' => $fiches >= 5, 'ratio' => min(1, $fiches / 5)];

        $total = array_sum(array_column($checks, 'weight'));
        $score = array_sum(array_map(fn ($c) => $c['weight'] * ($c['ratio'] ?? ($c['done'] ? 1 : 0)), $checks));

        return [
            'percent' => (int) round($score * 100 / max(1, $total)),
            'checks' => array_map(fn ($c) => ['key' => $c['key'], 'label' => $c['label'], 'done' => $c['done'], 'url' => $c['url']], $checks),
        ];
    }

    /** L'IA externe peut-elle répondre pour cette entreprise maintenant ? */
    public static function aiAllowed(Organization $organization): bool
    {
        if (! config('clientlyx_ai.enabled') || blank(config('clientlyx_ai.anthropic_key')) || ! $organization->assistantSettings()['use_ai']) {
            return false;
        }

        $quota = self::monthlyQuota($organization);

        return $quota === null || AiUsage::questionsThisMonth($organization) < $quota;
    }

    public static function monthlyQuota(Organization $organization): ?int
    {
        $quotas = config('clientlyx_ai.monthly_questions', []);

        return array_key_exists($organization->pack(), $quotas) ? $quotas[$organization->pack()] : 0;
    }

    /** Question sans réponse : l'équipe répond, le gérant validera la réponse pour la base. */
    /** $reply = false : le client a déjà reçu une réponse partielle, pas de second message. */
    public static function toLearn(Contact $contact, string $question, bool $reply = true): void
    {
        $organization = $contact->store->organization;

        KnowledgeQuestion::create([
            'organization_id' => $organization->id,
            'store_id' => $contact->store_id,
            'contact_id' => $contact->id,
            'question' => mb_substr($question, 0, 2000),
        ]);

        Task::open([
            'organization_id' => $organization->id,
            'store_id' => $contact->store_id,
            'customer_id' => $contact->customer_id,
            'contact_id' => $contact->id,
            'assigned_user_id' => $contact->assigned_user_id,
            'kind' => 'learn_question',
            'title' => 'Question à apprendre',
            'details' => 'Question sans réponse de l’assistant : « '.mb_substr($question, 0, 200).' ». Répondez au client dans Discussions, puis validez la réponse dans Assistant IA › Questions à apprendre.',
        ]);

        $contact->forceFill(['needs_attention' => true])->saveQuietly();
        if ($reply) {
            WhatsAppConversation::text($contact, $organization->assistantSettings()['unknown_reply'], 'assistant');
        }
    }

    /** Une réponse de l'équipe à un contact qui a une question en attente : gardée pour validation. */
    public static function recordHumanAnswer(Contact $contact, string $answer, int $userId): void
    {
        $question = KnowledgeQuestion::where('contact_id', $contact->id)->where('status', 'pending')->oldest()->first();

        $question?->update(['status' => 'answered', 'answer' => mb_substr($answer, 0, 5000), 'answered_by' => $userId, 'answered_at' => now()]);
    }

    /** Messages écrits du client depuis la dernière réponse envoyée. */
    private static function pendingQuestion(Contact $contact): string
    {
        $lastOut = WhatsAppOutboundMessage::where('contact_id', $contact->id)->latest('id')->value('created_at');

        return WhatsAppInboundMessage::where('contact_id', $contact->id)
            ->whereIn('type', ['text', 'audio'])
            ->when($lastOut, fn ($q) => $q->where('received_at', '>=', $lastOut))
            ->orderBy('received_at')
            ->limit(5)
            ->pluck('body')
            ->filter()
            ->implode("\n");
    }

    /** @param  list<array{entry: KnowledgeEntry, score: float}>  $results  @param  list<string>  $forbidden */
    private static function systemPrompt(Organization $organization, Store $store, array $results, array $forbidden, bool $canBook, array $instructions = []): string
    {
        $facts = collect($results)->map(fn ($r) => '- '.$r['entry']->title.' : '.$r['entry']->content)->implode("\n");
        $booking = $canBook ? 'Pour réserver, le client écrit « RDV » : les créneaux libres lui sont proposés par boutons.' : 'La réservation se fait avec l’équipe.';

        return implode("\n\n", array_filter([
            "Tu es l'assistant WhatsApp de « {$organization->name} » ({$store->name}). Tu réponds aux clients en français (ou dans leur langue), poliment, en 1 à 4 phrases courtes, sans Markdown.",
            "Réponds UNIQUEMENT à partir des fiches ci-dessous. N'invente jamais un prix, une disponibilité, une adresse ou une promesse. Si les fiches ne permettent pas de répondre, réponds exactement ".self::UNKNOWN.' et rien d’autre. Si le message contient plusieurs questions, réponds à chacune ; pour celles que les fiches ne couvrent pas, écris '.self::UNKNOWN.' à la place de la réponse.',
            $booking.' Tu ne réserves et n’encaisses rien toi-même. Ne demande jamais de code ni de carte bancaire.',
            $instructions !== [] ? "Consignes de l'entreprise pour mener la conversation :\n- ".implode("\n- ", $instructions) : null,
            $forbidden !== [] ? 'À ne pas faire (réponds alors '.self::UNKNOWN.") :\n- ".implode("\n- ", $forbidden) : null,
            "Fiches de l'entreprise :\n".($facts !== '' ? $facts : '(aucune fiche pertinente)'),
        ]));
    }
}
