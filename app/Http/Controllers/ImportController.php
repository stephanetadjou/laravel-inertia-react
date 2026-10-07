<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\ContactImporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ImportController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Imports/Index', [
            'stores' => $this->visibleStoresQuery($request)->orderBy('name')->get(['id', 'name']),
            'preview' => null,
            'fields' => $this->fields(),
        ]);
    }

    /**
     * Aperçu sans écriture en base, avant confirmation par l'admin
     * (critère d'acceptation MVP : upload -> aperçu -> confirmation -> résumé).
     */
    public function preview(Request $request, ContactImporter $importer): Response
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls'],
            'mapping' => ['nullable', 'array'],
            'mapping.*' => ['nullable', 'integer', 'min:0'],
            'delimiter' => ['nullable', 'in:auto,comma,semicolon,tab'],
        ]);

        $store = $this->visibleStoresQuery($request)->findOrFail($data['store_id']);

        return Inertia::render('Imports/Index', [
            'stores' => $this->visibleStoresQuery($request)->orderBy('name')->get(['id', 'name']),
            'fields' => $this->fields(),
            'preview' => $importer->preview($request->file('file'), $store, $this->mapping($request), $this->delimiter($data)),
        ]);
    }

    public function store(Request $request, ContactImporter $importer): RedirectResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls'],
            'mapping' => ['nullable', 'array'],
            'mapping.*' => ['nullable', 'integer', 'min:0'],
            'delimiter' => ['nullable', 'in:auto,comma,semicolon,tab'],
        ]);

        $store = $this->visibleStoresQuery($request)->findOrFail($data['store_id']);

        $summary = $importer->import($request->file('file'), $store, $this->mapping($request), $this->delimiter($data));

        $message = sprintf(
            '%d contact(s) créé(s), %d mis à jour, %d achat(s) enregistré(s).',
            $summary['contacts_created'],
            $summary['contacts_updated'],
            $summary['purchases_recorded'],
        );

        if (! empty($summary['skipped'])) {
            $message .= ' '.count($summary['skipped']).' ligne(s) ignorée(s).';
        }

        return back()->with('success', $message);
    }

    /**
     * Association champ => colonne choisie dans l'aperçu (absente au premier
     * aperçu : détection automatique d'après les en-têtes).
     *
     * @return array<string, mixed>|null
     */
    private function mapping(Request $request): ?array
    {
        return $request->has('mapping') ? (array) $request->input('mapping') : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function delimiter(array $data): ?string
    {
        return ['comma' => ',', 'semicolon' => ';', 'tab' => "\t"][$data['delimiter'] ?? 'auto'] ?? null;
    }

    /**
     * @return array<int, array{key: string, label: string, required: bool}>
     */
    private function fields(): array
    {
        return collect(ContactImporter::FIELDS)
            ->map(fn (array $field, string $key) => ['key' => $key, 'label' => $field['label'], 'required' => $field['required']])
            ->values()
            ->all();
    }

    /**
     * @return Builder<Store>
     */
    private function visibleStoresQuery(Request $request): Builder
    {
        $user = $request->user();

        $query = Store::where('organization_id', $user->organization_id);

        $visibleIds = $user->visibleStoreIds();

        if ($visibleIds !== null) {
            $query->whereIn('id', $visibleIds);
        }

        return $query;
    }
}
