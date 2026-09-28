<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Purchase;
use App\Models\Store;
use App\Services\PurchaseImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController extends Controller
{
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
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls'],
        ]);

        return Inertia::render('Purchases/Index', [
            ...$this->pageProps($request),
            'preview' => $importer->preview($request->file('file'), $request->user()->organization, $request->user()->visibleStoreIds()),
        ]);
    }

    public function store(Request $request, PurchaseImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls'],
        ]);

        $summary = $importer->import($request->file('file'), $request->user()->organization, $request->user()->visibleStoreIds());

        $message = "{$summary['purchases_recorded']} achat(s) enregistré(s).";

        if ($summary['contacts_created'] > 0) {
            $message .= " {$summary['contacts_created']} nouveau(x) client(s) créé(s).";
        }

        if (! empty($summary['skipped'])) {
            $message .= ' '.count($summary['skipped']).' ligne(s) ignorée(s).';
        }

        return redirect()->route('purchases.index')->with('success', $message);
    }

    private function pageProps(Request $request): array
    {
        $user = $request->user();
        $organizationId = $user->organization_id;
        $visibleIds = $user->visibleStoreIds();

        $query = Purchase::query()
            ->whereHas('store', fn ($q) => $q->where('organization_id', $organizationId));

        if ($visibleIds !== null) {
            $query->whereIn('store_id', $visibleIds);
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->integer('store_id'));
        }

        if ($request->filled('category')) {
            $query->where('product_category', $request->string('category'));
        }

        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('purchased_at', [$request->string('from'), $request->string('to')]);
        }

        $perPage = (int) $request->integer('per_page', 25);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;

        $purchases = (clone $query)
            ->with(['contact', 'store'])
            ->latest('purchased_at')
            ->paginate($perPage)
            ->withQueryString();

        return [
            'purchases' => $purchases->through(fn (Purchase $purchase) => [
                'id' => $purchase->id,
                'contact_name' => $purchase->contact->name,
                'contact_id' => $purchase->contact_id,
                'store_name' => $purchase->store->name,
                'product_category' => $purchase->product_category,
                'quantity' => $purchase->quantity,
                'amount' => $purchase->amount,
                'purchased_at' => $purchase->purchased_at->format('d/m/Y'),
            ]),
            'stats' => [
                'total_purchases' => (clone $query)->count(),
                'total_amount' => (clone $query)->sum('amount'),
                'total_quantity' => (clone $query)->sum('quantity'),
            ],
            'perPage' => $perPage,
            'stores' => Store::where('organization_id', $organizationId)
                ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))
                ->orderBy('name')
                ->get(['id', 'name']),
            'categories' => Category::where('organization_id', $organizationId)
                ->orderBy('name')
                ->pluck('name'),
            'filters' => $request->only(['store_id', 'category', 'from', 'to']),
            'preview' => null,
        ];
    }
}
