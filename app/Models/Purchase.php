<?php

namespace App\Models;

use App\Services\CustomerLifecycle;
use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'store_id',
        'amount',
        'quantity',
        'product_category',
        'purchased_at',
        'payment_method',
    ];

    /** Moyens de paiement proposés à l'encaissement. */
    public const PAYMENT_METHODS = [
        'cash' => 'Espèces',
        'airtel_money' => 'Airtel Money',
        'moov_money' => 'Moov Money',
        'mtn_momo' => 'MTN Mobile Money',
        'orange_money' => 'Orange Money',
        'card' => 'Carte bancaire',
        'transfer' => 'Virement',
        'other' => 'Autre',
    ];

    protected function casts(): array
    {
        return [
            'purchased_at' => 'date',
        ];
    }

    /**
     * Chaque achat met à jour les compteurs et l'étape de la fiche client
     * (regroupé en fin d'import, cf. CustomerLifecycle::batch()).
     */
    protected static function booted(): void
    {
        $refresh = function (Purchase $purchase) {
            app(CustomerLifecycle::class)->touch(
                Contact::whereKey($purchase->contact_id)->value('customer_id'),
            );
        };

        static::saved($refresh);
        static::deleted($refresh);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
