<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paramètres › Occupation : seuils du moteur d'occupation (creux, saturation,
 * immobilisation). Les valeurs par défaut dépendent du secteur.
 */
class OccupationController extends Controller
{
    public function settings(Request $request): Response
    {
        $organization = $request->user()->organization;

        return Inertia::render('Settings/Occupation', [
            'settings' => $organization->occupationSettings(),
            'defaults' => [...Organization::OCCUPATION_DEFAULTS, ...(Organization::OCCUPATION_SECTOR_DEFAULTS[$organization->sector] ?? [])],
            'modules' => [
                'rdv' => $organization->hasModule('rdv'),
                'seances_groupe' => $organization->hasModule('seances_groupe'),
                'ressources' => $organization->hasModule('ressources'),
                'vehicules' => $organization->hasModule('vehicules'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'low' => ['required', 'integer', 'min:0', 'max:100'],
            'high' => ['required', 'integer', 'min:1', 'max:100', 'gt:low'],
            'window_days' => ['required', 'integer', 'min:1', 'max:14'],
            'class_low' => ['required', 'integer', 'min:0', 'max:100'],
            'class_window_days' => ['required', 'integer', 'min:1', 'max:60'],
            'idle_days' => ['required', 'integer', 'min:1', 'max:365'],
            'safety_stock' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'sale_days' => ['sometimes', 'integer', 'min:1', 'max:730'],
        ], [
            'high.gt' => 'Le seuil de saturation doit être supérieur au seuil de creux.',
        ]);

        $organization = $request->user()->organization;
        $organization->update(['occupation_settings' => array_map('intval', [...$organization->occupationSettings(), ...$data])]);
        Audit::record('settings.occupation', $organization, 'Seuils d’occupation modifiés', $data);

        return back()->with('success', 'Seuils d’occupation enregistrés.');
    }
}
