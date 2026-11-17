<?php

namespace App\Modules\Location\Http;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PlanController;
use App\Models\BookableResource;
use App\Models\Store;
use App\Modules\Facturation\Services\Invoicing;
use App\Modules\Location\Models\RideFare;
use App\Modules\Location\Models\VehicleRequest;
use App\Modules\Location\Models\VehicleSale;
use App\Modules\Location\Services\RentalDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Demandes (prospects) : Nouvelle → Devis envoyé → Réservée / Vendue, ou Perdue. */
class RequestController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $storeIds = PlanController::storeIds($user);

        $requests = VehicleRequest::where('organization_id', $user->organization_id)->whereIn('store_id', $storeIds)
            ->where(fn ($q) => $q->whereIn('status', ['new', 'quoted'])->orWhere('closed_at', '>=', now()->subDays(30)))
            ->with(['customer:id,name,phone_e164,responsible_id', 'vehicle:id,name,reference', 'quote:id,number,status,total,token,customer_id,store_id,organization_id'])
            ->latest()->limit(300)->get();

        return Inertia::render('Location/Requests', [
            'requests' => $requests->map(fn (VehicleRequest $r) => [
                'id' => $r->id,
                'status' => $r->status,
                'need' => $r->need,
                'need_label' => VehicleRequest::NEEDS[$r->need] ?? $r->need,
                'summary' => $r->summary(),
                'customer_id' => $r->customer_id,
                'customer' => $r->customer?->name,
                'phone' => $r->customer?->phone_e164,
                'purpose_label' => VehicleRequest::PURPOSES[$r->purpose] ?? null,
                'source_label' => VehicleRequest::SOURCES[$r->source] ?? $r->source,
                'category' => $r->category,
                'resource_id' => $r->resource_id,
                'starts_at' => $r->starts_at?->format('Y-m-d\TH:i'),
                'ends_at' => $r->ends_at?->format('Y-m-d\TH:i'),
                'with_driver' => $r->with_driver,
                'budget' => $r->budget,
                'notes' => $r->notes,
                'quote' => $r->quote ? ['number' => $r->quote->number, 'total' => $r->quote->total, 'status' => $r->quote->status, 'url' => $r->quote->publicUrl()] : null,
                'quote_wa' => $r->quote ? RentalDesk::waUrl($r, app(Invoicing::class)->shareMessage($r->quote->loadMissing('customer', 'store', 'organization'))) : null,
                'lost_label' => VehicleRequest::LOST_REASONS[$r->lost_reason] ?? null,
                'created_at' => $r->created_at->toDateTimeString(),
                'quoted_at' => $r->quoted_at?->toDateTimeString(),
                'ride' => $r->need === 'ride' ? ['from' => $r->ride_from, 'to' => $r->ride_to, 'passengers' => $r->ride_passengers, 'luggage' => $r->ride_luggage, 'flight' => $r->flight_number] : null,
            ]),
            'vehicles' => BookableResource::fleet($user->organization_id)->where(fn ($q) => $q->whereNull('store_id')->orWhereIn('store_id', $storeIds))
                ->orderBy('name')->get(['id', 'name', 'reference', 'category', 'status', 'price_day', 'sale_price'])
                ->map(fn ($v) => ['id' => $v->id, 'label' => $v->label(), 'category' => $v->category, 'status' => $v->status, 'price_day' => $v->price_day, 'sale_price' => $v->sale_price]),
            'stores' => Store::whereIn('id', $storeIds)->orderBy('name')->get(['id', 'name']),
            'options' => [
                'needs' => VehicleRequest::NEEDS, 'sources' => VehicleRequest::SOURCES, 'purposes' => VehicleRequest::PURPOSES,
                'lost_reasons' => VehicleRequest::LOST_REASONS, 'categories' => BookableResource::CATEGORIES,
            ],
            'whatsappUrl' => session('whatsapp_url'),
            'fares' => RideFare::where('organization_id', $user->organization_id)->orderBy('label')->get(['id', 'label', 'price', 'minutes']),
        ]);
    }

    public function store(Request $request, RentalDesk $desk): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'store_id' => ['required', Rule::in(PlanController::storeIds($user))],
            'customer_id' => ['nullable', 'integer'],
            'name' => ['required_without:customer_id', 'nullable', 'string', 'max:120'],
            'phone' => ['required_without:customer_id', 'nullable', 'string', 'max:30'],
            'need' => ['required', Rule::in(array_keys(VehicleRequest::NEEDS))],
            'purpose' => ['nullable', Rule::in(array_keys(VehicleRequest::PURPOSES))],
            'category' => ['nullable', Rule::in(array_keys(BookableResource::CATEGORIES))],
            'resource_id' => ['nullable', Rule::exists('bookable_resources', 'id')->where('organization_id', $user->organization_id)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'with_driver' => ['boolean'],
            'budget' => ['nullable', 'integer', 'min:0'],
            'source' => ['required', Rule::in(array_keys(VehicleRequest::SOURCES))],
            'notes' => ['nullable', 'string', 'max:2000'],
            'ride_from' => ['nullable', 'string', 'max:255'],
            'ride_to' => ['nullable', 'required_if:need,ride', 'string', 'max:255'],
            'ride_passengers' => ['nullable', 'integer', 'min:1', 'max:60'],
            'ride_luggage' => ['nullable', 'integer', 'min:0', 'max:60'],
            'flight_number' => ['nullable', 'string', 'max:20'],
        ]);

        $vehicleRequest = $desk->createRequest($user, $data);

        // Achat : l'acheteur est aussi suivi sur le véhicule choisi (essai, offre…).
        if ($vehicleRequest->need === 'buy' && $vehicleRequest->resource_id) {
            $sale = VehicleSale::create(['organization_id' => $user->organization_id, 'store_id' => $vehicleRequest->store_id, 'resource_id' => $vehicleRequest->resource_id,
                'customer_id' => $vehicleRequest->customer_id, 'status' => 'interested', 'created_by' => $user->id]);
            $vehicleRequest->update(['vehicle_sale_id' => $sale->id]);
        }

        return back()->with('success', 'Demande enregistrée.');
    }

    public function quote(Request $request, VehicleRequest $vehicleRequest, RentalDesk $desk): RedirectResponse
    {
        $this->authorizeRequest($request, $vehicleRequest);
        $data = $request->validate([
            'resource_id' => ['nullable', 'integer'],
            'rate' => ['nullable', 'integer', 'min:0'],
            'driver_rate' => ['nullable', 'integer', 'min:0'],
            'deposit' => ['nullable', 'integer', 'min:0'],
            'price' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $quote = $desk->quote($vehicleRequest, $request->user(), $data);
        $url = RentalDesk::waUrl($vehicleRequest->fresh('customer'), app(Invoicing::class)->shareMessage($quote->load('customer', 'store', 'organization')));

        return back()->with('success', "Devis {$quote->number} prêt : envoyez-le sur WhatsApp.")->with('whatsapp_url', $url);
    }

    public function book(Request $request, VehicleRequest $vehicleRequest, RentalDesk $desk): RedirectResponse
    {
        $this->authorizeRequest($request, $vehicleRequest);
        $data = $request->validate([
            'resource_id' => ['required', 'integer'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'rate' => ['nullable', 'integer', 'min:0'],
            'deposit' => ['nullable', 'integer', 'min:0'],
            'deposit_percent' => ['nullable', 'integer', 'min:0', 'max:99'],
            'pickup_place' => ['nullable', 'string', 'max:255'],
            'driver_name' => ['nullable', 'string', 'max:120'],
        ]);

        $booking = $desk->book($vehicleRequest, $request->user(), $data);
        $booking->load('customer', 'vehicle', 'store', 'quote');

        return redirect('/location/planning?from='.$booking->starts_at->toDateString())
            ->with('success', 'Réservation confirmée : envoyez la confirmation au client.')
            ->with('whatsapp_url', RentalDesk::waUrl($booking, RentalDesk::confirmationMessage($booking)));
    }

    public function lose(Request $request, VehicleRequest $vehicleRequest, RentalDesk $desk): RedirectResponse
    {
        $this->authorizeRequest($request, $vehicleRequest);
        $data = $request->validate(['lost_reason' => ['required', 'string'], 'lost_note' => ['nullable', 'string', 'max:255']]);
        $desk->lose($vehicleRequest, $request->user(), $data['lost_reason'], $data['lost_note'] ?? null);

        return back()->with('success', 'Demande classée perdue.');
    }

    private function authorizeRequest(Request $request, VehicleRequest $vehicleRequest): void
    {
        abort_unless($vehicleRequest->organization_id === $request->user()->organization_id && in_array($vehicleRequest->store_id, PlanController::storeIds($request->user()), true), 404);
    }
}
