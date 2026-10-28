<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mouvement de points d'une fiche client : gain sur un achat (earn),
 * échange d'une récompense (redeem), ajustement manuel (adjust) ou
 * annulation d'un gain quand l'achat est supprimé (reverse).
 */
class LoyaltyTransaction extends Model
{
    public const TYPES = [
        'earn' => 'Achat',
        'redeem' => 'Récompense',
        'adjust' => 'Ajustement',
        'reverse' => 'Achat annulé',
    ];

    protected $fillable = [
        'organization_id',
        'customer_id',
        'type',
        'points',
        'purchase_id',
        'reward_id',
        'user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(LoyaltyReward::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
