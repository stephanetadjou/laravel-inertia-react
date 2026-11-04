<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** État d'un client dans une enseigne d'un programme, recalculé par LoyaltyEngine. */
class LoyaltyCard extends Model
{
    public const STATUS_NONE = 'none';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_NONE => 'Non membre',
        self::STATUS_ACTIVE => 'Actif',
        self::STATUS_EXPIRED => 'Expiré',
    ];

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['activated_on' => 'date', 'expires_on' => 'date', 'last_purchase_on' => 'date', 'computed_at' => 'datetime'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_program_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
