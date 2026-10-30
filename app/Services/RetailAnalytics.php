<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerMatchReview;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tableaux de bord Menentia Retail : Direction, Marques & points de vente,
 * Familles (au niveau d'analyse de l'entreprise), Clientèle, Rétention et
 * Qualité des imports, sur un même jeu de filtres (période, points de vente
 * du périmètre, marque, famille).
 *
 * Source unique : les lignes de ticket valides (purchase_items, ou le ticket
 * lui-même s'il n'a pas de ligne), ventes anonymes comprises. CA net =
 * ventes − retours − remises (les montants des lignes sont déjà remisés,
 * ceux des retours négatifs). Panier moyen = CA des ventes / tickets.
 *
 * Chaque tableau est rendu sous une forme unique (titre, colonnes, lignes)
 * utilisée par l'écran, les exports CSV / Excel et le rapport de direction.
 */
class RetailAnalytics
{
    /**
     * @param  array<int, int>  $storeIds  points de vente retenus (périmètre ∩ marque ∩ point de vente)
     * @param  array<int, int>|null  $categoryIds  famille choisie (et ses sous-familles), null = toutes
     */
    public function __construct(
        private Organization $organization,
        private array $storeIds,
        private Carbon $from,
        private Carbon $to,
        private ?array $categoryIds = null,
        private int $activeDays = 90,
    ) {}

    /**
     * Lignes de vente valides de la période : purchase_id, store_id,
     * brand_id, customer_id (null = anonyme), day, amount, quantity,
     * discount, type, category_id, article_id.
     */
    public function lines(?Carbon $from = null, ?Carbon $to = null): Builder
    {
        $from ??= $this->from;
        $to ??= $this->to;

        $common = function (Builder $q) use ($from, $to) {
            $q->join('stores', 'stores.id', '=', 'purchases.store_id')
                ->leftJoin('contacts', 'contacts.id', '=', 'purchases.contact_id')
                ->whereIn('purchases.store_id', $this->storeIds ?: [0])
                ->where('purchases.status', Purchase::STATUS_VALID)
                ->whereDate('purchases.purchased_at', '>=', $from->toDateString())
                ->whereDate('purchases.purchased_at', '<=', $to->toDateString());
        };

        $itemized = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->leftJoin('catalog_items', 'catalog_items.id', '=', 'purchase_items.catalog_item_id')
            ->tap($common)
            ->selectRaw('purchases.id as purchase_id, purchases.store_id as store_id, stores.brand_id as brand_id, contacts.customer_id as customer_id, substr(purchases.purchased_at, 1, 10) as day, purchase_items.amount as amount, purchase_items.quantity as quantity, purchase_items.discount_amount as discount, purchases.type as type, COALESCE(purchase_items.category_id, catalog_items.category_id) as category_id, purchase_items.article_id as article_id');

        $bare = DB::table('purchases')
            ->tap($common)
            ->whereNotExists(fn ($q) => $q->from('purchase_items')->whereColumn('purchase_items.purchase_id', 'purchases.id'))
            ->selectRaw('purchases.id as purchase_id, purchases.store_id as store_id, stores.brand_id as brand_id, contacts.customer_id as customer_id, substr(purchases.purchased_at, 1, 10) as day, purchases.amount as amount, purchases.quantity as quantity, purchases.discount_amount as discount, purchases.type as type, purchases.category_id as category_id, NULL as article_id');

        return DB::query()->fromSub($itemized->unionAll($bare), 'l')
            ->when($this->categoryIds !== null, fn ($q) => $q->whereIn('l.category_id', $this->categoryIds ?: [0]));
    }

