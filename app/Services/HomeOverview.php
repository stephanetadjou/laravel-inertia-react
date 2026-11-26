<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerMatchReview;
use App\Models\ImportBatch;
use App\Models\Installment;
use App\Models\KnowledgeQuestion;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Accueil : vue globale de l'activité et recommandations, pour tous les
 * secteurs. Quatre blocs calculés sur le périmètre de l'utilisateur
 * (visibleStoreIds) et la boutique choisie :
 *
 * 1. « Mon activité » : CA net, clients actifs, panier moyen, rétention,
 *    nouveaux clients, tickets identifiés, comparés à la même période du
 *    mois précédent et à N-1 (mêmes lignes de vente que RetailAnalytics) ;
 * 2. « À faire aujourd'hui » : compteurs avec lien direct (ActionLists,
 *    discussions, rapprochements, fidélité, Assistant IA, imports, modules) ;
 * 3. « Recommandations » : trois audiences de Fiches clients (CustomerSearch),
 *    donc réutilisables en campagne ;
 * 4. « Comparaison des boutiques » : seulement si plusieurs boutiques visibles.
 *
 * Définitions : client actif = au moins un achat sur les N derniers jours
 * (N = seuil « À risque » du parcours client, 90 j par défaut) ; rétention =
 * part des clients actifs il y a N à 2N jours qui ont racheté sur les N
 * derniers jours.
 */
class HomeOverview
{
    public const PERIODS = [
        'month' => 'Mois en cours',
        'last_month' => 'Mois dernier',
        '30d' => '30 derniers jours',
        '90d' => '90 derniers jours',
        'year' => 'Année en cours',
    ];

    /** Une boutique dont le dernier import de ventes date de plus de N jours est signalée. */
    private const IMPORT_LATE_DAYS = 5;

    private Organization $organization;

    private int $activeDays;

    private Carbon $today;

