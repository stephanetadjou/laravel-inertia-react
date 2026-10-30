<?php

namespace App\Http\Controllers;

use App\Jobs\RefreshBrandMetricsJob;
use App\Models\Brand;
use App\Models\Organization;
use App\Services\Audit;
use App\Services\CustomerScoring;
use App\Services\RfmCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Analyses › Multimarque : clients mono / multimarques, clients communs entre
 * marques, CA et tickets des multimarques, nouveaux / récurrents et
 * segments RFM par marque (customer_brand_metrics). Et Paramètres ›
 * Segments RFM : période et seuils de la segmentation.
 */
class MultibrandController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $organization = $user->organization;
        $visible = $user->visibleStoreIds();

        // Marques du périmètre : un responsable de marque ne voit que la sienne.
        $brands = Brand::where('organization_id', $organization->id)
            ->when($visible !== null, fn ($q) => $q->whereIn('id', DB::table('stores')->whereIn('id', $visible ?: [0])->select('brand_id')))
            ->orderBy('name')
            ->get(['id', 'name']);
        $brandIds = $brands->pluck('id')->all();
        $since = $request->integer('months', 12) > 0 ? today()->subMonths($request->integer('months', 12))->toDateString() : null;

        $metrics = DB::table('customer_brand_metrics')
            ->where('organization_id', $organization->id)
            ->whereIn('brand_id', $brandIds ?: [0])
            ->where('order_count', '>', 0)
            ->when($since, fn ($q) => $q->where('last_purchase_at', '>=', $since))
            ->get(['customer_id', 'brand_id', 'first_purchase_at', 'order_count', 'net_spend', 'rfm_segment']);

        $byCustomer = $metrics->groupBy('customer_id');
        // Nombre de marques du client dans tout le groupe (et pas seulement dans le périmètre affiché).
        $multi = $byCustomer->filter(fn ($rows) => $rows->count() >= 2);
        $mono = $byCustomer->filter(fn ($rows) => $rows->count() < 2);

        $matrix = [];
        foreach ($brands as $a) {
            $buyersA = $metrics->where('brand_id', $a->id)->pluck('customer_id')->flip();
            $matrix[] = [
                'brand_id' => $a->id,
                'brand' => $a->name,
                'customers' => $buyersA->count(),
                'shared' => $brands->map(fn ($b) => $a->id === $b->id ? null : $metrics->where('brand_id', $b->id)->filter(fn ($r) => $buyersA->has($r->customer_id))->count())->values(),
            ];
        }

        $sinceYear = today()->subYear()->toDateString();

        return Inertia::render('Multibrand/Index', [
            'months' => $request->integer('months', 12),
            'brands' => $brands->map(fn ($b) => ['id' => $b->id, 'name' => $b->name]),
            'kpis' => [
                'customers' => $byCustomer->count(),
                'multi' => $multi->count(),
                'multi_share' => $byCustomer->count() > 0 ? (int) round($multi->count() / $byCustomer->count() * 100) : 0,
                'multi_spend' => (int) $multi->flatten(1)->sum('net_spend'),
                'mono_spend' => (int) $mono->flatten(1)->sum('net_spend'),
                'multi_orders' => (int) $multi->flatten(1)->sum('order_count'),
                'multi_avg_spend' => $multi->count() > 0 ? (int) round($multi->flatten(1)->sum('net_spend') / $multi->count()) : 0,
                'mono_avg_spend' => $mono->count() > 0 ? (int) round($mono->flatten(1)->sum('net_spend') / $mono->count()) : 0,
            ],
            'matrix' => $matrix,
            'perBrand' => $brands->map(function ($brand) use ($metrics, $byCustomer, $sinceYear) {
                $rows = $metrics->where('brand_id', $brand->id);

                return [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'customers' => $rows->count(),
                    'new' => $rows->where('first_purchase_at', '>=', $sinceYear)->count(),
                    'returning' => $rows->where('first_purchase_at', '<', $sinceYear)->count(),
                    'also_elsewhere' => $rows->filter(fn ($r) => $byCustomer->get($r->customer_id)->count() >= 2)->count(),
                    'net' => (int) $rows->sum('net_spend'),
                    'orders' => (int) $rows->sum('order_count'),
                    'segments' => $rows->countBy('rfm_segment')->all(),
                ];
            })->values(),
            'segments' => collect(RfmCalculator::SEGMENTS)->except('none')->map(fn ($s, $key) => ['key' => $key, 'label' => $s['label']])->values(),
            'currency' => $organization->currencySymbol(),
            // Clients actifs sur la fenêtre choisie (export, relance).
            'since' => $since,
        ]);
    }

    public function settings(Request $request): Response
    {
        $organization = $request->user()->organization;

        return Inertia::render('Settings/Rfm', [
            'settings' => $organization->rfmSettings(),
            'defaults' => Organization::RFM_DEFAULTS,
            'segments' => collect(RfmCalculator::SEGMENTS)->map(fn ($s, $key) => ['key' => $key, 'label' => $s['label'], 'action' => $s['action']])->values(),
        ]);
    }

    public function update(Request $request, CustomerScoring $scoring): RedirectResponse
    {
        $score = ['required', 'integer', 'min:1', 'max:5'];
        $data = $request->validate([
            'period_months' => ['required', 'integer', 'in:0,6,12,24,36'],
            'active_min_r' => ['required', 'integer', 'min:2', 'max:5'],
            'champions_min_r' => $score,
            'champions_min_fm' => $score,
            'loyal_min_fm' => $score,
            'cant_lose_min_fm' => $score,
            'at_risk_min_fm' => $score,
        ]);

        $organization = $request->user()->organization;
        $settings = [...array_map('intval', $data), 'version' => $organization->rfmSettings()['version'] + 1];
        $organization->update(['rfm_settings' => $settings]);

        Audit::record('settings.updated', $organization, "Règles RFM (version {$settings['version']})", $settings);

        $scoring->refreshOrganization($organization);
        RefreshBrandMetricsJob::dispatch($organization->id);

        return back()->with('success', "Règles enregistrées (version {$settings['version']}) : segments recalculés.");
    }
}
