<?php

namespace App\Services;

use App\Models\Category;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Comportement d'achat par famille de produits (catégorie).
 *
 * Source unique, des « lignes » (client, boutique, famille, montant, date) :
 * - vente importée sans ligne : famille reconnue par FamilyMatcher ;
 * - vente encaissée dans l'app : chaque ligne prend la famille de son
 *   article du catalogue.
 * Une visite = un client, un jour. Le panier moyen se calcule par visite,
 * pour qu'un ticket importé en plusieurs lignes ne compte qu'une fois.
 */
class FamilyAnalytics
{
    /**
     * @param  array<int, int>  $storeIds  boutiques du périmètre
     */
    public function __construct(private array $storeIds) {}

    /**
     * Lignes d'achat du périmètre : customer_id, store_id, category_id, amount, day, purchase_id,
     * properties (attributs JSON de la vente importée ou de l'article du catalogue).
     */
    public function lines(?CarbonInterface $from = null, ?CarbonInterface $to = null): Builder
    {
        $period = function (Builder $q) use ($from, $to) {
            $q->when($from, fn ($q) => $q->whereDate('purchases.purchased_at', '>=', $from->toDateString()))
                ->when($to, fn ($q) => $q->whereDate('purchases.purchased_at', '<=', $to->toDateString()));
        };

        $imported = DB::table('purchases')
            ->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
            ->whereIn('purchases.store_id', $this->storeIds)
            ->whereNotExists(fn ($q) => $q->from('purchase_items')->whereColumn('purchase_items.purchase_id', 'purchases.id'))
            ->tap($period)
            ->selectRaw('contacts.customer_id as customer_id, purchases.store_id as store_id, purchases.category_id as category_id, purchases.amount as amount, date(purchases.purchased_at) as day, purchases.id as purchase_id, purchases.properties as properties');

        $itemized = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
            ->leftJoin('catalog_items', 'catalog_items.id', '=', 'purchase_items.catalog_item_id')
            ->whereIn('purchases.store_id', $this->storeIds)
            ->tap($period)
            ->selectRaw('contacts.customer_id as customer_id, purchases.store_id as store_id, catalog_items.category_id as category_id, purchase_items.amount as amount, date(purchases.purchased_at) as day, purchases.id as purchase_id, catalog_items.properties as properties');

        return DB::query()->fromSub($imported->unionAll($itemized), 'lines')->whereNotNull('lines.customer_id');
    }

    /**
     * Tableau des familles sur la période : clients, CA, part du CA, panier
     * moyen par visite, taux de réachat (clients venus au moins 2 jours
     * différents pour la famille) et clients qui l'ont abandonnée : plus
     * d'achat dans la famille depuis $lapseDays jours alors qu'ils achètent
     * encore autre chose.
     *
     * @param  Collection<int, Category>  $categories
     * @return array{rows: list<array<string, mixed>>, total: int, unclassified: int, customers: int}
     */
    public function overview(Collection $categories, ?CarbonInterface $from, CarbonInterface $to, int $lapseDays): array
    {
        $perCustomer = $this->lines($from, $to)
            ->selectRaw('category_id, customer_id, sum(amount) as amount, count(distinct day) as days')
            ->groupBy('category_id', 'customer_id')
            ->get()
            ->groupBy(fn ($row) => (int) $row->category_id);

        $lapsed = $this->lapsedCounts($lapseDays, $to);
        $total = (int) $perCustomer->flatten(1)->sum('amount');

        $rows = $categories->map(function (Category $category) use ($perCustomer, $lapsed, $total) {
            $rows = $perCustomer->get($category->id, collect());
            $customers = $rows->count();
            $amount = (int) $rows->sum('amount');
            // Visites de la famille = jours d'achat distincts de chaque client.
            $visitCount = (int) $rows->sum('days');

            return [
                'id' => $category->id,
                'name' => $category->name,
                'customers' => $customers,
                'amount' => $amount,
                'share' => $total > 0 ? round($amount / $total * 100, 1) : 0.0,
                'average_basket' => $visitCount > 0 ? (int) round($amount / $visitCount) : 0,
                'repeat_rate' => $customers > 0 ? (int) round($rows->where('days', '>=', 2)->count() / $customers * 100) : 0,
                'lapsed' => (int) ($lapsed[$category->id] ?? 0),
            ];
        })->sortByDesc('amount')->values()->all();

        return [
            'rows' => $rows,
            'total' => $total,
            'unclassified' => (int) $perCustomer->get(0, collect())->sum('amount'),
            'customers' => (int) $perCustomer->flatten(1)->pluck('customer_id')->unique()->count(),
        ];
    }

