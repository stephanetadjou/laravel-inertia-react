<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerFollowUp;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Listes d'actions du jour : quels clients relancer, pourquoi, avec quel
 * message. Chaque ligne a un lien wa.me qui ouvre WhatsApp avec le message
 * prérempli ; l'envoi se fait à la main depuis le téléphone de
 * l'établissement, sans l'API WhatsApp.
 *
 * Une relance enregistrée (CustomerFollowUp) retire le client de la liste
 * pendant le délai de la liste. Un client qui a répondu « Stop » partout
 * (opt-out sur tous ses contacts du périmètre) n'apparaît dans aucune liste.
 */
class ActionLists
{
    public const LISTS = [
        'at_risk' => [
            'label' => 'Clients à risque à relancer',
            'description' => "Bons clients en retard sur leur rythme d'achat habituel.",
            'cooldown_days' => 14,
        ],
        'second_purchase' => [
            'label' => 'Nouveaux clients à fidéliser',
            'description' => 'Un seul achat il y a 7 à 45 jours : le moment de déclencher le deuxième.',
            'cooldown_days' => 30,
        ],
        'win_back' => [
            'label' => 'Clients inactifs à reconquérir',
            'description' => 'Plus aucun achat depuis longtemps : une relance de dernière chance.',
            'cooldown_days' => 30,
        ],
        'vip_thanks' => [
            'label' => 'Meilleurs clients à remercier',
            'description' => 'Segment Champions : remerciez-les et proposez-leur les nouveautés.',
            'cooldown_days' => 60,
        ],
        'appointment_reminder' => [
            'label' => 'Rendez-vous à rappeler',
            'description' => "Rendez-vous d'aujourd'hui et de demain, pas encore rappelés.",
            'cooldown_days' => null,
        ],
        'no_show' => [
            'label' => 'Rendez-vous manqués à reprogrammer',
            'description' => 'Clients absents à leur rendez-vous ces 30 derniers jours, sans nouveau rendez-vous.',
            'cooldown_days' => null,
        ],
        'birthday' => [
            'label' => 'Anniversaires de la semaine',
            'description' => 'Clients dont l\'anniversaire tombe dans les 7 prochains jours.',
            'cooldown_days' => 300,
        ],
    ];

    /**
     * Variables : {prenom}, {nom}, {boutique}, {date_rdv}, {heure_rdv}, {prestation}.
     */
    public const DEFAULT_MESSAGES = [
        'at_risk' => 'Bonjour {prenom}, cela fait un moment que nous ne vous avons pas vu chez {boutique}. Nous avons de nouvelles choses qui devraient vous plaire : passez nous voir, nous serons ravis de vous accueillir !',
        'second_purchase' => 'Bonjour {prenom}, merci encore pour votre visite chez {boutique}. Tout vous convient ? Nous restons à votre disposition, et serons heureux de vous revoir très vite.',
        'win_back' => 'Bonjour {prenom}, vous nous manquez chez {boutique} ! Revenez nous voir, une attention vous attend lors de votre prochaine visite.',
        'vip_thanks' => 'Bonjour {prenom}, toute l\'équipe de {boutique} vous remercie pour votre fidélité. Vous faites partie de nos meilleurs clients : vous serez parmi les premiers informés de nos nouveautés.',
        'appointment_reminder' => 'Bonjour {prenom}, nous vous rappelons votre rendez-vous chez {boutique} le {date_rdv} à {heure_rdv}. Pouvez-vous nous confirmer votre présence ?',
        'no_show' => 'Bonjour {prenom}, nous ne vous avons pas vu à votre rendez-vous chez {boutique} le {date_rdv}. Souhaitez-vous en fixer un nouveau ?',
        'birthday' => 'Joyeux anniversaire {prenom} ! Toute l\'équipe de {boutique} vous souhaite une excellente journée.',
    ];

    /** Nombre maximum de lignes affichées par liste. */
    public const LIMIT = 200;

    /** @var array<int, int> */
    private array $storeIds;

    /** @var array<string, string> */
    private array $messages;

    public function __construct(private User $user, ?int $storeId = null)
    {
        $visible = $user->visibleStoreIds();
        $this->storeIds = Store::where('organization_id', $user->organization_id)
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->when($storeId !== null, fn ($q) => $q->whereKey($storeId))
            ->pluck('id')
            ->all();
        $this->messages = $user->organization->actionMessages();
    }

    /** @return array<int, int> */
    public function storeIds(): array
    {
        return $this->storeIds;
    }

