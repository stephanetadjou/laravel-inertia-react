<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Models\Store;
use App\Services\PhoneNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Paramètres › Réservation en ligne : ouverture de la page publique,
 * confirmation automatique ou non, créneaux ; horaires, capacité et numéro
 * WhatsApp de chaque établissement ; prestations réservables.
 */
class BookingSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->organization;

        return Inertia::render('Settings/Booking', [
            'settings' => $organization->bookingSettings(),
            'url' => $organization->bookingUrl(),
            'stores' => $organization->stores()->orderBy('name')->get()->map(fn (Store $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'hours' => $s->bookingHours(),
                'capacity' => max(1, (int) $s->booking_capacity),
                'break' => $s->bookingBreak(),
                'phone' => $s->booking_phone ?? '',
                'whatsapp_display_phone' => $s->whatsapp_display_phone_number,
            ]),
            'services' => CatalogItem::where('organization_id', $organization->id)
                ->where('type', CatalogItem::TYPE_SERVICE)
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'price', 'duration_minutes', 'bookable_online']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'auto_confirm' => ['required', 'boolean'],
            'slot_step' => ['required', 'integer', Rule::in([10, 15, 20, 30, 45, 60])],
            'min_notice_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'horizon_days' => ['required', 'integer', 'min:1', 'max:180'],
            'welcome' => ['nullable', 'string', 'max:500'],
            // Prestations proposées sur la page publique.
            'bookable_ids' => ['nullable', 'array'],
            'bookable_ids.*' => ['integer'],
        ]);

        $organization = $request->user()->organization;
        $organization->update(['booking_settings' => [...collect($data)->except('bookable_ids')->all(), 'welcome' => $data['welcome'] ?? '']]);

        if (array_key_exists('bookable_ids', $data)) {
            $services = CatalogItem::where('organization_id', $organization->id)->where('type', CatalogItem::TYPE_SERVICE);
            (clone $services)->update(['bookable_online' => false]);
            (clone $services)->whereKey($data['bookable_ids'] ?? [])->update(['bookable_online' => true]);
        }

        return back()->with('success', $data['enabled'] ? 'Réservation en ligne enregistrée : la page est ouverte.' : 'Réservation en ligne enregistrée (page fermée).');
    }

    public function updateStore(Request $request, Store $store): RedirectResponse
    {
        if ($store->organization_id !== $request->user()->organization_id) {
            throw new HttpException(403, "Cet établissement n'appartient pas à votre entreprise.");
        }

        $data = $request->validate([
            'hours' => ['required', 'array'],
            'hours.*' => ['nullable', 'array'],
            'hours.*.open' => ['required_with:hours.*', 'date_format:H:i'],
            'hours.*.close' => ['required_with:hours.*', 'date_format:H:i'],
            'capacity' => ['required', 'integer', 'min:1', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            // Pause quotidienne (déjeuner), facultative.
            'break' => ['nullable', 'array'],
            'break.start' => ['required_with:break', 'date_format:H:i'],
            'break.end' => ['required_with:break', 'date_format:H:i', 'after:break.start'],
        ], [
            'break.end.after' => 'La fin de la pause doit suivre son début.',
        ]);

        $hours = [];
        foreach (range(1, 7) as $day) {
            $value = $data['hours'][$day] ?? null;
            if ($value !== null && $value['open'] >= $value['close']) {
                throw ValidationException::withMessages(["hours.{$day}" => 'L’heure de fermeture doit suivre l’heure d’ouverture.']);
            }
            $hours[$day] = $value ? ['open' => $value['open'], 'close' => $value['close']] : null;
        }

        $phone = null;
        if (! empty($data['phone'])) {
            $phone = PhoneNormalizer::normalize($data['phone'])
                ?? throw ValidationException::withMessages(['phone' => 'Numéro invalide : attendu '.PhoneNormalizer::EXPECTED_FORMAT.'.']);
        }

        $store->update([
            'booking_hours' => $hours,
            'booking_capacity' => $data['capacity'],
            'booking_phone' => $phone,
            'booking_break' => isset($data['break']) ? ['start' => $data['break']['start'], 'end' => $data['break']['end']] : null,
        ]);

        return back()->with('success', "Horaires de « {$store->name} » enregistrés.");
    }
}
