<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Task;
use Illuminate\Support\Str;

/**
 * Parcours guidé de l'assistant jusqu'à la fiche client complète : le client
 * choisit la suite (RDV, devis, démo, essai, être rappelé), décrit son besoin
 * en une phrase et donne son nom s'il est inconnu. Le besoin est enregistré
 * sur la fiche (customers.need, need_kind), une demande part dans « À faire »
 * et, avec le suivi des prospects, la fiche entre dans le parcours prospect.
 *
 * Identifiants des boutons : as:need:{rdv|devis|demo|essai|rappel}.
 */
class AssistantNeeds
{
    public const PREFIX = 'as:need:';

    public const OUTCOMES = [
        'rdv' => 'Prendre RDV',
        'devis' => 'Demander un devis',
        'demo' => 'Demander une démo',
        'essai' => 'Faire un essai',
        'rappel' => 'Être rappelé',
    ];

    private const ASK = [
        'devis' => 'Avec plaisir ! Décrivez votre besoin en une phrase (quoi, quand, pour combien de personnes…) et l’équipe vous prépare un devis.',
        'demo' => 'Très bien ! Dites-moi en une phrase ce que vous voulez voir pendant la démo.',
        'essai' => 'Super ! Dites-moi en une phrase ce que vous aimeriez essayer.',
        'rappel' => 'Bien sûr. À quel sujet souhaitez-vous être rappelé(e) ?',
    ];

    private const TASK_TITLES = [
        'devis' => 'Demande de devis',
        'demo' => 'Demande de démo',
        'essai' => 'Demande d’essai',
        'rappel' => 'Être rappelé',
    ];

    /** Une étape reste valable 2 h. */
    private const STATE_HOURS = 2;

    public function __construct(private Contact $contact) {}

    /** Suites proposées par l'entreprise et possibles maintenant. @return array<string, string> */
    public function outcomes(): array
    {
        $enabled = $this->contact->store->organization->assistantSettings()['outcomes'];

        return collect(self::OUTCOMES)
            ->only($enabled)
            ->reject(fn ($label, $key) => $key === 'rdv' && ! WhatsAppBooking::available($this->contact))
            ->all();
    }

    /** Propose la suite après une réponse (au plus une fois par 24 h). */
    public function offer(): void
    {
        $state = $this->contact->assistant_state ?? [];
        $outcomes = $this->outcomes();

        if ($outcomes === [] || (isset($state['offered_at']) && now()->subDay()->lt($state['offered_at']))) {
            return;
        }

        WhatsAppConversation::interactive($this->contact, WhatsAppConversation::menu(
            'Je peux aussi m’occuper de la suite :',
            collect($outcomes)->mapWithKeys(fn ($label, $key) => [self::PREFIX.$key => $label])->all(),
        ), 'assistant');
        $this->save([...$state, 'offered_at' => now()->toIso8601String()]);
    }

    public function start(string $kind): void
    {
        if ($kind === 'rdv') {
            (new WhatsAppBooking($this->contact))->start();

            return;
        }

        if (! isset(self::ASK[$kind])) {
            return;
        }

        // Démo ou essai réservables dans l'agenda : on propose directement les créneaux.
        if (in_array($kind, ['demo', 'essai'], true) && WhatsAppBooking::available($this->contact)) {
            $booking = new WhatsAppBooking($this->contact);
            $service = $booking->serviceMatching($kind === 'demo' ? ['demo'] : ['essai', 'essaie', 'test']);
            if ($service !== null) {
                $booking->startFor($service);

                return;
            }
        }

        $this->save([...($this->contact->assistant_state ?? []), 'step' => 'need', 'kind' => $kind, 'at' => now()->toIso8601String()]);
        WhatsAppConversation::text($this->contact, self::ASK[$kind], 'assistant');
    }

    /** Mot-clé d'une suite dans un message court (« un devis », « une démo », « essai »). */
    public static function keywordOf(string $text): ?string
    {
        $t = Str::of($text)->ascii()->lower()->value();

        return match (true) {
            (bool) preg_match('/\bdevis\b/', $t) => 'devis',
            (bool) preg_match('/\bdemo/', $t) => 'demo',
            (bool) preg_match('/\b(essai|essayer|tester)\b/', $t) => 'essai',
            default => null,
        };
    }

    /** Réponse attendue à une étape en cours (besoin, nom) ? true = message pris en charge. */
    public function continue(string $text): bool
    {
        $state = $this->contact->assistant_state ?? [];
        $step = $state['step'] ?? null;

        if ($step === null || trim($text) === '' || ! isset($state['at']) || now()->subHours(self::STATE_HOURS)->gt($state['at'])) {
            return false;
        }

        if ($step === 'need') {
            $state['need'] = mb_substr(trim($text), 0, 2000);
            $customer = $this->contact->customer;

            if ($customer === null || CustomerLifecycle::isPlaceholderName($customer->name)) {
                $this->save([...$state, 'step' => 'name', 'at' => now()->toIso8601String()]);
                WhatsAppConversation::text($this->contact, 'Merci ! À quel nom dois-je enregistrer votre demande ?', 'assistant');

                return true;
            }

            $this->finish($state);

            return true;
        }

        if ($step === 'name') {
            $name = mb_substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 120);
            $this->contact->update(['name' => $name]);
            $this->contact->customer?->refresh();
            $this->finish($state);

            return true;
        }

        return false;
    }

    /** Fiche client complétée, demande dans « À faire », confirmation au client. */
    private function finish(array $state): void
    {
        $kind = $state['kind'] ?? 'rappel';
        $customer = $this->contact->customer;
        $organization = $this->contact->store->organization;

        $customer?->forceFill(['need' => $state['need'] ?? null, 'need_kind' => $kind, 'need_at' => now()])->save();

        Task::open([
            'organization_id' => $organization->id,
            'store_id' => $this->contact->store_id,
            'customer_id' => $this->contact->customer_id,
            'contact_id' => $this->contact->id,
            'assigned_user_id' => $this->contact->assigned_user_id,
            'kind' => 'online_request',
            'title' => self::TASK_TITLES[$kind] ?? 'Demande reçue sur WhatsApp',
            'details' => 'Besoin exprimé à l’assistant : « '.mb_substr((string) ($state['need'] ?? ''), 0, 300).' »',
        ]);

        if ($customer !== null && $organization->hasModule('prospects') && $customer->prospect_stage === null) {
            ProspectPipeline::for($organization)->start($customer);
        }

        unset($state['step'], $state['kind'], $state['at'], $state['need']);
        $this->save($state);

        $first = $customer ? ActionLists::firstNameOf((string) $customer->name) : '';
        WhatsAppConversation::text($this->contact, 'C’est noté'.($first !== '' ? ' '.$first : '').' ✅ '.match ($kind) {
            'devis' => 'L’équipe vous prépare votre devis et revient vers vous très vite.',
            'demo' => 'L’équipe vous recontacte pour fixer la démo.',
            'essai' => 'L’équipe vous recontacte pour organiser votre essai.',
            default => 'L’équipe vous rappelle très vite.',
        }, 'assistant');
    }

    private function save(array $state): void
    {
        $this->contact->forceFill(['assistant_state' => $state === [] ? null : $state])->saveQuietly();
    }
}
