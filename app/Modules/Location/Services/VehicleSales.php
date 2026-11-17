<?php

namespace App\Modules\Location\Services;

use App\Models\BookableResource;
use App\Models\Rental;
use App\Models\User;
use App\Modules\Location\Models\VehicleRequest;
use App\Modules\Location\Models\VehicleSale;
use App\Services\Audit;
use App\Services\Fleet;
use App\Services\Installments;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vente de véhicules : acheteurs suivis (essai, offre, reprise), réservation
 * puis vente comptant (une vente) ou en plusieurs fois (échéancier : les
 * rappels d'échéance sont ceux du module « Paiement en plusieurs fois »).
 */
class VehicleSales
{
    public function __construct(private Fleet $fleet, private Installments $installments) {}

    /** Statut affiché d'un véhicule à vendre : Disponible, Réservé, Vendu. */
    public static function saleStatus(BookableResource $vehicle): string
    {
        if ($vehicle->status === 'sold') {
            return 'sold';
        }

        return VehicleSale::where('resource_id', $vehicle->id)->where('status', 'reserved')->exists() ? 'reserved' : 'available';
    }

    /** @param array<string, mixed> $data */
    public function move(VehicleSale $sale, User $user, string $status, array $data = []): void
    {
        if (! in_array($sale->status, VehicleSale::OPEN, true)) {
            throw ValidationException::withMessages(['status' => 'Ce suivi est clos.']);
        }

        $update = match ($status) {
            'test_drive' => ['test_drive_at' => $data['test_drive_at'] ?? throw ValidationException::withMessages(['test_drive_at' => 'Indiquez la date de l’essai.'])],
            'offer' => [
                'offer_amount' => (int) ($data['offer_amount'] ?? 0) ?: throw ValidationException::withMessages(['offer_amount' => 'Indiquez le montant de l’offre.']),
                'trade_in_label' => $data['trade_in_label'] ?? null,
                'trade_in_value' => $data['trade_in_value'] ?? null,
            ],
            'reserved' => [],
            'lost' => ['lost_reason' => $data['lost_reason'] ?? throw ValidationException::withMessages(['lost_reason' => 'Indiquez la raison.'])],
            'interested' => [],
            default => throw ValidationException::withMessages(['status' => 'Étape inconnue.']),
        };

        if ($status === 'reserved' && VehicleSale::where('resource_id', $sale->resource_id)->where('status', 'reserved')->whereKeyNot($sale->id)->exists()) {
            throw ValidationException::withMessages(['status' => 'Ce véhicule est déjà réservé par un autre acheteur.']);
        }

        $sale->update([...$update, 'status' => $status]);
        Audit::record('location.sale_step', $sale, "{$sale->customer->name} : ".VehicleSale::STATUSES[$status]." pour {$sale->vehicle->label()}", [], $user);
    }

    /**
     * Vente conclue : comptant (une vente au nom de l'acheteur) ou en N fois
     * (échéancier, première tranche aujourd'hui). Les autres acheteurs du
     * véhicule passent en « Perdu ».
     */
    public function sell(VehicleSale $sale, User $user, int $price, int $installments = 1, ?string $paymentMethod = null): void
    {
        $vehicle = $sale->vehicle;

        if ($vehicle->status === 'sold' || ! in_array($sale->status, VehicleSale::OPEN, true)) {
            throw ValidationException::withMessages(['price' => 'Ce véhicule est déjà vendu.']);
        }

        if ($price < 1) {
            throw ValidationException::withMessages(['price' => 'Indiquez le prix de vente.']);
        }

        DB::transaction(function () use ($sale, $vehicle, $user, $price, $installments, $paymentMethod) {
            if ($installments <= 1) {
                $purchase = $this->fleet->sell($vehicle, $sale->customer, $sale->store_id, $price, $user, $paymentMethod);
                $sale->update(['purchase_id' => $purchase->id]);
            } else {
                if (Rental::where('resource_id', $vehicle->id)->blocking()->where('ends_at', '>', now())->exists()) {
                    throw ValidationException::withMessages(['price' => 'Ce véhicule a encore une location en cours ou réservée.']);
                }
                $schedule = $this->installments->createSchedule($user, [
                    'customer_id' => $sale->customer_id, 'store_id' => $sale->store_id, 'label' => 'Achat '.$vehicle->label(),
                    'note' => $sale->trade_in_label ? "Reprise : {$sale->trade_in_label}".($sale->trade_in_value ? ' ('.number_format($sale->trade_in_value, 0, ',', ' ').' F)' : '') : null,
                    'installments' => Installments::split($price, $installments, today()),
                ]);
                $vehicle->update(['status' => 'sold', 'sold_on' => today(), 'sale_price' => $price]);
                $sale->update(['payment_schedule_id' => $schedule->id]);
            }

            $sale->update(['status' => 'sold', 'sold_at' => now(), 'offer_amount' => $price, 'installments_count' => max(1, $installments)]);
            VehicleSale::where('resource_id', $vehicle->id)->whereKeyNot($sale->id)->whereIn('status', VehicleSale::OPEN)->update(['status' => 'lost', 'lost_reason' => 'sold_other']);
            VehicleRequest::where('vehicle_sale_id', $sale->id)->whereIn('status', ['new', 'quoted'])->update(['status' => 'won', 'closed_at' => now()]);
        });
    }
}
