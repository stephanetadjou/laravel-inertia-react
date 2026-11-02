<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paramètres › Modules : modules à la carte de l'entreprise et leurs réglages
 * de base (cf. Organization::MODULES). Le socle reste toujours actif.
 */
class ModuleController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->organization;
        $sector = Organization::SECTORS[$organization->sector] ?? null;

        return Inertia::render('Settings/Modules', [
            'modules' => collect(Organization::MODULES)->map(fn ($module, $key) => [
                'key' => $key,
                'label' => $module['label'],
                'description' => $module['description'],
                'enabled' => $organization->hasModule($key),
                'suggested' => in_array($key, [...($sector['modules'] ?? []), ...($sector['suggested'] ?? [])], true),
            ])->values(),
            'settings' => collect(array_keys(Organization::MODULE_DEFAULTS))->mapWithKeys(fn ($key) => [$key => $organization->moduleSettings($key)]),
            'beneficiaryLabels' => collect(Organization::BENEFICIARY_LABELS)->map(fn ($labels, $key) => ['value' => $key, 'label' => $labels[0]])->values(),
            'sectorLabel' => $sector['label'] ?? null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['present', 'array'],
            'enabled.*' => [Rule::in(array_keys(Organization::MODULES))],
            ...self::settingsRules(),
        ]);

        $organization = $request->user()->organization;
        $organization->setModules($data['enabled'], $data['settings'] ?? [], reviewed: true);
        $organization->whatsapp_api_enabled = in_array('campagnes', $data['enabled'], true);
        $organization->save();

        Audit::record('settings.updated', $organization, 'Modules : '.implode(', ', $organization->enabledModules()), $organization->modules);

        return back()->with('success', 'Modules enregistrés : le menu et les fiches clients sont à jour.');
    }

    /**
     * Règles de validation des réglages de base (inscription et Paramètres › Modules).
     *
     * @return array<string, list<mixed>>
     */
    public static function settingsRules(): array
    {
        return [
            'settings' => ['nullable', 'array'],
            'settings.seances_groupe.capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'settings.seances_groupe.makeup_after' => ['nullable', 'integer', 'min:1', 'max:20'],
            'settings.seances_groupe.makeup_weekday' => ['nullable', 'integer', 'min:1', 'max:7'],
            'settings.abonnements.period' => ['nullable', Rule::in(['month', 'season'])],
            'settings.abonnements.season_start' => ['nullable', 'date'],
            'settings.abonnements.season_end' => ['nullable', 'date', 'after:settings.abonnements.season_start'],
            'settings.tranches.due_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'settings.tranches.reminders' => ['nullable', 'array', 'max:5'],
            'settings.tranches.reminders.*' => ['integer', 'min:-30', 'max:60'],
            'settings.beneficiaires.label' => ['nullable', Rule::in(array_keys(Organization::BENEFICIARY_LABELS))],
            'settings.beneficiaires.minor_needs_responsible' => ['nullable', 'boolean'],
            'settings.fidelite.points_per_attendance' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
