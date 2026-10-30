<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Numéro de téléphone d'une fiche client : le numéro principal (celui des
 * envois), les anciens numéros avec leur période de validité, et l'indication
 * « partagé » (numéro familial ou de standard : jamais de rattachement
 * automatique d'une vente à un autre nom).
 */
class CustomerPhone extends Model
{
    protected $fillable = ['customer_id', 'phone_e164', 'is_primary', 'is_verified', 'is_shared', 'valid_from', 'valid_to'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_verified' => 'boolean',
            'is_shared' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
