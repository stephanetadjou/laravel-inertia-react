<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Formule (module « abonnements ») : mensualité, saison, carte de séances ou
 * paiement unique, avec frais uniques et options.
 */
class Plan extends Model
{
    public const PERIODS = [
        'month' => 'Au mois',
        'season' => 'À la saison',
        'sessions' => 'Carte de séances',
        'once' => 'Paiement unique',
    ];

    protected $fillable = [
        'organization_id',
        'name',
        'catalog_item_id',
        'price',
        'period',
        'sessions_count',
        'fees',
        'options',
        'description',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'fees' => 'array',
            'options' => 'array',
            'active' => 'boolean',
        ];
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** « 13 000 FCFA / mois », « 10 séances : 25 000 FCFA »… */
    public function priceLabel(): string
    {
        $price = number_format((int) $this->price, 0, ',', ' ').' FCFA';

        return match ($this->period) {
            'month' => "{$price} / mois",
            'season' => "{$price} / saison",
            'sessions' => "{$this->sessions_count} séances : {$price}",
            default => $price,
        };
    }
}
