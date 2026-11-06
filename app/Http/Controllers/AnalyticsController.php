<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesBrandFilter;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Store;
use App\Services\Audit;
use App\Services\RetailAnalytics;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Analyses NENTIX Retail : tableaux de bord Direction, Marques & points de
 * vente, Familles, Clientèle, Rétention et Qualité des imports, filtres
 * communs (période, marque, point de vente, famille) bornés par le périmètre
 * du rôle ; export CSV / Excel de chaque tableau et rapport de direction
 * imprimable (PDF si barryvdh/laravel-dompdf est installé). Chaque export
 * est journalisé.
 */
class AnalyticsController extends Controller
{
    use ResolvesBrandFilter;

    public const TABS = [
        'direction' => 'Direction',
        'network' => 'Marques & points de vente',
        'families' => 'Familles',
        'clientele' => 'Clientèle',
        'retention' => 'Rétention',
        'quality' => 'Qualité des imports',
    ];

    public const PERIODS = ['30d' => '30 jours', '90d' => '90 jours', '12m' => '12 mois', 'ytd' => 'Depuis janvier'];

    public const ACTIVE_WINDOWS = [30, 60, 90, 180, 365];

    public function index(Request $request, string $tab = 'direction'): Response
    {
        abort_unless(array_key_exists($tab, self::TABS), 404);
        [$analytics, $filters, $context] = $this->resolve($request);

        return Inertia::render('Analytics/Index', [
            'tab' => $tab,
            'tabs' => collect(self::TABS)->map(fn ($label, $key) => ['key' => $key, 'label' => $key === 'families' ? $this->familiesLabel($request->user()->organization) : $label])->values(),
            'filters' => $filters,
            ...$context,
            'data' => $analytics->{$tab}(),
            'canExport' => $request->user()->canExport(),
        ]);
    }

