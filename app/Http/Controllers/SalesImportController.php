<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ValidatesImportFile;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\ImportMapping;
use App\Models\ImportRow;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\SalesImport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Imports de ventes v2 : dépôt, association des colonnes, vérification,
 * rapport d'erreurs, confirmation, bilan et annulation (cf. SalesImport).
 * Réservé aux rôles qui importent ; un responsable de marque ou de point de
 * vente n'importe et ne voit que les lots de son périmètre.
 */
class SalesImportController extends Controller
{
    use ValidatesImportFile;

    public function index(Request $request): Response
    {
        $user = $this->authorizeImport($request);
        $stores = $this->visibleStores($user);

        $batches = $this->scopedBatches($user)
            ->with(['user:id,name', 'brand:id,name', 'store:id,name'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('SalesImports/Index', [
            'batches' => $batches->through(fn (ImportBatch $batch) => $this->batchSummary($batch)),
            'brands' => $user->role?->scope === Role::SCOPE_STORE ? [] : Brand::whereIn('id', $stores->pluck('brand_id')->unique())->orderBy('name')->get(['id', 'name']),
            'stores' => $stores->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name, 'brand_id' => $s->brand_id, 'code' => $s->code])->values(),
            'fields' => collect(SalesImport::FIELDS)->map(fn ($f, $key) => ['key' => $key, 'label' => $f['label'], 'help' => $f['help']])->values(),
        ]);
    }

    public function store(Request $request, SalesImport $import): RedirectResponse
    {
        $user = $this->authorizeImport($request);
        if (! $user->organization->salesImportOpen()) {
            return back()->with('error', 'La reprise de l’historique est terminée : les ventes se saisissent maintenant dans NENTIX. Un administrateur peut la rouvrir dans Paramètres › Modules.');
        }

        $stores = $this->visibleStores($user);

        $data = $this->validateImportFile($request, [
            'brand_id' => ['nullable', 'integer', Rule::in($stores->pluck('brand_id')->unique()->all())],
            'store_id' => ['nullable', 'integer', Rule::in($stores->pluck('id')->all())],
        ]);

        $storeId = $data['store_id'] ?? null;
        $brandId = $storeId !== null ? $stores->firstWhere('id', $storeId)->brand_id : ($data['brand_id'] ?? null);

        // Le périmètre du rôle s'impose : marque du responsable, point de vente du responsable de boutique.
        if ($user->role?->scope === Role::SCOPE_BRAND) {
            $brandId = $user->brand_id;
        }
        if ($user->role?->scope === Role::SCOPE_STORE) {
            $storeId = $user->store_id;
            $brandId = $stores->firstWhere('id', $storeId)?->brand_id;
        }

        $batch = $import->upload($request->file('file'), $user, $brandId, $storeId);

        return redirect()->route('sales-imports.show', $batch);
    }

    public function show(Request $request, ImportBatch $batch, SalesImport $import): Response
    {
        $user = $this->authorizeBatch($request, $batch);
        $batch->loadMissing(['user:id,name', 'brand:id,name', 'store:id,name', 'defaultCategory:id,name']);

        $errors = $batch->rows()
            ->whereIn('status', [ImportRow::STATUS_REJECTED, ImportRow::STATUS_DUPLICATE])
            ->orderBy('row_number')
            ->paginate(20, ['id', 'row_number', 'status', 'error_code', 'error_message', 'payload'], 'errors_page')
            ->withQueryString();

        $errorCodes = $batch->rows()->whereNotNull('error_code')->selectRaw('error_code, count(*) as total')->groupBy('error_code')->pluck('total', 'error_code');

        return Inertia::render('SalesImports/Show', [
            'batch' => [
                ...$this->batchSummary($batch),
                'headers' => $batch->headers ?? [],
                'mapping' => (object) ($batch->mapping ?? []),
                'default_category_id' => $batch->default_category_id,
                'default_category' => $batch->defaultCategory?->name,
                'error_message' => $batch->error_message,
            ],
            'sample' => $batch->isOpen() ? $import->sample($batch) : [],
            'preview' => $batch->status === ImportBatch::STATUS_VALIDATED ? $import->preview($batch) : null,
            'errors' => $errors->through(fn (ImportRow $row) => [
                'row_number' => $row->row_number,
                'status' => $row->status,
                'message' => $row->error_message,
                'content' => collect($row->payload)->filter(fn ($v) => $v !== '')->map(fn ($v, $k) => "{$k} : {$v}")->take(6)->implode(' · '),
            ]),
            'errorCodes' => $errorCodes->map(fn ($total, $code) => ['code' => $code, 'label' => SalesImport::ERRORS[$code] ?? $code, 'total' => (int) $total])->values(),
            'fields' => collect(SalesImport::FIELDS)->map(fn ($f, $key) => ['key' => $key, 'label' => $f['label'], 'help' => $f['help']])->values(),
            'categories' => Category::where('organization_id', $user->organization_id)->orderBy('name')->get(['id', 'name']),
            'mappings' => ImportMapping::where('organization_id', $user->organization_id)->orderBy('name')->get(['id', 'name', 'version']),
            'familyWord' => $user->organization->sellsProducts() ? 'famille' : 'catégorie',
        ]);
    }

