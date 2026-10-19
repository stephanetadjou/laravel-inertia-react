<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relance faite à la main depuis une liste d'actions (WhatsApp ouvert par
 * lien wa.me, message envoyé depuis le téléphone de l'établissement).
 */
class CustomerFollowUp extends Model
{
    protected $fillable = [
        'customer_id',
        'store_id',
        'user_id',
        'appointment_id',
        'action',
        'channel',
        'message',
        'done_at',
    ];

    protected function casts(): array
    {
        return [
            'done_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
