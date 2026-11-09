<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mouvement du solde de crédits de messages (recharge +, campagne −). */
class CreditMovement extends Model
{
    protected $fillable = ['organization_id', 'user_id', 'campaign_id', 'amount', 'balance_after', 'reason'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance_after' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
