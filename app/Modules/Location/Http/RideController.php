<?php

namespace App\Modules\Location\Http;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PlanController;
use App\Models\Purchase;
use App\Modules\Location\Models\Booking;
use App\Modules\Location\Models\RideFare;
use App\Modules\Location\Models\VehicleRequest;
use App\Modules\Location\Services\RentalDesk;
use App\Modules\Location\Services\RideDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Courses / transferts : réservation immédiate ou depuis une demande, étapes, forfaits. */
class RideController extends Controller
{
    /** @return array<string, mixed> */
    private function rules(Request $request, bool $withCustomer): array
    {
        $user = $request->user();

        return [
            ...($withCustomer ? [
                'store_id' => ['required', Rule::in(PlanController::storeIds($user))],
                'customer_id' => ['nullable', 'integer'],
                'name' => ['required_without:customer_id', 'nullable', 'string', 'max:120'],
                'phone' => ['required_without:customer_id', 'nullable', 'string', 'max:30'],
            ] : []),
            'resource_id' => ['required', Rule::exists('bookable_resources', 'id')->where('organization_id', $user->organization_id)],
            'starts_at' => ['nullable', 'date'],
            'ride_from' => ['nullable', 'string', 'max:255'],
            'ride_to' => ['required', 'string', 'max:255'],
            'ride_passengers' => ['nullable', 'integer', 'min:1', 'max:60'],
            'ride_luggage' => ['nullable', 'integer', 'min:0', 'max:60'],
            'flight_number' => ['nullable', 'string', 'max:20'],
            'fare_id' => ['nullable', 'integer'],
            'price' => ['nullable', 'integer', 'min:0'],
            'minutes' => ['nullable', 'integer', 'min:15', 'max:720'],
            'driver_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function store(Request $request, RideDesk $rides): RedirectResponse
    {
        $booking = $rides->book($request->user(), $request->validate($this->rules($request, true)))->load('customer', 'vehicle', 'store');

        return back()->with('success', 'Course réservée : envoyez la confirmation au passager.')->with('whatsapp_url', RentalDesk::waUrl($booking, RideDesk::confirmationMessage($booking)));
    }

    public function fromRequest(Request $request, VehicleRequest $vehicleRequest, RideDesk $rides): RedirectResponse
    {
        abort_unless($vehicleRequest->organization_id === $request->user()->organization_id && in_array($vehicleRequest->store_id, PlanController::storeIds($request->user()), true), 404);
        abort_unless($vehicleRequest->isOpen(), 422);
        $data = $request->validate($this->rules($request, false));
        $booking = $rides->book($request->user(), [...$data, 'store_id' => $vehicleRequest->store_id], $vehicleRequest)->load('customer', 'vehicle', 'store');

        return redirect('/location/planning')->with('success', 'Course réservée : envoyez la confirmation au passager.')->with('whatsapp_url', RentalDesk::waUrl($booking, RideDesk::confirmationMessage($booking)));
    }

    public function move(Request $request, Booking $booking, RideDesk $rides): RedirectResponse
    {
        abort_unless($booking->organization_id === $request->user()->organization_id && in_array($booking->store_id, PlanController::storeIds($request->user()), true), 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(['en_route', 'on_board', 'done', 'cancelled'])],
            'payment_method' => ['nullable', Rule::in(array_keys(Purchase::PAYMENT_METHODS))],
        ]);
        $rides->move($booking, $request->user(), $data['status'], $data['payment_method'] ?? null);
        $booking->refresh()->load('customer', 'vehicle', 'store');

        return $data['status'] === 'done'
            ? back()->with('success', 'Course terminée et encaissée : envoyez le reçu.')->with('whatsapp_url', RentalDesk::waUrl($booking, RideDesk::receiptMessage($booking)))
            : back()->with('success', 'Course : '.mb_strtolower(Booking::RIDE_STATUSES[$data['status']]).'.');
    }

    public function storeFare(Request $request): RedirectResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:120'], 'price' => ['required', 'integer', 'min:1'], 'minutes' => ['nullable', 'integer', 'min:15', 'max:720']]);
        RideFare::create([...$data, 'minutes' => $data['minutes'] ?? RideDesk::DEFAULT_MINUTES, 'organization_id' => $request->user()->organization_id]);

        return back()->with('success', 'Forfait ajouté.');
    }

    public function destroyFare(Request $request, RideFare $fare): RedirectResponse
    {
        abort_unless($fare->organization_id === $request->user()->organization_id, 404);
        $fare->delete();

        return back()->with('success', 'Forfait supprimé.');
    }
}
