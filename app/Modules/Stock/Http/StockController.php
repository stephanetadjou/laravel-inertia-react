<?php

namespace App\Modules\Stock\Http;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\PurchaseItem;
use App\Models\Store;
use App\Modules\Stock\Models\StockItem;
use App\Modules\Stock\Models\StockMovement;
use App\Modules\Stock\Services\Stock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Application « Stock » : niveaux, réassort, inventaire, mouvements. */
class StockController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->organization;
        $level = in_array($request->query('level'), ['low', 'out'], true) ? $request->query('level') : 'all';
        $q = trim((string) $request->query('q', ''));

        $items = StockItem::where('organization_id', $organization->id)->with('product:id,name,price,reference,category_id')->get()
            ->filter(fn (StockItem $i) => $i->product && ($q === '' || mb_stripos($i->product->name, $q) !== false))
            ->sortBy(fn (StockItem $i) => [$i->level() === 'ok' ? 1 : 0, $i->product->name]);

        // Ventes des 30 derniers jours par produit : couverture estimée en jours.
        $sold = PurchaseItem::whereIn('catalog_item_id', $items->pluck('catalog_item_id'))
            ->whereHas('purchase', fn ($p) => $p->where('purchased_at', '>=', now()->subDays(30))->where('status', 'valid'))
            ->selectRaw('catalog_item_id, sum(quantity) as qty')->groupBy('catalog_item_id')->pluck('qty', 'catalog_item_id');

        $rows = $items->map(fn (StockItem $i) => [
            'id' => $i->id,
            'name' => $i->product->name,
            'reference' => $i->product->reference,
            'price' => (int) $i->product->price,
            'quantity' => $i->quantity,
            'min_quantity' => $i->min_quantity,
            'cost_price' => $i->cost_price,
            'supplier' => $i->supplier,
            'level' => $i->level(),
            'sold_30' => (int) ($sold[$i->catalog_item_id] ?? 0),
            'days_left' => ($s = (float) ($sold[$i->catalog_item_id] ?? 0)) > 0 ? (int) floor($i->quantity / ($s / 30)) : null,
        ]);

        return Inertia::render('Stock/Index', [
            'filters' => ['level' => $level, 'q' => $q],
            'stats' => [
                'products' => $rows->count(),
                'low' => $rows->where('level', 'low')->count(),
                'out' => $rows->where('level', 'out')->count(),
                'value' => (int) $items->sum(fn (StockItem $i) => max(0, $i->quantity) * ($i->cost_price ?? (int) $i->product->price)),
            ],
            'items' => $rows->when($level !== 'all', fn ($c) => $c->where('level', $level))->values(),
            'untracked' => CatalogItem::where('organization_id', $organization->id)->where('type', CatalogItem::TYPE_PRODUCT)->where('active', true)
                ->whereNotIn('id', $items->pluck('catalog_item_id'))->orderBy('name')->get(['id', 'name']),
            'stores' => Store::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'hasExpenses' => $organization->hasModule('depenses'),
        ]);
    }

    public function movements(Request $request): Response
    {
        $organization = $request->user()->organization;

        return Inertia::render('Stock/Movements', [
            'movements' => StockMovement::whereHas('stockItem', fn ($s) => $s->where('organization_id', $organization->id))
                ->with(['stockItem.product:id,name', 'user:id,name'])->latest('id')->paginate(50)
                ->through(fn (StockMovement $m) => [
                    'id' => $m->id,
                    'at' => $m->created_at->toIso8601String(),
                    'product' => $m->stockItem->product?->name,
                    'quantity' => $m->quantity,
                    'quantity_after' => $m->quantity_after,
                    'reason' => StockMovement::REASONS[$m->reason] ?? $m->reason,
                    'note' => $m->note,
                    'user' => $m->user?->name,
                ]),
        ]);
    }

    public function track(Request $request, Stock $stock): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'catalog_item_id' => ['required', 'integer', Rule::exists('catalog_items', 'id')->where('organization_id', $user->organization_id)],
            'quantity' => ['required', 'integer', 'min:0'],
            'min_quantity' => ['nullable', 'integer', 'min:0'],
            'cost_price' => ['nullable', 'integer', 'min:0'],
        ]);
        $item = StockItem::firstOrCreate(['catalog_item_id' => $data['catalog_item_id']], [
            'organization_id' => $user->organization_id,
            'min_quantity' => $data['min_quantity'] ?? 2,
            'cost_price' => $data['cost_price'] ?? null,
        ]);
        $stock->count($item, (int) $data['quantity'], $user);

        return back()->with('success', "Stock suivi pour « {$item->product->name} ».");
    }

    public function receive(Request $request, StockItem $stockItem, Stock $stock): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeItem($request, $stockItem);
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'cost_price' => ['nullable', 'integer', 'min:0'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')->where('organization_id', $user->organization_id)],
        ]);
        $stock->receive($stockItem, (int) $data['quantity'], $user, $data['cost_price'] ?? null, $data['supplier'] ?? null, $data['store_id'] ?? Store::where('organization_id', $user->organization_id)->value('id'));

        return back()->with('success', "+{$data['quantity']} « {$stockItem->product->name} » en stock.");
    }

    public function count(Request $request, StockItem $stockItem, Stock $stock): RedirectResponse
    {
        $this->authorizeItem($request, $stockItem);
        $data = $request->validate(['counted' => ['required', 'integer', 'min:0']]);
        $stock->count($stockItem, (int) $data['counted'], $request->user());

        return back()->with('success', "Inventaire enregistré : {$data['counted']} « {$stockItem->product->name} ».");
    }

    public function update(Request $request, StockItem $stockItem): RedirectResponse
    {
        $this->authorizeItem($request, $stockItem);
        $stockItem->update($request->validate([
            'min_quantity' => ['required', 'integer', 'min:0'],
            'cost_price' => ['nullable', 'integer', 'min:0'],
            'supplier' => ['nullable', 'string', 'max:255'],
        ]));

        return back()->with('success', 'Seuil de réassort mis à jour.');
    }

    public function untrack(Request $request, StockItem $stockItem): RedirectResponse
    {
        $this->authorizeItem($request, $stockItem);
        $stockItem->delete();

        return back()->with('success', 'Ce produit n’est plus suivi en stock.');
    }

    private function authorizeItem(Request $request, StockItem $item): void
    {
        abort_unless($item->organization_id === $request->user()->organization_id, 404);
    }
}
