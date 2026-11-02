<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Abonnement d'un client (payeur) ou d'une personne suivie à une formule ;
 * son échéancier est dans payment_schedule_id.
 */
class Subscription extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE => 'En cours',
        self::STATUS_ENDED => 'Terminé',
        self::STATUS_CANCELLED => 'Arrêté',
    ];

    protected $fillable = [
        'organization_id',
        'store_id',
        'customer_id',
        'beneficiary_id',
        'plan_id',
        'starts_on',
        'ends_on',
        'status',
        'sessions_left',
        'price',
        'options',
        'payment_schedule_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'options' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'beneficiary_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PaymentSchedule::class, 'payment_schedule_id');
    }

    /** Personne qui pratique : la personne suivie, sinon le payeur. */
    public function member(): Customer
    {
        return $this->beneficiary ?? $this->customer;
    }

    /** Statut affiché : un abonnement en cours dont la fin est passée est terminé. */
    public function currentStatus(): string
    {
        if ($this->status === self::STATUS_ACTIVE && $this->ends_on !== null && $this->ends_on->lt(today())) {
            return self::STATUS_ENDED;
        }

        return $this->status;
    }

    /** Abonnements en cours (fin non passée). */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', today()->toDateString()));
    }
}
