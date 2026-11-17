<?php

namespace App\Modules\Location\Http;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PlanController;
use App\Models\BookableResource;
use App\Models\Purchase;
use App\Models\Store;
use App\Modules\Facturation\Services\Invoicing;
use App\Modules\Location\Models\Booking;
use App\Modules\Location\Models\RideFare;
use App\Modules\Location\Models\VehicleInspection;
use App\Modules\Location\Models\VehicleRequest;
use App\Modules\Location\Services\RentalDesk;
use App\Modules\Location\Services\RideDesk;
use App\Services\Fleet;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Réservations et planning par véhicule, états des lieux, caution. */
class BookingController extends Controller
{
    public function planning(Request $request): Response
    {
        $user = $request->user();
        $storeIds = PlanController::storeIds($user);
        $view = $request->query('view') === 'day' ? 'day' : 'week';
        $from = $request->date('from') ?? today();
        $from = $view === 'week' ? $from->copy()->startOfWeek() : $from->copy()->startOfDay();
        $to = $from->copy()->addDays($view === 'week' ? 7 : 1);

        $bookings = Booking::where('organization_id', $user->organization_id)->whereIn('store_id', $storeIds)
            ->where(fn ($q) => $q->whereIn('status', ['reserved', 'ongoing'])->orWhere('deposit_status', 'held')
                ->orWhere(fn ($w) => $w->where('status', 'returned')->where('returned_at', '>=', now()->subDays(3))))
            ->with(['vehicle:id,name,reference', 'customer:id,name,phone_e164,responsible_id', 'store:id,name', 'quote', 'inspections:id,rental_id,phase'])
            ->orderBy('starts_at')->get();

        return Inertia::render('Location/Planning', [
            'view' => $view,
            'from' => $from->toDateString(),
            'planning' => Fleet::planning($user->organization_id, $storeIds, $from, $view === 'week' ? 7 : 1),
            'dayBookings' => $view === 'day' ? $bookings->filter(fn (Booking $b) => $b->starts_at->lt($to) && Fleet::effectiveEnd($b)->gt($from))->map(fn ($b) => $this->row($b))->values() : [],
            'bookings' => $bookings->map(fn ($b) => $this->row($b))->values(),
            'vehicles' => BookableResource::fleet($user->organization_id)->where(fn ($q) => $q->whereNull('store_id')->orWhereIn('store_id', $storeIds))->orderBy('name')
                ->get(['id', 'name', 'reference', 'status', 'price_day'])->map(fn ($v) => ['id' => $v->id, 'label' => $v->label(), 'status' => $v->status, 'price_day' => $v->price_day]),
            'stores' => Store::whereIn('id', $storeIds)->orderBy('name')->get(['id', 'name']),
            'fares' => RideFare::where('organization_id', $user->organization_id)->orderBy('label')->get(['id', 'label', 'price', 'minutes']),
            'contract' => $user->organization->moduleSettings('location'),
            'options' => ['deposit_reasons' => Booking::DEPOSIT_REASONS, 'payment_methods' => Purchase::PAYMENT_METHODS, 'sources' => VehicleRequest::SOURCES, 'purposes' => VehicleRequest::PURPOSES],
            'whatsappUrl' => session('whatsapp_url'),
        ]);
    }

