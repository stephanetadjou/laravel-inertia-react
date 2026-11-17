<?php

namespace App\Modules\Stock\Models;

use App\Models\CatalogItem;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockItem extends Model
{
    protected $fillable = ['organization_id', 'catalog_item_id', 'quantity', 'min_quantity', 'cost_price', 'supplier'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'min_quantity' => 'integer', 'cost_price' => 'integer'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest('id');
    }

    /** out = rupture, low = à réassortir, ok. */
    public function level(): string
    {
        return $this->quantity <= 0 ? 'out' : ($this->quantity <= $this->min_quantity ? 'low' : 'ok');
    }
}
