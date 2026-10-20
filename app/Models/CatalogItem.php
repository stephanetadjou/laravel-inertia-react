<?php

namespace App\Models;

use Database\Factories\CatalogItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Article du catalogue : prestation (durée, réservable dans l'agenda) ou
 * produit (vendu, sans réservation). Proposé dans tous les établissements,
 * ou seulement dans ceux choisis (stores).
 */
class CatalogItem extends Model
{
    /** @use HasFactory<CatalogItemFactory> */
    use HasFactory;

    public const TYPE_SERVICE = 'service';

    public const TYPE_PRODUCT = 'product';

    public const TYPES = [
        self::TYPE_SERVICE => 'Prestation',
        self::TYPE_PRODUCT => 'Produit',
    ];

    protected $fillable = [
        'organization_id',
        'type',
        'name',
        'category_id',
        'price',
        'duration_minutes',
        'reference',
        'active',
        'bookable_online',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'duration_minutes' => 'integer',
            'active' => 'boolean',
            'bookable_online' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class);
    }

    /** Articles proposés dans un établissement (aucun établissement choisi = tous). */
    public function scopeAvailableIn(Builder $query, int $storeId): Builder
    {
        return $query->where(fn ($q) => $q->whereDoesntHave('stores')->orWhereHas('stores', fn ($s) => $s->whereKey($storeId)));
    }

    public function isService(): bool
    {
        return $this->type === self::TYPE_SERVICE;
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'category_id' => $this->category_id,
            'category' => $this->category?->name,
            'price' => (int) $this->price,
            'duration_minutes' => $this->duration_minutes,
            'reference' => $this->reference,
            'active' => $this->active,
            'bookable_online' => $this->bookable_online ?? true,
            'store_ids' => $this->stores->pluck('id')->values(),
        ];
    }
}
