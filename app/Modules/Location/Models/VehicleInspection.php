<?php

namespace App\Modules\Location\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** État des lieux au départ (out) ou au retour (in) : photos, kilométrage, carburant, dommages. */
class VehicleInspection extends Model
{
    public const PHASES = ['out' => 'Départ', 'in' => 'Retour'];

    protected $fillable = ['rental_id', 'phase', 'mileage', 'fuel', 'photos', 'damages', 'signed_name', 'user_id', 'checklist'];

    protected function casts(): array
    {
        return ['photos' => 'array', 'checklist' => 'array', 'mileage' => 'integer', 'fuel' => 'integer'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'rental_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
