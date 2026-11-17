<?php

namespace App\Modules\Location\Http;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Task;
use App\Modules\Location\Models\RideFare;
use App\Modules\Location\Services\RentalDesk;
use App\Services\PhoneNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Demande de course en ligne, sans compte (/rdv/{slug}/course) : le
 * passager indique départ (ou sa position), destination, maintenant ou plus
 * tard, passagers, bagages, vol, nom et WhatsApp. Une demande « Course »
 * (source Lien en ligne) et la fiche client sont créées ; l'équipe la voit
 * dans « À faire » et sur le tableau de bord, puis confirme sur WhatsApp en
 * affectant véhicule et chauffeur.
 */
class PublicRideController extends Controller
{
    public function show(Organization $organization): Response
    {
        abort_unless($organization->hasModule('location'), 404);

        return Inertia::render('Location/RideRequest', [
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'stores' => $organization->stores()->orderBy('name')->get(['id', 'name', 'city']),
            'fares' => RideFare::where('organization_id', $organization->id)->orderBy('label')->get(['id', 'label', 'price']),
            'sent' => (bool) session('ride_sent'),
        ]);
    }

    public function store(Request $request, Organization $organization, RentalDesk $desk): RedirectResponse
    {
        abort_unless($organization->hasModule('location'), 404);

        $data = $request->validate([
            'store_id' => ['nullable', 'integer'],
            'ride_from' => ['required', 'string', 'max:255'],
            'ride_to' => ['required', 'string', 'max:255'],
            'when' => ['required', 'in:now,later'],
            'starts_at' => ['nullable', 'required_if:when,later', 'date', 'after:now'],
            'ride_passengers' => ['nullable', 'integer', 'min:1', 'max:30'],
            'ride_luggage' => ['nullable', 'integer', 'min:0', 'max:30'],
            'flight_number' => ['nullable', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'website' => ['nullable', 'max:0'], // champ caché anti-robots
        ]);

        if (PhoneNormalizer::normalize($data['phone']) === null) {
            throw ValidationException::withMessages(['phone' => 'Numéro WhatsApp invalide.']);
        }

        $store = $organization->stores()->whereKey($data['store_id'] ?? 0)->first() ?? $organization->stores()->orderBy('id')->firstOrFail();
        $owner = $organization->users()->orderBy('id')->firstOrFail();

        $vehicleRequest = $desk->createRequest($owner, [
            'store_id' => $store->id, 'name' => $data['name'], 'phone' => $data['phone'], 'need' => 'ride', 'source' => 'online',
            'starts_at' => $data['when'] === 'later' ? $data['starts_at'] : now()->toDateTimeString(),
            'ride_from' => $data['ride_from'], 'ride_to' => $data['ride_to'],
            'ride_passengers' => $data['ride_passengers'] ?? null, 'ride_luggage' => $data['ride_luggage'] ?? null,
            'flight_number' => $data['flight_number'] ?? null,
            'notes' => $data['when'] === 'now' ? 'Course demandée pour maintenant (lien en ligne).' : null,
        ]);
        $vehicleRequest->forceFill(['created_by' => null])->save();

        Task::open([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'customer_id' => $vehicleRequest->customer_id,
            'kind' => 'ride_request', 'title' => 'Demande de course : '.$vehicleRequest->summary(),
            'details' => ($data['when'] === 'now' ? 'Maintenant. ' : '').trim(($data['ride_passengers'] ?? 1).' passager(s), '.($data['ride_luggage'] ?? 0).' bagage(s)'),
        ]);

        return redirect("/rdv/{$organization->slug}/course")->with('ride_sent', true);
    }
}
