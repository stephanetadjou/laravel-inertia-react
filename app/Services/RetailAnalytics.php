<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
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
            // Clients derrière les chiffres clés (export, relance).
            'kpi_audiences' => [
                'customers' => ['label' => 'Clients identifiés de la période', 'filters' => $this->bought()],
                'new_customers' => ['label' => 'Nouveaux clients de la période', 'filters' => ['first_from' => $this->from->toDateString(), 'first_to' => $this->to->toDateString()]],
                'active_customers' => ['label' => "Clients actifs ({$this->activeDays} j)", 'filters' => ['bought_from' => $this->to->copy()->subDays($this->activeDays - 1)->toDateString(), 'bought_to' => $this->to->toDateString()]],
            ],
            'previous' => $previous,
            'active_days' => $this->activeDays,
            'insights' => $this->insights(),
            'monthly' => $this->monthly(),
            'tables' => [$this->brandsTable(), $this->monthlyTable()],
        ];
    }

    /**
     * « À retenir » : 3 à 5 constats calculés par des règles simples (pas
     * d'IA), chacun avec le chiffre clé, la décision suggérée et le lien
     * vers l'écran où agir. Les règles portent sur le périmètre filtré ; la
     * date de référence est la fin de la période.
     *
     * @return list<array{key: string, tone: string, title: string, detail: string, action: string, link: string, score: float}>
     */
    public function insights(int $limit = 5): array
    {
        $insights = array_filter([
            $this->insightLostLoyal(),
            $this->insightMultibrand(),
            $this->insightCrossSell(),
            $this->insightFamilyTrend('down'),
            $this->insightFamilyTrend('up'),
            $this->insightNewCustomers(),
            $this->insightReturns(),
            $this->insightAnonymous(),
        ]);

        usort($insights, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice(array_values($insights), 0, $limit);
    }

    /** Marques du périmètre : id => nom. */
    private function scopeBrands(): Collection
    {
        return Brand::whereIn('id', Store::whereIn('id', $this->storeIds ?: [0])->select('brand_id'))->orderBy('name')->pluck('name', 'id');
    }

    /** Bons clients d'une marque (segments RFM de la marque) qui n'achètent plus. */
    private function insightLostLoyal(): ?array
    {
        $rows = DB::table('customer_brand_metrics')
            ->whereIn('brand_id', $this->scopeBrands()->keys()->all() ?: [0])
            ->whereIn('rfm_segment', ['at_risk', 'cant_lose'])
            ->where('order_count', '>=', 3)
            ->selectRaw('brand_id, COUNT(*) as customers, SUM(net_spend) as spend')
            ->groupBy('brand_id')
            ->orderByDesc('spend')
            ->first();

        if (! $rows || $rows->customers < 5) {
            return null;
        }

        $brand = $this->scopeBrands()[$rows->brand_id] ?? '';

        return [
            'key' => 'lost_loyal',
            'tone' => 'bad',
            'title' => "{$brand} : {$rows->customers} bons clients n’achètent plus",
            'detail' => 'Segments « À risque » et « À ne pas perdre » (3 achats ou plus), '.number_format((int) $rows->spend, 0, ',', ' ').' FCFA dépensés chez '.$brand.'.',
            'action' => 'Campagne de reconquête ciblée',
            'link' => "/customers?brand={$rows->brand_id}&segment=at_risk,cant_lose",
            'audience' => ['brand' => (int) $rows->brand_id, 'segment' => 'at_risk,cant_lose'],
            'score' => 100 + $rows->customers,
        ];
    }

    /** Poids des clients multimarques dans le CA. */
    private function insightMultibrand(): ?array
    {
        if ($this->scopeBrands()->count() < 2) {
            return null;
        }

        $rows = $this->lines()->whereNotNull('customer_id')->selectRaw('customer_id, COUNT(DISTINCT brand_id) as brands, SUM(amount) as net')->groupBy('customer_id')->get();
        $total = max(1, (int) $rows->sum('net'));
        $multi = $rows->where('brands', '>=', 2);

        if ($rows->count() < 20 || $multi->isEmpty()) {
            return null;
        }

        $customerShare = (int) round($multi->count() / $rows->count() * 100);
        $revenueShare = (int) round($multi->sum('net') / $total * 100);

        if ($revenueShare < $customerShare * 1.5) {
            return null;
        }

        return [
            'key' => 'multibrand',
            'tone' => 'ok',
            'title' => "Les clients multimarques : {$customerShare} % des clients, {$revenueShare} % du CA identifié",
            'detail' => $multi->count().' clients achètent dans au moins deux marques du groupe.',
            'action' => 'Programme VIP groupe',
            'link' => '/customers?brands=multi',
            'audience' => ['brands' => 'multi'],
            'score' => 80 + $revenueShare - $customerShare,
        ];
    }

    /** Plus gros potentiel de vente croisée entre deux marques du périmètre. */
    private function insightCrossSell(): ?array
    {
        $brands = $this->scopeBrands();

        if ($brands->count() < 2) {
            return null;
        }

        $buyers = DB::table('customer_brand_metrics')->whereIn('brand_id', $brands->keys()->all())->where('order_count', '>', 0)
            ->get(['customer_id', 'brand_id', 'order_count'])->groupBy('brand_id');
        $best = null;
        // Zones (villes / quartiers) de chaque marque : une marque voisine se fait découvrir plus facilement.
        $zones = Store::whereIn('id', $this->storeIds ?: [0])->whereNotNull('city')->get(['brand_id', 'city'])->groupBy('brand_id')->map(fn ($s) => $s->pluck('city')->unique());

        foreach ($brands as $from => $fromName) {
            $loyal = ($buyers[$from] ?? collect())->where('order_count', '>=', 2)->pluck('customer_id')->flip();

            foreach ($brands as $to => $toName) {
                if ($from === $to || $loyal->isEmpty()) {
                    continue;
                }
                $already = ($buyers[$to] ?? collect())->filter(fn ($r) => $loyal->has($r->customer_id))->count();
                $never = $loyal->count() - $already;
                $near = ($zones[$from] ?? collect())->intersect($zones[$to] ?? collect())->isNotEmpty();
                // Même zone : potentiel compté 1,5 fois (les clients passent déjà devant la boutique).
                $weight = $never * ($near ? 1.5 : 1);

                if ($best === null || $weight > $best['weight']) {
                    $best = ['from' => $from, 'to' => $to, 'never' => $never, 'loyal' => $loyal->count(), 'weight' => $weight, 'zone' => $near ? ($zones[$from] ?? collect())->intersect($zones[$to] ?? collect())->first() : null];
                }
            }
        }

        if ($best === null || $best['never'] < 10) {
            return null;
        }

        return [
            'key' => 'cross_sell',
            'tone' => 'info',
            'title' => "{$best['never']} clients fidèles de {$brands[$best['from']]} ne sont jamais venus chez {$brands[$best['to']]}",
            'detail' => (int) round($best['never'] / max(1, $best['loyal']) * 100).' % des clients de '.$brands[$best['from']].' ayant au moins 2 achats'
                .($best['zone'] ? ", alors que les deux enseignes sont à {$best['zone']}." : '.'),
            'action' => 'Offre croisée '.$brands[$best['from']].' → '.$brands[$best['to']],
            'link' => "/actions?list=cross_sell&brand={$best['from']}",
            'audience' => ['brand' => (int) $best['from'], 'never_brand' => (int) $best['to']],
            'score' => 60 + min(40, $best['never'] / 5),
        ];
    }

    /** Famille dont le CA des 90 derniers jours baisse (ou monte) le plus par rapport aux 90 précédents. */
    private function insightFamilyTrend(string $direction): ?array
    {
        $end = $this->to->copy();
        $recent = $this->familyTotals($end->copy()->subDays(89), $end);
        $before = $this->familyTotals($end->copy()->subDays(179), $end->copy()->subDays(90));
        $names = Category::whereKey($recent->keys()->merge($before->keys())->unique()->all())->pluck('name', 'id');
        $best = null;

        foreach ($names as $id => $name) {
            $old = (int) ($before[$id] ?? 0);
            $new = (int) ($recent[$id] ?? 0);

            if (max($old, $new) < 500000 || $old <= 0) {
                continue;
            }

            $change = (int) round(($new - $old) / $old * 100);

            if (($direction === 'down' && $change <= -25 && ($best === null || $change < $best['change']))
                || ($direction === 'up' && $change >= 25 && ($best === null || $change > $best['change']))) {
                $best = ['id' => $id, 'name' => $name, 'change' => $change, 'new' => $new, 'old' => $old];
            }
        }

        if ($best === null) {
            return null;
        }

        $money = fn ($v) => number_format($v, 0, ',', ' ').' FCFA';

        return [
            'key' => 'family_'.$direction,
            'tone' => $direction === 'down' ? 'warn' : 'ok',
            'title' => $direction === 'down'
                ? "{$best['name']} en baisse : {$best['change']} % sur 90 jours"
                : "{$best['name']} en hausse : +{$best['change']} % sur 90 jours",
            'detail' => $money($best['new']).' contre '.$money($best['old']).' les 90 jours précédents.',
            'action' => $direction === 'down' ? 'Revoir l’offre et le réassort' : 'Renforcer le stock et la mise en avant',
            'link' => "/analytics/families?family={$best['id']}",
            // En baisse : clients qui ont acheté la famille mais plus depuis 90 jours ; en hausse : ses acheteurs récents.
            'audience' => $direction === 'down'
                ? ['family' => (int) $best['id'], 'family_mode' => 'lapsed', 'family_days' => 90]
                : ['family' => (int) $best['id'], 'family_days' => 90],
            'score' => 50 + min(40, abs($best['change']) / 2),
        ];
    }

    /** Marque où les nouveaux clients reviennent le moins dans les 90 jours. */
    private function insightNewCustomers(): ?array
    {
        $from = $this->to->copy()->subDays(270)->toDateString();
        $until = $this->to->copy()->subDays(90)->toDateString();

        $worst = null;
        $metrics = DB::table('customer_brand_metrics')->whereIn('brand_id', $this->scopeBrands()->keys()->all() ?: [0])
            ->whereBetween('first_purchase_at', [$from, $until])->get(['customer_id', 'brand_id', 'first_purchase_at']);

        // Dates d'achat de ces clients dans leurs marques, en une requête.
        $days = DB::table('purchases')->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
            ->join('stores', 'stores.id', '=', 'purchases.store_id')
            ->whereIn('contacts.customer_id', $metrics->pluck('customer_id')->unique()->all() ?: [0])
            ->whereIn('stores.brand_id', $metrics->pluck('brand_id')->unique()->all() ?: [0])
            ->where('purchases.status', Purchase::STATUS_VALID)->where('purchases.type', '!=', Purchase::TYPE_RETURN)
            ->selectRaw('contacts.customer_id as customer_id, stores.brand_id as brand_id, substr(purchases.purchased_at, 1, 10) as day')
            ->distinct()->get()
            ->groupBy(fn ($r) => $r->customer_id.'-'.$r->brand_id);

        foreach ($metrics->groupBy('brand_id') as $brandId => $rows) {
            if ($rows->count() < 15) {
                continue;
            }

            $returned = $rows->filter(function ($row) use ($days, $brandId) {
                $limit = Carbon::parse($row->first_purchase_at)->addDays(90)->toDateString();

                return ($days[$row->customer_id.'-'.$brandId] ?? collect())
                    ->contains(fn ($d) => $d->day > $row->first_purchase_at && $d->day <= $limit);
            })->count();

            $rate = (int) round($returned / $rows->count() * 100);

            if ($worst === null || $rate < $worst['rate']) {
                $worst = ['brand' => $brandId, 'rate' => $rate, 'count' => $rows->count()];
            }
        }

        if ($worst === null || $worst['rate'] >= 30) {
            return null;
        }

        $brand = $this->scopeBrands()[$worst['brand']] ?? '';

        return [
            'key' => 'new_customers',
            'tone' => 'warn',
            'title' => "{$brand} : seulement {$worst['rate']} % des nouveaux clients reviennent dans les 90 jours",
            'detail' => "{$worst['count']} nouveaux clients entre 9 et 3 mois avant le {$this->to->format('d/m/Y')}.",
            'action' => 'Message de bienvenue + relance à J+30',
            'link' => '/actions?list=second_purchase&brand='.$worst['brand'],
            'audience' => ['brand' => (int) $worst['brand'], 'first_from' => $from, 'first_to' => $until],
            'score' => 70 + (30 - $worst['rate']),
        ];
    }

    /** Famille au taux de retour anormalement élevé. */
    private function insightReturns(): ?array
    {
        $rows = $this->lines()->whereNotNull('category_id')
            ->selectRaw("category_id, SUM(CASE WHEN type = 'return' THEN -amount ELSE 0 END) as returns, SUM(CASE WHEN type = 'return' THEN 0 ELSE amount END) as sales")
            ->groupBy('category_id')->get()
            ->filter(fn ($r) => $r->sales >= 300000)
            ->map(fn ($r) => [...(array) $r, 'rate' => round($r->returns / max(1, $r->sales) * 100, 1)])
            ->sortByDesc('rate')->first();

        if (! $rows || $rows['rate'] < 8) {
            return null;
        }

        $name = Category::whereKey($rows['category_id'])->value('name');

        return [
            'key' => 'returns',
            'tone' => 'bad',
            'title' => "Retours élevés sur {$name} : {$rows['rate']} % des ventes",
            'detail' => number_format((int) $rows['returns'], 0, ',', ' ').' FCFA retournés sur la période.',
            'action' => 'Alerte qualité : vérifier les produits et les tailles',
            'link' => '/purchases?type=return&category='.rawurlencode((string) $name),
            'audience' => ['family' => (int) $rows['category_id'], 'family_days' => 0] + $this->bought(),
            'score' => 55 + $rows['rate'],
        ];
    }

    /** Point de vente où trop de tickets restent anonymes. */
    private function insightAnonymous(): ?array
    {
        $row = $this->lines()
            ->selectRaw("store_id, COUNT(DISTINCT CASE WHEN type = 'return' THEN NULL ELSE purchase_id END) as tickets, COUNT(DISTINCT CASE WHEN type <> 'return' AND customer_id IS NULL THEN purchase_id END) as anonymous")
            ->groupBy('store_id')->get()
            ->filter(fn ($r) => $r->tickets >= 30)
            ->map(fn ($r) => ['store_id' => $r->store_id, 'rate' => (int) round($r->anonymous / $r->tickets * 100), 'anonymous' => (int) $r->anonymous])
            ->sortByDesc('rate')->first();

        if (! $row || $row['rate'] < 25) {
            return null;
        }

        $store = Store::whereKey($row['store_id'])->value('name');

        return [
            'key' => 'anonymous',
            'tone' => 'warn',
            'title' => "{$store} : {$row['rate']} % de ventes anonymes",
            'detail' => "{$row['anonymous']} tickets sans numéro de client sur la période : ces clients ne peuvent être ni suivis ni relancés.",
            'action' => 'Collecter le téléphone en caisse',
            'link' => "/purchases?anonymous=1&store={$row['store_id']}",
            'audience' => null,
            'score' => 50 + $row['rate'] / 2,
        ];
    }

    /** Filtre « a acheté sur la période » des audiences. @return array{bought_from: string, bought_to: string} */
    private function bought(): array
    {
        return ['bought_from' => $this->from->toDateString(), 'bought_to' => $this->to->toDateString()];
    }

    /** @return Collection<int, int> famille => CA net */
    private function familyTotals(Carbon $from, Carbon $to): Collection
    {
        return $this->lines($from, $to)->whereNotNull('category_id')
            ->selectRaw('category_id, SUM(amount) as net')->groupBy('category_id')->pluck('net', 'category_id')
            ->map(fn ($v) => (int) $v);
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
                'audiences' => $rows->sortByDesc('net')->take(200)->map(fn ($r) => $r->k !== null
                    ? ($level === 'product' ? ['article' => (int) $r->k] : ['family' => (int) $r->k, 'family_days' => 0]) + $this->bought()
                    : null)->values()->all(),
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
                    'audiences' => [
                        ['first_from' => $this->from->toDateString(), 'first_to' => $this->to->toDateString()],
                        $this->bought() + ['first_before' => $this->from->toDateString()],
                        $this->bought() + ['brands' => 'mono'],
                        $this->bought() + ['brands' => 'multi'],
                        $this->bought() + ['customer_type' => 'person'],
                        $this->bought() + ['customer_type' => 'business'],
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
            'rows' => array_map(fn ($m) => [self::monthLabel($m['month']), $m['net'], $m['tickets'], $m['customers']], $monthly = $this->monthly()),
            'audiences' => array_map(fn ($m) => [
                'bought_from' => max($m['month'].'-01', $this->from->toDateString()),
                'bought_to' => min(Carbon::parse($m['month'].'-01')->endOfMonth()->toDateString(), $this->to->toDateString()),
            ], $monthly),
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
            'audiences' => $rows->sortByDesc('net')->map(fn ($r) => ['brand' => (int) $r->brand_id] + $this->bought())->values()->all(),
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
            'audiences' => $rows->sortByDesc('net')->map(fn ($r) => ['store' => (int) $r->store_id, 'brand' => (int) ($stores->get($r->store_id)?->brand_id ?? 0)] + $this->bought())->values()->all(),
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
        $audiences = [];

        foreach ($cohorts->sortKeys() as $month => $members) {
            $row = [self::monthLabel($month), $members->count()];

            for ($k = 1; $k <= 6; $k++) {
                $target = Carbon::parse($month.'-01')->addMonths($k)->format('Y-m');
                $row[] = $target > $this->to->format('Y-m')
                    ? ''
                    : (int) round($members->filter(fn ($months) => $months->contains($target))->count() / max(1, $members->count()) * 100).' %';
            }

            $rows[] = $row;
            $audiences[] = ['first_from' => $month.'-01', 'first_to' => Carbon::parse($month.'-01')->endOfMonth()->toDateString()];
        }

        return [
            'audiences' => $audiences,
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
