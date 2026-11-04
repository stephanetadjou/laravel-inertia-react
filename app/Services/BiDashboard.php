<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyProgram;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pilotage : pages à onglets (Direction, Clients et RFM, Fidélité, Enseignes
 * et magasins, Familles, Qualité des données). Mêmes lignes de vente que
 * RetailAnalytics, avec en plus le filtre segment RFM et statut fidélité.
 *
 * Chaque onglet rend la même forme : `kpis` (valeur, période précédente, N-1),
 * `charts` (barres cliquables : `filter` dit quel filtre un clic applique) et
 * `lists` (tableaux exportables, enregistrables en groupe s'ils ont `audience`).
 */
class BiDashboard
{
    public const TABS = [
        'direction' => 'Direction',
        'clients' => 'Clients et RFM',
        'fidelite' => 'Fidélité',
        'enseignes' => 'Enseignes et magasins',
        'familles' => 'Familles',
        'qualite' => 'Qualité des données',
    ];

    private const COHORT_OFFSETS = [1, 2, 3, 6];

    private const SCREEN_ROWS = 15;

    private const EXPORT_ROWS = 5000;

    private ?EloquentBuilder $customers = null;

    /** @var array<int, int> */
    private array $brandIds;

    private int $days;

    /**
     * @param  array<int, int>  $storeIds  points de vente retenus
     * @param  array{segment?: string|null, loyalty_status?: string|null}  $customerFilters
     */
    public function __construct(
        private User $user,
        private Organization $organization,
        private RetailAnalytics $analytics,
        private array $storeIds,
        private Carbon $from,
        private Carbon $to,
        private array $customerFilters = [],
        private ?int $brandId = null,
    ) {
        $filters = array_filter($customerFilters);

        if ($filters !== []) {
            $this->customers = (new CustomerSearch($user, $filters))->baseQuery()->select('customers.id');
        }

        $this->brandIds = Store::whereIn('id', $storeIds ?: [0])->distinct()->pluck('brand_id')->all();
        $this->days = (int) $this->from->copy()->startOfDay()->diffInDays($this->to->copy()->startOfDay()) + 1;
    }

    /** @return array<string, mixed> */
    public function tab(string $tab, int $listRows = self::SCREEN_ROWS): array
    {
        return match ($tab) {
            'clients' => $this->clients($listRows),
            'fidelite' => $this->loyalty($listRows),
            'enseignes' => $this->network(),
            'familles' => $this->families(),
            'qualite' => $this->quality(),
            default => $this->direction(),
        };
    }

    /** @return list<array<string, mixed>> toutes les lignes d'un tableau, pour l'export */
    public function list(string $tab, string $key): ?array
    {
        return collect($this->tab($tab, self::EXPORT_ROWS)['lists'])->firstWhere('key', $key);
    }

    private function direction(): array
    {
        $now = $this->headline($this->from, $this->to);
        $previous = $this->headline(...$this->previousPeriod());
        $lastYear = $this->headline(...$this->lastYearPeriod());
        $brands = $this->groupBy('brand_id', Brand::whereIn('id', $this->brandIds)->pluck('name', 'id')->all());
        $families = $this->groupBy('category_id', Category::where('organization_id', $this->organization->id)->pluck('name', 'id')->all());

        return [
            'kpis' => $this->kpis(['net', 'tickets', 'basket', 'buyers', 'new', 'lost', 'identification', 'repeat', 'frequency', 'value'], $now, $previous, $lastYear),
            'monthly' => $this->monthly(),
            'charts' => [
                $this->chart('brands', 'CA net par enseigne', 'brand', $brands),
                $this->chart('families', 'CA net par famille', 'family', array_slice($families, 0, 8)),
            ],
            'lists' => [],
        ];
    }

    private function clients(int $listRows): array
    {
        $now = $this->headline($this->from, $this->to);
        $previous = $this->headline(...$this->previousPeriod());
        $lastYear = $this->headline(...$this->lastYearPeriod());

        $segments = $this->lines($this->from, $this->to)->join('customers', 'customers.id', '=', 'l.customer_id')
            ->groupBy('customers.rfm_segment')
            ->selectRaw('customers.rfm_segment as k, COUNT(DISTINCT l.customer_id) as total, COALESCE(SUM(l.amount), 0) as net')
            ->get();

        $moves = DB::table('customer_segment_history')
            ->where('organization_id', $this->organization->id)->whereNull('brand_id')
            ->whereBetween('computed_on', [$this->from->toDateString(), $this->to->toDateString()])
            ->when($this->customers, fn ($q) => $q->whereIn('customer_id', clone $this->customers))
            ->groupBy('segment')->selectRaw('segment, COUNT(*) as total')->pluck('total', 'segment');

        $label = fn (?string $key) => RfmCalculator::SEGMENTS[$key]['label'] ?? 'Sans segment';
        $brandCounts = $this->lines($this->from, $this->to)->whereNotNull('customer_id')
            ->groupBy('customer_id')->selectRaw('customer_id, COUNT(DISTINCT brand_id) as brands')->pluck('brands');
        $multi = $brandCounts->filter(fn ($n) => $n >= 2)->count();

        return [
            'kpis' => $this->kpis(['buyers', 'new', 'lost', 'repeat', 'frequency', 'value'], $now, $previous, $lastYear),
            'charts' => [
                [
                    'key' => 'segments', 'title' => 'Clients par segment RFM', 'filter' => 'segment', 'unit' => 'clients',
                    'items' => $segments->sortByDesc('total')->values()->map(fn ($r) => ['id' => $r->k, 'label' => $label($r->k), 'value' => (int) $r->total, 'sub' => (int) $r->net])->all(),
                ],
                [
                    'key' => 'moves', 'title' => 'Mouvements RFM : clients entrés dans chaque segment', 'filter' => 'segment', 'unit' => 'clients',
                    'items' => $moves->sortDesc()->map(fn ($total, $key) => ['id' => $key, 'label' => $label($key), 'value' => (int) $total])->values()->all(),
                ],
                [
                    'key' => 'multi', 'title' => 'Clients d’une ou plusieurs enseignes', 'filter' => null, 'unit' => 'clients',
                    'items' => [['id' => 'multi', 'label' => 'Plusieurs enseignes', 'value' => $multi], ['id' => 'mono', 'label' => 'Une seule enseigne', 'value' => $brandCounts->count() - $multi]],
                ],
            ],
            'lists' => [$this->cohorts(), ...$this->segmentList($segments, $label, $listRows)],
        ];
    }

    /** Fidélité : membres, comparaison membres / non-membres, coût des remises, listes d'action. */
    private function loyalty(int $listRows): array
    {
        $cards = fn () => LoyaltyCard::query()
            ->where('organization_id', $this->organization->id)
            ->whereIn('brand_id', $this->brandIds ?: [0])
            ->whereIn('loyalty_program_id', LoyaltyProgram::where('organization_id', $this->organization->id)
                ->whereDate('starts_on', '<=', $this->to->toDateString())->whereDate('ends_on', '>=', $this->from->toDateString())->select('id'))
            ->when($this->customers, fn ($q) => $q->whereIn('customer_id', clone $this->customers));

        $members = (clone $cards())->where('status', LoyaltyCard::STATUS_ACTIVE)->select('customer_id');
        $compare = $this->lines($this->from, $this->to)->whereNotNull('customer_id')
            ->selectRaw("CASE WHEN customer_id IN ({$members->toSql()}) THEN 'member' ELSE 'other' END as side, COALESCE(SUM(amount), 0) as net, COALESCE(SUM(CASE WHEN type = 'return' THEN 0 ELSE amount END), 0) as sales, COUNT(DISTINCT CASE WHEN type = 'return' THEN NULL ELSE purchase_id END) as tickets, COUNT(DISTINCT customer_id) as buyers", $members->getBindings())
            ->groupBy('side')->get()->keyBy('side');

        $side = fn (string $key) => $compare->get($key);
        $basket = fn (string $key) => $side($key) && $side($key)->tickets > 0 ? (int) round($side($key)->sales / $side($key)->tickets) : 0;
        $share = fn () => ($total = (int) $compare->sum('net')) > 0 ? (int) round(($side('member')->net ?? 0) / $total * 100) : 0;

        $discounts = fn (Carbon $from, Carbon $to) => (array) LoyaltyMovement::query()->where('organization_id', $this->organization->id)
            ->where('type', LoyaltyMovement::DISCOUNT_USED)->whereIn('brand_id', $this->brandIds ?: [0])
            ->whereBetween('happened_on', [$from->toDateString(), $to->toDateString()])
            ->when($this->customers, fn ($q) => $q->whereIn('customer_id', clone $this->customers))
            ->selectRaw('COUNT(*) as used, COALESCE(SUM(amount), 0) as cost')->toBase()->first();

        $now = $discounts($this->from, $this->to);
        $previous = $discounts(...$this->previousPeriod());
        $lastYear = $discounts(...$this->lastYearPeriod());
        $count = fn (string $status) => (new CustomerSearch($this->user, $this->audience(['loyalty_status' => $status])))->baseQuery()->count();
        $tile = fn (string $label, int $value, string $unit = 'n', ?int $prev = null, ?int $year = null, bool $inverted = false) => compact('label', 'value', 'unit') + ['previous' => $prev, 'last_year' => $year, 'inverted' => $inverted];

        $statuses = (clone $cards())->groupBy('status')->selectRaw('status, COUNT(*) as total')->pluck('total', 'status');
        $byBrand = (clone $cards())->where('status', LoyaltyCard::STATUS_ACTIVE)->groupBy('brand_id')->selectRaw('brand_id, COUNT(*) as total')->pluck('total', 'brand_id');
        $tiers = (clone $cards())->where('status', LoyaltyCard::STATUS_ACTIVE)->whereNotNull('discount_percent')->groupBy('discount_percent')->selectRaw('discount_percent, COUNT(*) as total')->orderBy('discount_percent')->pluck('total', 'discount_percent');
        $brandNames = Brand::whereIn('id', $this->brandIds)->pluck('name', 'id');

        return [
            'kpis' => [
                $tile('Membres actifs', (int) (clone $cards())->where('status', LoyaltyCard::STATUS_ACTIVE)->distinct()->count('customer_id')),
                $tile('Part du CA des membres', $share(), '%'),
                $tile('Panier moyen membres', $basket('member'), 'fcfa'),
                $tile('Panier moyen non-membres', $basket('other'), 'fcfa'),
                $tile('Coût des remises', (int) $now['cost'], 'fcfa', (int) $previous['cost'], (int) $lastYear['cost'], true),
                $tile('Remises utilisées', (int) $now['used'], 'n', (int) $previous['used'], (int) $lastYear['used']),
                $tile('Proches du seuil', $count('near_threshold')),
                $tile('Proches d’un palier', $count('near_tier')),
                $tile('Expirent sous 30 jours', $count('expiring')),
            ],
            'charts' => [
                [
                    'key' => 'status', 'title' => 'Cartes par statut', 'filter' => 'loyalty_status', 'unit' => 'cartes',
                    'items' => collect(LoyaltyCard::STATUSES)->map(fn ($label, $key) => ['id' => $key, 'label' => $label, 'value' => (int) ($statuses[$key] ?? 0)])->values()->all(),
                ],
                [
                    'key' => 'brands', 'title' => 'Membres actifs par enseigne', 'filter' => 'brand', 'unit' => 'membres',
                    'items' => $byBrand->sortDesc()->map(fn ($total, $id) => ['id' => $id, 'label' => $brandNames[$id] ?? '—', 'value' => (int) $total])->values()->all(),
                ],
                [
                    'key' => 'tiers', 'title' => 'Membres par palier atteint', 'filter' => null, 'unit' => 'membres',
                    'items' => $tiers->map(fn ($total, $percent) => ['id' => $percent, 'label' => "Palier {$percent} %", 'value' => (int) $total])->values()->all(),
                ],
                [
                    'key' => 'versus', 'title' => 'CA net : membres et non-membres', 'filter' => null, 'unit' => 'fcfa',
                    'items' => [['id' => 'member', 'label' => 'Membres actifs', 'value' => (int) ($side('member')->net ?? 0)], ['id' => 'other', 'label' => 'Clients non membres', 'value' => (int) ($side('other')->net ?? 0)]],
                ],
            ],
            'lists' => [
                $this->cardList('near_threshold', 'Proches du seuil', $listRows),
                $this->cardList('near_tier', 'Proches d’un palier', $listRows),
                $this->cardList('expiring', 'Cartes expirant sous 30 jours', $listRows),
            ],
        ];
    }

    private function network(): array
    {
        $stores = Store::whereIn('id', $this->storeIds ?: [0])->with('brand:id,name')->get()->keyBy('id');
        $now = $this->groupStats('store_id', $this->from, $this->to);
        $year = $this->groupStats('store_id', ...$this->lastYearPeriod());
        $rows = $now->sortByDesc('net')->values();

        return [
            'kpis' => $this->kpis(['net', 'tickets', 'basket', 'identification'], $this->headline($this->from, $this->to), $this->headline(...$this->previousPeriod()), $this->headline(...$this->lastYearPeriod())),
            'charts' => [
                $this->chart('brands', 'CA net par enseigne', 'brand', $this->groupBy('brand_id', Brand::whereIn('id', $this->brandIds)->pluck('name', 'id')->all())),
                $this->chart('stores', 'CA net par magasin', 'store', $this->groupBy('store_id', $stores->map->name->all())),
            ],
            'lists' => [[
                'key' => 'stores',
                'title' => 'Magasins',
                'columns' => ['Enseigne', 'Magasin', 'CA net', 'CA N-1', 'Tickets', 'Panier moyen', 'Clients', 'Identification %'],
                'rows' => $rows->map(fn ($r) => [
                    $stores[$r->k]->brand?->name, $stores[$r->k]->name, (int) $r->net, (int) ($year[$r->k]->net ?? 0), (int) $r->tickets,
                    $r->tickets > 0 ? (int) round($r->sales / $r->tickets) : 0, (int) $r->buyers, $r->tickets > 0 ? (int) round($r->identified / $r->tickets * 100) : 0,
                ])->all(),
            ]],
        ];
    }

    private function families(): array
    {
        $names = Category::where('organization_id', $this->organization->id)->pluck('name', 'id');
        $now = $this->groupStats('category_id', $this->from, $this->to);
        $year = $this->groupStats('category_id', ...$this->lastYearPeriod());
        $unclassified = 'Sans famille';

        return [
            'kpis' => $this->kpis(['net', 'basket'], $this->headline($this->from, $this->to), $this->headline(...$this->previousPeriod()), $this->headline(...$this->lastYearPeriod())),
            'charts' => [$this->chart('families', 'CA net par famille', 'family', $this->groupBy('category_id', $names->all()))],
            'lists' => [[
                'key' => 'families',
                'title' => 'Familles',
                'columns' => ['Famille', 'CA net', 'CA N-1', 'Tickets', 'Clients'],
                'rows' => $now->sortByDesc('net')->values()->map(fn ($r) => [$names[$r->k] ?? $unclassified, (int) $r->net, (int) ($year[$r->k]->net ?? 0), (int) $r->tickets, (int) $r->buyers])->all(),
                'audiences' => $now->sortByDesc('net')->values()->map(fn ($r) => $r->k ? ['family' => (int) $r->k, 'family_days' => 0] : null)->all(),
            ]],
        ];
    }

    private function quality(): array
    {
        $data = $this->analytics->quality();
        $kpis = $data['kpis'];
        $customers = Customer::where('organization_id', $this->organization->id);
        $total = max(1, (clone $customers)->count());
        $tile = fn (string $label, int $value, string $unit = 'n') => ['label' => $label, 'value' => $value, 'unit' => $unit, 'previous' => null, 'last_year' => null, 'inverted' => false];
        $filled = fn (string $column) => (int) round((clone $customers)->whereNotNull($column)->count() / $total * 100);

        return [
            'kpis' => [
                $tile('Imports', $kpis['batches']),
                $tile('Lignes rejetées', $kpis['rejected']),
                $tile('Doublons écartés', $kpis['duplicates']),
                $tile('Tickets anonymes', $kpis['anonymous_share'], '%'),
                $tile('Lignes sans famille', $kpis['unclassified_share'], '%'),
                $tile('Rapprochements à vérifier', $kpis['pending_reviews']),
                $tile('Fiches avec sexe', $filled('gender'), '%'),
                $tile('Fiches avec date de naissance', $filled('birthdate'), '%'),
            ],
            'charts' => [],
            'lists' => $data['tables'],
        ];
    }

    /**
     * Rétention par cohorte : part des clients d'un mois de premier achat
     * revenus 1, 2, 3 et 6 mois plus tard.
     *
     * @return array<string, mixed>
     */
    private function cohorts(): array
    {
        $start = $this->from->copy()->startOfMonth();
        $visits = $this->lines($start, now())->whereNotNull('customer_id')->where('l.type', '!=', 'return')
            ->join('customers', 'customers.id', '=', 'l.customer_id')
            ->whereRaw('substr(customers.first_purchase_at, 1, 7) >= ?', [$start->format('Y-m')])
            ->selectRaw('DISTINCT l.customer_id as id, substr(customers.first_purchase_at, 1, 7) as cohort, substr(l.day, 1, 7) as month')
            ->get()->groupBy('cohort');

        $rows = $visits->sortKeys()->map(function ($lines, string $cohort) {
            $size = $lines->pluck('id')->unique()->count();
            $base = Carbon::parse("{$cohort}-01");
            $rates = array_map(function (int $offset) use ($lines, $base, $size) {
                $month = $base->copy()->addMonthsNoOverflow($offset);

                return $month->isFuture() ? '—' : (int) round($lines->where('month', $month->format('Y-m'))->pluck('id')->unique()->count() / $size * 100).' %';
            }, self::COHORT_OFFSETS);

            return [RetailAnalytics::monthLabel($cohort), $size, ...$rates];
        })->values()->all();

        return ['key' => 'cohorts', 'title' => 'Cohortes : clients revenus après leur premier achat', 'columns' => ['Premier achat', 'Clients', ...array_map(fn ($n) => "M+{$n}", self::COHORT_OFFSETS)], 'rows' => $rows];
    }

    /** @return list<array<string, mixed>> */
    private function segmentList($segments, callable $label, int $listRows): array
    {
        return [[
            'key' => 'segments',
            'title' => 'Segments RFM',
            'columns' => ['Segment', 'Clients', 'CA net'],
            'rows' => $segments->sortByDesc('net')->values()->map(fn ($r) => [$label($r->k), (int) $r->total, (int) $r->net])->take($listRows)->all(),
            'audiences' => $segments->sortByDesc('net')->values()->map(fn ($r) => $this->audience(['segment' => $r->k]))->take($listRows)->all(),
        ]];
    }

    /** @return array<string, mixed> */
    private function cardList(string $status, string $title, int $limit): array
    {
        $search = new CustomerSearch($this->user, $this->audience(['loyalty_status' => $status]));
        $rows = $search->loyaltyCards()
            ->join('customers', 'customers.id', '=', 'loyalty_cards.customer_id')
            ->join('brands', 'brands.id', '=', 'loyalty_cards.brand_id')
            ->whereIn('loyalty_cards.brand_id', $this->brandIds ?: [0])
            ->when($this->customers, fn ($q) => $q->whereIn('loyalty_cards.customer_id', clone $this->customers))
            ->orderBy($status === 'near_threshold' ? 'loyalty_cards.amount_to_threshold' : ($status === 'near_tier' ? 'loyalty_cards.points_to_next_tier' : 'loyalty_cards.expires_on'))
            ->limit($limit)
            ->get(['customers.name', 'customers.phone_e164', 'brands.name as brand', 'loyalty_cards.points', 'loyalty_cards.amount_to_threshold', 'loyalty_cards.points_to_next_tier', 'loyalty_cards.expires_on']);

        $detail = match ($status) {
            'near_threshold' => ['Reste à acheter', fn ($r) => (int) $r->amount_to_threshold],
            'near_tier' => ['Points manquants', fn ($r) => (int) $r->points_to_next_tier],
            default => ['Expire le', fn ($r) => Carbon::parse($r->expires_on)->format('d/m/Y')],
        };

        return [
            'key' => $status,
            'title' => $title,
            'columns' => ['Client', 'Téléphone', 'Enseigne', 'Points', $detail[0]],
            'rows' => $rows->map(fn ($r) => [$r->name, $r->phone_e164, $r->brand, (int) $r->points, $detail[1]($r)])->all(),
            'audience' => $this->audience(['loyalty_status' => $status]),
            'personal' => true,
        ];
    }

    /**
     * Filtres Fiches clients correspondant à l'écran, plus ceux de la liste.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function audience(array $extra): array
    {
        return array_filter([
            'loyalty_brand' => $this->brandId,
            'segment' => $this->customerFilters['segment'] ?? null,
            'loyalty_status' => $this->customerFilters['loyalty_status'] ?? null,
            ...$extra,
        ]);
    }

    private function lines(Carbon $from, Carbon $to): Builder
    {
        return $this->analytics->lines($from, $to)
            ->when($this->customers, fn ($q) => $q->whereIn('l.customer_id', clone $this->customers));
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function previousPeriod(): array
    {
        return [$this->from->copy()->subDays($this->days), $this->from->copy()->subDay()];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function lastYearPeriod(): array
    {
        return [$this->from->copy()->subYear(), $this->to->copy()->subYear()];
    }

    /** @return array<string, int|float> */
    private function headline(Carbon $from, Carbon $to): array
    {
        $totals = $this->lines($from, $to)->selectRaw("
            COALESCE(SUM(amount), 0) as net,
            COALESCE(SUM(CASE WHEN type = 'return' THEN 0 ELSE amount END), 0) as sales,
            COUNT(DISTINCT CASE WHEN type = 'return' THEN NULL ELSE purchase_id END) as tickets,
            COUNT(DISTINCT CASE WHEN type <> 'return' AND customer_id IS NOT NULL THEN purchase_id END) as identified
        ")->first();

        $buyers = $this->buyers($from, $to);
        $earlier = $this->buyers($from->copy()->subDays((int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1), $from->copy()->subDay());
        $count = $buyers->count();
        $new = $this->lines($from, $to)->join('customers', 'customers.id', '=', 'l.customer_id')
            ->whereRaw('substr(customers.first_purchase_at, 1, 10) >= ?', [$from->toDateString()])
            ->distinct()->count('l.customer_id');

        return [
            'net' => (int) $totals->net,
            'tickets' => (int) $totals->tickets,
            'basket' => $totals->tickets > 0 ? (int) round($totals->sales / $totals->tickets) : 0,
            'buyers' => $count,
            'new' => $new,
            'lost' => $earlier->keys()->diff($buyers->keys())->count(),
            'identification' => $totals->tickets > 0 ? (int) round($totals->identified / $totals->tickets * 100) : 0,
            'repeat' => $count > 0 ? (int) round($buyers->filter(fn ($b) => $b->tickets >= 2)->count() / $count * 100) : 0,
            'frequency' => $count > 0 ? round($buyers->sum('tickets') / $count, 1) : 0,
            'value' => $count > 0 ? (int) round($buyers->sum('net') / $count) : 0,
        ];
    }

    private function buyers(Carbon $from, Carbon $to)
    {
        return $this->lines($from, $to)->whereNotNull('customer_id')->groupBy('customer_id')
            ->selectRaw("customer_id, SUM(amount) as net, COUNT(DISTINCT CASE WHEN type = 'return' THEN NULL ELSE purchase_id END) as tickets")
            ->get()->keyBy('customer_id');
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function kpis(array $keys, array $now, array $previous, array $lastYear): array
    {
        $definitions = [
            'net' => ['CA net', 'fcfa'], 'tickets' => ['Tickets', 'n'], 'basket' => ['Panier moyen', 'fcfa'], 'buyers' => ['Clients actifs', 'n'],
            'new' => ['Nouveaux clients', 'n'], 'lost' => ['Clients perdus', 'n'], 'identification' => ['Taux d’identification', '%'],
            'repeat' => ['Taux de réachat', '%'], 'frequency' => ['Fréquence d’achat', 'x'], 'value' => ['Valeur client', 'fcfa'],
        ];

        return array_map(fn (string $key) => [
            'label' => $definitions[$key][0],
            'value' => $now[$key],
            'unit' => $definitions[$key][1],
            'previous' => $previous[$key],
            'last_year' => $lastYear[$key],
            'inverted' => $key === 'lost',
        ], $keys);
    }

    /** @return list<array{month: string, label: string, net: int, last_year: int}> */
    private function monthly(): array
    {
        $byMonth = fn (Carbon $from, Carbon $to) => $this->lines($from, $to)->selectRaw('substr(day, 1, 7) as month, COALESCE(SUM(amount), 0) as net')->groupBy('month')->pluck('net', 'month');
        [$yearFrom, $yearTo] = $this->lastYearPeriod();
        $current = $byMonth($this->from, $this->to);
        $last = $byMonth($yearFrom, $yearTo);
        $rows = [];
        $cursor = $this->from->copy()->startOfMonth();

        while ($cursor->lte($this->to)) {
            $month = $cursor->format('Y-m');
            $rows[] = ['month' => $month, 'label' => RetailAnalytics::monthLabel($month), 'net' => (int) ($current[$month] ?? 0), 'last_year' => (int) ($last[$cursor->copy()->subYear()->format('Y-m')] ?? 0)];
            $cursor->addMonthNoOverflow();
        }

        return $rows;
    }

    /**
     * @param  array<int|string, string>  $names
     * @return list<array{id: int|string|null, label: string, value: int}>
     */
    private function groupBy(string $column, array $names): array
    {
        return $this->lines($this->from, $this->to)->selectRaw("{$column} as k, COALESCE(SUM(amount), 0) as net")->groupBy($column)->orderByDesc('net')->get()
            ->map(fn ($r) => ['id' => $r->k, 'label' => $names[$r->k] ?? 'Sans famille', 'value' => (int) $r->net])->all();
    }

    private function groupStats(string $column, Carbon $from, Carbon $to)
    {
        return $this->lines($from, $to)->groupBy($column)->selectRaw("
            {$column} as k,
            COALESCE(SUM(amount), 0) as net,
            COALESCE(SUM(CASE WHEN type = 'return' THEN 0 ELSE amount END), 0) as sales,
            COUNT(DISTINCT CASE WHEN type = 'return' THEN NULL ELSE purchase_id END) as tickets,
            COUNT(DISTINCT CASE WHEN type <> 'return' AND customer_id IS NOT NULL THEN purchase_id END) as identified,
            COUNT(DISTINCT customer_id) as buyers
        ")->get()->keyBy('k');
    }

    /** @return array<string, mixed> */
    private function chart(string $key, string $title, ?string $filter, array $items): array
    {
        return ['key' => $key, 'title' => $title, 'filter' => $filter, 'unit' => 'fcfa', 'items' => $items];
    }
}
