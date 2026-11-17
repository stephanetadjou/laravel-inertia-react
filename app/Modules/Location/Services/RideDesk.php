<?php

namespace App\Modules\Location\Services;

use App\Models\BookableResource;
use App\Models\User;
use App\Modules\Facturation\Services\Invoicing;
use App\Modules\Location\Models\Booking;
use App\Modules\Location\Models\RideFare;
use App\Modules\Location\Models\VehicleRequest;
use App\Services\ActionLists;
use App\Services\Audit;
use App\Services\CustomerScoring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Courses / transferts vendus au passager (aéroport → domicile, par
 * exemple) : une course réserve le véhicule pendant sa durée estimée, sans
 * chevauchement, puis passe Réservée → Chauffeur en route → Client à bord →
 * Terminée (une vente au nom du passager), ou Annulée. Ni GPS ni
 * application chauffeur : le chauffeur est noté sur la course.
 */
class RideDesk
{
    public const DEFAULT_MINUTES = 60;

    public function __construct(private RentalDesk $desk) {}

    /**
     * @param  array{store_id: int, customer_id?: ?int, name?: ?string, phone?: ?string, resource_id: int, starts_at?: ?string, ride_from?: ?string, ride_to: string, ride_passengers?: ?int, ride_luggage?: ?int, flight_number?: ?string, fare_id?: ?int, price?: ?int, minutes?: ?int, driver_name?: ?string}  $data
     */
    public function book(User $user, array $data, ?VehicleRequest $request = null): Booking
    {
        $fare = filled($data['fare_id'] ?? null) ? RideFare::where('organization_id', $user->organization_id)->find($data['fare_id']) : null;
        $price = (int) ($data['price'] ?? $fare?->price ?? 0);
        if ($price < 1) {
            throw ValidationException::withMessages(['price' => 'Indiquez le prix de la course ou choisissez un forfait.']);
        }

        $from = Carbon::parse($data['starts_at'] ?? now());
        $to = $from->copy()->addMinutes((int) ($data['minutes'] ?? $fare?->minutes ?? self::DEFAULT_MINUTES));
        $customer = $request?->customer ?? $this->desk->customerFor($user, (int) $data['store_id'], $data['customer_id'] ?? null, $data['name'] ?? null, $data['phone'] ?? null);

        $booking = DB::transaction(function () use ($user, $data, $request, $customer, $from, $to, $price) {
            $vehicle = BookableResource::where('organization_id', $user->organization_id)->whereKey($data['resource_id'])->lockForUpdate()->firstOrFail();
            RentalDesk::ensureAvailable($vehicle, $from, $to);

            $booking = Booking::create([
                'organization_id' => $user->organization_id,
                'store_id' => $request?->store_id ?? $data['store_id'],
                'resource_id' => $vehicle->id,
                'customer_id' => $customer->id,
                'kind' => 'ride',
                'status' => 'reserved',
                'ride_status' => 'booked',
                'starts_at' => $from,
                'ends_at' => $to,
                'rate' => $price,
                'rate_period' => 'day',
                'amount' => $price,
                'vehicle_request_id' => $request?->id,
                'pickup_place' => $data['ride_from'] ?? 'Aéroport',
                'ride_from' => $data['ride_from'] ?? 'Aéroport',
                'ride_to' => $data['ride_to'],
                'ride_passengers' => $data['ride_passengers'] ?? null,
                'ride_luggage' => $data['ride_luggage'] ?? null,
                'flight_number' => $data['flight_number'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'confirmed_at' => now(),
                'created_by' => $user->id,
            ]);
            $request?->update(['status' => 'won', 'rental_id' => $booking->id, 'resource_id' => $vehicle->id, 'closed_at' => now()]);

            return $booking;
        });

        Audit::record('location.ride', $booking, "Course {$booking->ride_from} → {$booking->ride_to} pour {$customer->name}", ['amount' => $price], $user);

        return $booking;
    }

    /** Avance la course ; « Terminée » encaisse la course (une vente au nom du passager). */
    public function move(Booking $booking, User $user, string $status, ?string $paymentMethod = null): void
    {
        $order = ['booked' => 0, 'en_route' => 1, 'on_board' => 2, 'done' => 3];
        $current = $booking->ride_status ?? 'booked';

        if (! $booking->isRide() || in_array($current, ['done', 'cancelled'], true)) {
            throw ValidationException::withMessages(['ride' => 'Cette course est déjà close.']);
        }

        if ($status !== 'cancelled' && (! isset($order[$status]) || $order[$status] !== $order[$current] + 1)) {
            throw ValidationException::withMessages(['ride' => 'Étape de course invalide.']);
        }

        if ($status === 'cancelled' && $current === 'on_board') {
            throw ValidationException::withMessages(['ride' => 'Le client est à bord : terminez la course.']);
        }

        DB::transaction(function () use ($booking, $user, $status, $paymentMethod) {
            $update = ['ride_status' => $status];
            if ($status === 'on_board') {
                $update += ['status' => 'ongoing', 'departed_at' => now()];
            } elseif ($status === 'done') {
                // La course libère le véhicule dès qu'elle est terminée.
                $update += ['status' => 'returned', 'departed_at' => $booking->departed_at ?? now(), 'returned_at' => now()];
            } elseif ($status === 'cancelled') {
                $update += ['status' => 'cancelled'];
            }
            $booking->update($update);

            if ($status === 'done' && $booking->amount > 0) {
                $purchase = $this->desk->recordSale($booking, $user, (int) $booking->amount, "Course {$booking->ride_from} → {$booking->ride_to}", $paymentMethod);
                $booking->update(['purchase_id' => $purchase->id]);
            }
        });

        if ($status === 'done') {
            app(CustomerScoring::class)->refreshOrganization($user->organization);
        }
    }

    public static function label(Booking $booking): string
    {
        return "Course {$booking->ride_from} → {$booking->ride_to}";
    }

    // --- Messages WhatsApp ---

    public static function confirmationMessage(Booking $booking): string
    {
        $first = ActionLists::firstNameOf((string) $booking->customer?->name);

        return "Bonjour {$first}, votre course avec {$booking->store?->name} est confirmée : {$booking->ride_from} → {$booking->ride_to}, le "
            .$booking->starts_at->locale('fr')->translatedFormat('l j F à H\hi')
            .($booking->flight_number ? " (vol {$booking->flight_number})" : '')
            .'. Véhicule : '.$booking->vehicle->label()
            .($booking->driver_name ? ", chauffeur : {$booking->driver_name}" : '')
            .'. Prix : '.Invoicing::money((int) $booking->amount).'. Bon voyage !';
    }

    public static function reminderMessage(Booking $booking): string
    {
        $first = ActionLists::firstNameOf((string) $booking->customer?->name);

        return "Bonjour {$first}, votre chauffeur".($booking->driver_name ? " {$booking->driver_name}" : '')." vous attend à {$booking->starts_at->format('H\hi')} ({$booking->ride_from})"
            ." avec {$booking->vehicle->label()}. En cas de retard de vol, répondez à ce message.";
    }

    public static function receiptMessage(Booking $booking): string
    {
        $first = ActionLists::firstNameOf((string) $booking->customer?->name);

        return "Bonjour {$first}, reçu de votre course du ".$booking->starts_at->format('d/m/Y').' : '
            ."{$booking->ride_from} → {$booking->ride_to}, ".Invoicing::money((int) $booking->amount).'. Merci et à bientôt chez '.$booking->store?->name.' !';
    }
}
