<?php

namespace App\Modules\Location\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Compte entreprise : tarif négocié (remise ou prix par jour) appliqué à ses devis. */
class RentalAccount extends Model
{
    protected $fillable = ['organization_id', 'customer_id', 'discount_percent', 'day_rate', 'notes'];

    protected function casts(): array
    {
        return ['discount_percent' => 'integer', 'day_rate' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Tarif journalier appliqué à ce client pour un véhicule au tarif public $rate. */
    public function rateFor(int $rate): int
    {
        if ($this->day_rate) {
            return min($rate ?: $this->day_rate, $this->day_rate);
        }

        return (int) round($rate * (100 - $this->discount_percent) / 100);
    }
}
