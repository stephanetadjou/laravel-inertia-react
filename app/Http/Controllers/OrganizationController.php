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
            'privacy' => [
                'url' => $organization->privacyPolicyUrl(),
                'legal_name' => $organization->legal_name,
                'email' => $organization->privacy_email,
                'phone' => $organization->privacy_phone,
                'address' => $organization->privacy_address,
                'extra' => $organization->privacy_extra,
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

    /**
     * Coordonnées du responsable du traitement affichées sur la politique de
     * confidentialité publique (/confidentialite/{slug}).
     */
    public function updatePrivacy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'extra' => ['nullable', 'string', 'max:5000'],
        ], [
            'email.required' => 'Un courriel de contact est nécessaire pour que les clients exercent leurs droits.',
        ]);

        $request->user()->organization->update([
            'legal_name' => $data['legal_name'] ?? null,
            'privacy_email' => $data['email'],
            'privacy_phone' => $data['phone'] ?? null,
            'privacy_address' => $data['address'] ?? null,
            'privacy_extra' => $data['extra'] ?? null,
        ]);

        return back()->with('success', 'Politique de confidentialité mise à jour.');
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
