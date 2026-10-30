<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AnalyzesPeriods;
use App\Http\Controllers\Concerns\ResolvesBrandFilter;
use App\Http\Controllers\Concerns\ValidatesImportFile;
use App\Models\Brand;
use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Store;
use App\Services\Audit;
use App\Services\CustomerScoring;
use App\Services\PurchaseImporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PurchaseController extends Controller
{
    use AnalyzesPeriods, ResolvesBrandFilter, ValidatesImportFile;

    public function index(Request $request): Response
    {
        return Inertia::render('Purchases/Index', $this->pageProps($request));
    }

    /**
     * Détail d'un ticket : lignes, client (ou vente anonyme), import
     * d'origine, ticket d'origine d'un retour et retours liés.
     */
    public function show(Request $request, int $purchase): Response
    {
        $ticket = $this->findTicket($request, $purchase);
        $ticket->load(['items.category:id,name', 'items.article:id,code,label', 'store.brand', 'contact.customer', 'importBatch:id,original_filename', 'originalPurchase:id,receipt_number,purchased_at,amount']);
        $user = $request->user();

        return Inertia::render('Purchases/Show', [
            'ticket' => [
                'id' => $ticket->id,
                'receipt_number' => $ticket->receipt_number,
                'purchased_at' => $ticket->purchased_at->toDateString(),
                'type' => $ticket->type,
                'status' => $ticket->status,
                'store' => $ticket->store->name,
                'brand' => $ticket->store->brand?->name,
                'amount' => (int) $ticket->amount,
                'subtotal' => $ticket->subtotal,
                'discount_amount' => (int) $ticket->discount_amount,
                'quantity' => (int) $ticket->quantity,
                'payment_method' => $ticket->payment_method !== null ? (Purchase::PAYMENT_METHODS[$ticket->payment_method] ?? $ticket->payment_method) : null,
                'properties' => $ticket->properties,
                'customer' => $ticket->contact?->customer ? [
                    'id' => $ticket->contact->customer->id,
                    'name' => $ticket->contact->customer->displayName(),
                    'phone' => $ticket->contact->customer->phone_e164,
                    'type' => $ticket->contact->customer->type,
                ] : null,
                'buyer_name' => $ticket->buyer_name,
                'customer_code' => $ticket->customer_code,
                'batch' => $ticket->importBatch ? ['id' => $ticket->importBatch->id, 'filename' => $ticket->importBatch->original_filename] : null,
                'original' => $ticket->originalPurchase ? [
                    'id' => $ticket->originalPurchase->id,
                    'receipt_number' => $ticket->originalPurchase->receipt_number,
                    'purchased_at' => $ticket->originalPurchase->purchased_at->toDateString(),
                ] : null,
                'returns' => Purchase::where('original_purchase_id', $ticket->id)->get(['id', 'receipt_number', 'purchased_at', 'amount'])
                    ->map(fn (Purchase $r) => ['id' => $r->id, 'receipt_number' => $r->receipt_number, 'purchased_at' => $r->purchased_at->toDateString(), 'amount' => (int) $r->amount]),
                'cancelled_at' => $ticket->cancelled_at?->toIso8601String(),
                'cancel_reason' => $ticket->cancel_reason,
                'lines' => $ticket->items->map(fn (PurchaseItem $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'article_code' => $item->article_code ?? $item->article?->code,
                    'family' => $item->category?->name,
                    'raw_family' => $item->product_category,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (int) $item->unit_price,
                    'discount_amount' => (int) $item->discount_amount,
                    'amount' => (int) $item->amount,
                    'properties' => $item->properties,
                ]),
            ],
            'canCorrect' => $user->canImport(),
        ]);
    }

    /** Annule un ticket (correction) : conservé, exclu des calculs, journalisé. */
    public function cancel(Request $request, int $purchase): RedirectResponse
    {
        $ticket = $this->findTicket($request, $purchase);
        $this->authorizeCorrection($request);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Indiquez le motif de l’annulation.']);

        if ($ticket->status === Purchase::STATUS_CANCELLED) {
            return back()->with('error', 'Ce ticket est déjà annulé.');
        }

        $ticket->update(['status' => Purchase::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $request->user()->id, 'cancel_reason' => $data['reason']]);
        app(CustomerScoring::class)->refreshOrganization($request->user()->organization);

        Audit::record('sale.cancelled', $ticket, "Ticket {$this->ticketLabel($ticket)} annulé : {$data['reason']}", ['amount' => (int) $ticket->amount, 'store_id' => $ticket->store_id]);

        return back()->with('success', 'Ticket annulé : il n’entre plus dans les chiffres.');
    }

    public function restore(Request $request, int $purchase): RedirectResponse
    {
        $ticket = $this->findTicket($request, $purchase);
        $this->authorizeCorrection($request);

        if ($ticket->status !== Purchase::STATUS_CANCELLED) {
            return back();
        }

        $ticket->update(['status' => Purchase::STATUS_VALID, 'cancelled_at' => null, 'cancelled_by' => null, 'cancel_reason' => null]);
        app(CustomerScoring::class)->refreshOrganization($request->user()->organization);

        Audit::record('sale.restored', $ticket, "Ticket {$this->ticketLabel($ticket)} rétabli", ['amount' => (int) $ticket->amount]);

        return back()->with('success', 'Ticket rétabli.');
    }

    /**
     * Export CSV des tickets : mêmes filtres et même périmètre que l'écran
     * (données personnelles : réservé au droit d'export, journalisé).
     */
    public function export(Request $request): StreamedResponse
    {
        if (! $request->user()->canExport()) {
            throw new HttpException(403, 'Votre rôle ne permet pas d’exporter des données.');
        }

        $props = $this->pageProps($request, export: true);
        /** @var Builder $query */
        $query = $props['query'];
        $count = (clone $query)->count();

        Audit::export('ventes', $count, $props['filters']);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Ticket', 'Type', 'Statut', 'Marque', 'Point de vente', 'Client', 'Téléphone', 'Code client', 'Famille', 'Articles', 'Quantité', 'Remise', 'Montant'], ';', '"', '');

            $query->with(['contact.customer', 'store.brand', 'category:id,name'])->withCount('items')
                ->orderBy('purchased_at')->orderBy('id')
                ->chunk(500, function ($tickets) use ($out) {
                    foreach ($tickets as $t) {
                        fputcsv($out, [
                            $t->purchased_at->format('d/m/Y'),
                            $t->receipt_number,
                            $t->type === Purchase::TYPE_RETURN ? 'Retour' : 'Vente',
                            $t->status === Purchase::STATUS_CANCELLED ? 'Annulé' : 'Valide',
                            $t->store->brand?->name,
                            $t->store->name,
                            $t->contact?->customer?->displayName() ?? ($t->buyer_name ? $t->buyer_name.' (non rattaché)' : 'Anonyme'),
                            $t->contact?->phone_e164,
                            $t->customer_code,
                            $t->category?->name ?? $t->product_category,
                            $t->items_count,
                            $t->quantity,
                            $t->discount_amount,
                            $t->amount,
                        ], ';', '"', '');
                    }
                });

            fclose($out);
        }, 'ventes-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function findTicket(Request $request, int $id): Purchase
    {
        $ticket = Purchase::withCancelled()->findOrFail($id);
        $user = $request->user();
        $visible = $user->visibleStoreIds();
        $organizationId = Store::whereKey($ticket->store_id)->value('organization_id');

        if ($organizationId !== $user->organization_id || ($visible !== null && ! in_array($ticket->store_id, $visible, true))) {
            throw new HttpException(403, 'Ce ticket n’est pas dans votre périmètre.');
        }

        return $ticket;
    }

    private function authorizeCorrection(Request $request): void
    {
        if (! $request->user()->canImport()) {
            throw new HttpException(403, 'Votre rôle ne permet pas de corriger des ventes.');
        }
    }

    private function ticketLabel(Purchase $ticket): string
    {
        return ($ticket->receipt_number ? "n° {$ticket->receipt_number}" : "#{$ticket->id}").' du '.$ticket->purchased_at->format('d/m/Y');
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
    private function pageProps(Request $request, bool $export = false): array
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
        // Prestations : ventes avec au moins une prestation ; produits : avec au moins un produit
        // (une vente mixte figure dans les deux). Les ventes importées sans ligne n'y figurent pas.
        $kind = in_array($request->query('kind'), [CatalogItem::TYPE_SERVICE, CatalogItem::TYPE_PRODUCT], true) ? (string) $request->query('kind') : null;
        $search = trim((string) $request->query('q', ''));
        // Tickets : statut (valides par défaut, annulés, tous), type, ventes anonymes, lot d'import.
        $status = in_array($request->query('status'), ['cancelled', 'all'], true) ? (string) $request->query('status') : null;
        $type = in_array($request->query('type'), [Purchase::TYPE_SALE, Purchase::TYPE_RETURN], true) ? (string) $request->query('type') : null;
        $anonymous = in_array($request->query('anonymous'), ['1', '0'], true) ? (string) $request->query('anonymous') : null;
        $batchId = $request->integer('batch') ?: null;
        $sort = $request->query('sort') === 'amount' ? 'amount' : 'date';
        $direction = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $purchases = fn (Carbon $start, Carbon $end): Builder => Purchase::query()
            ->when($status !== null, fn ($q) => $q->withCancelled()->when($status === 'cancelled', fn ($q) => $q->where('purchases.status', Purchase::STATUS_CANCELLED)))
            ->when($type !== null, fn ($q) => $q->where('purchases.type', $type))
            ->when($anonymous === '1', fn ($q) => $q->whereNull('purchases.contact_id'))
            ->when($anonymous === '0', fn ($q) => $q->whereNotNull('purchases.contact_id'))
            ->when($batchId !== null, fn ($q) => $q->where('purchases.import_batch_id', $batchId))
            ->whereIn('purchases.store_id', $storeIds)
            ->whereBetween('purchases.purchased_at', [$start, $end])
            // Catégorie du fichier ou famille reconnue portant ce nom.
            ->when($category !== null, fn ($q) => $q->where(fn ($q) => $q->where('purchases.product_category', $category)
                ->orWhereIn('purchases.category_id', Category::where('organization_id', $user->organization_id)->where('name', $category)->select('id'))))
            ->when($kind !== null, fn ($q) => $q->whereHas('items', fn ($i) => $i->where('type', $kind)))
            ->when($search !== '', function ($q) use ($search) {
                $digits = ltrim(preg_replace('/\D+/', '', $search), '0'); // 0 du format local ignoré

                $q->where(function ($q) use ($search, $digits) {
                    $q->where('purchases.product_category', 'like', "%{$search}%")
                        ->orWhere('purchases.article', 'like', "%{$search}%")
                        ->orWhere('purchases.receipt_number', 'like', "%{$search}%")
                        ->orWhere('purchases.buyer_name', 'like', "%{$search}%")
                        ->orWhere('purchases.customer_code', $search)
                        ->orWhereHas('items', fn ($i) => $i->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('contact', function ($q) use ($search, $digits) {
                            $q->where('name', 'like', "%{$search}%");

                            if ($digits !== '') {
                                $q->orWhere('phone_e164', 'like', "%{$digits}%");
                            }
                        });
                });
            });

        if ($export) {
            return [
                'query' => $purchases($period['from'], $period['to']),
                'filters' => ['from' => $period['from']->toDateString(), 'to' => $period['to']->toDateString(), 'brand' => $brandFilter['selected'], 'store' => $storeId, 'category' => $category, 'q' => $search, 'status' => $status, 'type' => $type, 'anonymous' => $anonymous, 'batch' => $batchId],
            ];
        }

        $totals = fn (Carbon $start, Carbon $end): array => [
            // CA net : ventes moins retours (montants négatifs), remises déjà déduites.
            'revenue' => (int) $purchases($start, $end)->sum('amount'),
            'purchases' => $purchases($start, $end)->where('purchases.type', '!=', Purchase::TYPE_RETURN)->count(),
            'returns' => (int) -$purchases($start, $end)->where('purchases.type', Purchase::TYPE_RETURN)->sum('amount'),
            'anonymous' => $purchases($start, $end)->whereNull('purchases.contact_id')->count(),
            'buyers' => $purchases($start, $end)->distinct()->count('contact_id'),
            'quantity' => (int) $purchases($start, $end)->sum('quantity'),
        ];
        $current = $totals($period['from'], $period['to']);
        $previous = $totals($period['previous_from'], $period['previous_to']);

        $perPage = (int) $request->integer('per_page', 25);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;

        $page = $purchases($period['from'], $period['to'])
            ->with(['contact', 'store.brand', 'category:id,name', 'items:id,purchase_id,type,name,quantity'])
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
                'kind' => $kind,
                'q' => $search,
                'status' => $status,
                'type' => $type,
                'anonymous' => $anonymous,
                'batch' => $batchId,
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
            'canExport' => $user->canExport(),
            'purchases' => $page->through(fn (Purchase $purchase) => [
                'id' => $purchase->id,
                'receipt_number' => $purchase->receipt_number,
                'type' => $purchase->type,
                'status' => $purchase->status,
                'buyer_name' => $purchase->buyer_name,
                'purchased_at' => $purchase->purchased_at->toDateString(),
                'contact' => [
                    'id' => $purchase->contact_id,
                    'customer_id' => $purchase->contact?->customer_id,
                    'name' => $purchase->contact?->name,
                    'phone_e164' => $purchase->contact?->phone_e164,
                ],
                'store_name' => $purchase->store->name,
                'brand_name' => $purchase->store->brand?->name,
                // Famille reconnue en priorité, sinon la catégorie telle qu'importée.
                'product_category' => $purchase->category?->name ?? $purchase->product_category,
                'article' => $purchase->article,
                'lines' => $purchase->items->map(fn ($item) => ['name' => $item->name, 'type' => $item->type, 'quantity' => $item->quantity])->values(),
                'quantity' => $purchase->quantity,
                'amount' => (int) $purchase->amount,
            ]),
            'preview' => null,
        ];
    }
}
