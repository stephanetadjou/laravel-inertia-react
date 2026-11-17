<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\BookableResource;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Rental;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Flotte de véhicules (module « vehicules ») : disponibilité, planning,
 * contrats de location (départ, retour, encaissement) et vente.
 *
 * Un véhicule est occupé par un contrat réservé ou en cours, de son début à
 * sa fin prévue ; un contrat en cours dont la fin est passée (retour en
 * retard) l'occupe jusqu'au retour réel.
 */
class Fleet
{
    /** Contrat qui occupe déjà le véhicule sur la période (hors celui qu'on modifie). */
    public static function conflict(int $vehicleId, Carbon $from, Carbon $to, ?int $exceptId = null): ?Rental
    {
        return Rental::where('resource_id', $vehicleId)
            ->blocking()
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->where('starts_at', '<', $to)
            ->with('customer:id,name')
            ->get()
            ->first(fn (Rental $r) => self::effectiveEnd($r)->gt($from));
    }

    /**
     * Fin réelle de l'occupation. Un retour en retard garde le véhicule
     * occupé jusqu'à la fin de demain : on ne le promet pas à un autre client
     * tant qu'il n'est pas rentré.
     */
    public static function effectiveEnd(Rental $rental): Carbon
    {
        if ($rental->returned_at !== null) {
            return $rental->returned_at;
        }

        return $rental->status === 'ongoing' && $rental->ends_at->lt(now()) ? now()->addDay()->endOfDay() : $rental->ends_at;
    }

    /**
     * Prix d'un contrat : tarif × nombre de périodes commencées (un jour
     * entamé est dû, minimum une période).
     */
    public static function amount(int $rate, string $period, Carbon $from, Carbon $to): int
    {
        $hours = max(1, (int) ceil($from->diffInMinutes($to) / 60));
        $units = match ($period) {
            'week' => (int) ceil($hours / (24 * 7)),
            'month' => max(1, (int) round($from->diffInMonths($to))),
            default => (int) ceil($hours / 24),
        };

        return $rate * max(1, $units);
    }

    /**
     * Véhicules libres un jour donné (ni en entretien, ni à vendre, sans contrat ce jour-là).
     *
     * @param  array<int, int>|null  $storeIds
     * @return Collection<int, BookableResource>
     */
    public static function availableOn(int $organizationId, Carbon $day, ?array $storeIds = null): Collection
    {
        $from = $day->copy()->startOfDay();
        $to = $from->copy()->addDay();
        $vehicles = BookableResource::fleet($organizationId)
            ->where('status', 'available')
            ->when($storeIds !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('store_id')->orWhereIn('store_id', $storeIds)))
            ->get();
        $busy = Rental::whereIn('resource_id', $vehicles->pluck('id'))->blocking()->where('starts_at', '<', $to)->get()
            ->filter(fn (Rental $r) => self::effectiveEnd($r)->gt($from))
            ->pluck('resource_id')
            ->merge(Appointment::whereIn('resource_id', $vehicles->pluck('id'))->whereIn('status', Appointment::BLOCKING_STATUSES)
                ->where('starts_at', '>=', $from)->where('starts_at', '<', $to)->pluck('resource_id'))
            ->flip();

