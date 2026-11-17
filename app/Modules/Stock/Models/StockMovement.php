<?php

namespace App\Modules\Stock\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    public const REASONS = ['sale' => 'Vente', 'cancel' => 'Vente annulée', 'receipt' => 'Entrée de marchandise', 'adjustment' => 'Inventaire'];

    protected $fillable = ['stock_item_id', 'quantity', 'quantity_after', 'reason', 'purchase_id', 'note', 'user_id'];

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
