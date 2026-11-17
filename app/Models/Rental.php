<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contrat de location d'un véhicule (module « vehicules »).
 *
 * Courte durée (jour, week-end, semaine) : réservé → en cours au départ →
 * rendu au retour, qui encaisse la location en une vente. Longue durée
 * (mois, année) : payée en mensualités (échéancier), renouvellement proposé
 * avant la fin. Le véhicule est occupé de starts_at à ends_at (ou jusqu'au
 * retour réel) ; deux contrats ne se chevauchent jamais sur un véhicule.
 */
class Rental extends Model
{
    public const KINDS = ['short' => 'Courte durée', 'long' => 'Longue durée'];

    public const STATUSES = [
        'reserved' => 'Réservé',
        'ongoing' => 'En cours',
        'returned' => 'Rendu',
        'cancelled' => 'Annulé',
    ];

    /** Contrats qui occupent le véhicule. */
    public const BLOCKING = ['reserved', 'ongoing'];

    public const PERIODS = ['day' => 'jour', 'week' => 'semaine', 'month' => 'mois'];

    protected $fillable = [
        'organization_id',
        'store_id',
        'resource_id',
        'customer_id',
        'driver_id',
        'kind',
        'status',
        'starts_at',
        'ends_at',
        'departed_at',
        'returned_at',
        'rate',
        'rate_period',
        'amount',
        'deposit',
        'km_out',
        'km_in',
        'fuel_out',
        'fuel_in',
        'notes',
        'purchase_id',
        'payment_schedule_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'departed_at' => 'datetime',
            'returned_at' => 'datetime',
            'rate' => 'integer',
            'amount' => 'integer',
            'deposit' => 'integer',
            'km_out' => 'integer',
            'km_in' => 'integer',
            'fuel_out' => 'integer',
            'fuel_in' => 'integer',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(BookableResource::class, 'resource_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'driver_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PaymentSchedule::class, 'payment_schedule_id');
    }

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', self::BLOCKING);
    }

    /** Contrats qui occupent le véhicule entre deux instants. */
    public function scopeOverlapping(Builder $query, $from, $to): Builder
    {
        return $query->blocking()->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }

    public function isLate(): bool
    {
        return $this->status === 'ongoing' && $this->ends_at->lt(now());
    }
}
