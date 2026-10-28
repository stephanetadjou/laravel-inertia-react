<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Fermeture : jour férié, congés ou fermeture exceptionnelle, d'un
 * établissement ou de toute l'entreprise (store_id null), du starts_on au
 * ends_on inclus.
 */
class Closure extends Model
{
    protected $fillable = [
        'organization_id',
        'store_id',
        'starts_on',
        'ends_on',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** Fermetures d'un établissement (les siennes et celles de toute l'entreprise) qui touchent la période. */
    public function scopeAffecting(Builder $query, Store $store, Carbon $from, Carbon $to): Builder
    {
        return $query->where('organization_id', $store->organization_id)
            ->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $store->id))
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->whereDate('ends_on', '>=', $from->toDateString());
    }

    public function covers(Carbon $day): bool
    {
        return $day->toDateString() >= $this->starts_on->toDateString() && $day->toDateString() <= $this->ends_on->toDateString();
    }
}
