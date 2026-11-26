<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\Store;
use App\Models\Task;
use App\Support\BookingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prise de rendez-vous guidée par boutons WhatsApp (Assistant IA, sans
 * modèle externe) : prestation → jour → créneau → confirmation. Mêmes règles
 * que la réservation en ligne (/rdv) : créneaux de BookingAvailability,
 * vérification et enregistrement dans une transaction qui verrouille
 * l'établissement, statut « À confirmer » ou confirmé selon les réglages.
 *
 * Identifiants des boutons : bk:s:{prestation}, bk:d:{prestation}:{AAAAMMJJ},
 * bk:t:{prestation}:{AAAAMMJJHHMM}, bk:ok:{prestation}:{AAAAMMJJHHMM}, bk:no ;
 * suffixe :r{rdv} quand le client décale un rendez-vous ; annulation :
 * bk:cx:{rdv}, bk:mv:{rdv} (décaler) ou bk:keep.
 */
class WhatsAppBooking
{
    public const PREFIX = 'bk:';

    private const MAX_PENDING = 3;

    private array $settings;

    /** Rendez-vous déplacé par ce parcours (« décaler »), annulé une fois le nouveau pris. */
    private ?int $moving = null;

    public function __construct(private Contact $contact)
    {
        $this->settings = $contact->store->organization->bookingSettings();
    }

    /** Réservation possible par WhatsApp (réservation en ligne ouverte et au moins une prestation réservable). */
    public static function available(Contact $contact): bool
    {
        $organization = $contact->store->organization;

        return $organization->hasAgenda() && $organization->bookingSettings()['enabled'] && (new self($contact))->services()->isNotEmpty();
    }

    /** Étape 1 : liste des prestations. */
    public function start(): void
    {
        $services = $this->services()->take(10);

        if ($services->isEmpty()) {
            WhatsAppConversation::text($this->contact, __('La prise de rendez-vous n’est pas disponible sur WhatsApp pour le moment. L’équipe vous recontacte très vite.'), 'assistant');

            return;
        }

        $currency = $this->contact->store->organization->currencySymbol();
        $this->send(__('Quelle prestation souhaitez-vous réserver ?'), __('Choisir'), $services->mapWithKeys(fn (CatalogItem $s) => [
            self::PREFIX.'s:'.$s->id => [
                'title' => $s->name,
                'description' => trim(($s->price > 0 ? number_format($s->price, 0, ',', ' ').' '.$currency.' · ' : '').($s->duration_minutes ? $s->duration_minutes.' min' : ''), ' ·'),
            ],
        ])->all());
    }

    /** Démarre directement sur une prestation (ex. « démo » ou « essai » demandés par le client). */
    public function startFor(CatalogItem $service): void
    {
        $this->days($service);
    }

    /** Prestation réservable dont le nom contient l'un des mots (sans accents). */
    public function serviceMatching(array $words): ?CatalogItem
    {
        return $this->services()->first(function (CatalogItem $s) use ($words) {
            $name = Str::of($s->name)->ascii()->lower()->value();

            return collect($words)->contains(fn ($w) => str_contains($name, $w));
        });
    }

    /**
     * Demande écrite complète (« shooting famille samedi 10 h ») : va
     * directement à l'étape utile (récapitulatif, heures du jour, jours, ou
     * liste des prestations).
     */
    public function fromRequest(BookingRequest $request): void
    {
        $services = $this->services();
        $service = $request->service ?? ($services->count() === 1 ? $services->first() : null);

        if ($service === null) {
            $this->start();

            return;
        }

        $this->goTo($service, $request);
    }

    /**
     * L'assistant pousse au RDV : 2 créneaux libres en boutons (le plus tôt
     * et un créneau d'un jour creux de la semaine) + « Autre créneau ».
     * false = rien à proposer (pas de prestation ou plus de place).
     */
    public function proposeSlots(?CatalogItem $service, string $intro): bool
    {
        $services = $this->services();
        $service ??= $services->count() === 1 ? $services->first() : null;
        if ($service === null) {
            return false;
        }

        $picks = $this->suggestedSlots($service);
        if ($picks === []) {
            return false;
        }

        $buttons = collect($picks)->map(fn (Carbon $start) => ['type' => 'reply', 'reply' => [
            'id' => self::PREFIX.'t:'.$service->id.':'.$start->format('YmdHi'),
            'title' => mb_substr(ucfirst($this->dayLabel($start)).' '.$this->hour($start), 0, 20),
        ]])->push(['type' => 'reply', 'reply' => ['id' => self::PREFIX.'s:'.$service->id, 'title' => __('Autre créneau')]]);

        WhatsAppConversation::interactive($this->contact, [
            'type' => 'button',
            'body' => ['text' => $intro],
            'action' => ['buttons' => $buttons->all()],
        ], 'assistant');

        return true;
    }

