<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Store;
use App\Services\Audit;
use App\Services\PdfReport;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Analyses › Rapports : rapports A4 en PDF, sur le périmètre du rôle. */
class PdfReportController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $organization = $user->organization;
        $visible = $user->visibleStoreIds();
        $months = collect(range(0, 17))->map(fn ($i) => now()->startOfMonth()->subMonthsNoOverflow($i))
            ->map(fn ($m) => ['value' => $m->format('Y-m'), 'label' => $m->locale('fr')->isoFormat('MMMM YYYY')]);
        $hasPrograms = $organization->hasModule('fidelite') && $organization->loyaltyPrograms()->exists();

        return Inertia::render('Reports/Index', [
            'months' => $months,
            'stores' => Store::where('organization_id', $organization->id)->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))->orderBy('name')->get(['id as value', 'name as label']),
            'types' => collect(PdfReport::TYPES)->reject(fn ($label, $key) => $key === 'fidelite' && ! $hasPrograms)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'canExport' => $user->canExport(),
        ]);
    }

    public function download(Request $request, string $type): HttpResponse
    {
        abort_unless(array_key_exists($type, PdfReport::TYPES), 404);
        $this->authorizeExport($request);

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'store' => ['nullable', 'integer', Rule::requiredIf($type === 'magasin')],
        ], ['store.required' => 'Choisissez un magasin.']);

        $pdf = (new PdfReport($request->user()))->monthly($type, $data['month'], $data['store'] ?? null);
        Audit::export('rapport PDF '.PdfReport::TYPES[$type], 1, $data);

        return $pdf->download("menentia-{$type}-{$data['month']}.pdf");
    }

    public function customer(Request $request, Customer $customer): HttpResponse
    {
        $this->authorizeExport($request);
        $user = $request->user();
        $visible = $user->visibleStoreIds();
        abort_unless($customer->organization_id === $user->organization_id && ($visible === null || $customer->contacts()->whereIn('store_id', $visible)->exists()), 404);

        $pdf = (new PdfReport($user))->customer($customer);
        Audit::record('export', $customer, "Fiche client PDF de {$customer->name}");

        return $pdf->download("fiche-client-{$customer->id}.pdf");
    }

    private function authorizeExport(Request $request): void
    {
        if (! $request->user()->canExport()) {
            throw new HttpException(403, 'Votre rôle ne permet pas d’exporter des données.');
        }
    }
}
