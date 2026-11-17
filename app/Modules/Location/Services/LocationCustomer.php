<?php

namespace App\Modules\Location\Services;

use App\Http\Controllers\PlanController;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Location\Models\Booking;
use App\Modules\Location\Models\RentalAccount;
use App\Modules\Location\Models\VehicleRequest;
use App\Modules\Location\Models\VehicleSale;

/** Onglet « Location » de la fiche client : demandes, réservations, cautions, achats suivis, tarif entreprise. */
class LocationCustomer
{
    /** @return array<string, mixed>|null */
    public static function forCustomer(Customer $customer, User $user): ?array
    {
        if (! $user->organization->hasModule('location')) {
            return null;
        }

        $storeIds = PlanController::storeIds($user);
        $requests = VehicleRequest::where('customer_id', $customer->id)->whereIn('store_id', $storeIds)->with('vehicle:id,name,reference')->latest()->limit(20)->get();
        $bookings = Booking::where('customer_id', $customer->id)->whereIn('store_id', $storeIds)->with(['vehicle:id,name,reference', 'quote'])->latest('starts_at')->limit(20)->get();
        $sales = VehicleSale::where('customer_id', $customer->id)->whereIn('store_id', $storeIds)->with('vehicle:id,name,reference')->latest()->get();

        if ($requests->isEmpty() && $bookings->isEmpty() && $sales->isEmpty()) {
            return null;
        }

        $account = RentalAccount::where('customer_id', $customer->id)->first();

        return [
            'account' => $account ? ['discount_percent' => $account->discount_percent, 'day_rate' => $account->day_rate, 'notes' => $account->notes] : null,
            'revenue' => (int) $bookings->where('status', '!=', 'cancelled')->sum('amount'),
            'requests' => $requests->map(fn (VehicleRequest $r) => [
                'id' => $r->id, 'summary' => $r->summary(), 'status_label' => VehicleRequest::STATUSES[$r->status] ?? $r->status,
                'lost' => VehicleRequest::LOST_REASONS[$r->lost_reason] ?? null, 'created_at' => $r->created_at->toDateString(),
            ])->values(),
            'bookings' => $bookings->map(fn (Booking $b) => [
                'id' => $b->id, 'vehicle' => $b->isRide() ? RideDesk::label($b).' · '.$b->vehicle?->name : $b->vehicle?->label(), 'starts_at' => $b->starts_at->format('Y-m-d H:i'), 'ends_at' => $b->ends_at->format('Y-m-d H:i'),
                'status_label' => $b->isRide() ? (Booking::RIDE_STATUSES[$b->ride_status] ?? 'Course') : ($b->isLate() ? 'Retour en retard' : (Booking::STATUSES[$b->status] ?? $b->status)), 'amount' => $b->amount,
                'balance' => $b->balanceDue(), 'deposit_label' => Booking::DEPOSIT_STATUSES[$b->deposit_status] ?? null, 'deposit_retained' => $b->deposit_retained,
            ])->values(),
            'sales' => $sales->map(fn (VehicleSale $s) => [
                'id' => $s->id, 'vehicle' => $s->vehicle?->label(), 'status_label' => VehicleSale::STATUSES[$s->status] ?? $s->status, 'offer_amount' => $s->offer_amount,
            ])->values(),
        ];
    }
}