        return $vehicles->reject(fn (BookableResource $v) => isset($busy[$v->id]))->values();
    }

    /**
     * Planning de la flotte : une ligne par véhicule, une case par jour.
     *
     * @param  array<int, int>  $storeIds
     * @return array{days: list<array{date: string, label: string}>, rows: list<array<string, mixed>>}
     */
    public static function planning(int $organizationId, array $storeIds, Carbon $from, int $days = 14): array
    {
        $from = $from->copy()->startOfDay();
        $to = $from->copy()->addDays($days);
        $vehicles = BookableResource::fleet($organizationId)
            ->where(fn ($q) => $q->whereNull('store_id')->orWhereIn('store_id', $storeIds))
            ->orderBy('category')->orderBy('name')
            ->get();
        $rentals = Rental::whereIn('resource_id', $vehicles->pluck('id'))->blocking()
            ->where('starts_at', '<', $to)
            ->with(['customer:id,name', 'driver:id,name'])
            ->get()
            ->filter(fn (Rental $r) => self::effectiveEnd($r)->gt($from))
            ->groupBy('resource_id');

        $dates = [];
        for ($d = $from->copy(); $d->lt($to); $d->addDay()) {
            $dates[] = ['date' => $d->toDateString(), 'label' => $d->locale('fr')->translatedFormat('D j')];
        }

        return [
            'days' => $dates,
            'rows' => $vehicles->map(fn (BookableResource $v) => [
                'id' => $v->id,
                'name' => $v->label(),
                'category' => BookableResource::CATEGORIES[$v->category] ?? null,
                'status' => $v->status,
                'cells' => array_map(function (array $day) use ($rentals, $v, $dates) {
                    $start = Carbon::parse($day['date']);
                    $rental = collect($rentals[$v->id] ?? [])->first(fn (Rental $r) => $r->starts_at->lt($start->copy()->addDay()) && self::effectiveEnd($r)->gt($start));

                    return $rental ? [
                        'rental_id' => $rental->id,
                        'customer' => $rental->customer?->name,
                        'status' => $rental->isLate() ? 'late' : $rental->status,
                        // Première case du contrat dans la grille : on y écrit le nom du client.
                        'first' => $rental->starts_at->toDateString() === $day['date'] || $day['date'] === $dates[0]['date'],
                    ] : null;
                }, $dates),
            ])->values()->all(),
        ];
    }

    /** Départ du véhicule : kilométrage et carburant relevés. */
    public function depart(Rental $rental, ?int $km, ?int $fuel): void
    {
        if ($rental->status !== 'reserved') {
            throw ValidationException::withMessages(['rental' => 'Ce contrat n’est pas en attente de départ.']);
        }

        DB::transaction(function () use ($rental, $km, $fuel) {
            $rental->update(['status' => 'ongoing', 'departed_at' => now(), 'km_out' => $km ?? $rental->vehicle->mileage, 'fuel_out' => $fuel]);
            if ($km !== null) {
                $rental->vehicle->update(['mileage' => max((int) $rental->vehicle->mileage, $km)]);
            }
        });
    }

    /**
     * Retour du véhicule : relevés, puis encaissement de la courte durée
     * (une vente au nom du client) ; la longue durée est payée par ses mensualités.
     */
    public function giveBack(Rental $rental, User $user, ?int $km, ?int $fuel, ?string $paymentMethod = null, ?int $extra = null): ?Purchase
    {
        if ($rental->status !== 'ongoing') {
            throw ValidationException::withMessages(['rental' => 'Ce véhicule n’est pas sorti.']);
        }

        if ($km !== null && $rental->km_out !== null && $km < $rental->km_out) {
            throw ValidationException::withMessages(['km_in' => 'Le kilométrage au retour est inférieur à celui du départ.']);
        }

        $purchase = DB::transaction(function () use ($rental, $user, $km, $fuel, $paymentMethod, $extra) {
            $returnedAt = now();
            // Retour après la date prévue : les jours en plus sont facturés au même tarif.
            $amount = $rental->kind === 'short'
                ? max($rental->amount, self::amount($rental->rate, $rental->rate_period, $rental->starts_at, $returnedAt->max($rental->ends_at))) + (int) $extra
                : $rental->amount;
            $rental->update(['status' => 'returned', 'returned_at' => $returnedAt, 'km_in' => $km, 'fuel_in' => $fuel, 'amount' => $amount]);
            if ($km !== null) {
                $rental->vehicle->update(['mileage' => max((int) $rental->vehicle->mileage, $km)]);
            }

            if ($rental->kind !== 'short' || $amount === 0) {
                return null;
            }

            $purchase = $this->sale($rental->customer, $rental->store_id, $amount, 'Location '.$rental->vehicle->label(), 'Location de véhicules', $paymentMethod, $user);
            $rental->update(['purchase_id' => $purchase->id]);

            return $purchase;
        });

        if ($purchase) {
            app(CustomerScoring::class)->refreshOrganization($user->organization);
        }
        Audit::record('rental.returned', $rental, "Retour de {$rental->vehicle->label()} ({$rental->customer->name})", ['amount' => $rental->amount], $user);

        return $purchase;
    }

    /** Vente d'un véhicule : une vente au nom de l'acheteur, le véhicule sort de la flotte. */
    public function sell(BookableResource $vehicle, Customer $buyer, int $storeId, int $price, User $user, ?string $paymentMethod = null): Purchase
    {
        if ($vehicle->status === 'sold') {
            throw ValidationException::withMessages(['vehicle' => 'Ce véhicule est déjà vendu.']);
        }

        if (Rental::where('resource_id', $vehicle->id)->blocking()->where('ends_at', '>', now())->exists()) {
            throw ValidationException::withMessages(['vehicle' => 'Ce véhicule a encore une location en cours ou réservée.']);
        }

        $purchase = DB::transaction(function () use ($vehicle, $buyer, $storeId, $price, $user, $paymentMethod) {
            $purchase = $this->sale($buyer, $storeId, $price, 'Vente '.$vehicle->label(), 'Vente de véhicules', $paymentMethod, $user);
            $vehicle->update(['status' => 'sold', 'sold_on' => today(), 'sale_price' => $price]);

            return $purchase;
        });

        app(CustomerScoring::class)->refreshOrganization($user->organization);
        Audit::record('vehicle.sold', $vehicle, "Vente de {$vehicle->label()} à {$buyer->name}", ['price' => $price], $user);

        return $purchase;
    }

    private function sale(Customer $customer, int $storeId, int $amount, string $line, string $category, ?string $paymentMethod, User $user): Purchase
    {
        $contact = Contact::where('store_id', $storeId)->where('customer_id', $customer->id)->first()
            ?? Contact::create(['store_id' => $storeId, 'phone_e164' => $customer->phone_e164, 'name' => $customer->name, 'consent_status' => 'unknown']);

        $purchase = Purchase::create([
            'contact_id' => $contact->id,
            'store_id' => $storeId,
            'amount' => $amount,
            'quantity' => 1,
            'subtotal' => $amount,
            'discount_amount' => 0,
            'currency' => $user->organization->currency ?? 'XAF',
            'product_category' => $category,
            'purchased_at' => now(),
            'payment_method' => $paymentMethod,
            'type' => Purchase::TYPE_SALE,
            'source' => 'rental',
        ]);

        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'type' => 'service',
            'name' => mb_substr($line, 0, 255),
            'quantity' => 1,
            'unit_price' => $amount,
            'amount' => $amount,
            'discount_amount' => 0,
        ]);

        return $purchase;
    }
}