    public function mapping(Request $request, ImportBatch $batch, SalesImport $import): RedirectResponse
    {
        $user = $this->authorizeBatch($request, $batch);

        if (! $user->organization->salesImportOpen()) {
            return back()->with('error', 'La reprise de l’historique est terminée : les ventes se saisissent maintenant dans NENTIX. Un administrateur peut la rouvrir dans Paramètres › Modules.');
        }

        if (! $batch->isOpen()) {
            return back()->with('error', 'Cet import est déjà confirmé.');
        }

        $columns = count($batch->headers ?? []);
        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'integer', 'min:0', 'max:'.max(0, $columns - 1)],
            'default_category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('organization_id', $user->organization_id)],
            'save_as' => ['nullable', 'string', 'max:100'],
        ]);

        $mapping = array_filter($data['mapping'], fn ($v) => $v !== null);
        $missing = [];

        if (! isset($mapping['date'])) {
            $missing[] = 'la date';
        }
        if (! isset($mapping['store']) && $batch->store_id === null) {
            $missing[] = 'le point de vente (ou fixez-le au dépôt)';
        }
        if (! isset($mapping['line_total']) && ! isset($mapping['unit_price'])) {
            $missing[] = 'le montant (ou le prix unitaire)';
        }
        // Famille facultative sans le module Familles, ou avec le catalogue quand l'article est associé.
        $organization = $user->organization;
        $familyOptional = ! $organization->hasModule('familles')
            || ($organization->hasModule('catalogue') && (isset($mapping['article']) || isset($mapping['article_code'])));
        if (! $familyOptional && ! isset($mapping['family']) && ! isset($mapping['category']) && empty($data['default_category_id'])) {
            $missing[] = 'la famille ou la catégorie (ou une famille par défaut)';
        }

        if ($missing !== []) {
            return back()->withErrors(['mapping' => 'Associez au moins '.implode(', ', $missing).'.']);
        }

        $import->validate($batch, $mapping, $data['default_category_id'] ?? null, $user, $data['save_as'] ?? null);

        return redirect()->route('sales-imports.show', $batch);
    }

    public function confirm(Request $request, ImportBatch $batch, SalesImport $import): RedirectResponse
    {
        $user = $this->authorizeBatch($request, $batch);

        if (! $user->organization->salesImportOpen()) {
            return back()->with('error', 'La reprise de l’historique est terminée : les ventes se saisissent maintenant dans NENTIX. Un administrateur peut la rouvrir dans Paramètres › Modules.');
        }

        if ($batch->status !== ImportBatch::STATUS_VALIDATED) {
            return back()->with('error', 'Vérifiez d’abord le fichier.');
        }

        if ($batch->accepted_rows === 0) {
            return back()->with('error', 'Aucune ligne valide à importer.');
        }

        $import->confirm($batch);

        return redirect()->route('sales-imports.show', $batch)->with('success', 'Import lancé : les ventes sont créées en arrière-plan.');
    }

    public function cancel(Request $request, ImportBatch $batch, SalesImport $import): RedirectResponse
    {
        $user = $this->authorizeBatch($request, $batch);

        if (in_array($batch->status, [ImportBatch::STATUS_CANCELLED, ImportBatch::STATUS_PROCESSING], true)) {
            return back()->with('error', $batch->status === ImportBatch::STATUS_PROCESSING ? 'Import en cours : attendez la fin du traitement pour l’annuler.' : 'Import déjà annulé.');
        }

        $deleted = $import->cancel($batch, $user);

        return redirect()->route('sales-imports.show', $batch)->with('success', $deleted > 0
            ? "Import annulé : {$deleted} vente(s) supprimée(s). Le fichier peut être déposé à nouveau."
            : 'Import annulé.');
    }

    /** Rapport d'erreurs (lignes rejetées et doublons) en CSV ou XLSX. */
    public function errors(Request $request, ImportBatch $batch, SalesImport $import): StreamedResponse
    {
        $this->authorizeBatch($request, $batch);

        [$headers, $rows] = $import->errorRows($batch);
        $name = 'erreurs-import-'.$batch->id;

        if ($request->query('format') === 'xlsx') {
            $sheet = new Spreadsheet;
            $sheet->getActiveSheet()->setTitle('Erreurs')->fromArray([$headers, ...$rows], null, 'A1', true);

            return response()->streamDownload(fn () => (new Xlsx($sheet))->save('php://output'), "{$name}.xlsx", [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        }

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ';', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, $row, ';', '"', '');
            }
            fclose($out);
        }, "{$name}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function batchSummary(ImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'filename' => $batch->original_filename,
            'status' => $batch->status,
            'status_label' => $batch->statusLabel(),
            'user' => $batch->user?->name,
            'brand' => $batch->brand?->name,
            'store' => $batch->store?->name,
            'created_at' => $batch->created_at?->toIso8601String(),
            'completed_at' => $batch->completed_at?->toIso8601String(),
            'total_rows' => $batch->total_rows,
            'accepted_rows' => $batch->accepted_rows,
            'rejected_rows' => $batch->rejected_rows,
            'duplicate_rows' => $batch->duplicate_rows,
            'tickets_count' => $batch->tickets_count,
            'lines_count' => $batch->lines_count,
            'anonymous_tickets' => $batch->anonymous_tickets,
            'unclassified_lines' => $batch->unclassified_lines,
            'customers_created' => $batch->customers_created,
        ];
    }

    /** Gestion complète : l'administrateur termine la reprise de l'historique (imports fermés). */
    public function closeHistory(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageOrganization(), 403);
        $organization = $request->user()->organization;
        $organization->forceFill(['history_import_closed_at' => now()])->save();

        return redirect('/dashboard')->with('success', 'Reprise de l’historique terminée : vos ventes se saisissent maintenant dans NENTIX.');
    }

    public function reopenHistory(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageOrganization(), 403);
        $request->user()->organization->forceFill(['history_import_closed_at' => null])->save();

        return back()->with('success', 'Import des ventes rouvert. Attention aux doublons avec les ventes déjà saisies.');
    }

    public function updateUsage(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageOrganization(), 403);
        $data = $request->validate(['usage_mode' => ['required', Rule::in(array_keys(Organization::USAGE_MODES))]]);
        $request->user()->organization->forceFill(['usage_mode' => $data['usage_mode']])->save();

        return back()->with('success', 'Mode d’utilisation enregistré.');
    }

    /** Paramètres › Modules : nom des personnes suivies (Clients, Patients, Adhérents, Élèves). */
    public function updateCustomerLabel(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageOrganization(), 403);
        $data = $request->validate(['customer_label' => ['required', Rule::in(array_keys(Organization::CUSTOMER_LABELS))]]);
        $request->user()->organization->forceFill(['customer_label' => $data['customer_label']])->save();

        return back()->with('success', 'Nom enregistré : le menu s’adapte.');
    }

    private function authorizeImport(Request $request): User
    {
        $user = $request->user();

        if (! $user->canImport()) {
            throw new HttpException(403, 'Votre rôle ne permet pas d’importer des ventes.');
        }

        return $user;
    }

    private function authorizeBatch(Request $request, ImportBatch $batch): User
    {
        $user = $this->authorizeImport($request);

        if (! $this->scopedBatches($user)->whereKey($batch->id)->exists()) {
            throw new HttpException(403, 'Cet import n’est pas dans votre périmètre.');
        }

        return $user;
    }

    /** Lots visibles : toute l'organisation, ou ceux de la marque / du point de vente du rôle. */
    private function scopedBatches(User $user): Builder
    {
        $visible = $user->visibleStoreIds();

        return ImportBatch::where('organization_id', $user->organization_id)
            ->when($visible !== null, fn ($q) => $q->where(fn ($q) => $q->where('user_id', $user->id)
                ->orWhereIn('store_id', $visible)
                ->orWhere(fn ($q) => $q->whereNull('store_id')->whereIn('brand_id', Store::whereIn('id', $visible)->select('brand_id'))->when($user->role?->scope === Role::SCOPE_STORE, fn ($q) => $q->whereRaw('1 = 0')))));
    }

    private function visibleStores(User $user)
    {
        $visible = $user->visibleStoreIds();

        return Store::where('organization_id', $user->organization_id)
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->orderBy('name')
            ->get(['id', 'name', 'brand_id', 'code']);
    }
}
