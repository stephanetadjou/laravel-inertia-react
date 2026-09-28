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
        ]);

        $store = $this->visibleStoresQuery($request)->findOrFail($data['store_id']);

        return Inertia::render('Imports/Index', [
            'stores' => $this->visibleStoresQuery($request)->orderBy('name')->get(['id', 'name']),
            'preview' => $importer->preview($request->file('file'), $store),
        ]);
    }

    public function store(Request $request, ContactImporter $importer): RedirectResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls'],
        ]);

        $store = $this->visibleStoresQuery($request)->findOrFail($data['store_id']);

        $summary = $importer->import($request->file('file'), $store);

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
