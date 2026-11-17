<?php

namespace App\Modules\Location\Models;

use App\Models\Organization;
use App\Models\Rental;
use App\Modules\Facturation\Models\Invoice;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Réservation d'un loueur : le contrat de location de la flotte (table
 * rentals), avec sa demande, son devis (acompte, solde), le lieu de prise
 * en charge, le chauffeur noté (texte libre, pas de planning des
 * chauffeurs), les rappels WhatsApp, les états des lieux et la caution.
 */
class Booking extends Rental
{
    protected $table = 'rentals';

    public const DEPOSIT_STATUSES = ['held' => 'Caution encaissée', 'returned' => 'Caution rendue', 'retained' => 'Caution retenue', 'partial' => 'Caution retenue en partie'];

    /** Course / transfert : statuts vus par l'équipe. */
    public const RIDE_STATUSES = ['booked' => 'Réservée', 'en_route' => 'Chauffeur en route', 'on_board' => 'Client à bord', 'done' => 'Terminée', 'cancelled' => 'Annulée'];

    public function isRide(): bool
    {
        return $this->kind === 'ride';
    }

    public const DEPOSIT_REASONS = ['damage' => 'Dommages', 'late' => 'Retard', 'fuel' => 'Carburant manquant', 'cleaning' => 'Nettoyage', 'other' => 'Autre'];

    public function getFillable(): array
    {
        return [...parent::getFillable(), 'vehicle_request_id', 'quote_id', 'pickup_place', 'driver_name', 'confirmed_at', 'reminder_sent_at', 'thanked_at',
            'deposit_status', 'deposit_retained', 'deposit_reason', 'deposit_note', 'deposit_closed_at',
            'ride_from', 'ride_to', 'ride_passengers', 'ride_luggage', 'flight_number', 'ride_status', 'ride_reminded_at', 'km_included', 'extra_km_amount'];
    }

    protected function casts(): array
    {
        return [...parent::casts(),
            'confirmed_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'thanked_at' => 'datetime',
            'deposit_closed_at' => 'datetime',
            'deposit_retained' => 'integer',
            'ride_reminded_at' => 'datetime',
            'ride_passengers' => 'integer',
            'ride_luggage' => 'integer',
            'km_included' => 'integer',
            'extra_km_amount' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(VehicleRequest::class, 'vehicle_request_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'quote_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(VehicleInspection::class, 'rental_id');
    }

    /** Ce qui reste à encaisser sur le devis (acomptes et factures payés déduits). */
    public function balanceDue(): int
    {
        if ($this->quote === null || in_array($this->status, ['cancelled'], true)) {
            return 0;
        }

        $paid = (int) $this->quote->invoices()->where('status', '!=', 'cancelled')->sum('paid_amount');

        return max(0, (int) $this->quote->total - $paid);
    }

    /** Caution rendue au client (montant versé moins la retenue). */
    public function depositRefund(): int
    {
        return max(0, (int) $this->deposit - (int) $this->deposit_retained);
    }
}