    /**
     * Les 2 créneaux proposés : le premier libre, puis le premier créneau du
     * jour le plus creux des 7 prochains jours (heures creuses remplies en
     * priorité), ou à défaut le suivant.
     *
     * @return list<Carbon>
     */
    public function suggestedSlots(CatalogItem $service): array
    {
        $duration = $this->duration($service);
        $availability = $this->availability();
        $days = [];
        foreach (array_slice($availability->openDays($duration, [$service->id]), 0, 7) as $date) {
            $slots = array_values(array_filter(
                $availability->slots(Carbon::parse($date), $duration, [$service->id]),
                fn ($time) => Carbon::parse($date.' '.$time)->gt(now()->addMinutes(30)),
            ));
            if ($slots !== []) {
                $days[$date] = $slots;
            }
        }

        if ($days === []) {
            return [];
        }

        $first = array_key_first($days);
        $picks = [Carbon::parse($first.' '.$days[$first][0])];

        // Jour le plus creux = le plus de créneaux encore libres (autre que le premier si possible).
        $others = array_diff_key($days, [$first => true]);
        if ($others !== []) {
            uasort($others, fn ($a, $b) => count($b) <=> count($a));
            $quiet = array_key_first($others);
            $slots = $others[$quiet];
            $picks[] = Carbon::parse($quiet.' '.($slots[(int) floor(count($slots) / 3)] ?? $slots[0]));
        } elseif (isset($days[$first][1])) {
            $picks[] = Carbon::parse($first.' '.$days[$first][count($days[$first]) > 4 ? 4 : 1]);
        }

        return $picks;
    }

    /** Prochain rendez-vous à venir du client (pour l'annuler ou le décaler). */
    public function nextAppointment(): ?Appointment
    {
        if ($this->contact->customer_id === null) {
            return null;
        }

        return Appointment::where('customer_id', $this->contact->customer_id)
            ->whereIn('status', Appointment::OPEN_STATUSES)
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->first();
    }

    /** « Annuler mon rdv » : demande de confirmation par boutons. */
    public function askCancel(): void
    {
        $appointment = $this->nextAppointment();
        if ($appointment === null) {
            WhatsAppConversation::text($this->contact, __('Je ne trouve aucun rendez-vous à venir à votre nom. Écrivez « RDV » pour en prendre un.'), 'assistant');

            return;
        }

        WhatsAppConversation::interactive($this->contact, [
            'type' => 'button',
            'body' => ['text' => __('Vous souhaitez annuler votre rendez-vous :rdv ?', ['rdv' => $this->describe($appointment)])],
            'action' => ['buttons' => [
                ['type' => 'reply', 'reply' => ['id' => self::PREFIX.'cx:'.$appointment->id, 'title' => __('Oui, annuler')]],
                ['type' => 'reply', 'reply' => ['id' => self::PREFIX.'mv:'.$appointment->id, 'title' => __('Plutôt décaler')]],
                ['type' => 'reply', 'reply' => ['id' => self::PREFIX.'keep', 'title' => __('Non, je garde')]],
            ]],
        ], 'assistant');
    }

    /** « Décaler mon rdv (à jeudi 15 h) » : nouveau créneau pour la même prestation. */
    public function reschedule(?Appointment $appointment = null, ?BookingRequest $request = null): void
    {
        $appointment ??= $this->nextAppointment();
        if ($appointment === null) {
            WhatsAppConversation::text($this->contact, __('Je ne trouve aucun rendez-vous à venir à votre nom. Écrivez « RDV » pour en prendre un.'), 'assistant');

            return;
        }

        $service = $this->serviceOf($appointment);
        if ($service === null) {
            // Prestation non réservable en ligne : l'équipe recase le rendez-vous.
            $this->contact->forceFill(['needs_attention' => true])->saveQuietly();
            Task::open([
                'organization_id' => $this->contact->store->organization_id,
                'store_id' => $this->contact->store_id,
                'contact_id' => $this->contact->id,
                'assigned_user_id' => $this->contact->assigned_user_id,
                'kind' => 'reschedule',
                'title' => 'Rendez-vous à recaser',
                'details' => 'Le client souhaite décaler son rendez-vous '.$this->describe($appointment),
                'customer_id' => $this->contact->customer_id,
                'appointment_id' => $appointment->id,
            ]);
            WhatsAppConversation::text($this->contact, __('C’est noté : l’équipe vous propose un nouveau créneau très vite.'), 'assistant');

            return;
        }

        $this->moving = $appointment->id;
        $this->goTo($service, $request);
    }

