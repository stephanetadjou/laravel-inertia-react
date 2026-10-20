<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\PurchaseItem;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Catalogue de l'entreprise (Paramètres › Catalogue) : prestations et
 * produits. Un article déjà vendu ou réservé s'archive au lieu d'être
 * supprimé, pour garder l'historique.
 */
class CatalogController extends Controller
{
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $items = CatalogItem::where('organization_id', $organizationId)
            ->with(['category:id,name', 'stores:id'])
            ->orderBy('name')
            ->get();

        $sold = PurchaseItem::whereIn('catalog_item_id', $items->pluck('id'))
            ->selectRaw('catalog_item_id, sum(quantity) as qty, sum(amount) as revenue')
            ->groupBy('catalog_item_id')
            ->get()
            ->keyBy('catalog_item_id');

        return Inertia::render('Catalog/Index', [
            'items' => $items->map(fn (CatalogItem $item) => [
                ...$item->present(),
                'sold' => (int) ($sold[$item->id]->qty ?? 0),
                'revenue' => (int) ($sold[$item->id]->revenue ?? 0),
            ]),
            'categories' => Category::where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name']),
            'stores' => Store::where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name']),
            'types' => CatalogItem::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($request, $data) {
            $item = CatalogItem::create([...$this->attributes($data), 'organization_id' => $request->user()->organization_id]);
            $item->stores()->sync($data['store_ids'] ?? []);
        });

        return back()->with('success', CatalogItem::TYPES[$data['type']]." « {$data['name']} » ajoutée au catalogue.");
    }

    public function update(Request $request, CatalogItem $item): RedirectResponse
    {
        $this->authorizeItem($request, $item);
        $data = $this->validated($request);

        DB::transaction(function () use ($item, $data) {
            $item->update($this->attributes($data));
            $item->stores()->sync($data['store_ids'] ?? []);
        });

        return back()->with('success', "« {$item->name} » mis à jour.");
    }

    /** Archiver / réactiver : un article archivé n'est plus proposé, l'historique reste. */
    public function toggle(Request $request, CatalogItem $item): RedirectResponse
    {
        $this->authorizeItem($request, $item);
        $item->update(['active' => ! $item->active]);

        return back()->with('success', $item->active ? "« {$item->name} » réactivé." : "« {$item->name} » archivé.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $organizationId = $request->user()->organization_id;

        return $request->validate([
            'type' => ['required', Rule::in(array_keys(CatalogItem::TYPES))],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('organization_id', $organizationId)],
            'price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:600', 'required_if:type,service'],
            'reference' => ['nullable', 'string', 'max:100'],
            'bookable_online' => ['nullable', 'boolean'],
            'store_ids' => ['nullable', 'array'],
            'store_ids.*' => ['integer', Rule::exists('stores', 'id')->where('organization_id', $organizationId)],
        ], [
            'duration_minutes.required_if' => 'Indiquez la durée de la prestation.',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function attributes(array $data): array
    {
        $service = $data['type'] === CatalogItem::TYPE_SERVICE;

        return [
            'type' => $data['type'],
            'name' => $data['name'],
            'category_id' => $data['category_id'] ?? null,
            'price' => $data['price'],
            'duration_minutes' => $service ? $data['duration_minutes'] : null,
            'reference' => $service ? null : ($data['reference'] ?? null),
            'bookable_online' => $service && ($data['bookable_online'] ?? true),
        ];
    }

    private function authorizeItem(Request $request, CatalogItem $item): void
    {
        if ($item->organization_id !== $request->user()->organization_id) {
            throw new HttpException(403, "Cet article n'appartient pas à votre entreprise.");
        }
    }
}
