<?php

namespace App\Models;

use App\Services\CustomerLifecycle;
use App\Services\FamilyMatcher;
use App\Services\Loyalty;
use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Builder;
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
        'receipt_number',
        'source_key',
        'type',
        'status',
        'subtotal',
        'discount_amount',
        'loyalty_discount_percent',
        'currency',
        'import_batch_id',
        'original_purchase_id',
        'customer_code',
        'buyer_name',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'source',
        'note',
    ];

    public const TYPE_SALE = 'sale';

    /** Retour : ticket à montant négatif, lié si possible au ticket d'origine. */
    public const TYPE_RETURN = 'return';

    public const STATUS_VALID = 'valid';

    /** Vente annulée (correction) : conservée et tracée, exclue de tous les calculs. */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Fragments SQL pour les agrégats sur `purchases` : un retour diminue le
     * CA mais ne compte ni comme un achat, ni comme une date d'achat.
     */
    public const SQL_SALES_COUNT = "SUM(CASE WHEN purchases.type = 'return' THEN 0 ELSE 1 END)";

    public const SQL_FIRST_SALE = "MIN(CASE WHEN purchases.type = 'return' THEN NULL ELSE purchases.purchased_at END)";

    public const SQL_LAST_SALE = "MAX(CASE WHEN purchases.type = 'return' THEN NULL ELSE purchases.purchased_at END)";

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
            'cancelled_at' => 'datetime',
            'amount' => 'integer',
            'subtotal' => 'integer',
            'discount_amount' => 'integer',
        ];
    }

    /**
     * Chaque achat met à jour les compteurs et l'étape de la fiche client
     * (regroupé en fin d'import, cf. CustomerLifecycle::batch()).
     */
    protected static function booted(): void
    {
        // Une vente annulée reste en base (traçabilité) mais disparaît des
        // listes et des calculs ; l'écran Ventes la montre avec withCancelled().
        static::addGlobalScope('valid', fn (Builder $q) => $q->where($q->qualifyColumn('status'), '!=', self::STATUS_CANCELLED));

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

    /** Inclut les ventes annulées (écran Ventes, journal). */
    public function scopeWithCancelled(Builder $query): Builder
    {
        return $query->withoutGlobalScope('valid');
    }

    public function isAnonymous(): bool
    {
        return $this->contact_id === null;
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function originalPurchase(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_purchase_id')->withoutGlobalScope('valid');
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
