<?php

namespace App\Modules\Location\Models;

use Illuminate\Database\Eloquent\Model;

/** Forfait d'une course (ex. « Aéroport ↔ Centre-ville », 15 000 F, 60 min). */
class RideFare extends Model
{
    protected $fillable = ['organization_id', 'label', 'price', 'minutes'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'minutes' => 'integer'];
    }
}
