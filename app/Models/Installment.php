<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tranche d'un échéancier : à payer, en retard (échéance passée) ou payée
 * (paid_at, avec la vente créée à l'encaissement).
 */
class Installment extends Model
{
    public const STATUS_DUE = 'due';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_PAID = 'paid';

    public const STATUSES = [
        self::STATUS_DUE => 'À payer',
        self::STATUS_OVERDUE => 'En retard',
        self::STATUS_PAID => 'Payée',
    ];

    protected $fillable = [
        'payment_schedule_id',
        'organization_id',
        'number',
        'label',
        'amount',
        'due_on',
        'paid_at',
        'payment_method',
        'purchase_id',
        'paid_by',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PaymentSchedule::class, 'payment_schedule_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function status(): string
    {
        return match (true) {
            $this->paid_at !== null => self::STATUS_PAID,
            $this->due_on->lt(today()) => self::STATUS_OVERDUE,
            default => self::STATUS_DUE,
        };
    }

    /** Tranches non payées d'échéanciers actifs. */
    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereNull('paid_at')
            ->whereHas('schedule', fn ($q) => $q->where('status', PaymentSchedule::STATUS_ACTIVE));
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->unpaid()->where('due_on', '<', today()->toDateString());
    }
}