    /**
     * Nombre de lignes de chaque liste.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return collect(array_keys(self::LISTS))->mapWithKeys(fn (string $key) => [$key => count($this->rows($key))])->all();
    }

    /**
     * Lignes d'une liste, prêtes à afficher.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $key): array
    {
        if ($this->storeIds === []) {
            return [];
        }

        return match ($key) {
            'appointment_reminder' => $this->appointmentReminders(),
            'no_show' => $this->noShows(),
            'birthday' => $this->birthdays(),
            default => $this->customerList($key),
        };
    }

    /** @return list<array<string, mixed>> */
    private function customerList(string $key): array
    {
        $today = today();
        $query = $this->customers($key);

        match ($key) {
            'at_risk' => $query
                ->where(fn ($q) => $q->whereIn('churn_risk', ['medium', 'high'])->orWhere('lifecycle_stage', Customer::STAGE_AT_RISK))
                ->where('lifecycle_stage', '!=', Customer::STAGE_CHURNED)
                ->orderByRaw("case when churn_risk = 'high' or lifecycle_stage = 'at_risk' then 0 else 1 end")
                ->orderByDesc('total_spent'),
            'second_purchase' => $query
                ->where('purchases_count', 1)
                ->whereBetween('last_purchase_at', [$today->copy()->subDays(45)->toDateString(), $today->copy()->subDays(7)->toDateString()])
                ->orderBy('last_purchase_at'),
            'win_back' => $query
                ->where('lifecycle_stage', Customer::STAGE_CHURNED)
                ->orderByDesc('total_spent'),
            'vip_thanks' => $query
                ->where('rfm_segment', 'champions')
                ->orderByDesc('score')
                ->orderByDesc('total_spent'),
        };

        return $query->limit(self::LIMIT)->get()
            ->map(fn (Customer $customer) => $this->row($key, $customer, $this->reason($key, $customer)))
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function birthdays(): array
    {
        $today = today();

        return $this->customers('birthday')
            ->whereNotNull('birthdate')
            ->get()
            ->map(function (Customer $customer) use ($today) {
                $next = $customer->birthdate->copy()->year($today->year);
                if ($next->lt($today)) {
                    $next->addYear();
                }

                return [$customer, $today->diffInDays($next)];
            })
            ->filter(fn ($pair) => $pair[1] <= 7)
            ->sortBy(fn ($pair) => $pair[1])
            ->take(self::LIMIT)
            ->map(fn ($pair) => $this->row('birthday', $pair[0], match ((int) $pair[1]) {
                0 => "Anniversaire aujourd'hui",
                1 => 'Anniversaire demain',
                default => 'Anniversaire le '.self::date($pair[0]->birthdate->copy()->year($today->year)),
            }))
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function appointmentReminders(): array
    {
        $reminded = CustomerFollowUp::where('action', 'appointment_reminder')->whereNotNull('appointment_id')->select('appointment_id');

        return Appointment::whereIn('store_id', $this->storeIds)
            ->whereIn('status', Appointment::OPEN_STATUSES)
            ->whereBetween('starts_at', [now(), today()->addDays(2)])
            ->whereNotIn('id', $reminded)
            ->with(['customer', 'store:id,name'])
            ->orderBy('starts_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Appointment $a) => $this->row('appointment_reminder', $a->customer, sprintf(
                'Rendez-vous %s à %s%s',
                $a->starts_at->isToday() ? "aujourd'hui" : 'demain',
                $a->starts_at->format('H\hi'),
                $a->service ? " ({$a->service})" : '',
            ), $a))
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function noShows(): array
    {
        $handled = CustomerFollowUp::where('action', 'no_show')->whereNotNull('appointment_id')->select('appointment_id');

        return Appointment::whereIn('store_id', $this->storeIds)
            ->where('status', 'no_show')
            ->where('starts_at', '>=', now()->subDays(30))
            ->whereNotIn('id', $handled)
            // Déjà reprogrammé : un rendez-vous à venir existe pour ce client.
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('appointments as next')
                ->whereColumn('next.customer_id', 'appointments.customer_id')
                ->whereIn('next.status', Appointment::OPEN_STATUSES)
                ->where('next.starts_at', '>=', now()))
            ->with(['customer', 'store:id,name'])
            ->orderByDesc('starts_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Appointment $a) => $this->row('no_show', $a->customer, 'Absent le '.self::date($a->starts_at).($a->service ? " ({$a->service})" : ''), $a))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Fiches du périmètre joignables (au moins un contact sans opt-out), hors
     * relance récente pour cette liste.
     *
     * @return Builder<Customer>
     */
    private function customers(string $key): Builder
    {
        $cooldown = self::LISTS[$key]['cooldown_days'];

        return Customer::where('organization_id', $this->user->organization_id)
            ->whereHas('contacts', fn ($q) => $q->whereIn('store_id', $this->storeIds)->where('consent_status', '!=', 'opt_out'))
            ->when($cooldown !== null, fn ($q) => $q->whereDoesntHave('followUps', fn ($f) => $f
                ->where('action', $key)
                ->where('done_at', '>=', now()->subDays($cooldown))));
    }

    private function reason(string $key, Customer $customer): string
    {
        $days = $customer->last_purchase_at ? (int) $customer->last_purchase_at->diffInDays(today()) : null;

        return match ($key) {
            'at_risk' => "Dernier achat il y a {$days} jours".($customer->avg_purchase_interval_days
                ? ", d'habitude tous les {$customer->avg_purchase_interval_days} jours"
                : ''),
            'second_purchase' => "Premier achat il y a {$days} jours",
            'win_back' => "Dernier achat il y a {$days} jours · ".number_format((int) $customer->total_spent, 0, ',', ' ').' FCFA dépensés',
            'vip_thanks' => "Score {$customer->score}/100 · ".number_format((int) $customer->total_spent, 0, ',', ' ').' FCFA dépensés',
            default => '',
        };
    }

    /**
     * @return array<string, mixed>|null null si le client n'a aucun contact joignable dans le périmètre
     */
    private function row(string $key, ?Customer $customer, string $reason, ?Appointment $appointment = null): ?array
    {
        if ($customer === null) {
            return null;
        }

        $contact = $this->contactFor($customer, $appointment?->store_id);

        if ($contact === null) {
            return null;
        }

        $storeName = $appointment?->store?->name ?? $contact->store->name;
        $message = $this->message($key, $customer, $storeName, $appointment);

        return [
            'key' => $key.'-'.$customer->id.($appointment ? '-'.$appointment->id : ''),
            'customer_id' => $customer->id,
            'appointment_id' => $appointment?->id,
            'store_id' => $appointment?->store_id ?? $contact->store_id,
            'name' => $customer->name,
            'phone_e164' => $contact->phone_e164,
            'store_name' => $storeName,
            'stage' => $customer->lifecycle_stage,
            'score' => $customer->score,
            'churn_risk' => $customer->churn_risk,
            'consent' => $contact->consent_status,
            'reason' => $reason,
            'message' => $message,
            'whatsapp_url' => self::whatsappUrl($contact->phone_e164, $message),
        ];
    }

    /**
     * Contact par lequel joindre le client : celui de la boutique du rendez-vous,
     * sinon celui du dernier achat, sinon le plus récent ; jamais un opt-out.
     */
    private function contactFor(Customer $customer, ?int $storeId): ?Contact
    {
        /** @var Collection<int, Contact> $contacts */
        $contacts = $customer->contacts()
            ->whereIn('store_id', $storeId !== null ? [$storeId] : $this->storeIds)
            ->where('consent_status', '!=', 'opt_out')
            ->with('store:id,name')
            ->withMax('purchases', 'purchased_at')
            ->get();

        return $contacts->sortByDesc(fn (Contact $c) => [(string) $c->purchases_max_purchased_at, $c->created_at?->timestamp])->first();
    }

    private function message(string $key, Customer $customer, string $storeName, ?Appointment $appointment): string
    {
        $firstName = CustomerLifecycle::isPlaceholderName($customer->name) ? '' : (string) strtok(trim($customer->name), ' ');

        $text = strtr($this->messages[$key], [
            '{prenom}' => $firstName,
            '{nom}' => CustomerLifecycle::isPlaceholderName($customer->name) ? '' : $customer->name,
            '{boutique}' => $storeName,
            '{date_rdv}' => $appointment ? self::date($appointment->starts_at) : '',
            '{heure_rdv}' => $appointment ? $appointment->starts_at->format('H\hi') : '',
            '{prestation}' => $appointment?->service ?? '',
        ]);

        // « Bonjour , » quand le prénom est inconnu.
        return trim(preg_replace(['/ +,/u', '/ {2,}/u'], [',', ' '], $text));
    }

    public static function whatsappUrl(string $phoneE164, string $message): string
    {
        return 'https://wa.me/'.preg_replace('/\D+/', '', $phoneE164).'?text='.rawurlencode($message);
    }

    private static function date(Carbon $date): string
    {
        return $date->copy()->locale('fr')->translatedFormat('l j F');
    }
}
