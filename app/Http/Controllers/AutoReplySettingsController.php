<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Audit;
use App\Services\AutoReplies;
use App\Support\AutoReplyDefaults;
use App\Support\SectorPresets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paramètres › Réponses automatiques : accueil, hors horaires, menu à
 * boutons et rappel de la veille, avec un aperçu façon téléphone. Les
 * horaires sont ceux de l'établissement (Paramètres › Réservation en ligne,
 * ou horaires par défaut).
 */
class AutoReplySettingsController extends Controller
{
    private const TEXTS = ['welcome', 'away', 'menu_intro', 'prices_intro', 'prices_empty', 'hours_reply', 'booking_reply', 'callback_reply', 'human_reply', 'reminder', 'reminder_confirmed', 'reminder_reschedule'];

    public function edit(Request $request): Response
    {
        $organization = $request->user()->organization;
        $store = $organization->stores()->orderBy('id')->first();

        return Inertia::render('Settings/AutoReplies', [
            'settings' => $organization->autoReplySettings(),
            'defaults' => AutoReplyDefaults::merge($organization->sector, []),
            'options' => collect(AutoReplyDefaults::OPTIONS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'preview' => [
                'store' => $store?->name ?? $organization->name,
                'hours' => $store ? AutoReplies::hoursText($store) : '',
                'booking_url' => $organization->bookingUrl(),
                'has_agenda' => $organization->hasAgenda() && $organization->bookingSettings()['enabled'],
            ],
            'reminders' => $organization->hasModule('rappels_rdv'),
            'apiEnabled' => $organization->usesWhatsAppApi(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'options' => ['present', 'array', 'max:5'],
            'options.*' => [Rule::in(array_keys(AutoReplyDefaults::OPTIONS))],
            'labels' => ['nullable', 'array'],
            'labels.*' => ['nullable', 'string', 'max:20'],
            'reminder_template' => ['nullable', 'string', 'max:255'],
            'reminder_template_language' => ['nullable', 'string', 'max:10'],
            ...collect(self::TEXTS)->mapWithKeys(fn ($k) => [$k => ['nullable', 'string', 'max:1000']])->all(),
        ]);

        $organization = $request->user()->organization;
        $defaults = AutoReplyDefaults::merge($organization->sector, []);
        // Un texte vidé revient au texte par défaut du secteur.
        $saved = collect($data)->map(fn ($v, $k) => in_array($k, self::TEXTS, true) && blank($v) ? $defaults[$k] : $v)->all();
        $organization->update(['auto_replies' => $saved]);
        Audit::record('settings.auto_replies', $organization, 'Réponses automatiques modifiées', ['enabled' => $data['enabled']]);

        return back()->with('success', 'Réponses automatiques enregistrées.');
    }

    /** Rejoue le préréglage du secteur (complète ce qui manque, sans doublon). */
    public function applyPreset(Request $request): RedirectResponse
    {
        $organization = $request->user()->organization;

        if (! SectorPresets::has($organization->sector)) {
            return back()->with('error', 'Aucun préréglage pour ce secteur.');
        }

        SectorPresets::apply($organization, $request->boolean('reset_modules'));

        return back()->with('success', 'Préréglage « '.Organization::SECTORS[$organization->sector]['label'].' » appliqué.');
    }
}
