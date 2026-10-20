<?php

namespace App\Models;

use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rendez-vous d'un client dans un établissement.
 */
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    public const STATUSES = [
        'planned' => 'Prévu',
        'confirmed' => 'Confirmé',
        'done' => 'Honoré',
        'no_show' => 'Absent',
        'cancelled' => 'Annulé',
    ];

    /** Statuts d'un rendez-vous encore à venir (à rappeler ou confirmer). */
    public const OPEN_STATUSES = ['planned', 'confirmed'];

    protected $fillable = [
        'store_id',
        'customer_id',
        'staff_user_id',
        'created_by',
        'starts_at',
        'duration_minutes',
        'service',
        'status',
        'notes',
        'purchase_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AppointmentItem::class);
    }

    /** Vente créée à l'encaissement. */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