    /**
     * Clients qui ont arrêté chaque famille (cf. overview), toutes dates confondues.
     *
     * @return Collection<int, int> famille => nombre de clients
     */
    public function lapsedCounts(int $lapseDays, CarbonInterface $today): Collection
    {
        $since = $today->copy()->subDays($lapseDays)->toDateString();

        $lastAny = $this->lines()->selectRaw('customer_id, max(day) as last_day')->groupBy('customer_id')->pluck('last_day', 'customer_id');

        return $this->lines()
            ->whereNotNull('category_id')
            ->selectRaw('category_id, customer_id, max(day) as last_day')
            ->groupBy('category_id', 'customer_id')
            ->get()
            ->filter(fn ($row) => $row->last_day < $since && ($lastAny[$row->customer_id] ?? '') >= $since)
            ->countBy(fn ($row) => (int) $row->category_id);
    }

    /**
     * Achats liés : parmi les clients de la famille sur la période, part de
     * ceux qui achètent aussi chaque autre famille, et « indice » = cette
     * part rapportée à la part de tous les clients (au-dessus de 1 : lien
     * plus fort que la moyenne, bonne piste de vente croisée).
     *
     * @return list<array{id: int, name: string, customers: int, share: int, lift: float}>
     */
    public function affinities(Category $category, Collection $categories, ?CarbonInterface $from, CarbonInterface $to, int $limit = 8): array
    {
        $pairs = $this->lines($from, $to)->whereNotNull('category_id')->select('category_id', 'customer_id')->distinct()->get();
        $allCustomers = max(1, $pairs->pluck('customer_id')->unique()->count());
        $buyers = $pairs->where('category_id', $category->id)->pluck('customer_id')->flip();
        $base = max(1, $buyers->count());
        $names = $categories->pluck('name', 'id');

        return $pairs->where('category_id', '!=', $category->id)
            ->groupBy('category_id')
            ->map(function (Collection $rows, $categoryId) use ($buyers, $base, $allCustomers, $names) {
                $together = $rows->filter(fn ($row) => $buyers->has($row->customer_id))->count();
                $share = $together / $base;
                $overall = $rows->count() / $allCustomers;

                return [
                    'id' => (int) $categoryId,
                    'name' => (string) ($names[$categoryId] ?? '—'),
                    'customers' => $together,
                    'share' => (int) round($share * 100),
                    'lift' => $overall > 0 ? round($share / $overall, 1) : 0.0,
                ];
            })
            ->filter(fn ($row) => $row['customers'] > 0)
            ->sortByDesc(fn ($row) => [$row['customers'], $row['lift']])
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Habitudes d'un client : familles achetées (part de ses dépenses,
     * dernier achat), visites, panier moyen, nombre de familles.
     *
     * @param  Collection<int, string>  $names  famille => nom
     * @return array{visits: int, average_basket: int, families_count: int, total: int, families: list<array<string, mixed>>}
     */
    public function forCustomer(int $customerId, Collection $names): array
    {
        $lines = $this->lines()->where('customer_id', $customerId);

        $rows = (clone $lines)
            ->selectRaw('category_id, sum(amount) as amount, count(distinct day) as days, max(day) as last_day')
            ->groupBy('category_id')
            ->get();

        $total = (int) $rows->sum('amount');
        $visits = (int) (clone $lines)->selectRaw('count(distinct day) as visits')->value('visits');

        return [
            'visits' => $visits,
            'average_basket' => $visits > 0 ? (int) round($total / $visits) : 0,
            'families_count' => $rows->whereNotNull('category_id')->count(),
            'total' => $total,
            'families' => $rows->sortByDesc('amount')->map(fn ($row) => [
                'id' => $row->category_id !== null ? (int) $row->category_id : null,
                'name' => $row->category_id !== null ? (string) ($names[$row->category_id] ?? '—') : 'Sans famille',
                'amount' => (int) $row->amount,
                'share' => $total > 0 ? (int) round($row->amount / $total * 100) : 0,
                'days' => (int) $row->days,
                'last_day' => (string) $row->last_day,
            ])->values()->all(),
        ];
    }

