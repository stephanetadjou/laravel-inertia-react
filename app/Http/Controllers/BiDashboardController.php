<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\BiDashboard;
use App\Services\CustomerSearch;
use App\Services\RfmCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Analyses › Pilotage : mêmes filtres de base que les tableaux de bord
 * (période, marque, point de vente, famille, bornés au périmètre du rôle),
 * plus le segment RFM et le statut fidélité.
 */
class BiDashboardController extends AnalyticsController
{
    public function dashboard(Request $request, string $tab = 'direction'): Response
    {
        abort_unless(array_key_exists($tab, $this->tabs($request)), 404);
        [$dashboard, $filters, $context] = $this->build($request);

        return Inertia::render('Pilotage/Index', [
            'tab' => $tab,
            'tabs' => collect($this->tabs($request))->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'filters' => $filters,
            ...$context,
            'segments' => collect(RfmCalculator::SEGMENTS)->map(fn ($s, $key) => ['value' => $key, 'label' => $s['label']])->values(),
            'loyaltyStatuses' => $request->user()->organization->loyaltyPrograms()->exists()
                ? collect(CustomerSearch::LOYALTY_STATUSES)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values()
                : [],
            'data' => $dashboard->tab($tab),
            'canExport' => $request->user()->canExport(),
        ]);
    }

    public function exportList(Request $request, string $tab, string $list): StreamedResponse
    {
        abort_unless(array_key_exists($tab, $this->tabs($request)), 404);

        if (! $request->user()->canExport()) {
            throw new HttpException(403, 'Votre rôle ne permet pas d’exporter des données.');
        }

        [$dashboard, $filters] = $this->build($request);
        $data = $dashboard->list($tab, $list);
        abort_if($data === null, 404);

        Audit::export('pilotage '.BiDashboard::TABS[$tab].' › '.$data['title'], count($data['rows']), [...$filters, 'personal' => $data['personal'] ?? false]);

        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->setTitle(mb_substr(str_replace(['/', '\\', '?', '*', ':', '[', ']'], ' ', $data['title']), 0, 31))
            ->fromArray([$data['columns'], ...$data['rows']], null, 'A1', true);

        return response()->streamDownload(fn () => (new Xlsx($sheet))->save('php://output'), "menentia-{$tab}-{$list}-".now()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @return array{0: BiDashboard, 1: array<string, mixed>, 2: array<string, mixed>} */
    private function build(Request $request): array
    {
        [$analytics, $filters, $context] = $this->resolve($request);
        $user = $request->user();

        $segment = array_key_exists((string) $request->query('segment'), RfmCalculator::SEGMENTS) ? (string) $request->query('segment') : null;
        $loyalty = array_key_exists((string) $request->query('loyalty_status'), CustomerSearch::LOYALTY_STATUSES) ? (string) $request->query('loyalty_status') : null;
        $filters += ['segment' => $segment, 'loyalty_status' => $loyalty];

        $storeIds = collect($context['stores'])->pluck('value')->all();
        $storeIds = $filters['store'] ? [$filters['store']] : $storeIds;

        return [
            new BiDashboard($user, $user->organization, $analytics, $storeIds, Carbon::parse($filters['from'])->startOfDay(), Carbon::parse($filters['to'])->endOfDay(),
                ['segment' => $segment, 'loyalty_status' => $loyalty], $filters['brand']),
            $filters,
            $context,
        ];
    }

    /** @return array<string, string> onglets de cette entreprise */
    private function tabs(Request $request): array
    {
        $organization = $request->user()->organization;

        return collect(BiDashboard::TABS)
            ->reject(fn ($label, $key) => $key === 'fidelite' && ! ($organization->hasModule('fidelite') && $organization->loyaltyPrograms()->exists()))
            ->all();
    }
}
