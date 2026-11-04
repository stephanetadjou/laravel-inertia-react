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

    public const REWARD_TYPES = [
        'percent' => '% de remise',
        'amount' => 'Montant de remise',
        'voucher' => 'Bon d’achat',
        'gift' => 'Cadeau ou article offert',
        'perk' => 'Avantage libre',
    ];

    /**
     * Récompense d'un palier. Un palier sans type (points, percent) est une remise en %.
     *
     * @param  array<string, mixed>  $tier
     * @return array{points: int, percent: int, label: string, cost: int}
     */
    public static function reward(array $tier, string $currency = 'FCFA'): array
    {
        $type = $tier['type'] ?? 'percent';
        $percent = $type === 'percent' ? (int) ($tier['percent'] ?? 0) : 0;
        $amount = number_format((int) ($tier['amount'] ?? 0), 0, ',', ' ').' '.$currency;

        return [
            'points' => (int) $tier['points'],
            'percent' => $percent,
            'cost' => in_array($type, ['amount', 'voucher'], true) ? (int) $tier['amount'] : 0,
            'label' => match ($type) {
                'amount' => "Remise de {$amount}",
                'voucher' => "Bon d’achat de {$amount}",
                'gift', 'perk' => (string) ($tier['label'] ?? ''),
                default => "Remise de {$percent} %",
            },
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
