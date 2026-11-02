<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Échéancier d'un client (module « tranches ») : seul (« Payer en plusieurs
 * fois ») ou préparé par un abonnement (subscription_id).
 */
class PaymentSchedule extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id',
        'store_id',
        'customer_id',
        'beneficiary_id',
        'catalog_item_id',
        'subscription_id',
        'label',
        'total_amount',
        'status',
        'note',
        'created_by',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'beneficiary_id');
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('number');
    }

    /** Personne concernée : la personne suivie s'il y en a une, sinon le payeur. */
    public function subject(): Customer
    {
        return $this->beneficiary ?? $this->customer;
    }
}