    /**
     * Familles vendues sur une journée (rapport du soir).
     *
     * @param  Collection<int, string>  $names
     * @return list<array{name: string, customers: int, revenue: int}>
     */
    public function day(CarbonInterface $day, Collection $names, int $limit = 5): array
    {
        $rows = $this->lines($day, $day)
            ->selectRaw('category_id, count(distinct customer_id) as customers, sum(amount) as revenue')
            ->groupBy('category_id')
            ->orderByDesc('revenue')
            ->get();

        $classified = $rows->whereNotNull('category_id')->values();
        $top = $classified->take($limit)->map(fn ($r) => [
            'name' => (string) ($names[$r->category_id] ?? '—'),
            'customers' => (int) $r->customers,
            'revenue' => (int) $r->revenue,
        ]);
        $rest = $classified->slice($limit)->merge($rows->whereNull('category_id'));

        if ($top->isNotEmpty() && $rest->isNotEmpty()) {
            $top->push(['name' => 'Autres', 'customers' => (int) $rest->sum('customers'), 'revenue' => (int) $rest->sum('revenue')]);
        }

        return $top->values()->all();
    }

    /**
     * Clients (ids) ayant acheté dans la famille, depuis une date si donnée.
     */
    public function buyers(int $categoryId, ?CarbonInterface $since = null): Builder
    {
        return $this->lines($since)->where('category_id', $categoryId)->select('customer_id');
    }

    /**
     * Clients (ids) ayant acheté un article avec cet attribut (genre = Femme…).
     */
    public function propertyBuyers(string $key, string $value, ?CarbonInterface $since = null): Builder
    {
        return $this->lines($since)->where('properties->'.$key, $value)->select('customer_id');
    }

    /**
     * Attributs présents dans les ventes de la période et leurs valeurs (genre : Homme, Femme…).
     *
     * @return array<string, list<string>>
     */
    public function propertyValues(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $values = [];

        foreach ($this->lines($from, $to)->whereNotNull('properties')->distinct()->pluck('properties') as $json) {
            foreach ((array) json_decode((string) $json, true) as $key => $value) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $values[$key][(string) $value] = true;
                }
            }
        }

        ksort($values);

        return array_map(function (array $set) {
            $list = array_map('strval', array_keys($set));
            sort($list, SORT_NATURAL | SORT_FLAG_CASE);

            return $list;
        }, $values);
    }

    /**
     * Même lecture que overview(), par valeur d'un attribut (ex. genre) au lieu de la famille.
     *
     * @return list<array{value: string, customers: int, amount: int, share: float, average_basket: int, repeat_rate: int}>
     */
    public function byProperty(string $key, ?CarbonInterface $from, CarbonInterface $to): array
    {
        $groups = [];
        $total = 0;

        foreach ($this->lines($from, $to)->select('customer_id', 'amount', 'day', 'properties')->get() as $line) {
            $value = json_decode((string) $line->properties, true)[$key] ?? null;
            $value = is_scalar($value) && trim((string) $value) !== '' ? (string) $value : 'Non renseigné';
            $groups[$value]['amount'] = ($groups[$value]['amount'] ?? 0) + (int) $line->amount;
            $groups[$value]['days'][$line->customer_id][$line->day] = true;
            $total += (int) $line->amount;
        }

        return collect($groups)->map(function (array $group, $value) use ($total) {
            $customers = count($group['days']);
            $visits = array_sum(array_map('count', $group['days']));

            return [
                'value' => (string) $value,
                'customers' => $customers,
                'amount' => $group['amount'],
                'share' => $total > 0 ? round($group['amount'] / $total * 100, 1) : 0.0,
                'average_basket' => $visits > 0 ? (int) round($group['amount'] / $visits) : 0,
                'repeat_rate' => $customers > 0 ? (int) round(count(array_filter($group['days'], fn ($days) => count($days) >= 2)) / $customers * 100) : 0,
            ];
        })->sortByDesc('amount')->values()->all();
    }

    /**
     * Attributs préférés d'un client : pour chaque attribut, la valeur qui pèse le plus dans ses achats.
     *
     * @return list<array{key: string, label: string, value: string, share: int}>
     */
    public function customerProperties(int $customerId): array
    {
        $amounts = [];

        foreach ($this->lines()->where('customer_id', $customerId)->whereNotNull('properties')->select('amount', 'properties')->get() as $line) {
            foreach ((array) json_decode((string) $line->properties, true) as $key => $value) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $amounts[$key][(string) $value] = ($amounts[$key][(string) $value] ?? 0) + (int) $line->amount;
                }
            }
        }

        ksort($amounts);

        return collect($amounts)->map(function (array $values, string $key) {
            arsort($values);
            $total = max(1, array_sum($values));

            return [
                'key' => $key,
                'label' => self::propertyLabel($key),
                'value' => (string) array_key_first($values),
                'share' => (int) round(reset($values) / $total * 100),
            ];
        })->values()->all();
    }

    /** « tranche_age » → « Tranche age ». */
    public static function propertyLabel(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }
}
