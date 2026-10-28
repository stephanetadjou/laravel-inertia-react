<?php

namespace App\Models;

use Database\Factories\StaffMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Intervenant d'un établissement (coiffeuse, praticien, coach…), avec ou
 * sans compte utilisateur. Horaires propres (null = ceux de l'établissement),
 * prestations qu'il réalise (aucune = toutes).
 */
class StaffMember extends Model
{
    /** @use HasFactory<StaffMemberFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'store_id',
        'user_id',
        'name',
        'color',
        'hours',
        'bookable_online',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'hours' => 'array',
            'bookable_online' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(CatalogItem::class);
    }

    /**
     * Horaires de l'intervenant, jour par jour (1 = lundi … 7 = dimanche) ;
     * null = ne travaille pas ce jour-là. Sans horaires propres : ceux de
     * l'établissement.
     *
     * @return array<int, array{open: string, close: string}|null>
     */
    public function workingHours(): array
    {
        if ($this->hours === null) {
            return $this->store->bookingHours();
        }

        $hours = [];
        foreach (range(1, 7) as $day) {
            $value = $this->hours[$day] ?? null;
            $hours[$day] = is_array($value) && isset($value['open'], $value['close']) && $value['open'] < $value['close']
                ? ['open' => (string) $value['open'], 'close' => (string) $value['close']]
                : null;
        }

        return $hours;
    }

    /** @param array<int, int> $serviceIds */
    public function canPerform(array $serviceIds): bool
    {
        $own = $this->services->pluck('id');

        return $own->isEmpty() || collect($serviceIds)->every(fn ($id) => $own->contains($id));
    }
}