    /**
     * @param  array<int, int>  $storeIds  boutiques retenues (périmètre ∩ boutique choisie)
     * @param  array<int, int>  $visibleStoreIds  toutes les boutiques visibles (comparaison)
     */
    public function __construct(
        private User $user,
        private array $storeIds,
        private array $visibleStoreIds,
        private string $period = 'month',
    ) {
        $this->organization = $user->organization;
        $this->activeDays = max(1, (int) $this->organization->lifecycleSettings()['at_risk_days']);
        $this->today = Carbon::now($this->organization->timezone ?: config('app.timezone'))->startOfDay();
        $this->period = array_key_exists($period, self::PERIODS) ? $period : 'month';
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function range(): array
    {
        $t = $this->today->copy();

        return match ($this->period) {
            'last_month' => [$t->copy()->subMonthNoOverflow()->startOfMonth(), $t->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            '30d' => [$t->copy()->subDays(29), $t],
            '90d' => [$t->copy()->subDays(89), $t],
            'year' => [$t->copy()->startOfYear(), $t],
            default => [$t->copy()->startOfMonth(), $t],
        };
    }

    /** Période de comparaison : même période du mois précédent (ou la période d'avant, de même durée). @return array{0: Carbon, 1: Carbon} */
    public function previousRange(): array
    {
        [$from, $to] = $this->range();

        return match ($this->period) {
            'month', 'last_month' => [$from->copy()->subMonthNoOverflow(), $this->period === 'last_month' ? $from->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay() : $to->copy()->subMonthNoOverflow()],
            'year' => [$from->copy()->subYear(), $to->copy()->subYear()],
            default => [$from->copy()->subDays($from->diffInDays($to) + 1), $from->copy()->subDay()],
        };
    }

    /** @return array<string, mixed> */
    public function activity(): array
    {
        [$from, $to] = $this->range();
        [$prevFrom, $prevTo] = $this->previousRange();
        $now = $this->metrics($this->storeIds, $from, $to);
        $previous = $this->metrics($this->storeIds, $prevFrom, $prevTo);
        $lastYear = $this->hasSalesBefore($from->copy()->subYear()->addDay())
            ? $this->metrics($this->storeIds, $from->copy()->subYear(), $to->copy()->subYear())
            : null;
        $storeQuery = count($this->storeIds) === 1 && count($this->visibleStoreIds) > 1 ? '&store='.$this->storeIds[0] : '';
        $d = $this->activeDays;
        $day = fn (Carbon $c) => $c->toDateString();

        $tiles = [
            ['key' => 'net', 'label' => 'CA net', 'unit' => 'fcfa', 'definition' => 'Ventes moins retours et remises de la période.',
                'href' => "/purchases?from={$day($from)}&to={$day($to)}{$storeQuery}"],
            ['key' => 'active', 'label' => 'Clients actifs', 'unit' => 'n', 'definition' => "Clients (fiches) avec au moins un achat sur les {$d} derniers jours à la fin de la période.",
                'foot' => "achat sur {$d} j", 'href' => "/customers?bought_from={$day($to->copy()->subDays($d - 1))}&bought_to={$day($to)}{$storeQuery}"],
            ['key' => 'basket', 'label' => 'Panier moyen', 'unit' => 'fcfa', 'definition' => 'CA des ventes divisé par le nombre de tickets de vente.',
                'href' => "/analytics/direction?from={$day($from)}&to={$day($to)}{$storeQuery}"],
            ['key' => 'retention', 'label' => 'Rétention', 'unit' => '%', 'definition' => "Part des clients actifs il y a {$d} à ".(2 * $d)." jours qui ont racheté sur les {$d} derniers jours.",
                'href' => '/analytics/retention'],
            ['key' => 'new', 'label' => 'Nouveaux clients', 'unit' => 'n', 'definition' => 'Clients dont le tout premier achat tombe dans la période.',
                'href' => "/customers?first_from={$day($from)}&first_to={$day($to)}{$storeQuery}"],
        ];

        if ($this->tracksIdentification()) {
            $tiles[] = ['key' => 'identification', 'label' => 'Tickets identifiés', 'unit' => '%', 'definition' => 'Part des tickets de vente rattachés à une fiche client (les autres sont des ventes anonymes).',
                'href' => '/analytics/quality'];
        }

        return [
            'period' => $this->period,
            'from' => $day($from),
            'to' => $day($to),
            'previous_label' => $this->period === 'year' ? 'vs même période de l’an dernier' : 'vs même période du mois précédent',
            'tiles' => array_map(fn (array $t) => [
                ...$t,
                'value' => $now[$t['key']],
                'previous' => $previous[$t['key']],
                'last_year' => $lastYear[$t['key']] ?? null,
            ], $tiles),
        ];
    }

    /**
     * Chiffres d'une période sur des boutiques.
     *
     * @param  array<int, int>  $storeIds
     * @return array{net: int, basket: int, identification: int, active: int, retention: int, new: int}
     */
    public function metrics(array $storeIds, Carbon $from, Carbon $to): array
    {
        $analytics = new RetailAnalytics($this->organization, $storeIds, $from, $to);
        $totals = $analytics->totals();
        $d = $this->activeDays;
        $recent = $this->buyerIds($analytics, $to->copy()->subDays($d - 1), $to);
        $before = $this->buyerIds($analytics, $to->copy()->subDays(2 * $d - 1), $to->copy()->subDays($d));

        return [
            'net' => $totals['net'],
            'basket' => $totals['basket'],
            'identification' => $totals['identification_rate'],
            'active' => count($recent),
            'retention' => $before !== [] ? (int) round(count(array_intersect_key($before, $recent)) / count($before) * 100) : 0,
            'new' => $analytics->newCustomersCount(),
        ];
    }

    /** @return array<int, true> clients (fiches) ayant acheté sur la période */
    private function buyerIds(RetailAnalytics $analytics, Carbon $from, Carbon $to): array
    {
        return $analytics->lines($from, $to)->whereNotNull('customer_id')->where('type', '!=', Purchase::TYPE_RETURN)
            ->distinct()->pluck('customer_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    private function hasSalesBefore(Carbon $date): bool
    {
        return Purchase::whereIn('store_id', $this->storeIds ?: [0])->where('purchased_at', '<', $date->toDateString())->exists();
    }

    /** Tickets identifiés : seulement si l'entreprise a des ventes anonymes ou importées. */
    private function tracksIdentification(): bool
    {
        return Purchase::whereIn('store_id', $this->visibleStoreIds ?: [0])->where(fn ($q) => $q->whereNull('contact_id')->orWhereNotNull('import_batch_id'))->exists();
    }

    /**
     * « À faire aujourd'hui » : lignes non nulles, les plus urgentes d'abord.
     *
     * @param  array<string, int>  $listCounts  lignes de chaque liste de « À faire » (ActionLists)
     * @return list<array{key: string, label: string, detail: string, count: int, href: string, button: string, urgent: bool}>
     */
    public function todo(array $listCounts): array
    {
        $org = $this->organization;
        $stores = $this->storeIds ?: [0];
        $rows = [];
        $add = function (string $key, int $count, string $label, string $detail, string $href, string $button, bool $urgent = false) use (&$rows) {
            if ($count > 0) {
                $rows[] = compact('key', 'label', 'detail', 'count', 'href', 'button', 'urgent');
            }
        };

        $add('discussions', Contact::whereIn('store_id', $stores)->where('needs_attention', true)->count(),
            'Discussions WhatsApp sans réponse', 'Des clients attendent une réponse', '/discussions?filter=todo', 'Répondre', true);
        $add('tasks', Task::where('organization_id', $org->id)->whereNull('done_at')->where(fn ($q) => $q->whereNull('store_id')->orWhereIn('store_id', $stores))->count(),
            'Demandes à traiter', 'Être rappelé, parler à quelqu’un, décaler un rendez-vous', '/actions?list=tasks', 'Traiter', true);

        if ($org->hasAgenda()) {
            $add('appointments_today', Appointment::whereIn('store_id', $stores)->whereIn('status', Appointment::OPEN_STATUSES)
                ->whereBetween('starts_at', [now(), now()->endOfDay()])->count(),
                'Rendez-vous aujourd’hui', 'Encore à venir dans la journée', '/appointments', 'Agenda');
            $add('appointments_requested', Appointment::whereIn('store_id', $stores)->where('status', 'requested')->where('starts_at', '>=', now())->count(),
                'Demandes de rendez-vous à confirmer', 'Réservations en ligne ou sur WhatsApp', '/appointments', 'Confirmer', true);
        }

        $recall = array_sum(array_intersect_key($listCounts, array_flip(ActionLists::RECALL_LISTS)));
        $add('recall', $recall, 'Clients à rappeler', 'Relances prêtes avec le message WhatsApp prérempli', '/actions', 'Relancer');

        $add('reviews', CustomerMatchReview::where('organization_id', $org->id)->where('status', CustomerMatchReview::STATUS_PENDING)->whereIn('store_id', $stores)->count(),
            'Fiches à vérifier', 'Même numéro, nom différent : à rattacher ou à séparer', '/customers/a-verifier', 'Vérifier');

        if ($org->hasModule('fidelite') && $org->usesLoyaltyPrograms()) {
            $brands = Store::whereIn('id', $stores)->distinct()->pluck('brand_id');
            $add('loyalty_rewards', LoyaltyCard::where('organization_id', $org->id)->whereIn('brand_id', $brands)->where('status', LoyaltyCard::STATUS_ACTIVE)
                ->whereNotNull('discount_percent')->distinct()->count('customer_id'),
                'Remises fidélité atteintes', 'Clients à prévenir de leur palier', '/pilotage/fidelite', 'Prévenir');
        }

        if ($org->hasModule('assistant')) {
            $add('questions', KnowledgeQuestion::where('organization_id', $org->id)->where('status', 'pending')
                ->where(fn ($q) => $q->whereNull('store_id')->orWhereIn('store_id', $stores))->count(),
                'Questions à apprendre', 'L’Assistant IA n’a pas su répondre', '/assistant', 'Répondre');
        }

        if ($org->hasModule('tranches')) {
            $add('overdue', Installment::overdue()->whereHas('schedule', fn ($q) => $q->whereIn('store_id', $stores))->count(),
                'Paiements en retard', 'Échéances dépassées', '/installments', 'Voir');
        }

        $late = $this->lateImports();
        $add('imports', count($late), 'Import des ventes en retard', 'Dernier import il y a plus de '.self::IMPORT_LATE_DAYS.' jours : '.implode(', ', $late), '/sales-imports', 'Importer');

        // Autres listes de « À faire » (modules), les plus fournies.
        foreach (collect($listCounts)->except([...ActionLists::RECALL_LISTS, 'tasks'])->filter()->sortDesc()->take(3) as $key => $count) {
            $add($key, (int) $count, ActionLists::LISTS[$key]['label'], ActionLists::LISTS[$key]['description'], "/actions?list={$key}", 'Voir');
        }

        return $rows;
    }

    /** @return list<string> boutiques dont le dernier import de ventes est ancien */
    private function lateImports(): array
    {
        $last = Purchase::withCancelled()->whereIn('store_id', $this->storeIds ?: [0])->whereNotNull('import_batch_id')
            ->groupBy('store_id')->selectRaw('store_id, MAX(created_at) as last_at')->pluck('last_at', 'store_id');

        if ($last->isEmpty() || ! ImportBatch::where('organization_id', $this->organization->id)->exists()) {
            return [];
        }

        $limit = now()->subDays(self::IMPORT_LATE_DAYS);

        return Store::whereIn('id', $last->filter(fn ($at) => Carbon::parse($at)->lt($limit))->keys())->orderBy('name')->pluck('name')->all();
    }

    /**
     * Trois recommandations : une audience de Fiches clients chacune.
     *
     * @return list<array<string, mixed>>
     */
    public function recommendations(): array
    {
        $day = fn (int $days) => $this->today->copy()->subDays($days)->toDateString();
        $store = count($this->storeIds) === 1 && count($this->visibleStoreIds) > 1 ? ['store' => $this->storeIds[0]] : [];
        $api = (bool) $this->organization->whatsapp_api_enabled;

        $items = [
            [
                'key' => 'rhythm',
                'icon' => 'clock',
                'filters' => ['risk' => 'any', ...$store],
                'title' => fn (int $n) => "{$n} client".($n > 1 ? 's dépassent' : ' dépasse').' leur rythme d’achat habituel',
                'text' => 'Ils achètent d’habitude à intervalle régulier et sont en retard : une relance maintenant évite de les perdre.',
                'stat' => fn (Collection $ids) => ['label' => 'CA en jeu sur 12 mois', 'value' => $this->spend($ids), 'money' => true],
                'list' => 'at_risk',
            ],
            [
                'key' => 'best',
                'icon' => 'star',
                'filters' => ['segment' => 'champions,loyal,cant_lose', 'last_before' => $day(60), ...$store],
                'title' => fn (int $n) => "{$n} meilleur".($n > 1 ? 's' : '').' client'.($n > 1 ? 's' : '').' non revenu'.($n > 1 ? 's' : '').' depuis 60 jours',
                'text' => 'Champions, clients fidèles et gros clients à ne pas perdre, sans achat depuis deux mois : un message personnel ou une avant-première les fait revenir.',
                'stat' => fn (Collection $ids) => ['label' => 'Dépense moyenne sur 12 mois', 'value' => $ids->isEmpty() ? 0 : intdiv($this->spend($ids), $ids->count()), 'money' => true],
                'list' => null,
            ],
            [
                'key' => 'new',
                'icon' => 'user',
                'filters' => ['first_from' => $day(45), 'first_to' => $day(15), 'max_sales' => 1, ...$store],
                'title' => fn (int $n) => "{$n} nouveau".($n > 1 ? 'x' : '').' client'.($n > 1 ? 's' : '').' à faire revenir',
                'text' => 'Premier achat il y a 15 à 45 jours, pas encore de deuxième : c’est le moment de déclencher le réachat.',
                'stat' => fn (Collection $ids) => ['label' => 'Boutique principale', 'value' => $this->mainStore($ids), 'money' => false],
                'list' => 'second_purchase',
            ],
        ];

        return array_map(function (array $item) use ($api) {
            $ids = (new CustomerSearch($this->user, $item['filters']))->baseQuery()->pluck('customers.id');
            $query = http_build_query($item['filters']);

            return [
                'key' => $item['key'],
                'icon' => $item['icon'],
                'count' => $ids->count(),
                'title' => ($item['title'])($ids->count()),
                'text' => $item['text'],
                'stat' => ($item['stat'])($ids),
                'list_href' => "/customers?{$query}",
                // Campagne : audience pré-remplie (API WhatsApp) ; sinon relance manuelle depuis « À faire ».
                'campaign_href' => $api ? "/customers?{$query}&campagne=1" : ($item['list'] ? "/actions?list={$item['list']}" : "/customers?{$query}"),
                'campaign_label' => $api ? 'Campagne WhatsApp' : 'Relancer sur WhatsApp',
            ];
        }, $items);
    }

    /** CA net des 12 derniers mois de ces clients, dans le périmètre. */
    private function spend(Collection $customerIds): int
    {
        $total = 0;

        foreach ($customerIds->chunk(500) as $chunk) {
            $total += (int) DB::table('purchases')->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
                ->whereIn('contacts.customer_id', $chunk->all())
                ->whereIn('purchases.store_id', $this->storeIds ?: [0])
                ->where('purchases.status', Purchase::STATUS_VALID)
                ->where('purchases.purchased_at', '>=', $this->today->copy()->subYear()->toDateString())
                ->sum('purchases.amount');
        }

        return $total;
    }

    private function mainStore(Collection $customerIds): string
    {
        if ($customerIds->isEmpty()) {
            return '—';
        }

        $storeId = DB::table('purchases')->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
            ->whereIn('contacts.customer_id', $customerIds->take(1000)->all())
            ->whereIn('purchases.store_id', $this->storeIds ?: [0])
            ->where('purchases.status', Purchase::STATUS_VALID)
            ->groupBy('purchases.store_id')->orderByRaw('COUNT(DISTINCT contacts.customer_id) DESC')
            ->value('purchases.store_id');

        return (string) (Store::whereKey($storeId)->value('name') ?? '—');
    }

    /**
     * Comparaison des boutiques visibles, triée par CA net. null si une seule.
     *
     * @return array<string, mixed>|null
     */
    public function stores(): ?array
    {
        if (count($this->visibleStoreIds) < 2) {
            return null;
        }

        [$from, $to] = $this->range();
        [$prevFrom, $prevTo] = $this->previousRange();
        $rows = Store::whereIn('id', $this->visibleStoreIds)->orderBy('name')->get(['id', 'name'])->map(function (Store $store) use ($from, $to, $prevFrom, $prevTo) {
            $now = $this->metrics([$store->id], $from, $to);
            $previous = (new RetailAnalytics($this->organization, [$store->id], $prevFrom, $prevTo))->totals()['net'];

            return [
                'id' => $store->id,
                'name' => $store->name,
                ...$now,
                'change' => $previous !== 0 ? round(($now['net'] - $previous) / abs($previous) * 100, 1) : null,
            ];
        })->sortByDesc('net')->values();
        $all = $this->metrics($this->visibleStoreIds, $from, $to);

        return [
            'rows' => $rows->all(),
            'active_sum' => (int) $rows->sum('active'),
            'active_total' => $all['active'],
            'identification' => $this->tracksIdentification(),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public static function periodOptions(): array
    {
        return collect(self::PERIODS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all();
    }

    /**
     * Tuiles du commerce dans « Mon secteur ».
     *
     * @return list<array<string, mixed>>
     */
    public function commerceTiles(): array
    {
        $org = $this->organization;
        $stores = $this->storeIds ?: [0];
        [$from, $to] = $this->range();
        $today = Purchase::whereIn('store_id', $stores)->where('type', '!=', Purchase::TYPE_RETURN)->whereDate('purchased_at', $this->today->toDateString());
        $tiles = [
            ['key' => 'sales_today', 'label' => 'Ventes aujourd’hui', 'value' => (clone $today)->count(), 'foot' => number_format((int) (clone $today)->sum('amount'), 0, ',', ' ').' FCFA', 'href' => '/purchases', 'tone' => 'blue', 'button' => 'Voir les ventes'],
        ];

        if ($org->hasModule('fidelite') && $org->usesLoyaltyPrograms()) {
            $brands = Store::whereIn('id', $stores)->distinct()->pluck('brand_id');
            $members = LoyaltyCard::where('organization_id', $org->id)->whereIn('brand_id', $brands)->where('status', LoyaltyCard::STATUS_ACTIVE)->distinct()->pluck('customer_id');
            $analytics = new RetailAnalytics($org, $this->storeIds, $from, $to);
            $net = $analytics->totals()['net'];
            $memberNet = $members->isEmpty() ? 0 : (int) $analytics->lines()->whereIn('customer_id', $members->all())->sum('amount');
            $tiles[] = ['key' => 'loyalty_members', 'label' => 'Membres fidélité actifs', 'value' => $members->count(), 'href' => '/customers?loyalty_status=active', 'tone' => 'emerald', 'button' => 'Voir les membres'];
            $tiles[] = ['key' => 'loyalty_share', 'label' => 'Part du CA fidélité', 'value' => $net > 0 ? (int) round($memberNet / $net * 100) : 0, 'percent' => true, 'foot' => 'CA des membres sur la période', 'href' => '/pilotage/fidelite', 'tone' => 'amber', 'button' => 'Pilotage fidélité'];
        }

        if ($org->hasModule('carte_digitale')) {
            $tiles[] = ['key' => 'card_contacts', 'label' => 'Contacts reçus par les cartes', 'value' => Customer::where('organization_id', $org->id)->where('acquisition_source', 'carte')
                ->where('created_at', '>=', now()->subDays(30))->count(), 'foot' => '30 derniers jours', 'href' => '/cards', 'tone' => 'cyan', 'button' => 'Cartes digitales'];
        }

        return $tiles;
    }
}
