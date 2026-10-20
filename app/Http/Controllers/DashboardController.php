<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AnalyzesPeriods;
use App\Http\Controllers\Concerns\ResolvesBrandFilter;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerStageChange;
use App\Models\MessageTemplate;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tableau de bord consolidé de l'organisation (cahier des charges §2.2).
 *
 * Deux filtres en query string s'appliquent à tous les blocs :
 * - la période (`period` = today|7d|30d|90d, ou `from`/`to` explicites) ;
 * - la marque (`brand`), elle-même bornée par le périmètre du rôle : un
 *   responsable de marque est verrouillé sur sa marque, un rôle boutique
 *   n'a pas de filtre marque (sa boutique suffit).
 *
 * Chaque indicateur temporel est comparé à la période précédente de même
 * durée. Seules les tables existantes sont lues : pas de statut « délivré »
 * ni « lu » tant que le webhook Meta n'est pas branché, et pas d'historique
 * du consentement (donc pas de variation du taux d'opt-in).
 */
class DashboardController extends Controller
{
    use AnalyzesPeriods, ResolvesBrandFilter;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $organizationId = $user->organization_id;
        $visibleIds = $user->visibleStoreIds();

        $period = $this->resolvePeriod($request);
        ['from' => $from, 'to' => $to, 'preset' => $preset, 'days' => $days] = $period;
        ['previous_from' => $previousFrom, 'previous_to' => $previousTo] = $period;

        $brandFilter = $this->resolveBrandFilter($request, $user, $visibleIds);

        // Toutes les boutiques du périmètre du rôle (sert aux marques proposées
        // dans le filtre et au bloc « Contacts par marque »).
        $roleStores = Store::where('organization_id', $organizationId)
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))
            ->with('brand')
            ->orderBy('name')
            ->get();

        // Boutiques effectivement analysées : périmètre du rôle ∩ marque choisie.
        $stores = $brandFilter['selected'] !== null
            ? $roleStores->where('brand_id', $brandFilter['selected'])->values()
            : $roleStores;
        $storeIds = $stores->pluck('id')->all();

        $contacts = fn (): Builder => Contact::whereIn('store_id', $storeIds);
        $purchases = fn (Carbon $start, Carbon $end): Builder => Purchase::whereIn('store_id', $storeIds)
            ->whereBetween('purchased_at', [$start, $end]);
        $campaignMessages = fn (Carbon $start, Carbon $end): Builder => CampaignMessage::whereIn('status', ['sent', 'failed'])
            ->whereBetween('campaign_messages.updated_at', [$start, $end])
            ->whereHas('contact', fn ($q) => $q->whereIn('store_id', $storeIds));
        $welcomes = fn (Carbon $start, Carbon $end): Builder => Contact::whereIn('store_id', $storeIds)
            ->whereBetween('welcome_message_sent_at', [$start, $end]);
        $campaigns = fn (): Builder => Campaign::whereHas('stores', fn ($q) => $q->whereIn('stores.id', $storeIds));

        $current = $this->periodTotals($from, $to, $purchases, $campaignMessages, $welcomes, $campaigns);
        $previous = $this->periodTotals($previousFrom, $previousTo, $purchases, $campaignMessages, $welcomes, $campaigns);

        $consentCounts = $contacts()
            ->selectRaw('consent_status, count(*) as total')
            ->groupBy('consent_status')
            ->pluck('total', 'consent_status');
        $totalContacts = (int) $consentCounts->sum();

        $buckets = $this->buckets($from, $days);

        $pendingWelcomeCount = $contacts()
            ->whereBetween('created_at', [$from, $to])
            ->where('consent_status', 'unknown')
            ->whereNull('welcome_message_sent_at')
            ->count();

        return Inertia::render('Dashboard/Index', [
            'filters' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'preset' => $preset,
                'days' => $days,
                'brand' => $brandFilter['selected'],
                'brand_locked' => $brandFilter['locked'],
                'brand_hidden' => $brandFilter['hidden'],
                'brands' => $roleStores->pluck('brand')->filter()->unique('id')->sortBy('name')->values()
                    ->map(fn (Brand $brand) => ['id' => $brand->id, 'name' => $brand->name]),
                'store_count' => count($storeIds),
            ],
            'kpis' => [
                'revenue' => ['current' => $current['revenue'], 'previous' => $previous['revenue']],
                'contacts' => [
                    'current' => $totalContacts,
                    'previous' => $contacts()->where('created_at', '<=', $previousTo)->count(),
                    'new' => $contacts()->whereBetween('created_at', [$from, $to])->count(),
                ],
                'opt_in' => [
                    'opt_in' => (int) ($consentCounts['opt_in'] ?? 0),
                    'opt_out' => (int) ($consentCounts['opt_out'] ?? 0),
                    'unknown' => (int) ($consentCounts['unknown'] ?? 0),
                    'total' => $totalContacts,
                ],
                'messages' => [
                    'current' => $current['sent'],
                    'previous' => $previous['sent'],
                    'campaigns' => $current['campaigns'],
                ],
                'failure' => [
                    'failed' => $current['failed'],
                    'attempted' => $current['campaign_sent'] + $current['failed'],
                    'previous_failed' => $previous['failed'],
                    'previous_attempted' => $previous['campaign_sent'] + $previous['failed'],
                ],
                'buyers' => [
                    'current' => $current['buyers'],
                    'previous' => $previous['buyers'],
                    'purchases' => $current['purchases'],
                ],
            ],
            'messagesSeries' => $this->messagesSeries($buckets, $from, $to, $campaignMessages, $welcomes),
            'salesSeries' => $this->salesSeries($period, $purchases),
            'pendingWelcome' => $pendingWelcomeCount,
            'topCategories' => $purchases($from, $to)
                ->selectRaw('product_category, sum(amount) as revenue, count(distinct contact_id) as clients, count(*) as purchases')
                ->groupBy('product_category')
                ->orderByDesc('revenue')
                ->get()
                ->map(fn ($row) => [
                    'name' => $row->product_category ?: 'Sans catégorie',
                    'revenue' => (int) $row->revenue,
                    'clients' => (int) $row->clients,
                    'purchases' => (int) $row->purchases,
                ]),
            'catalogMix' => $this->catalogMix($purchases($from, $to)),
            'newClients' => $contacts()
                ->whereBetween('created_at', [$from, $to])
                ->with('store')
                ->latest()
                ->latest('id')
                ->take(10)
                ->get()
                ->map(fn (Contact $contact) => [
                    'id' => $contact->id,
                    'customer_id' => $contact->customer_id,
                    'name' => $contact->name,
                    'phone_e164' => $contact->phone_e164,
                    'store_name' => $contact->store->name,
                    'consent_status' => $contact->consent_status,
                    'welcomed' => $contact->welcome_message_sent_at !== null,
                    'created_at' => $contact->created_at->toIso8601String(),
                ]),
            'brandContacts' => $this->brandContacts($roleStores),
            'lifecycle' => $this->lifecycleFunnel($user->organization_id, $storeIds, $from, $to),
            'stores' => $this->storePerformance($stores, $from, $to, $purchases, $campaignMessages, $welcomes),
            'recentCampaigns' => $campaigns()
                ->whereBetween('created_at', [$from, $to])
                ->with('stores')
                ->withCount('campaignMessages as total')
                ->latest()
                ->latest('id')
                ->take(6)
                ->get()
                ->map(fn (Campaign $campaign) => [
                    'id' => $campaign->id,
                    'name' => $campaign->name ?: $campaign->template_name,
                    'store_names' => $campaign->stores->pluck('name')->implode(', '),
                    'status' => $campaign->status,
                    'sent_count' => $campaign->sent_count,
                    'failed_count' => $campaign->failed_count,
                    'total' => $campaign->total,
                    'created_at' => $campaign->created_at->format('d/m/Y H:i'),
                ]),
            'welcomeTemplates' => MessageTemplate::where('organization_id', $organizationId)
                ->sendable()
                ->where('category', 'utility')
                ->orderBy('name')
                ->get(['id', 'name', 'language']),
        ]);
    }

    /**
     * @return array{revenue: int, purchases: int, buyers: int, sent: int, campaign_sent: int, failed: int, campaigns: int}
     */
    private function periodTotals(Carbon $from, Carbon $to, callable $purchases, callable $campaignMessages, callable $welcomes, callable $campaigns): array
    {
        $messageCounts = $campaignMessages($from, $to)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $campaignSent = (int) ($messageCounts['sent'] ?? 0);

        return [
            'revenue' => (int) $purchases($from, $to)->sum('amount'),
            'purchases' => $purchases($from, $to)->count(),
            'buyers' => $purchases($from, $to)->distinct()->count('contact_id'),
            // Messages envoyés = messages de campagne partis + messages de bienvenue.
            'sent' => $campaignSent + $welcomes($from, $to)->count(),
            'campaign_sent' => $campaignSent,
            // Seuls les échecs de campagne sont tracés (un échec de bienvenue
            // n'est que journalisé, cf. SendWelcomeMessageJob).
            'failed' => (int) ($messageCounts['failed'] ?? 0),
            'campaigns' => $campaigns()->whereBetween('created_at', [$from, $to])->count(),
        ];
    }

    /**
     * @param  array<int, array{start: string, end: string}>  $buckets
     * @return array<int, array{start: string, end: string, sent: int, failed: int}>
     */
    private function messagesSeries(array $buckets, Carbon $from, Carbon $to, callable $campaignMessages, callable $welcomes): array
    {
        $sent = $this->sumIntoBuckets($buckets, $this->dailyTotals($campaignMessages($from, $to)->where('status', 'sent'), 'campaign_messages.updated_at'));
        $welcomed = $this->sumIntoBuckets($buckets, $this->dailyTotals($welcomes($from, $to), 'welcome_message_sent_at'));
        $failed = $this->sumIntoBuckets($buckets, $this->dailyTotals($campaignMessages($from, $to)->where('status', 'failed'), 'campaign_messages.updated_at'));

        return array_map(
            fn (array $bucket, int $i) => [...$bucket, 'sent' => $sent[$i] + $welcomed[$i], 'failed' => $failed[$i]],
            $buckets,
            array_keys($buckets),
        );
    }

    /**
     * Parcours client : nombre de fiches par étape (état actuel, fiches ayant
     * un contact dans le périmètre filtré) et mouvements de la période, lus
     * dans l'historique des changements d'étape.
     *
     * @param  array<int, int>  $storeIds
     * @return array{stages: array<int, array{key: string, label: string, count: int}>, moves: array<string, int>, total: int, vip: int}
     */
    private function lifecycleFunnel(int $organizationId, array $storeIds, Carbon $from, Carbon $to): array
    {
        $customers = fn () => Customer::where('organization_id', $organizationId)
            ->whereHas('contacts', fn ($q) => $q->whereIn('store_id', $storeIds));

        $counts = $customers()->selectRaw('lifecycle_stage, count(*) as total')->groupBy('lifecycle_stage')->pluck('total', 'lifecycle_stage');

        $changes = CustomerStageChange::whereIn('customer_id', $customers()->select('id'))
            ->whereNotNull('from_stage')
            ->whereBetween('changed_at', [$from, $to]);

        $reactivated = (clone $changes)
            ->whereIn('from_stage', [Customer::STAGE_AT_RISK, Customer::STAGE_CHURNED])
            ->whereIn('to_stage', [Customer::STAGE_NEW, Customer::STAGE_ACTIVE])
            ->distinct()
            ->count('customer_id');

        $newBuyers = (clone $changes)
            ->whereIn('from_stage', [Customer::STAGE_CONTACT, Customer::STAGE_PROSPECT])
            ->whereIn('to_stage', [Customer::STAGE_NEW, Customer::STAGE_ACTIVE])
            ->distinct()
            ->count('customer_id');

        $movedTo = fn (string $stage) => (clone $changes)->where('to_stage', $stage)->distinct()->count('customer_id');

        return [
            'stages' => collect(Customer::STAGES)->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'count' => (int) ($counts[$key] ?? 0),
            ])->values()->all(),
            'total' => (int) $counts->sum(),
            // VIP : statut propre à chaque boutique ; ici, clients VIP dans au
            // moins une boutique du périmètre filtré.
            'vip' => $customers()->whereHas('contacts', fn ($q) => $q->whereIn('store_id', $storeIds)->where('is_vip', true))->count(),
            'moves' => [
                'new_buyers' => $newBuyers,
                'new_prospects' => $movedTo(Customer::STAGE_PROSPECT),
                'at_risk' => $movedTo(Customer::STAGE_AT_RISK),
                'churned' => $movedTo(Customer::STAGE_CHURNED),
                'reactivated' => $reactivated,
            ],
        ];
    }

    /**
     * Contacts par marque et par statut de consentement, sur tout le
     * périmètre du rôle (volontairement pas restreint par le filtre marque,
     * pour pouvoir passer d'une marque à l'autre en cliquant).
     *
     * @param  Collection<int, Store>  $roleStores
     */
    private function brandContacts(Collection $roleStores): Collection
    {
        $counts = Contact::whereIn('contacts.store_id', $roleStores->pluck('id'))
            ->join('stores', 'stores.id', '=', 'contacts.store_id')
            ->selectRaw('stores.brand_id as brand_id, contacts.consent_status as consent_status, count(*) as total')
            ->groupBy('stores.brand_id', 'contacts.consent_status')
            ->get()
            ->groupBy('brand_id');

        return $roleStores->pluck('brand')->filter()->unique('id')->values()
            ->map(function (Brand $brand) use ($counts) {
                $rows = ($counts[$brand->id] ?? collect())->pluck('total', 'consent_status');

                return [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'opt_in' => (int) ($rows['opt_in'] ?? 0),
                    'unknown' => (int) ($rows['unknown'] ?? 0),
                    'opt_out' => (int) ($rows['opt_out'] ?? 0),
                    'total' => (int) $rows->sum(),
                ];
            })
            ->sortByDesc('total')
            ->values();
    }

    /**
     * @param  Collection<int, Store>  $stores
     */
    private function storePerformance(Collection $stores, Carbon $from, Carbon $to, callable $purchases, callable $campaignMessages, callable $welcomes): Collection
    {
        $contactCounts = Contact::whereIn('store_id', $stores->pluck('id'))
            ->selectRaw("store_id, count(*) as total, sum(case when consent_status = 'opt_in' then 1 else 0 end) as opt_in, sum(case when is_vip = 1 then 1 else 0 end) as vip")
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id');

        $revenue = $purchases($from, $to)
            ->selectRaw('store_id, sum(amount) as total')
            ->groupBy('store_id')
            ->pluck('total', 'store_id');

        $campaignSent = $campaignMessages($from, $to)
            ->where('status', 'sent')
            ->join('contacts', 'contacts.id', '=', 'campaign_messages.contact_id')
            ->selectRaw('contacts.store_id as store_id, count(*) as total')
            ->groupBy('contacts.store_id')
            ->pluck('total', 'store_id');

        $welcomeSent = $welcomes($from, $to)
            ->selectRaw('store_id, count(*) as total')
            ->groupBy('store_id')
            ->pluck('total', 'store_id');

        return $stores
            ->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'brand_name' => $store->brand?->name,
                'connected' => $store->canSendWhatsApp(),
                'shared_account' => $store->usesSharedWhatsAppAccount(),
                'contacts' => (int) ($contactCounts[$store->id]->total ?? 0),
                'opt_in' => (int) ($contactCounts[$store->id]->opt_in ?? 0),
                'vip' => (int) ($contactCounts[$store->id]->vip ?? 0),
                'messages' => (int) ($campaignSent[$store->id] ?? 0) + (int) ($welcomeSent[$store->id] ?? 0),
                'revenue' => (int) ($revenue[$store->id] ?? 0),
            ])
            ->sortByDesc('revenue')
            ->values();
    }

    /**
     * Prestations / produits sur la période, d'après les lignes des ventes
     * (encaissements depuis l'agenda) : CA par type, prestations les plus
     * demandées, panier moyen par visite. Les ventes sans ligne (imports)
     * n'y figurent pas.
     *
     * @return array{services: int, products: int, other: int, visits: int, average: int, top: list<array{name: string, quantity: int, revenue: int}>}
     */
    private function catalogMix(Builder $purchases): array
    {
        $lines = PurchaseItem::query()->whereIn('purchase_id', (clone $purchases)->select('id'));

        $byType = (clone $lines)->selectRaw('type, sum(amount) as revenue')->groupBy('type')->pluck('revenue', 'type');
        $visits = (clone $lines)->distinct()->count('purchase_id');
        $total = (int) $byType->sum();

        return [
            'services' => (int) ($byType[CatalogItem::TYPE_SERVICE] ?? 0),
            'products' => (int) ($byType[CatalogItem::TYPE_PRODUCT] ?? 0),
            'other' => (int) $byType->filter(fn ($v, $type) => ! in_array($type, [CatalogItem::TYPE_SERVICE, CatalogItem::TYPE_PRODUCT], true))->sum(),
            'visits' => $visits,
            'average' => $visits > 0 ? (int) round($total / $visits) : 0,
            'top' => (clone $lines)
                ->where('type', CatalogItem::TYPE_SERVICE)
                ->selectRaw('name, sum(quantity) as quantity, sum(amount) as revenue')
                ->groupBy('name')
                ->orderByDesc('quantity')
                ->orderByDesc('revenue')
                ->limit(5)
                ->get()
                ->map(fn ($row) => ['name' => $row->name, 'quantity' => (int) $row->quantity, 'revenue' => (int) $row->revenue])
                ->all(),
        ];
    }
}