    /** Agrégats d'une période. @return array<string, int> */
    public function totals(?Carbon $from = null, ?Carbon $to = null): array
    {
        $row = $this->lines($from, $to)->selectRaw("
            COALESCE(SUM(amount), 0) as net,
            COALESCE(SUM(CASE WHEN type = 'return' THEN -amount ELSE 0 END), 0) as returns,
            COALESCE(SUM(CASE WHEN type = 'return' THEN 0 ELSE amount END), 0) as sales,
            COALESCE(SUM(discount), 0) as discounts,
            COUNT(DISTINCT CASE WHEN type = 'return' THEN NULL ELSE purchase_id END) as tickets,
            COUNT(DISTINCT CASE WHEN type <> 'return' AND customer_id IS NOT NULL THEN purchase_id END) as identified_tickets,
            COALESCE(SUM(CASE WHEN type = 'return' THEN 0 ELSE quantity END), 0) as units,
            COUNT(DISTINCT customer_id) as customers
        ")->first();

        $tickets = (int) $row->tickets;

        return [
            'gross' => (int) $row->sales + (int) $row->discounts,
            'discounts' => (int) $row->discounts,
            'returns' => (int) $row->returns,
            'net' => (int) $row->net,
            'tickets' => $tickets,
            'units' => (int) $row->units,
            'basket' => $tickets > 0 ? (int) round($row->sales / $tickets) : 0,
            'customers' => (int) $row->customers,
            'identification_rate' => $tickets > 0 ? (int) round($row->identified_tickets / $tickets * 100) : 0,
        ];
    }

    /** Vue Direction : chiffres clés, comparaison des marques, évolution mensuelle. */
    public function direction(): array
    {
        $current = $this->totals();
        $days = (int) $this->from->diffInDays($this->to) + 1;
        $previous = $this->totals($this->from->copy()->subDays($days), $this->from->copy()->subDay());

        $current['new_customers'] = $this->newCustomersCount();
        $current['active_customers'] = $this->activeCustomersCount();

        return [
            'kpis' => $current,
            'previous' => $previous,
            'active_days' => $this->activeDays,
            'monthly' => $this->monthly(),
            'tables' => [$this->brandsTable(), $this->monthlyTable()],
        ];
    }

    /** Marques et points de vente : classement et contribution au CA. */
    public function network(): array
    {
        return ['tables' => [$this->brandsTable(), $this->storesTable()]];
    }

    /** Familles au niveau d'analyse de l'entreprise (article, famille ou catégorie). */
    public function families(): array
    {
        $level = $this->organization->analysisLevel();
        $key = match ($level) {
            'product' => 'l.article_id',
            'category' => 'COALESCE(dim.parent_id, l.category_id)',
            default => 'l.category_id',
        };

        $rows = $this->lines()
            ->when($level === 'category', fn ($q) => $q->leftJoin('categories as dim', 'dim.id', '=', 'l.category_id'))
            ->selectRaw("{$key} as k, SUM(l.amount) as net, SUM(CASE WHEN l.type = 'return' THEN 0 ELSE l.quantity END) as units, COUNT(DISTINCT l.purchase_id) as tickets, COUNT(DISTINCT l.customer_id) as customers")
            ->groupByRaw($key)
            ->get();

        $names = FamilyAnalytics::dimensionItems($this->organization->id, $level)->pluck('name', 'id');
        $total = max(1, (int) $rows->sum('net'));
        $word = Organization::ANALYSIS_LEVELS[$level];

        return [
            'level' => $level,
            'tables' => [[
                'key' => 'families',
                'title' => "Ventes par {$word}",
                'columns' => [$word, 'CA net', 'Part', 'Unités', 'Tickets', 'Clients identifiés'],
                'rows' => $rows->sortByDesc('net')->take(200)->map(fn ($r) => [
                    $r->k !== null ? (string) ($names[$r->k] ?? '—') : ($level === 'product' ? 'Sans article' : 'À classer'),
                    (int) $r->net,
                    round($r->net / $total * 100, 1).' %',
                    (int) $r->units,
                    (int) $r->tickets,
                    (int) $r->customers,
                ])->values()->all(),
                'links' => $rows->sortByDesc('net')->take(200)->map(fn ($r) => $r->k !== null ? '/customers?'.($level === 'product' ? "article={$r->k}" : "family={$r->k}") : null)->values()->all(),
            ]],
        ];
    }

    /** Clientèle : nouveaux / récurrents, mono / multimarques, particuliers / entreprises, meilleurs clients. */
    public function clientele(): array
    {
        $buyers = $this->lines()->whereNotNull('customer_id')
            ->selectRaw("customer_id, SUM(amount) as net, COUNT(DISTINCT CASE WHEN type = 'return' THEN NULL ELSE purchase_id END) as tickets, COUNT(DISTINCT brand_id) as brands")
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        $firsts = $this->firstPurchases($buyers->keys()->all());
        $customers = Customer::whereKey($buyers->keys())->get(['id', 'name', 'company_name', 'type', 'phone_e164'])->keyBy('id');
        $isNew = fn ($id) => isset($firsts[$id]) && $firsts[$id] >= $this->from->toDateString();

        $segment = fn (string $label, Collection $rows) => [$label, $rows->count(), (int) $rows->sum('net'), $rows->count() > 0 ? (int) round($rows->sum('net') / max(1, $rows->sum('tickets'))) : 0];

        $new = $buyers->filter(fn ($r) => $isNew($r->customer_id));
        $returning = $buyers->reject(fn ($r) => $isNew($r->customer_id));
        $multi = $buyers->where('brands', '>=', 2);
        $mono = $buyers->where('brands', '<', 2);
        $business = $buyers->filter(fn ($r) => ($customers->get($r->customer_id)?->type) === Customer::TYPE_BUSINESS);
        $persons = $buyers->reject(fn ($r) => ($customers->get($r->customer_id)?->type) === Customer::TYPE_BUSINESS);

        $top = $buyers->sortByDesc('net')->take(20);

        return [
            'kpis' => [
                'buyers' => $buyers->count(),
                'new' => $new->count(),
                'returning' => $returning->count(),
                'multi_brand' => $multi->count(),
                'business' => $business->count(),
            ],
            'tables' => [
                [
                    'key' => 'segments',
                    'title' => 'Clients identifiés de la période',
                    'columns' => ['Segment', 'Clients', 'CA net', 'Panier moyen'],
                    'rows' => [
                        $segment('Nouveaux clients (1er achat dans la période)', $new),
                        $segment('Clients récurrents', $returning),
                        $segment('Mono-marque', $mono),
                        $segment('Multimarques (2 marques ou plus)', $multi),
                        $segment('Particuliers', $persons),
                        $segment('Entreprises', $business),
                    ],
                ],
                [
                    'key' => 'top_customers',
                    'title' => 'Meilleurs clients',
                    'personal' => true,
                    'columns' => ['Client', 'Téléphone', 'Type', 'CA net', 'Tickets', 'Marques'],
                    'rows' => $top->map(fn ($r) => [
                        $customers->get($r->customer_id)?->displayName() ?? '—',
                        $customers->get($r->customer_id)?->phone_e164 ?? '',
                        ($customers->get($r->customer_id)?->type) === Customer::TYPE_BUSINESS ? 'Entreprise' : 'Particulier',
                        (int) $r->net,
                        (int) $r->tickets,
                        (int) $r->brands,
                    ])->values()->all(),
                    'links' => $top->map(fn ($r) => "/customers/{$r->customer_id}")->values()->all(),
                ],
            ],
        ];
    }

    /** Rétention : réachat, délai entre deux achats, cohortes mensuelles. */
    public function retention(): array
    {
        $days = $this->lines()->whereNotNull('customer_id')->where('type', '!=', Purchase::TYPE_RETURN)
            ->selectRaw('customer_id, day')->distinct()->get()->groupBy('customer_id');

        $buyers = $days->count();
        $repeaters = $days->filter(fn ($rows) => $rows->count() >= 2);
        $intervals = $repeaters->map(function ($rows) {
            $sorted = $rows->pluck('day')->sort()->values();

            return Carbon::parse($sorted->first())->diffInDays(Carbon::parse($sorted->last())) / ($sorted->count() - 1);
        });

        return [
            'kpis' => [
                'buyers' => $buyers,
                'repeat_rate' => $buyers > 0 ? (int) round($repeaters->count() / $buyers * 100) : 0,
                'average_interval' => $intervals->isNotEmpty() ? (int) round($intervals->avg()) : null,
            ],
            'tables' => [$this->cohortsTable()],
        ];
    }

    /** Qualité des imports : volumes, rejets, doublons, ventes anonymes, lignes à classer, rapprochements en attente. */
    public function quality(): array
    {
        $batches = ImportBatch::where('organization_id', $this->organization->id)
            ->whereBetween('created_at', [$this->from->copy()->startOfDay(), $this->to->copy()->endOfDay()])
            ->where(fn ($q) => $q->whereNull('store_id')->orWhereIn('store_id', $this->storeIds ?: [0]))
            ->orderByDesc('id')
            ->get();

        $lineStats = $this->lines()->selectRaw('COUNT(*) as lines_count, SUM(CASE WHEN category_id IS NULL THEN 1 ELSE 0 END) as unclassified, COUNT(DISTINCT purchase_id) as tickets, COUNT(DISTINCT CASE WHEN customer_id IS NULL THEN purchase_id END) as anonymous')->first();
        $tickets = max(1, (int) $lineStats->tickets);

        $codes = DB::table('import_rows')->whereIn('import_batch_id', $batches->pluck('id')->all() ?: [0])->whereNotNull('error_code')
            ->selectRaw('error_code, COUNT(*) as total')->groupBy('error_code')->pluck('total', 'error_code');

        return [
            'kpis' => [
                'batches' => $batches->count(),
                'rows' => (int) $batches->sum('total_rows'),
                'rejected' => (int) $batches->sum('rejected_rows'),
                'duplicates' => (int) $batches->sum('duplicate_rows'),
                'anonymous_share' => (int) round($lineStats->anonymous / $tickets * 100),
                'unclassified_share' => (int) round($lineStats->unclassified / max(1, (int) $lineStats->lines_count) * 100),
                'pending_reviews' => CustomerMatchReview::where('organization_id', $this->organization->id)->where('status', CustomerMatchReview::STATUS_PENDING)
                    ->whereIn('store_id', $this->storeIds ?: [0])->count(),
            ],
            'tables' => [
                [
                    'key' => 'batches',
                    'title' => 'Imports de la période',
                    'columns' => ['Import', 'Déposé le', 'Statut', 'Lignes', 'Rejets', 'Doublons', 'Tickets', 'Anonymes', 'À classer'],
                    'rows' => $batches->map(fn (ImportBatch $b) => [
                        "n° {$b->id} · {$b->original_filename}",
                        $b->created_at->format('d/m/Y H:i'),
                        $b->statusLabel(),
                        $b->total_rows, $b->rejected_rows, $b->duplicate_rows, $b->tickets_count, $b->anonymous_tickets, $b->unclassified_lines,
                    ])->values()->all(),
                    'links' => $batches->map(fn (ImportBatch $b) => "/sales-imports/{$b->id}")->values()->all(),
                ],
                [
                    'key' => 'errors',
                    'title' => 'Motifs de rejet',
                    'columns' => ['Motif', 'Lignes'],
                    'rows' => $codes->map(fn ($total, $code) => [SalesImport::ERRORS[$code] ?? $code, (int) $total])->values()->all(),
                ],
            ],
        ];
    }

    /** @return list<array{month: string, net: int, tickets: int, customers: int}> */
    public function monthly(): array
    {
        return $this->lines()
            ->selectRaw("substr(day, 1, 7) as month, SUM(amount) as net, COUNT(DISTINCT CASE WHEN type = 'return' THEN NULL ELSE purchase_id END) as tickets, COUNT(DISTINCT customer_id) as customers")
            ->groupByRaw('substr(day, 1, 7)')
            ->orderBy('month')
            ->get()
            ->map(fn ($r) => ['month' => (string) $r->month, 'net' => (int) $r->net, 'tickets' => (int) $r->tickets, 'customers' => (int) $r->customers])
            ->all();
    }

    private function monthlyTable(): array
    {
        return [
            'key' => 'monthly',
            'title' => 'Évolution mensuelle',
            'columns' => ['Mois', 'CA net', 'Tickets', 'Clients identifiés'],
            'rows' => array_map(fn ($m) => [self::monthLabel($m['month']), $m['net'], $m['tickets'], $m['customers']], $this->monthly()),
        ];
    }

    private function brandsTable(): array
    {
        $rows = $this->lines()
            ->selectRaw("brand_id, SUM(amount) as net, COUNT(DISTINCT CASE WHEN type = 'return' THEN NULL ELSE purchase_id END) as tickets, SUM(CASE WHEN type = 'return' THEN 0 ELSE amount END) as sales, COUNT(DISTINCT customer_id) as customers")
            ->groupBy('brand_id')
            ->get();
        $names = Brand::whereKey($rows->pluck('brand_id'))->pluck('name', 'id');
        $total = max(1, (int) $rows->sum('net'));

        return [
            'key' => 'brands',
            'title' => 'Comparaison des marques',
            'columns' => ['Marque', 'CA net', 'Part du CA', 'Tickets', 'Panier moyen', 'Clients identifiés'],
            'rows' => $rows->sortByDesc('net')->map(fn ($r) => [
                (string) ($names[$r->brand_id] ?? '—'),
                (int) $r->net,
                round($r->net / $total * 100, 1).' %',
                (int) $r->tickets,
                $r->tickets > 0 ? (int) round($r->sales / $r->tickets) : 0,
                (int) $r->customers,
            ])->values()->all(),
        ];
    }

    private function storesTable(): array
    {
        $rows = $this->lines()
            ->selectRaw("store_id, SUM(amount) as net, COUNT(DISTINCT CASE WHEN type = 'return' THEN NULL ELSE purchase_id END) as tickets, SUM(CASE WHEN type = 'return' THEN 0 ELSE amount END) as sales, COUNT(DISTINCT customer_id) as customers, COUNT(DISTINCT CASE WHEN type <> 'return' AND customer_id IS NOT NULL THEN purchase_id END) as identified, SUM(CASE WHEN type = 'return' THEN -amount ELSE 0 END) as returns")
            ->groupBy('store_id')
            ->get();
        $stores = Store::with('brand:id,name')->whereKey($rows->pluck('store_id'))->get()->keyBy('id');
        $total = max(1, (int) $rows->sum('net'));

        return [
            'key' => 'stores',
            'title' => 'Classement des points de vente',
            'columns' => ['Point de vente', 'Marque', 'Ville', 'CA net', 'Part du CA', 'Tickets', 'Panier moyen', 'Retours', 'Clients identifiés', 'Tickets identifiés'],
            'rows' => $rows->sortByDesc('net')->map(fn ($r) => [
                (string) ($stores->get($r->store_id)?->name ?? '—'),
                (string) ($stores->get($r->store_id)?->brand?->name ?? ''),
                (string) ($stores->get($r->store_id)?->city ?? ''),
                (int) $r->net,
                round($r->net / $total * 100, 1).' %',
                (int) $r->tickets,
                $r->tickets > 0 ? (int) round($r->sales / $r->tickets) : 0,
                (int) $r->returns,
                (int) $r->customers,
                $r->tickets > 0 ? (int) round($r->identified / $r->tickets * 100).' %' : '—',
            ])->values()->all(),
        ];
    }

    /**
     * Cohortes mensuelles : clients par mois de premier achat (12 derniers
     * mois de la période), et part revenue acheter 1 à 6 mois plus tard.
     */
    private function cohortsTable(): array
    {
        $since = $this->to->copy()->startOfMonth()->subMonths(11);
        $all = DB::table('purchases')
            ->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
            ->whereIn('purchases.store_id', $this->storeIds ?: [0])
            ->where('purchases.status', Purchase::STATUS_VALID)
            ->where('purchases.type', '!=', Purchase::TYPE_RETURN)
            ->whereDate('purchases.purchased_at', '<=', $this->to->toDateString())
            ->selectRaw('contacts.customer_id as customer_id, substr(purchases.purchased_at, 1, 7) as month')
            ->distinct()
            ->get()
            ->groupBy('customer_id')
            ->map(fn ($rows) => $rows->pluck('month')->sort()->values());

        $cohorts = $all->filter(fn ($months) => $months->first() >= $since->format('Y-m'))->groupBy(fn ($months) => $months->first());
        $rows = [];

        foreach ($cohorts->sortKeys() as $month => $members) {
            $row = [self::monthLabel($month), $members->count()];

            for ($k = 1; $k <= 6; $k++) {
                $target = Carbon::parse($month.'-01')->addMonths($k)->format('Y-m');
                $row[] = $target > $this->to->format('Y-m')
                    ? ''
                    : (int) round($members->filter(fn ($months) => $months->contains($target))->count() / max(1, $members->count()) * 100).' %';
            }

            $rows[] = $row;
        }

        return [
            'key' => 'cohorts',
            'title' => 'Cohortes : clients revenus acheter après leur premier achat',
            'columns' => ['Premier achat', 'Clients', 'M+1', 'M+2', 'M+3', 'M+4', 'M+5', 'M+6'],
            'rows' => $rows,
        ];
    }

    private function newCustomersCount(): int
    {
        $firsts = DB::table('purchases')
            ->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
            ->whereIn('purchases.store_id', $this->storeIds ?: [0])
            ->where('purchases.status', Purchase::STATUS_VALID)
            ->where('purchases.type', '!=', Purchase::TYPE_RETURN)
            ->groupBy('contacts.customer_id')
            ->havingRaw('MIN(substr(purchases.purchased_at, 1, 10)) >= ?', [$this->from->toDateString()])
            ->havingRaw('MIN(substr(purchases.purchased_at, 1, 10)) <= ?', [$this->to->toDateString()])
            ->select('contacts.customer_id');

        return DB::query()->fromSub($firsts, 'f')->count();
    }

    private function activeCustomersCount(): int
    {
        return (int) $this->lines($this->to->copy()->subDays($this->activeDays - 1), $this->to)
            ->whereNotNull('customer_id')->where('type', '!=', Purchase::TYPE_RETURN)
            ->selectRaw('COUNT(DISTINCT customer_id) as n')->value('n');
    }

    /**
     * Premier achat (dans le périmètre) de chaque client.
     *
     * @param  array<int, int>  $customerIds
     * @return array<int, string>
     */
    private function firstPurchases(array $customerIds): array
    {
        $firsts = [];

        foreach (array_chunk($customerIds, 500) as $chunk) {
            DB::table('purchases')
                ->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
                ->whereIn('contacts.customer_id', $chunk)
                ->whereIn('purchases.store_id', $this->storeIds ?: [0])
                ->where('purchases.status', Purchase::STATUS_VALID)
                ->where('purchases.type', '!=', Purchase::TYPE_RETURN)
                ->groupBy('contacts.customer_id')
                ->selectRaw('contacts.customer_id as id, MIN(substr(purchases.purchased_at, 1, 10)) as first_at')
                ->get()
                ->each(function ($r) use (&$firsts) {
                    $firsts[$r->id] = (string) $r->first_at;
                });
        }

        return $firsts;
    }

    public static function monthLabel(string $month): string
    {
        $names = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
        [$y, $m] = explode('-', $month) + [null, '1'];

        return ($names[(int) $m - 1] ?? $m).' '.$y;
    }
}