    /** Réservation directe (client au comptoir ou au téléphone) : demande + réservation en une fois. */
    public function store(Request $request, RentalDesk $desk): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'store_id' => ['required', Rule::in(PlanController::storeIds($user))],
            'customer_id' => ['nullable', 'integer'],
            'name' => ['required_without:customer_id', 'nullable', 'string', 'max:120'],
            'phone' => ['required_without:customer_id', 'nullable', 'string', 'max:30'],
            'resource_id' => ['required', Rule::exists('bookable_resources', 'id')->where('organization_id', $user->organization_id)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'with_driver' => ['boolean'],
            'driver_name' => ['nullable', 'string', 'max:120'],
            'pickup_place' => ['nullable', 'string', 'max:255'],
            'rate' => ['nullable', 'integer', 'min:0'],
            'deposit' => ['nullable', 'integer', 'min:0'],
            'deposit_percent' => ['nullable', 'integer', 'min:0', 'max:99'],
            'purpose' => ['nullable', Rule::in(array_keys(VehicleRequest::PURPOSES))],
            'source' => ['nullable', Rule::in(array_keys(VehicleRequest::SOURCES))],
        ]);

        // Contrôle avant de créer la demande : un refus ne laisse pas de demande orpheline.
        RentalDesk::ensureAvailable(BookableResource::findOrFail($data['resource_id']), Carbon::parse($data['starts_at']), Carbon::parse($data['ends_at']));
        $vehicleRequest = $desk->createRequest($user, [...$data, 'need' => 'rent', 'source' => $data['source'] ?? 'agency']);
        $booking = $desk->book($vehicleRequest, $user, $data)->load('customer', 'vehicle', 'store', 'quote');

        return back()->with('success', 'Réservation enregistrée.')->with('whatsapp_url', RentalDesk::waUrl($booking, RentalDesk::confirmationMessage($booking)));
    }

    public function inspection(Request $request, Booking $booking, string $phase): Response
    {
        $this->authorizeBooking($request, $booking);
        abort_unless(in_array($phase, ['out', 'in'], true), 404);
        $booking->load('vehicle', 'customer', 'inspections');
        $out = $booking->inspections->firstWhere('phase', 'out');

        return Inertia::render('Location/Inspection', [
            'phase' => $phase,
            'booking' => [
                'id' => $booking->id,
                'vehicle' => $booking->vehicle->label(),
                'customer' => $booking->customer->name,
                'status' => $booking->status,
                'starts_at' => $booking->starts_at->format('d/m H\hi'),
                'ends_at' => $booking->ends_at->format('d/m H\hi'),
                'late' => $booking->isLate(),
                'mileage' => $booking->vehicle->mileage,
                'km_out' => $booking->km_out,
                'fuel_out' => $booking->fuel_out,
                'deposit' => $booking->deposit,
                'driver_name' => $booking->driver_name,
                'pickup_place' => $booking->pickup_place,
                'out_photos' => $out ? array_keys($out->photos ?? []) : [],
                'out_inspection_id' => $out?->id,
                'out_damages' => $out?->damages,
                'out_checklist' => $out?->checklist ?? [],
                'accessories' => $booking->organization->moduleSettings('location')['accessories'] ?? [],
            ],
        ]);
    }

    public function saveInspection(Request $request, Booking $booking, string $phase, RentalDesk $desk): RedirectResponse
    {
        $this->authorizeBooking($request, $booking);
        abort_unless(in_array($phase, ['out', 'in'], true), 404);
        $data = $request->validate([
            'mileage' => ['nullable', 'integer', 'min:0'],
            'fuel' => ['nullable', 'integer', 'min:0', 'max:100'],
            'damages' => ['nullable', 'string', 'max:2000'],
            'signed_name' => ['nullable', 'string', 'max:120'],
            'photos' => ['nullable', 'array', 'max:12'],
            'photos.*' => ['image', 'max:8192'],
            'checklist' => ['nullable', 'array'],
            'checklist.*' => ['string', 'max:60'],
        ]);

        $photos = [];
        foreach ($request->file('photos', []) as $file) {
            $photos[] = $file->store("location/{$booking->organization_id}/inspections/{$booking->id}");
        }

        $args = [$booking, $request->user(), $data['mileage'] ?? null, $data['fuel'] ?? null, $photos, $data['damages'] ?? null, $data['signed_name'] ?? null];
        $phase === 'out' ? $desk->depart(...$args) : $desk->giveBack(...$args);
        // Accessoires présents (cochés) à cet état des lieux.
        $booking->inspections()->where('phase', $phase)->first()?->update(['checklist' => array_values($data['checklist'] ?? [])]);
        $extra = (int) $booking->fresh()->extra_km_amount;

        return redirect('/location/planning')->with('success', $phase === 'out' ? 'Départ enregistré : bonne route !'
            : 'Retour enregistré'.($phase === 'in' && $extra > 0 ? ' : '.Invoicing::money($extra).' de kilomètres en plus facturés' : '').'. Clôturez la caution.');
    }

    public function photo(Request $request, VehicleInspection $inspection, int $index)
    {
        $this->authorizeBooking($request, $inspection->booking);
        $path = ($inspection->photos ?? [])[$index] ?? abort(404);
        abort_unless(Storage::exists($path), 404);

        return Storage::response($path);
    }

    public function closeDeposit(Request $request, Booking $booking, RentalDesk $desk): RedirectResponse
    {
        $this->authorizeBooking($request, $booking);
        $data = $request->validate([
            'retained' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', Rule::in(array_keys(Booking::DEPOSIT_REASONS))],
            'note' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', Rule::in(array_keys(Purchase::PAYMENT_METHODS))],
        ]);
        $desk->closeDeposit($booking, $request->user(), (int) $data['retained'], $data['reason'] ?? null, $data['note'] ?? null, $data['payment_method'] ?? null);
        $booking->refresh()->load('customer', 'vehicle', 'store');

        return back()->with('success', 'Caution clôturée : envoyez le reçu au client.')->with('whatsapp_url', RentalDesk::waUrl($booking, RentalDesk::receiptMessage($booking)));
    }

    public function cancel(Request $request, Booking $booking, RentalDesk $desk): RedirectResponse
    {
        $this->authorizeBooking($request, $booking);
        $desk->cancel($booking, $request->user());

        return back()->with('success', 'Réservation annulée : le véhicule est de nouveau libre.');
    }

    /** Contrat de location PDF (conditions, km inclus, caution, pénalités, signatures). */
    public function contract(Request $request, Booking $booking)
    {
        $this->authorizeBooking($request, $booking);
        $booking->load('customer', 'vehicle', 'store', 'organization', 'quote');
        $settings = $booking->organization->moduleSettings('location');

        return Pdf::loadView('location::contract', [
            'booking' => $booking, 'settings' => $settings, 'days' => RentalDesk::days($booking->starts_at, $booking->ends_at),
        ])->setPaper('a4')->stream("contrat-location-{$booking->id}.pdf");
    }

    /** Réglages du contrat et de l'état des lieux (accessoires, km inclus, prix du km en plus, conditions). */
    public function saveSettings(Request $request)
    {
        abort_unless($request->user()->canManageOrganization(), 403);
        $data = $request->validate([
            'accessories' => ['nullable', 'string', 'max:2000'],
            'km_per_day' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'extra_km_price' => ['nullable', 'integer', 'min:0'],
            'conditions' => ['nullable', 'string', 'max:3000'],
        ]);
        $organization = $request->user()->organization;
        $organization->setModules($organization->enabledModules(), ['location' => [
            'accessories' => preg_split('/\r?\n/', (string) ($data['accessories'] ?? '')),
            'km_per_day' => $data['km_per_day'] ?? 0,
            'extra_km_price' => $data['extra_km_price'] ?? 0,
            'conditions' => $data['conditions'] ?? '',
        ]]);
        $organization->save();

        return back()->with('success', 'Réglages du contrat enregistrés.');
    }

    /** @return array<string, mixed> */
    private function row(Booking $b): array
    {
        $late = $b->isLate();

        return [
            'id' => $b->id,
            'vehicle' => $b->vehicle?->label(),
            'customer_id' => $b->customer_id,
            'customer' => $b->customer?->name,
            'status' => $late && ! $b->isRide() ? 'late' : $b->status,
            'status_label' => $b->isRide() ? (Booking::RIDE_STATUSES[$b->ride_status] ?? 'Course') : ($late ? 'Retour en retard' : (Booking::STATUSES[$b->status] ?? $b->status)),
            'is_ride' => $b->isRide(),
            'ride_status' => $b->ride_status,
            'ride' => $b->isRide() ? ['from' => $b->ride_from, 'to' => $b->ride_to, 'passengers' => $b->ride_passengers, 'luggage' => $b->ride_luggage, 'flight' => $b->flight_number] : null,
            'starts_at' => $b->starts_at->format('Y-m-d H:i'),
            'ends_at' => $b->ends_at->format('Y-m-d H:i'),
            'pickup_place' => $b->pickup_place,
            'driver_name' => $b->driver_name,
            'amount' => $b->amount,
            'deposit' => $b->deposit,
            'deposit_status' => $b->deposit_status,
            'deposit_label' => Booking::DEPOSIT_STATUSES[$b->deposit_status] ?? null,
            'balance' => $b->balanceDue(),
            'quote_url' => $b->quote?->publicUrl(),
            'has_out' => $b->inspections->contains('phase', 'out'),
            'has_in' => $b->inspections->contains('phase', 'in'),
            'confirm_wa' => $b->status === 'reserved' ? RentalDesk::waUrl($b, $b->isRide() ? RideDesk::confirmationMessage($b) : RentalDesk::confirmationMessage($b)) : null,
        ];
    }

    private function authorizeBooking(Request $request, Booking $booking): void
    {
        abort_unless($booking->organization_id === $request->user()->organization_id && in_array($booking->store_id, PlanController::storeIds($request->user()), true), 404);
    }
}