    /** Réponse à un bouton du parcours. */
    public function handle(string $reply): void
    {
        $parts = explode(':', substr($reply, strlen(self::PREFIX)));
        $step = $parts[0] ?? '';

        if ($step === 'no') {
            WhatsAppConversation::text($this->contact, __('C’est noté, aucune réservation. Écrivez-moi quand vous voulez.'), 'assistant');

            return;
        }

        if ($step === 'keep') {
            WhatsAppConversation::text($this->contact, __('Parfait, votre rendez-vous est maintenu. À bientôt !'), 'assistant');

            return;
        }

        if (in_array($step, ['cx', 'mv'], true)) {
            $appointment = $this->ownAppointment((int) ($parts[1] ?? 0));
            if ($appointment === null) {
                WhatsAppConversation::text($this->contact, __('Ce rendez-vous n’est plus modifiable. L’équipe reste disponible si besoin.'), 'assistant');

                return;
            }
            $step === 'cx' ? $this->cancel($appointment) : $this->reschedule($appointment);

            return;
        }

        $last = end($parts);
        if (is_string($last) && preg_match('/^r(\d+)$/', $last, $m)) {
            $this->moving = $this->ownAppointment((int) $m[1])?->id;
        }

        $service = $this->services()->firstWhere('id', (int) ($parts[1] ?? 0));

        if ($service === null) {
            $this->start();

            return;
        }

        match ($step) {
            's' => $this->days($service),
            'd' => $this->slots($service, Carbon::createFromFormat('Ymd', (string) ($parts[2] ?? ''))?->startOfDay()),
            't' => $this->confirm($service, $this->dateTime($parts[2] ?? '')),
            'ok' => $this->book($service, $this->dateTime($parts[2] ?? '')),
            default => $this->start(),
        };
    }

    /** @return Collection<int, CatalogItem> prestations réservables dans l'établissement du contact */
    public function services(): Collection
    {
        $storeId = $this->contact->store_id;

        return CatalogItem::where('organization_id', $this->contact->store->organization_id)
            ->where('type', CatalogItem::TYPE_SERVICE)
            ->where('active', true)
            ->where('bookable_online', true)
            ->with('stores:id')
            ->orderBy('name')
            ->get()
            ->filter(fn (CatalogItem $s) => $s->stores->isEmpty() || $s->stores->contains('id', $storeId))
            ->values();
    }

    private function goTo(CatalogItem $service, ?BookingRequest $request): void
    {
        $start = $request?->start();
        if ($start !== null) {
            if ($start->isFuture() && $this->availability()->isFree($start, $this->duration($service), [$service->id])) {
                $this->confirm($service, $start);

                return;
            }
            WhatsAppConversation::text($this->contact, __(':when, ce n’est pas libre. Voici ce que je peux vous proposer :', ['when' => ucfirst($this->dayLabel($start)).' '.__('à').' '.$this->hour($start)]), 'assistant');
        }

        if ($request?->day !== null && $request->day->gte(now()->startOfDay())) {
            $this->slots($service, $request->day->copy());

            return;
        }

        $this->days($service);
    }

    private function cancel(Appointment $appointment): void
    {
        $appointment->update(['status' => 'cancelled', 'client_response' => 'cancelled', 'client_response_at' => now()]);
        WhatsAppConversation::text($this->contact, __('Votre rendez-vous :rdv est annulé. Écrivez « RDV » quand vous voulez en reprendre un.', ['rdv' => $this->describe($appointment)]), 'assistant');
    }

    /** Rendez-vous à venir et encore ouvert de ce client. */
    private function ownAppointment(int $id): ?Appointment
    {
        if ($this->contact->customer_id === null) {
            return null;
        }

        return Appointment::whereKey($id)
            ->where('customer_id', $this->contact->customer_id)
            ->whereIn('status', Appointment::OPEN_STATUSES)
            ->where('starts_at', '>=', now())
            ->first();
    }

    private function serviceOf(Appointment $appointment): ?CatalogItem
    {
        $itemId = $appointment->items()->value('catalog_item_id');
        $services = $this->services();

        return $services->firstWhere('id', $itemId)
            ?? $services->first(fn (CatalogItem $s) => Str::lower($s->name) === Str::lower((string) $appointment->service));
    }

