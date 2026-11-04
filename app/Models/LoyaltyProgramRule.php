<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Règles d'une enseigne dans un programme. Paliers : [{points, percent}]. */
class LoyaltyProgramRule extends Model
{
    protected $fillable = ['loyalty_program_id', 'brand_id', 'threshold', 'tranche_amount', 'points_per_tranche', 'activation_bonus', 'tiers', 'validity_months', 'reset_after_use'];

    protected function casts(): array
    {
        return [
            'threshold' => 'integer',
            'tranche_amount' => 'integer',
            'points_per_tranche' => 'integer',
            'activation_bonus' => 'integer',
            'tiers' => 'array',
            'validity_months' => 'integer',
            'reset_after_use' => 'boolean',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_program_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
