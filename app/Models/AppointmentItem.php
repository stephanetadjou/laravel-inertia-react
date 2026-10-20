<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prestation choisie pour un rendez-vous : nom, prix et durée copiés du
 * catalogue au moment du choix (un changement de tarif ne modifie pas les
 * rendez-vous déjà pris).
 */
class AppointmentItem extends Model
{
    protected $fillable = [
        'appointment_id',
        'catalog_item_id',
        'name',
        'price',
        'duration_minutes',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'duration_minutes' => 'integer',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