    private function describe(Appointment $appointment): string
    {
        return ($appointment->service ? '« '.$appointment->service.' » ' : '').__('du :day à :time', ['day' => $this->dayLabel($appointment->starts_at), 'time' => $this->hour($appointment->starts_at)]);
    }

    /** Suffixe des boutons quand le parcours décale un rendez-vous. */
    private function suffix(): string
    {
        return $this->moving ? ':r'.$this->moving : '';
    }

    private function days(CatalogItem $service): void
    {
        $days = collect($this->availability()->openDays($this->duration($service), [$service->id]))->take(10);

        if ($days->isEmpty()) {
            WhatsAppConversation::text($this->contact, __('Plus aucun créneau libre pour « :service » ces prochains jours. L’équipe va vous proposer une date.', ['service' => $service->name]), 'assistant');

            return;
        }

        $this->send(__('« :service » : quel jour vous arrange ?', ['service' => $service->name]), __('Choisir un jour'), $days->mapWithKeys(fn ($date) => [
            self::PREFIX.'d:'.$service->id.':'.str_replace('-', '', $date).$this->suffix() => ['title' => $this->dayLabel(Carbon::parse($date))],
        ])->all());
    }

    private function slots(CatalogItem $service, ?Carbon $day): void
    {
        $slots = $day ? collect($this->availability()->slots($day, $this->duration($service), [$service->id])) : collect();

        if ($slots->isEmpty()) {
            WhatsAppConversation::text($this->contact, __('Ce jour vient d’être complet. Choisissez un autre jour :'), 'assistant');
            $this->days($service);

            return;
        }

        // 10 lignes au plus : créneaux répartis sur la journée.
        $picked = $slots->count() <= 10 ? $slots : collect(range(0, 9))->map(fn ($i) => $slots[(int) floor($i * ($slots->count() - 1) / 9)])->unique()->values();

        $this->send(__('À quelle heure, :day ?', ['day' => $this->dayLabel($day)]), __('Choisir l’heure'), $picked->mapWithKeys(fn ($time) => [
            self::PREFIX.'t:'.$service->id.':'.$day->format('Ymd').str_replace(':', '', $time).$this->suffix() => ['title' => str_replace(':', 'h', $time)],
        ])->all());
    }

    private function confirm(CatalogItem $service, ?Carbon $start): void
    {
        if ($start === null) {
            $this->days($service);

            return;
        }

        $currency = $this->contact->store->organization->currencySymbol();
        $body = ($this->moving ? __('Nouveau créneau (l’ancien sera annulé) :') : __('Je récapitule :'))."\n• {$service->name}".($service->price > 0 ? ' ('.number_format($service->price, 0, ',', ' ').' '.$currency.')' : '')
            ."\n• ".$this->dayLabel($start).' '.__('à').' '.$this->hour($start)."\n• {$this->contact->store->name}\n\n".__('Je confirme ?');

        WhatsAppConversation::interactive($this->contact, [
            'type' => 'button',
            'body' => ['text' => $body],
            'action' => ['buttons' => [
                ['type' => 'reply', 'reply' => ['id' => self::PREFIX.'ok:'.$service->id.':'.$start->format('YmdHi').$this->suffix(), 'title' => __('Je confirme')]],
                ['type' => 'reply', 'reply' => ['id' => self::PREFIX.'d:'.$service->id.':'.$start->format('Ymd').$this->suffix(), 'title' => __('Autre heure')]],
                ['type' => 'reply', 'reply' => ['id' => self::PREFIX.'no', 'title' => __('Annuler')]],
            ]],
        ], 'assistant');
    }

