<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Store;
use App\Models\Task;
use App\Support\AutoReplyDefaults;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Robot de réponses WhatsApp, à règles simples (sans IA) :
 * - réponse à un bouton du rappel de la veille (« Je confirme » / « Je décale ») ;
 * - choix du menu (bouton, liste, ou mot-clé : prix, horaires, rdv, rappel, humain) ;
 * - hors horaires : message d'absence, au plus une fois par 24 h ;
 * - sinon, premier message depuis 24 h : accueil + menu.
 * « Parler à quelqu'un » marque la discussion à traiter, ouvre une tâche dans
 * « À faire » et coupe le robot 24 h pour ce contact.
 */
class AutoReplies
{
    public const CONFIRM = 'rdv_ok:';

    public const RESCHEDULE = 'rdv_move:';

    private Organization $organization;

    private array $settings;

    public function __construct(private Contact $contact)
    {
        $this->organization = $contact->store->organization;
        $this->settings = $this->organization->autoReplySettings();
    }

    /** @param  array<string, mixed>  $message  message entrant du webhook */
    public static function handle(Contact $contact, array $message): void
    {
        (new self($contact))->process($message);
    }

    /** @param  array<string, mixed>  $message */
    public function process(array $message): void
    {
        $reply = self::replyIdOf($message);

        if ($reply !== null && (str_starts_with($reply, self::CONFIRM) || str_starts_with($reply, self::RESCHEDULE))) {
            $this->appointmentAnswer($reply);

            return;
        }

        if (! $this->organization->hasModule('reponses_auto') || ! $this->settings['enabled']) {
            return;
        }

        if ($this->contact->bot_paused_until !== null && $this->contact->bot_paused_until->isFuture()) {
            return;
        }

        $option = $reply !== null && str_starts_with($reply, 'menu:') ? substr($reply, 5) : self::keywordOf($message['text']['body'] ?? null);

        if ($option !== null && isset(AutoReplyDefaults::OPTIONS[$option])) {
            $this->answer($option);

            return;
        }

        if (! self::isOpen($this->contact->store, now())) {
            if ($this->contact->last_away_at === null || $this->contact->last_away_at->lt(now()->subDay())) {
                WhatsAppConversation::text($this->contact, $this->fill($this->settings['away']), 'auto');
                $this->contact->forceFill(['last_away_at' => now()])->saveQuietly();
            }

            return;
        }

        if ($this->contact->last_greeting_at === null || $this->contact->last_greeting_at->lt(now()->subDay())) {
            WhatsAppConversation::interactive($this->contact, $this->menu());
            $this->contact->forceFill(['last_greeting_at' => now()])->saveQuietly();
        }
    }

    /** Accueil + menu (boutons si 3 options au plus, liste au-delà). @return array<string, mixed> */
    public function menu(): array
    {
        $options = collect($this->availableOptions())->mapWithKeys(fn ($key) => ['menu:'.$key => $this->settings['labels'][$key]])->all();

        return WhatsAppConversation::menu(trim($this->fill($this->settings['welcome'])."\n\n".$this->settings['menu_intro']), $options);
    }

    /** Options du menu réellement utilisables (« Prendre RDV » sans agenda devient « Être rappelé »). @return list<string> */
    public function availableOptions(): array
    {
        return collect($this->settings['options'])
            ->map(fn ($key) => $key === 'rdv' && ! $this->bookingOpen() ? 'rappel' : $key)
            ->unique()->values()->all();
    }

    private function answer(string $option): void
    {
        $option = $option === 'rdv' && ! $this->bookingOpen() ? 'rappel' : $option;

        match ($option) {
            'prix' => WhatsAppConversation::text($this->contact, $this->prices(), 'auto'),
            'horaires' => WhatsAppConversation::text($this->contact, $this->fill($this->settings['hours_reply']), 'auto'),
            'rdv' => WhatsAppConversation::text($this->contact, $this->fill($this->settings['booking_reply']), 'auto'),
            'rappel' => $this->callback(),
            'humain' => $this->handOver(),
        };
    }

    private function callback(): void
    {
        $this->openTask('callback', 'Être rappelé', 'Demande de rappel reçue sur WhatsApp');
        $this->contact->forceFill(['needs_attention' => true])->saveQuietly();
        WhatsAppConversation::text($this->contact, $this->fill($this->settings['callback_reply']), 'auto');
    }

    /** « Parler à quelqu'un » : à traiter, tâche, robot coupé 24 h. */
    private function handOver(): void
    {
        $this->openTask('talk_to_human', 'Parler à quelqu’un', 'Le client demande un humain sur WhatsApp');
        $this->contact->forceFill(['needs_attention' => true, 'bot_paused_until' => now()->addDay()])->saveQuietly();
        WhatsAppConversation::text($this->contact, $this->fill($this->settings['human_reply']), 'auto');
    }

    /** Réponse au rappel de la veille. */
    private function appointmentAnswer(string $reply): void
    {
        $confirm = str_starts_with($reply, self::CONFIRM);
        $appointment = Appointment::whereKey((int) Str::after($reply, ':'))
            ->where('customer_id', $this->contact->customer_id)
            ->where('store_id', $this->contact->store_id)
            ->first();

        if ($appointment === null || ! in_array($appointment->status, Appointment::OPEN_STATUSES, true)) {
            return;
        }

        $appointment->forceFill(['client_response' => $confirm ? 'confirmed' : 'reschedule', 'client_response_at' => now()]);

        if ($confirm) {
            $appointment->status = 'confirmed';
            $appointment->save();
            WhatsAppConversation::text($this->contact, $this->fill($this->settings['reminder_confirmed'], $appointment), 'auto');

            return;
        }

        $appointment->save();
        $this->openTask('reschedule', 'Rendez-vous à recaser', 'Le client souhaite décaler son rendez-vous du '.$appointment->starts_at->translatedFormat('d/m à H\hi'), $appointment);
        $this->contact->forceFill(['needs_attention' => true])->saveQuietly();
        WhatsAppConversation::text($this->contact, $this->fill($this->settings['reminder_reschedule'], $appointment), 'auto');
    }

