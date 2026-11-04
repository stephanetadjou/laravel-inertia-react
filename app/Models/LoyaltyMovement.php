<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Journal d'une carte, reconstruit à chaque recalcul (cf. LoyaltyEngine). */
class LoyaltyMovement extends Model
{
    public const ACTIVATION = 'activation';

    public const GAIN = 'gain';

    public const BONUS = 'bonus';

    public const TIER = 'tier';

    public const DISCOUNT_USED = 'discount_used';

    public const DISCOUNT_IGNORED = 'discount_ignored';

    public const RETURN = 'return';

    public const EXPIRATION = 'expiration';

    public const TYPES = [
        self::ACTIVATION => 'Activation',
        self::GAIN => 'Gain',
        self::BONUS => 'Bonus d’activation',
        self::TIER => 'Palier atteint',
        self::DISCOUNT_USED => 'Remise utilisée',
        self::DISCOUNT_IGNORED => 'Remise ignorée',
        self::RETURN => 'Retour',
        self::EXPIRATION => 'Expiration',
    ];

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['happened_on' => 'date'];
    }
}
