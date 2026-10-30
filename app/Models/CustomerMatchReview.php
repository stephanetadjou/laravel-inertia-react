<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rapprochement à vérifier (Clients › À vérifier) : une vente importée porte
 * un numéro déjà connu mais un nom très différent, ou un numéro marqué
 * partagé. Les ventes restent non rattachées tant que l'utilisateur n'a pas
 * choisi : rattacher à la fiche, ou garder séparé.
 */
class CustomerMatchReview extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ATTACHED = 'attached';

    public const STATUS_SEPARATE = 'separate';

    public const REASONS = [
        'name_mismatch' => 'Nom très différent',
        'shared_phone' => 'Numéro partagé',
    ];

    protected $fillable = [
        'organization_id', 'customer_id', 'store_id', 'import_batch_id', 'phone_e164', 'incoming_name', 'existing_name',
        'reason', 'purchase_ids', 'status', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['purchase_ids' => 'array', 'resolved_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
