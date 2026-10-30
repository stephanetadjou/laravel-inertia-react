<?php

namespace App\Models;

use App\Services\CustomerLifecycle;
use App\Services\FamilyMatcher;
use App\Services\Loyalty;
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
        'category_id',
        'article',
        'properties',
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
            'properties' => 'array',
        ];
    }

    /**
     * Chaque achat met à jour les compteurs et l'étape de la fiche client
     * (regroupé en fin d'import, cf. CustomerLifecycle::batch()).
     */
    protected static function booted(): void
    {
        // Famille reconnue à partir de la catégorie ou de l'article du fichier,
        // sauf si elle est choisie explicitement (cf. FamilyMatcher).
        static::saving(function (Purchase $purchase) {
            if ($purchase->isDirty('category_id') || ! $purchase->isDirty(['product_category', 'article'])) {
                return;
            }

            $organizationId = Store::whereKey($purchase->store_id)->value('organization_id');
            $purchase->category_id = $organizationId
                ? FamilyMatcher::for($organizationId)->match($purchase->product_category, $purchase->article)
                : null;
        });

        $refresh = function (Purchase $purchase) {
            app(CustomerLifecycle::class)->touch(
                Contact::whereKey($purchase->contact_id)->value('customer_id'),
            );
        };

        static::saved($refresh);
        static::deleted($refresh);

        // Fidélité : points gagnés (ou ajustés) à chaque achat, retirés si l'achat est supprimé.
        static::saved(function (Purchase $purchase) {
            if ($organization = Store::whereKey($purchase->store_id)->first()?->organization) {
                Loyalty::for($organization)->syncPurchase($purchase);
            }
        });
        static::deleting(function (Purchase $purchase) {
            if ($organization = Store::whereKey($purchase->store_id)->first()?->organization) {
                Loyalty::for($organization)->reversePurchase($purchase, Contact::whereKey($purchase->contact_id)->value('customer_id'));
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
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
