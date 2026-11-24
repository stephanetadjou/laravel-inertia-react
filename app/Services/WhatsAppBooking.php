<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\Store;
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
 * bk:t:{prestation}:{AAAAMMJJHHMM}, bk:ok:{prestation}:{AAAAMMJJHHMM}, bk:no.
 */
class WhatsAppBooking
{
    public const PREFIX = 'bk:';

    private const MAX_PENDING = 3;

    private array $settings;

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
            WhatsAppConversation::text($this->contact, 'La prise de rendez-vous n’est pas disponible sur WhatsApp pour le moment. L’équipe vous recontacte très vite.', 'assistant');

            return;
        }

        $currency = $this->contact->store->organization->currencySymbol();
        $this->send('Quelle prestation souhaitez-vous réserver ?', 'Choisir', $services->mapWithKeys(fn (CatalogItem $s) => [
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

    /** Réponse à un bouton du parcours. */
    public function handle(string $reply): void
    {
        $parts = explode(':', substr($reply, strlen(self::PREFIX)));
        $step = $parts[0] ?? '';

        if ($step === 'no') {
            WhatsAppConversation::text($this->contact, 'C’est noté, aucune réservation. Écrivez-moi quand vous voulez.', 'assistant');

            return;
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

    private function days(CatalogItem $service): void
    {
        $days = collect($this->availability()->openDays($this->duration($service), [$service->id]))->take(10);

        if ($days->isEmpty()) {
            WhatsAppConversation::text($this->contact, "Plus aucun créneau libre pour « {$service->name} » ces prochains jours. L’équipe va vous proposer une date.", 'assistant');

            return;
        }

        $this->send("« {$service->name} » : quel jour vous arrange ?", 'Choisir un jour', $days->mapWithKeys(fn ($date) => [
            self::PREFIX.'d:'.$service->id.':'.str_replace('-', '', $date) => ['title' => $this->dayLabel(Carbon::parse($date))],
        ])->all());
    }

    private function slots(CatalogItem $service, ?Carbon $day): void
    {
        $slots = $day ? collect($this->availability()->slots($day, $this->duration($service), [$service->id])) : collect();

        if ($slots->isEmpty()) {
            WhatsAppConversation::text($this->contact, 'Ce jour vient d’être complet. Choisissez un autre jour :', 'assistant');
            $this->days($service);

            return;
        }

        // 10 lignes au plus : créneaux répartis sur la journée.
        $picked = $slots->count() <= 10 ? $slots : collect(range(0, 9))->map(fn ($i) => $slots[(int) floor($i * ($slots->count() - 1) / 9)])->unique()->values();

        $this->send('À quelle heure, '.$this->dayLabel($day).' ?', 'Choisir l’heure', $picked->mapWithKeys(fn ($time) => [
            self::PREFIX.'t:'.$service->id.':'.$day->format('Ymd').str_replace(':', '', $time) => ['title' => str_replace(':', 'h', $time)],
        ])->all());
    }

    private function confirm(CatalogItem $service, ?Carbon $start): void
    {
        if ($start === null) {
            $this->days($service);

            return;
        }

        $currency = $this->contact->store->organization->currencySymbol();
        $body = "Je récapitule :\n• {$service->name}".($service->price > 0 ? ' ('.number_format($service->price, 0, ',', ' ').' '.$currency.')' : '')
            ."\n• ".$this->dayLabel($start).' à '.$start->format('H\hi')."\n• {$this->contact->store->name}\n\nJe confirme ?";

        WhatsAppConversation::interactive($this->contact, [
            'type' => 'button',
            'body' => ['text' => $body],
            'action' => ['buttons' => [
                ['type' => 'reply', 'reply' => ['id' => self::PREFIX.'ok:'.$service->id.':'.$start->format('YmdHi'), 'title' => 'Je confirme']],
                ['type' => 'reply', 'reply' => ['id' => self::PREFIX.'d:'.$service->id.':'.$start->format('Ymd'), 'title' => 'Autre heure']],
                ['type' => 'reply', 'reply' => ['id' => self::PREFIX.'no', 'title' => 'Annuler']],
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

        if ($this->contact->customer_id === null || $pending >= self::MAX_PENDING) {
            WhatsAppConversation::text($this->contact, 'Vous avez déjà plusieurs rendez-vous à venir : l’équipe vous recontacte pour en ajouter un.', 'assistant');

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
                'notes' => 'Pris sur WhatsApp par l’assistant ClientlyX',
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

            return $appointment;
        });

        if ($appointment === null) {
            WhatsAppConversation::text($this->contact, 'Ce créneau vient d’être pris. Voici les horaires encore libres :', 'assistant');
            $this->slots($service, $start->copy()->startOfDay());

            return;
        }

        // Fiche client : le besoin et la suite donnée.
        $this->contact->customer?->forceFill(['need' => 'RDV : '.$service->name.' le '.$start->format('d/m/Y H:i'), 'need_kind' => 'rdv', 'need_at' => now()])->save();

        $when = $this->dayLabel($start).' à '.$start->format('H\hi');
        WhatsAppConversation::text($this->contact, $appointment->status === 'confirmed'
            ? "C’est réservé ✅ « {$service->name} » le {$when} chez {$this->contact->store->name}. À bientôt !"
            : "Demande enregistrée ✅ « {$service->name} » le {$when}. L’équipe vous confirme très vite.", 'assistant');
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
        return $day->copy()->locale('fr')->translatedFormat('D j M');
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
