<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Closure;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerFollowUp;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StaffMember;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rapport quotidien au dirigeant : la journée close (CA, rendez-vous,
 * absences, ventes), le lendemain, les clients à risque, l'équipe, la
 * relation client et des actions recommandées. Tous les établissements de
 * l'entreprise. Les chiffres viennent des données ; les phrases ne font que
 * les reprendre.
 */
class DailyReport
{
    /** Objectif de taux d'absence affiché à côté du taux du jour. */
    public const NO_SHOW_TARGET = 8.0;

    /** @var Collection<int, Store> */
    private Collection $stores;

    /** @var array<int, int> */
    private array $storeIds;

    public function __construct(private Organization $organization, private Carbon $day)
    {
        $this->day = $day->copy()->startOfDay();
        $this->stores = $organization->stores()->orderBy('name')->get();
        $this->storeIds = $this->stores->pluck('id')->all();
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $day = $this->day;
        $appointments = $this->appointments($day, $day->copy()->addDay());
        $revenue = $this->revenue($day);
        $previousDay = $this->revenue($day->copy()->subDay());
        $lastWeek = $this->revenue($day->copy()->subWeek());
        $sales = $this->purchases($day, $day)->count();
        $done = $appointments->where('status', 'done')->count();
        $noShows = $appointments->where('status', 'no_show');
        $cancelled = $appointments->where('status', 'cancelled')->count();
        $planned = $appointments->count();
        $attended = $planned - $cancelled;
        $noShowRate = $attended > 0 ? round($noShows->count() / $attended * 100, 1) : 0.0;
        $newCustomers = Customer::where('organization_id', $this->organization->id)->whereDate('created_at', $day->toDateString())->pluck('id');
        $newOnline = Appointment::whereIn('customer_id', $newCustomers)->where('source', Appointment::SOURCE_ONLINE)->distinct()->count('customer_id');
        $recovered = $this->recovered($day);
        $tomorrow = $this->tomorrow($day->copy()->addDay());
        $target = $this->target($day);
        $risk = $this->atRisk();
        $absents = $this->absents($noShows);

        $report = [
            'organization' => $this->organization->name,
            'day' => $day->toDateString(),
            'day_label' => self::longDate($day),
            'stores' => $this->stores->pluck('name')->all(),
            'kpis' => [
                'revenue' => $revenue,
                'revenue_vs_previous_day' => self::change($revenue, $previousDay),
                'revenue_vs_last_week' => self::change($revenue, $lastWeek),
                'last_week_label' => mb_strtolower($day->copy()->subWeek()->locale('fr')->translatedFormat('l')).' dernier',
                'previous_day_label' => mb_strtolower($day->copy()->subDay()->locale('fr')->translatedFormat('l')),
                'done' => $done,
                'planned' => $planned,
                'cancelled' => $cancelled,
                'done_rate' => $attended > 0 ? (int) round($done / $attended * 100) : 0,
                'no_shows' => $noShows->count(),
                'no_show_rate' => $noShowRate,
                'no_show_target' => self::NO_SHOW_TARGET,
                'average_basket' => $sales > 0 ? (int) round($revenue / $sales) : 0,
                'sales' => $sales,
                'new_customers' => $newCustomers->count(),
                'new_online' => $newOnline,
                'recovered' => $recovered['count'],
                'recovered_amount' => $recovered['amount'],
            ],
            'series' => $this->series($day),
            'target' => $target,
            'tomorrow' => $tomorrow,
            'by_store' => $this->byStore($day, $appointments),
            'services' => $this->services($day),
            'payments' => $this->payments($day),
            'at_risk' => $risk,
            'team' => $this->team($day),
            'week' => $this->week($day),
            'absents' => $absents,
            'relationship' => $this->relationship($day),
            'base' => $this->base(),
        ];

        $report['summary'] = $this->summary($report);
        $report['actions'] = $this->actions($report);

        return $report;
    }

