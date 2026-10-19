<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AnalyzesPeriods;
use App\Http\Controllers\Concerns\ResolvesBrandFilter;
use App\Http\Controllers\Concerns\ValidatesImportFile;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Purchase;
use App\Models\Store;
use App\Services\CustomerScoring;
use App\Services\PurchaseImporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController extends Controller
{
    use AnalyzesPeriods, ResolvesBrandFilter, ValidatesImportFile;

    public function index(Request $request): Response
    {
        return Inertia::render('Purchases/Index', $this->pageProps($request));
    }

    /**
     * Aperçu sans écriture en base, avant confirmation par l'admin (même
     * logique que l'import de contacts : upload -> aperçu -> confirmation).
     * La liste des achats reste inchangée en arrière-plan, seul un prop
     * `preview` s'ajoute pour que le modal affiche le résumé.
     */
    public function preview(Request $request, PurchaseImporter $importer): Response
    {
        $this->validateImportFile($request);

        return Inertia::render('Purchases/Index', [
            ...$this->pageProps($request),
            'preview' => $importer->preview($request->file('file'), $request->user()->organization, $request->user()->visibleStoreIds()),
        ]);
    }

    public function store(Request $request, PurchaseImporter $importer, CustomerScoring $scoring): RedirectResponse
    {
        $this->validateImportFile($request);

        $summary = $importer->import($request->file('file'), $request->user()->organization, $request->user()->visibleStoreIds());

        // Segments RFM, scores et alertes de churn relatifs à tous les acheteurs : recalcul de l'organisation.
        $scoring->refreshOrganization($request->user()->organization);

        $message = "{$summary['purchases_recorded']} achat(s) enregistré(s).";

        if ($summary['contacts_created'] > 0) {
            $message .= " {$summary['contacts_created']} nouveau(x) client(s) créé(s).";
        }

        if (! empty($summary['skipped'])) {
            $message .= ' '.count($summary['skipped']).' ligne(s) ignorée(s).';
        }

        return redirect()->route('purchases.index')->with('success', $message);
    }

    /**
     * Filtres en query string, tous appliqués aux chiffres, au graphique et
     * au tableau : période (comme le tableau de bord, comparée à la période
     * précédente), marque (bornée par le rôle), boutique, catégorie et
     * recherche client / numéro / catégorie. Tri par date ou montant.
     *
     * @return array<string, mixed>
     */
    private function pageProps(Request $request): array
    {
        $user = $request->user();
        $visibleIds = $user->visibleStoreIds();

        $period = $this->resolvePeriod($request);
        $brandFilter = $this->resolveBrandFilter($request, $user, $visibleIds);

        $roleStores = Store::where('organization_id', $user->organization_id)
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))
            ->with('brand')
            ->orderBy('name')
            ->get();

        $brandStores = $brandFilter['selected'] !== null
            ? $roleStores->where('brand_id', $brandFilter['selected'])->values()
            : $roleStores;

        // Une boutique hors du périmètre (ou hors de la marque choisie) est ignorée.
        $storeId = $request->integer('store') ?: null;
        $storeId = $brandStores->contains('id', $storeId) ? $storeId : null;
        $storeIds = $storeId !== null ? [$storeId] : $brandStores->pluck('id')->all();

        $category = trim((string) $request->query('category', '')) ?: null;
        $search = trim((string) $request->query('q', ''));
        $sort = $request->query('sort') === 'amount' ? 'amount' : 'date';
        $direction = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $purchases = fn (Carbon $start, Carbon $end): Builder => Purchase::query()
            ->whereIn('purchases.store_id', $storeIds)
            ->whereBetween('purchases.purchased_at', [$start, $end])
            ->when($category !== null, fn ($q) => $q->where('purchases.product_category', $category))
            ->when($search !== '', function ($q) use ($search) {
                $digits = ltrim(preg_replace('/\D+/', '', $search), '0'); // 0 du format local ignoré

                $q->where(function ($q) use ($search, $digits) {
                    $q->where('purchases.product_category', 'like', "%{$search}%")
                        ->orWhereHas('contact', function ($q) use ($search, $digits) {
                            $q->where('name', 'like', "%{$search}%");

                            if ($digits !== '') {
                                $q->orWhere('phone_e164', 'like', "%{$digits}%");
                            }
                        });
                });
            });

        $totals = fn (Carbon $start, Carbon $end): array => [
            'revenue' => (int) $purchases($start, $end)->sum('amount'),
            'purchases' => $purchases($start, $end)->count(),
            'buyers' => $purchases($start, $end)->distinct()->count('contact_id'),
            'quantity' => (int) $purchases($start, $end)->sum('quantity'),
        ];
        $current = $totals($period['from'], $period['to']);
        $previous = $totals($period['previous_from'], $period['previous_to']);

        $perPage = (int) $request->integer('per_page', 25);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;

        $page = $purchases($period['from'], $period['to'])
            ->with(['contact', 'store.brand'])
            ->when(
                $sort === 'amount',
                fn ($q) => $q->orderBy('amount', $direction),
                fn ($q) => $q->orderBy('purchased_at', $direction),
            )
            ->orderBy('id', $direction)
            ->paginate($perPage)
            ->withQueryString();

        return [
            'filters' => [
                'from' => $period['from']->format('Y-m-d'),
                'to' => $period['to']->format('Y-m-d'),
                'preset' => $period['preset'],
                'days' => $period['days'],
                'brand' => $brandFilter['selected'],
                'brand_locked' => $brandFilter['locked'],
                'brand_hidden' => $brandFilter['hidden'],
                'brands' => $roleStores->pluck('brand')->filter()->unique('id')->sortBy('name')->values()
                    ->map(fn (Brand $brand) => ['id' => $brand->id, 'name' => $brand->name]),
                'store' => $storeId,
                'category' => $category,
                'q' => $search,
                'sort' => $sort,
                'dir' => $direction,
                'per_page' => $perPage,
            ],
            'stores' => $brandStores->map(fn (Store $store) => ['id' => $store->id, 'name' => $store->name])->values(),
            // Catégories réellement présentes dans les achats du périmètre (texte
            // libre de l'import), complétées par le référentiel de l'organisation.
            'categories' => Purchase::whereIn('store_id', $roleStores->pluck('id'))
                ->whereNotNull('product_category')
                ->distinct()
                ->pluck('product_category')
                ->merge(Category::where('organization_id', $user->organization_id)->pluck('name'))
                ->unique()
                ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                ->values(),
            'kpis' => ['current' => $current, 'previous' => $previous],
            'purchases' => $page->through(fn (Purchase $purchase) => [
                'id' => $purchase->id,
                'purchased_at' => $purchase->purchased_at->toDateString(),
                'contact' => [
                    'id' => $purchase->contact_id,
                    'customer_id' => $purchase->contact?->customer_id,
                    'name' => $purchase->contact?->name,
                    'phone_e164' => $purchase->contact?->phone_e164,
                ],
                'store_name' => $purchase->store->name,
                'brand_name' => $purchase->store->brand?->name,
                'product_category' => $purchase->product_category,
                'quantity' => $purchase->quantity,
                'amount' => (int) $purchase->amount,
            ]),
            'preview' => null,
        ];
    }
}