    private function book(CatalogItem $service, ?Carbon $start): void
    {
        if ($start === null) {
            $this->days($service);

            return;
        }

        $pending = Appointment::where('customer_id', $this->contact->customer_id)
            ->whereIn('status', Appointment::OPEN_STATUSES)
            ->where('starts_at', '>=', now())
            ->count();

        if ($this->contact->customer_id === null || ($pending >= self::MAX_PENDING && $this->moving === null)) {
            WhatsAppConversation::text($this->contact, __('Vous avez déjà plusieurs rendez-vous à venir : l’équipe vous recontacte pour en ajouter un.'), 'assistant');

            return;
        }

        $duration = $this->duration($service);
        $appointment = DB::transaction(function () use ($service, $start, $duration) {
            // Même verrou que /rdv : deux demandes pour le même créneau passent l'une après l'autre.
            $store = Store::whereKey($this->contact->store_id)->lockForUpdate()->first();
            $store->touch();
            $availability = new BookingAvailability($store, $this->settings);

            if (! $availability->isFree($start, $duration, [$service->id])) {
                return null;
            }

            $staff = $availability->assign($start, $duration, [$service->id], null);
            $appointment = Appointment::create([
                'store_id' => $store->id,
                'customer_id' => $this->contact->customer_id,
                'starts_at' => $start,
                'duration_minutes' => $duration,
                'service' => $service->name,
                'status' => $this->settings['auto_confirm'] ? 'confirmed' : 'requested',
                'notes' => AssistantStats::BOOKED_NOTE,
                'source' => Appointment::SOURCE_ONLINE,
                'cancel_token' => Str::random(40),
                'staff_member_id' => $staff?->id,
            ]);
            $appointment->items()->create([
                'catalog_item_id' => $service->id,
                'name' => $service->name,
                'price' => $service->price,
                'duration_minutes' => (int) $service->duration_minutes,
            ]);

            // Décalage : l'ancien rendez-vous est libéré une fois le nouveau enregistré.
            if ($this->moving !== null) {
                Appointment::whereKey($this->moving)->whereIn('status', Appointment::OPEN_STATUSES)
                    ->update(['status' => 'cancelled', 'client_response' => 'reschedule', 'client_response_at' => now()]);
            }

            return $appointment;
        });

        if ($appointment === null) {
            WhatsAppConversation::text($this->contact, __('Ce créneau vient d’être pris. Voici les horaires encore libres :'), 'assistant');
            $this->slots($service, $start->copy()->startOfDay());

            return;
        }

        // Fiche client : le besoin et la suite donnée.
        $this->contact->customer?->forceFill(['need' => 'RDV : '.$service->name.' le '.$start->format('d/m/Y H:i'), 'need_kind' => 'rdv', 'need_at' => now()])->save();

        $when = $this->dayLabel($start).' '.__('à').' '.$this->hour($start);
        if ($this->moving !== null) {
            WhatsAppConversation::text($this->contact, $appointment->status === 'confirmed'
                ? __('C’est décalé ✅ « :service » le :when chez :store. À bientôt !', ['service' => $service->name, 'when' => $when, 'store' => $this->contact->store->name])
                : __('Demande de changement enregistrée ✅ « :service » le :when. L’équipe vous confirme très vite.', ['service' => $service->name, 'when' => $when]), 'assistant');

            return;
        }

        WhatsAppConversation::text($this->contact, $appointment->status === 'confirmed'
            ? __('C’est réservé ✅ « :service » le :when chez :store. À bientôt !', ['service' => $service->name, 'when' => $when, 'store' => $this->contact->store->name])
            : __('Demande enregistrée ✅ « :service » le :when. L’équipe vous confirme très vite.', ['service' => $service->name, 'when' => $when]), 'assistant');
    }

    private function availability(): BookingAvailability
    {
        return new BookingAvailability($this->contact->store, $this->settings);
    }

    private function duration(CatalogItem $service): int
    {
        return max(5, (int) $service->duration_minutes);
    }

    private function dateTime(string $value): ?Carbon
    {
        return strlen($value) === 12 ? Carbon::createFromFormat('YmdHi', $value) : null;
    }

    private function dayLabel(Carbon $day): string
    {
        return $day->copy()->locale(app()->getLocale())->translatedFormat('D j M');
    }

    /** Heure dans la langue : 14h30 en français, 14:30 en anglais. */
    private function hour(Carbon $at): string
    {
        return app()->getLocale() === 'en' ? $at->format('H:i') : $at->format('H\hi');
    }

    /** Liste WhatsApp (10 lignes au plus). @param  array<string, array{title: string, description?: string}>  $rows */
    private function send(string $body, string $button, array $rows): void
    {
        WhatsAppConversation::interactive($this->contact, [
            'type' => 'list',
            'body' => ['text' => $body],
            'action' => [
                'button' => mb_substr($button, 0, 20),
                'sections' => [['title' => 'Choix', 'rows' => collect($rows)->map(fn ($row, $id) => array_filter([
                    'id' => (string) $id,
                    'title' => mb_substr($row['title'], 0, 24),
                    'description' => isset($row['description']) && $row['description'] !== '' ? mb_substr($row['description'], 0, 72) : null,
                ]))->values()->all()]],
            ],
        ], 'assistant');
    }
}
