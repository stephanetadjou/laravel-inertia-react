<?php

namespace App\Http\Controllers;

use App\Models\BookableResource;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Rental;
use App\Models\StaffMember;
use App\Models\Store;
use App\Services\Audit;
use App\Services\Fleet;
use App\Services\Installments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Flotte de véhicules (module « vehicules ») : planning, fiches véhicules,
 * contrats de location courte et longue durée, véhicules à vendre.
 */
class VehicleController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $organization = $user->organization;
        $storeIds = PlanController::storeIds($user);
        $from = $request->date('from') ?? today();

        $vehicles = BookableResource::where('organization_id', $organization->id)
            ->where('kind', 'vehicule')
            ->where(fn ($q) => $q->whereNull('store_id')->orWhereIn('store_id', $storeIds))
            ->with('store:id,name')
            ->orderByRaw("case status when 'available' then 0 when 'maintenance' then 1 when 'for_sale' then 2 else 3 end")
            ->orderBy('name')
            ->get();

        $rentals = Rental::where('organization_id', $organization->id)
            ->whereIn('store_id', $storeIds)
            ->where(fn ($q) => $q->blocking()->orWhere('returned_at', '>=', now()->subDays(7)))
            ->with(['vehicle:id,name,reference', 'customer:id,name', 'driver:id,name'])
            ->orderBy('starts_at')
            ->get();
        $available = Fleet::availableOn($organization->id, today()->addDay(), $storeIds);
        $safety = $organization->occupationSettings()['safety_stock'];

        return Inertia::render('Vehicles/Index', [
            'planning' => Fleet::planning($organization->id, $storeIds, $from),
            'vehicles' => $vehicles->map(fn (BookableResource $v) => [
                'id' => $v->id,
                'name' => $v->name,
                'reference' => $v->reference,
                'label' => $v->label(),
                'category' => $v->category,
                'category_label' => BookableResource::CATEGORIES[$v->category] ?? null,
                'store_id' => $v->store_id,
                'store' => $v->store?->name,
                'mileage' => $v->mileage,
                'price_day' => $v->price_day,
                'price_week' => $v->price_week,
                'price_month' => $v->price_month,
                'status' => $v->status,
                'status_label' => BookableResource::STATUSES[$v->status] ?? $v->status,
                'sale_price' => $v->sale_price,
                'for_sale_since' => $v->for_sale_since?->toDateString(),
                'days_for_sale' => $v->status === 'for_sale' && $v->for_sale_since ? (int) $v->for_sale_since->diffInDays(today()) : null,
                'service_due_km' => $v->service_due_km,
                'service_due_on' => $v->service_due_on?->toDateString(),
                'insurance_until' => $v->insurance_until?->toDateString(),
                'inspection_until' => $v->inspection_until?->toDateString(),
                'deadlines' => $v->deadlines(),
                'active' => $v->active,
            ]),
            'rentals' => $rentals->map(fn (Rental $r) => [
                'id' => $r->id,
                'vehicle' => $r->vehicle?->label(),
                'customer_id' => $r->customer_id,
                'customer' => $r->customer?->name,
                'driver' => $r->driver?->name,
                'kind' => $r->kind,
                'kind_label' => Rental::KINDS[$r->kind] ?? 'Course / transfert',
                'status' => $r->isLate() ? 'late' : $r->status,
                'status_label' => $r->isLate() ? 'Retour en retard' : Rental::STATUSES[$r->status],
                'starts_at' => $r->starts_at->format('Y-m-d H:i'),
                'ends_at' => $r->ends_at->format('Y-m-d H:i'),
                'amount' => $r->amount,
                'deposit' => $r->deposit,
                'km_out' => $r->km_out,
                'paid' => $r->purchase_id !== null || $r->payment_schedule_id !== null,
            ]),
            'summary' => [
                'fleet' => $vehicles->where('active', true)->whereIn('status', ['available', 'maintenance'])->count(),
                'out' => $rentals->where('status', 'ongoing')->count(),
                'late' => $rentals->filter(fn (Rental $r) => $r->isLate())->count(),
                'free_tomorrow' => $available->count(),
                'safety_stock' => $safety,
                'for_sale' => $vehicles->where('status', 'for_sale')->count(),
            ],
            'from' => $from->toDateString(),
            'stores' => Store::whereIn('id', $storeIds)->orderBy('name')->get(['id', 'name']),
            'drivers' => StaffMember::whereIn('store_id', $storeIds)->where('active', true)->orderBy('name')->get(['id', 'name', 'store_id']),
            'categories' => BookableResource::CATEGORIES,
            'statuses' => BookableResource::STATUSES,
            'paymentMethods' => Purchase::PAYMENT_METHODS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $vehicle = BookableResource::create([...$this->validatedVehicle($request), 'organization_id' => $request->user()->organization_id, 'kind' => 'vehicule', 'active' => true]);
        Audit::record('vehicle.created', $vehicle, "Véhicule « {$vehicle->label()} » ajouté");

        return back()->with('success', "« {$vehicle->label()} » ajouté à la flotte.");
    }

    public function update(Request $request, BookableResource $vehicle): RedirectResponse
    {
        $this->authorizeVehicle($request, $vehicle);
        $data = $this->validatedVehicle($request);

        if ($data['status'] === 'for_sale' && $vehicle->status !== 'for_sale') {
            $data['for_sale_since'] = today();
        }
        $vehicle->update([...$data, 'active' => $request->boolean('active', true)]);

        return back()->with('success', "« {$vehicle->label()} » mis à jour.");
    }

    public function sell(Request $request, BookableResource $vehicle, Fleet $fleet): RedirectResponse
    {
        $this->authorizeVehicle($request, $vehicle);
        $user = $request->user();
        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('organization_id', $user->organization_id)],
            'store_id' => ['required', 'integer', Rule::in(PlanController::storeIds($user))],
            'price' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'payment_method' => ['nullable', Rule::in(array_keys(Purchase::PAYMENT_METHODS))],
        ]);

        $fleet->sell($vehicle, Customer::findOrFail($data['customer_id']), (int) $data['store_id'], (int) $data['price'], $user, $data['payment_method'] ?? null);

        return back()->with('success', "« {$vehicle->label()} » vendu : ".number_format((int) $data['price'], 0, ',', ' ').' FCFA enregistrés dans les ventes.');
    }

    public function storeRental(Request $request, Installments $installments): RedirectResponse
    {
        $user = $request->user();
        $storeIds = PlanController::storeIds($user);
        $data = $request->validate([
            'resource_id' => ['required', 'integer', Rule::exists('bookable_resources', 'id')->where('organization_id', $user->organization_id)->where('kind', 'vehicule')],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('organization_id', $user->organization_id)],
            'store_id' => ['required', 'integer', Rule::in($storeIds)],
            'driver_id' => ['nullable', 'integer', Rule::exists('staff_members', 'id')->where('organization_id', $user->organization_id)],
            'kind' => ['required', Rule::in(array_keys(Rental::KINDS))],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'rate' => ['required', 'integer', 'min:0', 'max:100000000'],
            'rate_period' => ['required', Rule::in(array_keys(Rental::PERIODS))],
            'deposit' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'ends_at.after' => 'Le retour doit être après le départ.',
        ]);

        $vehicle = BookableResource::findOrFail($data['resource_id']);
        if ($vehicle->status !== 'available' || ! $vehicle->active) {
            throw ValidationException::withMessages(['resource_id' => "« {$vehicle->label()} » n’est pas disponible à la location (".(BookableResource::STATUSES[$vehicle->status] ?? $vehicle->status).').']);
        }

        $start = Carbon::parse($data['starts_at']);
        $end = Carbon::parse($data['ends_at']);
        if ($taken = Fleet::conflict($vehicle->id, $start, $end)) {
            throw ValidationException::withMessages(['resource_id' => "« {$vehicle->label()} » est déjà loué à {$taken->customer?->name} du ".$taken->starts_at->format('d/m H\hi').' au '.$taken->ends_at->format('d/m H\hi').'.']);
        }

        $amount = Fleet::amount((int) $data['rate'], $data['rate_period'], $start, $end);

        $rental = DB::transaction(function () use ($data, $user, $vehicle, $start, $end, $amount, $installments) {
            $rental = Rental::create([...$data, 'organization_id' => $user->organization_id, 'starts_at' => $start, 'ends_at' => $end, 'amount' => $amount, 'status' => 'reserved', 'created_by' => $user->id]);

            // Longue durée : une mensualité par mois de contrat, à partir du départ.
            if ($data['kind'] === 'long' && $amount > 0) {
                $months = max(1, (int) round($start->diffInMonths($end)));
                $schedule = $installments->createSchedule($user, [
                    'customer_id' => $rental->customer_id,
                    'store_id' => $rental->store_id,
                    'label' => 'Location longue durée '.$vehicle->label(),
                    'installments' => Installments::split($amount, $months, $start->copy()->startOfDay()),
                ]);
                $rental->update(['payment_schedule_id' => $schedule->id]);
            }

            return $rental;
        });

        Audit::record('rental.created', $rental, "Location de {$vehicle->label()} du ".$start->format('d/m').' au '.$end->format('d/m'), ['amount' => $amount], $user);

        return back()->with('success', "Contrat enregistré : {$vehicle->label()}, ".number_format($amount, 0, ',', ' ').' FCFA.');
    }

    public function depart(Request $request, Rental $rental, Fleet $fleet): RedirectResponse
    {
        $this->authorizeRental($request, $rental);
        $data = $request->validate(['km' => ['nullable', 'integer', 'min:0', 'max:5000000'], 'fuel' => ['nullable', 'integer', 'min:0', 'max:100']]);
        $fleet->depart($rental, $data['km'] ?? null, $data['fuel'] ?? null);

        return back()->with('success', "Départ enregistré : {$rental->vehicle->label()}.");
    }

    public function giveBack(Request $request, Rental $rental, Fleet $fleet): RedirectResponse
    {
        $this->authorizeRental($request, $rental);
        $data = $request->validate([
            'km' => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'fuel' => ['nullable', 'integer', 'min:0', 'max:100'],
            'extra' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'payment_method' => ['nullable', Rule::in(array_keys(Purchase::PAYMENT_METHODS))],
        ]);
        $purchase = $fleet->giveBack($rental, $request->user(), $data['km'] ?? null, $data['fuel'] ?? null, $data['payment_method'] ?? null, $data['extra'] ?? null);

        return back()->with('success', 'Retour enregistré'.($purchase ? ' et encaissé : '.number_format((int) $purchase->amount, 0, ',', ' ').' FCFA.' : '.'));
    }

    public function cancelRental(Request $request, Rental $rental, Installments $installments): RedirectResponse
    {
        $this->authorizeRental($request, $rental);
        if ($rental->status !== 'reserved') {
            throw ValidationException::withMessages(['rental' => 'Seul un contrat pas encore parti peut être annulé.']);
        }

        DB::transaction(function () use ($rental, $request, $installments) {
            $rental->update(['status' => 'cancelled']);
            if ($rental->schedule && $rental->schedule->installments()->whereNotNull('paid_at')->doesntExist()) {
                $installments->cancel($rental->schedule, $request->user());
            }
        });
        Audit::record('rental.cancelled', $rental, "Location annulée : {$rental->vehicle->label()} ({$rental->customer->name})");

        return back()->with('success', 'Contrat annulé.');
    }

    /** @return array<string, mixed> */
    private function validatedVehicle(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:60'],
            'category' => ['nullable', Rule::in(array_keys(BookableResource::CATEGORIES))],
            'store_id' => ['nullable', 'integer', Rule::in(PlanController::storeIds($request->user()))],
            'mileage' => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'price_day' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'price_week' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'price_month' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'status' => ['required', Rule::in(['available', 'maintenance', 'for_sale'])],
            'sale_price' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'service_due_km' => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'service_due_on' => ['nullable', 'date'],
            'insurance_until' => ['nullable', 'date'],
            'inspection_until' => ['nullable', 'date'],
        ]);
    }

    private function authorizeVehicle(Request $request, BookableResource $vehicle): void
    {
        if ($vehicle->organization_id !== $request->user()->organization_id || $vehicle->kind !== 'vehicule') {
            throw new HttpException(403, 'Ce véhicule n’appartient pas à votre entreprise.');
        }
    }

    private function authorizeRental(Request $request, Rental $rental): void
    {
        if ($rental->organization_id !== $request->user()->organization_id || ! in_array($rental->store_id, PlanController::storeIds($request->user()), true)) {
            throw new HttpException(403, 'Ce contrat n’appartient pas à votre périmètre.');
        }
    }
}