    /** @return Builder<Purchase> */
    private function purchases(Carbon $from, Carbon $to): Builder
    {
        return Purchase::whereIn('purchases.store_id', $this->storeIds)
            ->whereDate('purchases.purchased_at', '>=', $from->toDateString())
            ->whereDate('purchases.purchased_at', '<=', $to->toDateString());
    }

    private function revenue(Carbon $day): int
    {
        return (int) $this->purchases($day, $day)->sum('amount');
    }

    /** @return Collection<int, Appointment> */
    private function appointments(Carbon $from, Carbon $to): Collection
    {
        return Appointment::whereIn('store_id', $this->storeIds)
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $to)
            ->with(['customer:id,name', 'store:id,name', 'items', 'staffMember:id,name'])
            ->orderBy('starts_at')
            ->get();
    }

    /** Clients relancés dans les 30 jours précédents qui ont acheté ce jour-là. */
    private function recovered(Carbon $day): array
    {
        $followed = CustomerFollowUp::whereBetween('done_at', [$day->copy()->subDays(30), $day->copy()])
            ->whereHas('customer', fn ($q) => $q->where('organization_id', $this->organization->id))
            ->distinct()
            ->pluck('customer_id');

        $rows = $this->purchases($day, $day)
            ->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
            ->whereIn('contacts.customer_id', $followed)
            ->selectRaw('count(distinct contacts.customer_id) as customers, coalesce(sum(purchases.amount), 0) as amount')
            ->first();

        return ['count' => (int) $rows->customers, 'amount' => (int) $rows->amount];
    }

    /**
     * CA des 14 derniers jours (fermés signalés).
     *
     * @return list<array{day: string, label: string, number: int, revenue: int, closed: bool, current: bool}>
     */
    private function series(Carbon $day): array
    {
        $from = $day->copy()->subDays(13);
        $revenues = $this->purchases($from, $day)
            ->selectRaw('date(purchased_at) as d, sum(amount) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        $series = [];
        for ($d = $from->copy(); $d->lte($day); $d->addDay()) {
            $date = $d->toDateString();
            $series[] = [
                'day' => $date,
                'label' => mb_substr($d->copy()->locale('fr')->translatedFormat('D'), 0, 2),
                'number' => (int) $d->day,
                'revenue' => (int) ($revenues[$date] ?? 0),
                'closed' => $this->closedEverywhere($d) && (int) ($revenues[$date] ?? 0) === 0,
                'current' => $date === $day->toDateString(),
            ];
        }

        return $series;
    }

    /** Tous les établissements fermés ce jour-là (horaires ou fermeture). */
    private function closedEverywhere(Carbon $day): bool
    {
        return $this->stores->every(fn (Store $s) => ($s->bookingHours()[$day->dayOfWeekIso] ?? null) === null
            || Closure::affecting($s, $day, $day)->exists());
    }

    /** Objectif de CA du mois : réalisé, rythme attendu, reste à faire par jour ouvert. */
    private function target(Carbon $day): ?array
    {
        $goal = $this->organization->reportSettings()['monthly_target'];

        if ($goal <= 0) {
            return null;
        }

        $start = $day->copy()->startOfMonth();
        $end = $day->copy()->endOfMonth()->startOfDay();
        $done = (int) $this->purchases($start, $day)->sum('amount');
        $openDays = fn (Carbon $from, Carbon $to) => collect(range(0, max(0, (int) $from->diffInDays($to))))
            ->filter(fn ($i) => ! $this->closedEverywhere($from->copy()->addDays($i)))
            ->count();
        $totalOpen = max(1, $openDays($start, $end));
        $elapsedOpen = $openDays($start, $day);
        $remainingOpen = $day->lt($end) ? $openDays($day->copy()->addDay(), $end) : 0;
        $remaining = max(0, $goal - $done);

        return [
            'month' => $day->copy()->locale('fr')->translatedFormat('F'),
            'goal' => $goal,
            'done' => $done,
            'percent' => round($done / $goal * 100, 1),
            'expected_percent' => round($elapsedOpen / $totalOpen * 100, 1),
            'remaining' => $remaining,
            'remaining_days' => $remainingOpen,
            'per_day' => $remainingOpen > 0 ? (int) ceil($remaining / $remainingOpen) : $remaining,
        ];
    }

    /** Le lendemain : rendez-vous, à confirmer, CA prévu, créneaux libres. */
    private function tomorrow(Carbon $day): array
    {
        $appointments = $this->appointments($day, $day->copy()->addDay())->where('status', '!=', 'cancelled');
        $booked = (int) $appointments->sum('duration_minutes');
        $available = 0;
        $free = 0;
        $settings = [...$this->organization->bookingSettings(), 'min_notice_minutes' => 0];

        foreach ($this->stores as $store) {
            $hours = $store->bookingHours()[$day->dayOfWeekIso] ?? null;

            if ($hours === null || Closure::affecting($store, $day, $day)->exists()) {
                continue;
            }

            $minutes = (int) Carbon::parse($hours['open'])->diffInMinutes(Carbon::parse($hours['close']));
            if ($break = $store->bookingBreak()) {
                $minutes -= (int) Carbon::parse($break['start'])->diffInMinutes(Carbon::parse($break['end']));
            }
            $staff = StaffMember::where('store_id', $store->id)->where('active', true)->count();
            $available += max(0, $minutes) * max(1, $staff ?: (int) $store->booking_capacity);
            $free += count((new BookingAvailability($store, $settings))->slots($day, max(5, $settings['slot_step'])));
        }

        return [
            'label' => self::longDate($day, false),
            'count' => $appointments->count(),
            'confirmed' => $appointments->where('status', 'confirmed')->count(),
            'to_confirm' => $appointments->whereIn('status', ['requested', 'planned'])->count(),
            'expected_revenue' => (int) $appointments->sum(fn (Appointment $a) => $a->items->sum('price')),
            'fill_rate' => $available > 0 ? min(100, (int) round($booked / $available * 100)) : null,
            'free_slots' => $free,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function byStore(Carbon $day, Collection $appointments): array
    {
        $lines = PurchaseItem::query()
            ->whereIn('purchase_id', $this->purchases($day, $day)->select('id'))
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->selectRaw('purchases.store_id, purchase_items.type, sum(purchase_items.amount) as total')
            ->groupBy('purchases.store_id', 'purchase_items.type')
            ->get();
        $revenue = $this->purchases($day, $day)->selectRaw('store_id, sum(amount) as total')->groupBy('store_id')->pluck('total', 'store_id');

        $rows = $this->stores->map(fn (Store $s) => [
            'name' => $s->name,
            'planned' => $appointments->where('store_id', $s->id)->count(),
            'done' => $appointments->where('store_id', $s->id)->where('status', 'done')->count(),
            'no_shows' => $appointments->where('store_id', $s->id)->where('status', 'no_show')->count(),
            'cancelled' => $appointments->where('store_id', $s->id)->where('status', 'cancelled')->count(),
            'services' => (int) $lines->where('store_id', $s->id)->where('type', 'service')->sum('total'),
            'products' => (int) $lines->where('store_id', $s->id)->where('type', 'product')->sum('total'),
            'revenue' => (int) ($revenue[$s->id] ?? 0),
        ])->filter(fn ($r) => $r['planned'] > 0 || $r['revenue'] > 0)->values();

        return $rows->all();
    }

    /** @return list<array{name: string, count: int, revenue: int}> */
    private function services(Carbon $day): array
    {
        $rows = PurchaseItem::whereIn('purchase_id', $this->purchases($day, $day)->select('id'))
            ->where('type', 'service')
            ->selectRaw('name, sum(quantity) as qty, sum(amount) as total')
            ->groupBy('name')
            ->orderByDesc('total')
            ->get();

        $top = $rows->take(5)->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->qty, 'revenue' => (int) $r->total]);
        $rest = $rows->slice(5);

        if ($rest->isNotEmpty()) {
            $top->push(['name' => 'Autres prestations', 'count' => (int) $rest->sum('qty'), 'revenue' => (int) $rest->sum('total')]);
        }

        return $top->values()->all();
    }

    /** @return list<array{label: string, amount: int, share: int}> */
    private function payments(Carbon $day): array
    {
        $rows = $this->purchases($day, $day)->selectRaw('payment_method, sum(amount) as total')->groupBy('payment_method')->pluck('total', 'payment_method');
        $total = max(1, (int) $rows->sum());

        return $rows->map(fn ($amount, $method) => [
            'label' => Purchase::PAYMENT_METHODS[$method] ?? 'Non précisé',
            'amount' => (int) $amount,
            'share' => (int) round($amount / $total * 100),
        ])->sortByDesc('amount')->values()->all();
    }

    /** Clients à risque de départ (les 5 qui pèsent le plus), et leur nombre total. */
    private function atRisk(): array
    {
        $query = Customer::where('organization_id', $this->organization->id)
            ->whereIn('churn_risk', ['medium', 'high'])
            ->whereHas('contacts', fn ($q) => $q->whereIn('store_id', $this->storeIds));
        $customers = (clone $query)->withExists(['contacts as is_vip' => fn ($q) => $q->where('is_vip', true)])->get();
        $yearAgo = $this->day->copy()->subYear()->toDateString();
        $spent = DB::table('purchases')->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
            ->whereIn('contacts.customer_id', $customers->pluck('id'))
            ->whereDate('purchases.purchased_at', '>=', $yearAgo)
            ->groupBy('contacts.customer_id')
            ->selectRaw('contacts.customer_id as id, sum(purchases.amount) as total')
            ->pluck('total', 'id');

        $rows = $customers->map(fn (Customer $c) => [
            'name' => $c->name,
            'vip' => (bool) $c->is_vip,
            'segment' => RfmCalculator::SEGMENTS[$c->rfm_segment]['label'] ?? '—',
            'last_visit' => $c->last_purchase_at?->format('d/m'),
            'interval' => $c->avg_purchase_interval_days,
            'spent_12m' => (int) ($spent[$c->id] ?? 0),
            'risk' => $c->churn_risk,
        ])->sortBy([['risk', 'asc'], ['spent_12m', 'desc']])->values();

        $top = $rows->sortByDesc(fn ($r) => [$r['risk'] === 'high' ? 1 : 0, $r['spent_12m']])->take(5)->values();

        return ['count' => $rows->count(), 'top' => $top->all(), 'top_spent' => (int) $top->sum('spent_12m')];
    }

    /** @return list<array{name: string, store: string, appointments: int, revenue: int}> */
    private function team(Carbon $day): array
    {
        $appointments = Appointment::whereIn('store_id', $this->storeIds)
            ->whereNotNull('staff_member_id')
            ->where('starts_at', '>=', $day)->where('starts_at', '<', $day->copy()->addDay())
            ->where('status', 'done')
            ->with(['staffMember.store:id,name', 'purchase:id,amount'])
            ->get();

        return $appointments->groupBy('staff_member_id')->map(fn ($items) => [
            'name' => $items->first()->staffMember->name,
            'store' => $items->first()->staffMember->store->name,
            'appointments' => $items->count(),
            'revenue' => (int) $items->sum(fn (Appointment $a) => $a->purchase?->amount ?? 0),
        ])->sortByDesc('revenue')->values()->all();
    }

    /** Semaine en cours comparée à la même période de la semaine précédente ; absences. */
    private function week(Carbon $day): array
    {
        $start = $day->copy()->startOfWeek();
        $current = (int) $this->purchases($start, $day)->sum('amount');
        $previous = (int) $this->purchases($start->copy()->subWeek(), $day->copy()->subWeek())->sum('amount');
        $rate = function (Carbon $from, Carbon $to) {
            $rows = $this->appointments($from, $to->copy()->addDay())->where('status', '!=', 'cancelled');
            $closed = $rows->whereIn('status', ['done', 'no_show']);

            return $closed->count() > 0 ? round($closed->where('status', 'no_show')->count() / $closed->count() * 100, 1) : null;
        };

        return [
            'label' => $start->isSameDay($day) ? 'CA du jour' : 'CA '.mb_strtolower($start->copy()->locale('fr')->translatedFormat('l')).' – '.mb_strtolower($day->copy()->locale('fr')->translatedFormat('l')),
            'current' => $current,
            'previous' => $previous,
            'change' => self::change($current, $previous),
            'no_show_rate' => $rate($start, $day),
            'no_show_rate_3m' => $rate($day->copy()->subMonths(3), $day),
        ];
    }

    /**
     * Absents du jour, avec leur nombre d'absences sur 3 mois.
     *
     * @param  Collection<int, Appointment>  $noShows
     * @return list<array<string, mixed>>
     */
    private function absents(Collection $noShows): array
    {
        $since = $this->day->copy()->subMonths(3);

        return $noShows->map(function (Appointment $a) use ($since) {
            $count = Appointment::where('customer_id', $a->customer_id)->where('status', 'no_show')->where('starts_at', '>=', $since)->count();

            return [
                'name' => $a->customer?->name,
                'time' => $a->starts_at->format('H\hi'),
                'store' => $a->store?->name,
                'service' => $a->service,
                'count' => $count,
                'remark' => $count >= 3 ? "{$count}e absence : demander un acompte" : ($count === 1 ? '1re absence' : "{$count}e absence en 3 mois"),
            ];
        })->values()->all();
    }

    private function relationship(Carbon $day): array
    {
        $date = $day->toDateString();
        $contacts = Contact::whereIn('store_id', $this->storeIds);

        return [
            'online_bookings' => Appointment::whereIn('store_id', $this->storeIds)->where('source', Appointment::SOURCE_ONLINE)->whereDate('created_at', $date)->count(),
            'follow_ups' => CustomerFollowUp::whereDate('done_at', $date)->whereHas('customer', fn ($q) => $q->where('organization_id', $this->organization->id))->count(),
            'recovered' => $this->recovered($day)['count'],
            'new_consents' => (clone $contacts)->where('consent_status', 'opt_in')->whereDate('consent_at', $date)->count(),
            'stops' => (clone $contacts)->where('consent_status', 'opt_out')->where('consent_source', 'whatsapp_stop')->whereDate('consent_at', $date)->count(),
        ];
    }

    private function base(): array
    {
        $customers = Customer::where('organization_id', $this->organization->id)
            ->whereHas('contacts', fn ($q) => $q->whereIn('store_id', $this->storeIds));
        $vipIds = Contact::whereIn('store_id', $this->storeIds)->where('is_vip', true)->distinct()->pluck('customer_id');
        $total = (int) (clone $customers)->sum('total_spent');
        $vipSpent = (int) Customer::whereKey($vipIds)->sum('total_spent');
        $weekEnd = $this->day->copy()->addDays(7);
        $birthdays = (clone $customers)->whereNotNull('birthdate')->get(['birthdate'])->filter(function (Customer $c) use ($weekEnd) {
            $next = $c->birthdate->copy()->year($this->day->year);
            if ($next->lt($this->day)) {
                $next->addYear();
            }

            return $next->lte($weekEnd);
        })->count();

        return [
            'active' => (clone $customers)->whereDate('last_purchase_at', '>=', $this->day->copy()->subDays(90)->toDateString())->count(),
            'champions' => (clone $customers)->whereIn('rfm_segment', ['champions', 'loyal'])->count(),
            'vip' => $vipIds->count(),
            'vip_share' => $total > 0 ? (int) round($vipSpent / $total * 100) : 0,
            'at_risk' => (clone $customers)->whereIn('churn_risk', ['medium', 'high'])->count(),
            'birthdays' => $birthdays,
        ];
    }

    /** « L'essentiel » : trois ou quatre phrases tirées des chiffres. */
    private function summary(array $r): string
    {
        $k = $r['kpis'];
        $parts = [];
        $vs = $k['revenue_vs_last_week'];
        $parts[] = $k['revenue'] > 0
            ? ($vs !== null && $vs >= 0 ? 'Bonne journée : ' : 'Journée en retrait : ').self::money($k['revenue']).' encaissés'
                .($vs !== null ? ', '.($vs >= 0 ? 'au-dessus' : 'en dessous').' de '.$k['last_week_label'].' ('.self::percent($vs).').' : '.')
            : 'Aucun encaissement enregistré aujourd’hui.';

        if ($k['no_shows'] > 0) {
            $worst = collect($r['absents'])->sortByDesc('count')->first();
            $parts[] = ($k['no_show_rate'] > self::NO_SHOW_TARGET ? 'Point noir : ' : '')."{$k['no_shows']} absence".($k['no_shows'] > 1 ? 's' : '').' ('.number_format($k['no_show_rate'], 1, ',', ' ').' %)'
                .($worst && $worst['count'] >= 3 ? ", dont {$worst['name']} pour la {$worst['count']}e fois." : '.');
        }

        if ($r['tomorrow']['to_confirm'] > 0) {
            $n = $r['tomorrow']['to_confirm'];
            $parts[] = "Demain, {$n} rendez-vous sur {$r['tomorrow']['count']} ".($n > 1 ? 'ne sont pas confirmés.' : 'n’est pas confirmé.');
        }

        $vips = collect($r['at_risk']['top'])->where('vip', true)->where('risk', 'high')->pluck('name');
        if ($vips->isNotEmpty()) {
            $parts[] = 'VIP en retard sur leur rythme habituel, à appeler en priorité : '.$vips->join(', ', ' et ').'.';
        }

        return implode(' ', $parts);
    }

    /** @return list<string> */
    private function actions(array $r): array
    {
        $actions = [];

        if ($r['tomorrow']['to_confirm'] > 0) {
            $n = $r['tomorrow']['to_confirm'];
            $actions[] = '**Confirmer '.($n > 1 ? "les {$n} rendez-vous" : 'le rendez-vous').' de demain** avant 10h depuis la liste « Rendez-vous à rappeler » (un clic par client sur WhatsApp).';
        }

        $vips = collect($r['at_risk']['top'])->where('vip', true);
        if ($vips->isNotEmpty()) {
            $actions[] = '**Appeler '.$vips->pluck('name')->join(', ', ' et ').'** (VIP, '.self::money($vips->sum('spent_12m')).' sur 12 mois) avec une attention personnalisée.';
        } elseif ($r['at_risk']['count'] > 0) {
            $actions[] = "**Relancer les {$r['at_risk']['count']} clients à risque** depuis À faire › « Clients à risque à relancer ».";
        }

        if (count($r['absents']) > 0) {
            $repeat = collect($r['absents'])->firstWhere('count', '>=', 3);
            $actions[] = '**Reprogrammer '.(count($r['absents']) > 1 ? 'les '.count($r['absents']).' absents' : 'l’absent du jour').'**'
                .($repeat ? " ; pour {$repeat['name']} ({$repeat['count']}e absence), demander un acompte à la prochaine réservation." : '.');
        }

        if (($r['tomorrow']['free_slots'] ?? 0) > 0) {
            $actions[] = "**Remplir les {$r['tomorrow']['free_slots']} créneaux libres de demain** : proposez-les aux clients « Fidèles » qui ne sont pas revenus depuis 3 semaines.";
        }

        if ($r['target'] && $r['target']['remaining'] > 0 && $r['target']['remaining_days'] > 0) {
            $actions[] = '**Tenir l’objectif du mois** : '.self::money($r['target']['per_day']).' par jour jusqu’à la fin du mois.';
        }

        return array_slice($actions, 0, 5);
    }

    public static function change(int $current, int $previous): ?float
    {
        return $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null;
    }

    public static function money(int|float $amount): string
    {
        return number_format((float) $amount, 0, ',', ' ').' FCFA';
    }

    public static function percent(?float $value): string
    {
        return $value === null ? '—' : ($value > 0 ? '+' : '').number_format($value, 1, ',', ' ').' %';
    }

    private static function longDate(Carbon $day, bool $withYear = true): string
    {
        return $day->copy()->locale('fr')->translatedFormat($withYear ? 'l j F Y' : 'l j F');
    }
}
