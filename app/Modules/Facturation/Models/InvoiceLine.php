<?php

namespace App\Modules\Facturation\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['invoice_id', 'catalog_item_id', 'label', 'quantity', 'unit_price', 'amount', 'position'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_price' => 'integer', 'amount' => 'integer'];
    }
}