    private function openTask(string $kind, string $title, string $details, ?Appointment $appointment = null): void
    {
        Task::open([
            'organization_id' => $this->organization->id,
            'store_id' => $this->contact->store_id,
            'customer_id' => $this->contact->customer_id,
            'contact_id' => $this->contact->id,
            'appointment_id' => $appointment?->id,
            'assigned_user_id' => $this->contact->assigned_user_id,
            'kind' => $kind,
            'title' => $title,
            'details' => $details,
        ]);
    }

    private function bookingOpen(): bool
    {
        return $this->organization->hasAgenda() && $this->organization->bookingSettings()['enabled'];
    }

    private function prices(): string
    {
        $items = CatalogItem::where('organization_id', $this->organization->id)
            ->where('active', true)
            ->where('price', '>', 0)
            ->orderBy('type')->orderBy('name')
            ->limit(10)
            ->get(['name', 'price']);

        if ($items->isEmpty()) {
            return $this->fill($this->settings['prices_empty']);
        }

        $currency = $this->organization->currencySymbol();

        return $this->fill($this->settings['prices_intro'])."\n".$items->map(fn ($i) => '• '.$i->name.' : '.number_format($i->price, 0, ',', ' ').' '.$currency)->implode("\n");
    }

    public function fill(string $text, ?Appointment $appointment = null): string
    {
        $name = $this->contact->customer?->name ?? $this->contact->name;
        $firstName = CustomerLifecycle::isPlaceholderName($name) ? '' : ActionLists::firstNameOf((string) $name);

        $filled = strtr($text, [
            '{prenom}' => $firstName,
            '{boutique}' => $this->contact->store->name,
            '{horaires}' => self::hoursText($this->contact->store),
            '{lien_rdv}' => $this->organization->bookingUrl(),
            '{date_rdv}' => $appointment ? $appointment->starts_at->translatedFormat('l j F') : '',
            '{heure_rdv}' => $appointment ? $appointment->starts_at->format('H\hi') : '',
        ]);

        return trim(preg_replace(['/ +([,!])/u', '/ {2,}/u'], ['$1', ' '], $filled));
    }

    /** Ouvert maintenant, selon les horaires de l'établissement (fuseau de l'entreprise). */
    public static function isOpen(Store $store, Carbon $at): bool
    {
        $local = $at->copy()->setTimezone($store->organization->timezone ?: config('app.timezone'));
        $day = $store->bookingHours()[$local->dayOfWeekIso] ?? null;
        $time = $local->format('H:i');

        return $day !== null && $time >= $day['open'] && $time < $day['close'];
    }

    /** « lun.–ven. 08:00–18:00, sam. 09:00–13:00 » : jours consécutifs aux mêmes horaires regroupés. */
    public static function hoursText(Store $store): string
    {
        $names = [1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.'];
        $groups = [];

        foreach ($store->bookingHours() as $day => $hours) {
            if ($hours === null) {
                continue;
            }
            $label = str_replace(':', 'h', $hours['open']).'–'.str_replace(':', 'h', $hours['close']);
            $last = array_key_last($groups);
            if ($last !== null && $groups[$last]['label'] === $label && $groups[$last]['to'] === $day - 1) {
                $groups[$last]['to'] = $day;
            } else {
                $groups[] = ['from' => $day, 'to' => $day, 'label' => $label];
            }
        }

        return $groups === [] ? 'horaires sur demande' : collect($groups)
            ->map(fn ($g) => ($g['from'] === $g['to'] ? $names[$g['from']] : $names[$g['from']].'–'.$names[$g['to']]).' '.$g['label'])
            ->implode(', ');
    }

    /** Identifiant du bouton ou de la ligne de liste choisi, ou charge utile d'un bouton de modèle. @param  array<string, mixed>  $message */
    public static function replyIdOf(array $message): ?string
    {
        return match ($message['type'] ?? null) {
            'interactive' => $message['interactive']['button_reply']['id'] ?? $message['interactive']['list_reply']['id'] ?? null,
            'button' => $message['button']['payload'] ?? null,
            default => null,
        };
    }

    /** Mot-clé d'un message texte court (« prix ? », « vos horaires », « je veux un conseiller »…). */
    public static function keywordOf(?string $text): ?string
    {
        if ($text === null || mb_strlen($text) > 80) {
            return null;
        }

        $t = Str::of($text)->ascii()->lower()->value();

        return match (true) {
            (bool) preg_match('/\b(prix|tarif|combien|cout)/', $t) => 'prix',
            (bool) preg_match('/\b(horaire|ouvert|ouvre|ferme)/', $t) => 'horaires',
            (bool) preg_match('/\b(rdv|rendez|reserv|essai)/', $t) => 'rdv',
            (bool) preg_match('/\b(rappel)/', $t) => 'rappel',
            (bool) preg_match('/\b(humain|conseill|quelqu|parler)/', $t) => 'humain',
            default => null,
        };
    }
}
