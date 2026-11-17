<?php

namespace App\Modules\Location\Http;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PlanController;
use App\Models\BookableResource;
use App\Models\Purchase;
use App\Models\Store;
use App\Modules\Location\Models\VehicleSale;
use App\Modules\Location\Services\RentalDesk;
use App\Modules\Location\Services\VehicleSales;
use App\Services\ActionLists;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Véhicules à vendre (prix, photos, statut) et acheteurs suivis. */
class SaleController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $storeIds = PlanController::storeIds($user);

        $vehicles = BookableResource::where('organization_id', $user->organization_id)->where('kind', 'vehicule')
            ->where(fn ($q) => $q->where('status', 'for_sale')->orWhere(fn ($w) => $w->where('status', 'sold')->where('sold_on', '>=', today()->subDays(60))))
            ->where(fn ($q) => $q->whereNull('store_id')->orWhereIn('store_id', $storeIds))->orderBy('status')->orderBy('name')->get();
        $sales = VehicleSale::where('organization_id', $user->organization_id)->whereIn('resource_id', $vehicles->pluck('id'))
            ->with(['customer:id,name,phone_e164,responsible_id', 'schedule.installments'])->latest()->get()->groupBy('resource_id');

        return Inertia::render('Location/Sales', [
            'vehicles' => $vehicles->map(function (BookableResource $v) use ($sales) {
                $own = $sales[$v->id] ?? collect();
                $photos = json_decode((string) $v->getRawOriginal('photos'), true) ?: [];

                return [
                    'id' => $v->id,
                    'label' => $v->label(),
                    'mileage' => $v->mileage,
                    'sale_price' => $v->sale_price,
                    'description' => $v->getRawOriginal('sale_description'),
                    'days_for_sale' => $v->for_sale_since ? (int) $v->for_sale_since->diffInDays(today()) : null,
                    'sale_status' => $v->status === 'sold' ? 'sold' : ($own->contains('status', 'reserved') ? 'reserved' : 'available'),
                    'photos' => array_map(fn ($i) => "/location/vehicles/{$v->id}/photos/{$i}", array_keys($photos)),
                    'listing_url' => $v->getRawOriginal('sale_token') ? url('/annonce/'.$v->getRawOriginal('sale_token')) : null,
                    'buyers' => $own->map(fn (VehicleSale $s) => [
                        'id' => $s->id,
                        'customer' => $s->customer?->name,
                        'customer_id' => $s->customer_id,
                        'status' => $s->status,
                        'status_label' => VehicleSale::STATUSES[$s->status] ?? $s->status,
                        'test_drive_at' => $s->test_drive_at?->format('Y-m-d H:i'),
                        'offer_amount' => $s->offer_amount,
                        'trade_in_label' => $s->trade_in_label,
                        'trade_in_value' => $s->trade_in_value,
                        'installments' => $s->schedule ? ['paid' => $s->schedule->installments->whereNotNull('paid_at')->count(), 'count' => $s->schedule->installments->count()] : null,
                        'wa_url' => $s->customer?->recipient()->phone_e164 ? ActionLists::whatsappUrl($s->customer->recipient()->phone_e164, $this->message($s, $v)) : null,
                    ])->values(),
                ];
            }),
            'candidates' => BookableResource::fleet($user->organization_id)->where('status', '!=', 'for_sale')->orderBy('name')->get(['id', 'name', 'reference'])->map(fn ($v) => ['id' => $v->id, 'label' => $v->label()]),
            'stores' => Store::whereIn('id', $storeIds)->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => Purchase::PAYMENT_METHODS,
            'whatsappUrl' => session('whatsapp_url'),
        ]);
    }

    /** Mise en vente (ou mise à jour) : prix, description, photos. */
    public function saveVehicle(Request $request, BookableResource $vehicle): RedirectResponse
    {
        abort_unless($vehicle->organization_id === $request->user()->organization_id && $vehicle->kind === 'vehicule', 404);
        $data = $request->validate([
            'sale_price' => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:1000'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['image', 'max:8192'],
        ]);

        $photos = json_decode((string) $vehicle->getRawOriginal('photos'), true) ?: [];
        foreach ($request->file('photos', []) as $file) {
            $photos[] = $file->store("location/{$vehicle->organization_id}/vehicles/{$vehicle->id}");
        }

        $vehicle->forceFill([
            'status' => $vehicle->status === 'sold' ? 'sold' : 'for_sale',
            'for_sale_since' => $vehicle->for_sale_since ?? today(),
            'sale_price' => $data['sale_price'],
            'sale_description' => $data['description'] ?? null,
            'photos' => json_encode(array_values($photos)),
            'sale_token' => $vehicle->getRawOriginal('sale_token') ?: Str::random(32),
        ])->save();
        Audit::record('location.for_sale', $vehicle, "{$vehicle->label()} mis en vente", ['price' => $data['sale_price']]);

        return back()->with('success', "{$vehicle->label()} est en vente.");
    }

    public function vehiclePhoto(Request $request, BookableResource $vehicle, int $index)
    {
        abort_unless($vehicle->organization_id === $request->user()->organization_id, 404);
        $path = (json_decode((string) $vehicle->getRawOriginal('photos'), true) ?: [])[$index] ?? abort(404);
        abort_unless(Storage::exists($path), 404);

        return Storage::response($path);
    }

    public function storeBuyer(Request $request, BookableResource $vehicle, RentalDesk $desk): RedirectResponse
    {
        $user = $request->user();
        abort_unless($vehicle->organization_id === $user->organization_id, 404);
        $data = $request->validate([
            'store_id' => ['required', Rule::in(PlanController::storeIds($user))],
            'customer_id' => ['nullable', 'integer'],
            'name' => ['required_without:customer_id', 'nullable', 'string', 'max:120'],
            'phone' => ['required_without:customer_id', 'nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $customer = $desk->customerFor($user, (int) $data['store_id'], $data['customer_id'] ?? null, $data['name'] ?? null, $data['phone'] ?? null);
        VehicleSale::create(['organization_id' => $user->organization_id, 'store_id' => $data['store_id'], 'resource_id' => $vehicle->id, 'customer_id' => $customer->id,
            'status' => 'interested', 'notes' => $data['notes'] ?? null, 'created_by' => $user->id]);

        return back()->with('success', "{$customer->name} est suivi sur {$vehicle->label()}.");
    }

    public function move(Request $request, VehicleSale $sale, VehicleSales $sales): RedirectResponse
    {
        abort_unless($sale->organization_id === $request->user()->organization_id, 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(VehicleSale::STATUSES))],
            'test_drive_at' => ['nullable', 'date'],
            'offer_amount' => ['nullable', 'integer', 'min:0'],
            'trade_in_label' => ['nullable', 'string', 'max:255'],
            'trade_in_value' => ['nullable', 'integer', 'min:0'],
            'lost_reason' => ['nullable', 'string', 'max:30'],
        ]);
        $sales->move($sale, $request->user(), $data['status'], $data);

        return back()->with('success', 'Suivi mis à jour.');
    }

    public function sell(Request $request, VehicleSale $sale, VehicleSales $sales): RedirectResponse
    {
        abort_unless($sale->organization_id === $request->user()->organization_id, 404);
        $data = $request->validate([
            'price' => ['required', 'integer', 'min:1'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:36'],
            'payment_method' => ['nullable', Rule::in(array_keys(Purchase::PAYMENT_METHODS))],
        ]);
        $sales->sell($sale, $request->user(), (int) $data['price'], (int) ($data['installments'] ?? 1), $data['payment_method'] ?? null);

        return back()->with('success', 'Vente enregistrée'.((int) ($data['installments'] ?? 1) > 1 ? ' : les échéances sont dans Ventes › Échéances, avec leurs rappels WhatsApp.' : '.'));
    }

    private function message(VehicleSale $sale, BookableResource $vehicle): string
    {
        $first = ActionLists::firstNameOf((string) $sale->customer?->name);

        return match ($sale->status) {
            'interested' => "Bonjour {$first}, le {$vehicle->name} qui vous intéresse est toujours disponible. Quand voulez-vous venir l’essayer ?",
            'test_drive' => "Bonjour {$first}, nous vous attendons pour l’essai du {$vehicle->name}".($sale->test_drive_at ? ' le '.$sale->test_drive_at->locale('fr')->translatedFormat('l j F à H\hi') : '').'. À bientôt !',
            'offer' => "Bonjour {$first}, avez-vous réfléchi à notre proposition pour le {$vehicle->name} ? Paiement en plusieurs fois possible.",
            default => "Bonjour {$first}, merci pour votre confiance !",
        };
    }
}
