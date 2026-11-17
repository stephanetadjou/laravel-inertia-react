<?php

namespace App\Modules\Facturation\Models;

use App\Models\Purchase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Paiement d'une facture ; la vente liée alimente la fiche client et la caisse du jour. */
class InvoicePayment extends Model
{
    protected $fillable = ['invoice_id', 'purchase_id', 'amount', 'method', 'paid_at', 'user_id'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'amount' => 'integer'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }
}
