<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Récompense du programme de fidélité, échangeable contre des points. */
class LoyaltyReward extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'points_cost',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'points_cost' => 'integer',
            'active' => 'boolean',
        ];
    }
}
