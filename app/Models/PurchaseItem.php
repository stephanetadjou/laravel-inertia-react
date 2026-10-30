<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'une vente : prestation, produit ou ligne libre (type null).
 */
class PurchaseItem extends Model
{
    protected $fillable = [
        'purchase_id',
        'catalog_item_id',
        'type',
        'name',
        'quantity',
        'unit_price',
        'amount',
        'category_id',
        'article_id',
        'article_code',
        'product_category',
        'discount_amount',
        'properties',
        'import_row_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'amount' => 'integer',
            'discount_amount' => 'integer',
            'properties' => 'array',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
