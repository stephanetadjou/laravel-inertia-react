<?php

namespace App\Models;

use App\Services\CustomerLifecycle;
use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