    /** Export d'un tableau d'un onglet, avec les filtres de l'écran. */
    public function export(Request $request, string $tab, string $table): StreamedResponse
    {
        abort_unless(array_key_exists($tab, self::TABS), 404);

        if (! $request->user()->canExport()) {
            throw new HttpException(403, 'Votre rôle ne permet pas d’exporter des données.');
        }

        [$analytics, $filters] = $this->resolve($request);
        $data = collect($analytics->{$tab}()['tables'])->firstWhere('key', $table);
        abort_if($data === null, 404);

        Audit::export('analyse '.self::TABS[$tab].' › '.$data['title'], count($data['rows']), [...$filters, 'personal' => $data['personal'] ?? false]);

        $name = 'menentia-'.$tab.'-'.$table.'-'.now()->format('Y-m-d');
        $rows = [$data['columns'], ...$data['rows']];

        if ($request->query('format') === 'xlsx') {
            $sheet = new Spreadsheet;
            $sheet->getActiveSheet()->setTitle(mb_substr($data['title'], 0, 31))->fromArray($rows, null, 'A1', true);

            return response()->streamDownload(fn () => (new Xlsx($sheet))->save('php://output'), "{$name}.xlsx", [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($out, $row, ';', '"', '');
            }
            fclose($out);
        }, "{$name}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Rapport de direction (période et filtres de l'écran) : page imprimable ou PDF. */
    public function report(Request $request): HttpResponse
    {
        if (! $request->user()->canExport()) {
            throw new HttpException(403, 'Votre rôle ne permet pas d’exporter des données.');
        }

        [$analytics, $filters, $context] = $this->resolve($request);
        $organization = $request->user()->organization;

        $view = [
            'organization' => $organization,
            'filters' => $filters,
            'scopeLabel' => $context['scopeLabel'],
            'direction' => $analytics->direction(),
            'network' => $analytics->network(),
            'families' => $analytics->families(),
            'retention' => $analytics->retention(),
            'currency' => $organization->currencySymbol(),
            'autoPrint' => $request->boolean('print'),
        ];

        Audit::export('rapport de direction', 1, $filters);

        if ($request->query('format') === 'pdf' && class_exists(Pdf::class)) {
            return Pdf::loadView('reports.retail', $view)->setPaper('a4')->download('rapport-direction-'.now()->format('Y-m-d').'.pdf');
        }

        return response()->view('reports.retail', $view);
    }

    /**
     * Filtres communs bornés par le périmètre : période, marque, point de
     * vente, famille (et ses sous-familles), fenêtre « clients actifs ».
     *
     * @return array{0: RetailAnalytics, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    protected function resolve(Request $request): array
    {
        $user = $request->user();
        $organization = $user->organization;
        $visible = $user->visibleStoreIds();
        $brandFilter = $this->resolveBrandFilter($request, $user, $visible);

        $roleStores = Store::where('organization_id', $organization->id)
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->with('brand:id,name')
            ->orderBy('name')
            ->get();
        $brandStores = $brandFilter['selected'] !== null ? $roleStores->where('brand_id', $brandFilter['selected'])->values() : $roleStores;
        $storeId = $brandStores->contains('id', $request->integer('store')) ? $request->integer('store') : null;
        $storeIds = $storeId !== null ? [$storeId] : $brandStores->pluck('id')->all();

        [$from, $to, $preset] = $this->period($request);

        $family = $request->integer('family') ? Category::where('organization_id', $organization->id)->find($request->integer('family')) : null;
        $categoryIds = $family ? [$family->id, ...$family->children()->pluck('id')->all()] : null;

        $activeDays = in_array($request->integer('active_days', 90), self::ACTIVE_WINDOWS, true) ? $request->integer('active_days', 90) : 90;

        $filters = [
            'period' => $preset,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'brand' => $brandFilter['selected'],
            'brand_locked' => $brandFilter['locked'],
            'brand_hidden' => $brandFilter['hidden'],
            'store' => $storeId,
            'family' => $family?->id,
            'active_days' => $activeDays,
        ];

        $brandName = $brandFilter['selected'] ? Brand::whereKey($brandFilter['selected'])->value('name') : null;
        $storeName = $storeId ? $roleStores->firstWhere('id', $storeId)?->name : null;

        return [
            new RetailAnalytics($organization, $storeIds, $from, $to, $categoryIds, $activeDays),
            $filters,
            [
                'periods' => collect(self::PERIODS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
                'activeWindows' => self::ACTIVE_WINDOWS,
                'brands' => $roleStores->pluck('brand')->filter()->unique('id')->sortBy('name')->values()->map(fn ($b) => ['value' => $b->id, 'label' => $b->name]),
                'stores' => $brandStores->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values(),
                'families' => Category::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name', 'parent_id'])
                    ->map(fn ($c) => ['value' => $c->id, 'label' => $c->parent_id ? '— '.$c->name : $c->name]),
                'currency' => $organization->currencySymbol(),
                'scopeLabel' => collect([$storeName ?? $brandName ?? ($visible === null ? 'Tout le groupe' : 'Votre périmètre'), $family?->name])->filter()->implode(' · '),
                'analysisLevel' => $organization->analysisLevel(),
                // Filtres de base des audiences (export / relance des clients d'un chiffre) : le périmètre choisi.
                'audienceBase' => array_filter(['brand' => $brandFilter['locked'] ? null : $brandFilter['selected'], 'store' => $storeId]),
            ],
        ];
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string|null} */
    private function period(Request $request): array
    {
        if ($request->filled('from') && $request->filled('to')) {
            try {
                $from = Carbon::parse((string) $request->query('from'))->startOfDay();
                $to = Carbon::parse((string) $request->query('to'))->endOfDay();

                if ($from->lte($to)) {
                    return [$from, $to, null];
                }
            } catch (\Throwable) {
                // Dates illisibles : période par défaut.
            }
        }

        $preset = array_key_exists((string) $request->query('period'), self::PERIODS) ? (string) $request->query('period') : '12m';

        return [
            match ($preset) {
                '30d' => today()->subDays(29),
                '90d' => today()->subDays(89),
                'ytd' => today()->startOfYear(),
                default => today()->subMonthsNoOverflow(12)->addDay(),
            },
            today()->endOfDay(),
            $preset,
        ];
    }

    private function familiesLabel(Organization $organization): string
    {
        return match ($organization->analysisLevel()) {
            'product' => 'Articles',
            'category' => 'Catégories',
            default => 'Familles',
        };
    }
}
