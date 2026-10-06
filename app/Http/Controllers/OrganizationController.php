<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Organization;
use App\Services\CustomerLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->organization;

        $brands = Brand::where('organization_id', $organization->id)
            ->withCount('stores')
            ->with('stores:id,brand_id,name')
            ->orderBy('name')
            ->get();

        return Inertia::render('Organisation/Index', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'lifecycleSettings' => $organization->lifecycleSettings(),
            'lifecycleDefaults' => Organization::LIFECYCLE_DEFAULTS,
            'brands' => $brands->map(fn (Brand $brand) => [
                'id' => $brand->id,
                'name' => $brand->name,
                'stores_count' => $brand->stores_count,
                'stores' => $brand->stores->map(fn ($store) => [
                    'id' => $store->id,
                    'name' => $store->name,
                ]),
            ]),
        ]);
    }

    /**
     * Seuils du parcours client, puis recalcul immédiat de toutes les fiches.
     */
    public function updateLifecycle(Request $request, CustomerLifecycle $lifecycle): RedirectResponse
    {
        $data = $request->validate([
            'new_customer_days' => ['required', 'integer', 'min:1', 'max:730'],
            'at_risk_days' => ['required', 'integer', 'min:1', 'max:1095', 'lt:churn_days'],
            'churn_days' => ['required', 'integer', 'min:2', 'max:1825'],
            'vip_top_percent' => ['required', 'integer', 'min:0', 'max:50'],
            'vip_period_days' => ['required', 'integer', 'min:30', 'max:1095'],
        ], [
            'at_risk_days.lt' => 'Le délai « à risque » doit être plus court que le délai « inactif ».',
        ]);

        $organization = $request->user()->organization;
        $organization->update(['lifecycle_settings' => array_map('intval', $data)]);

        $changes = $lifecycle->refreshOrganization($organization);

        return back()->with('success', "Seuils du parcours client enregistrés : {$changes} fiche(s) ont changé d'étape.");
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $request->user()->organization->update($data);

        return back()->with('success', 'Organisation mise à jour.');
    }
}
