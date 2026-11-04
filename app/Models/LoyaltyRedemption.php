<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Remise utilisée, saisie depuis la fiche client (bouton « Remise utilisée »). */
class LoyaltyRedemption extends Model
{
    protected $fillable = ['organization_id', 'loyalty_program_id', 'brand_id', 'customer_id', 'store_id', 'user_id', 'used_on', 'discount_percent', 'reward_label', 'amount'];

    protected function casts(): array
    {
        return ['used_on' => 'date'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
