<?php

use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Modules\Stock\Services\Stock;

/*
 * Pont Ventes → Stock (comme un module « pont » d'Odoo) : chaque ligne de
 * vente d'un produit suivi fait baisser le stock, une annulation le rétablit.
 */
PurchaseItem::created(fn (PurchaseItem $line) => app(Stock::class)->sold($line));

Purchase::updated(function (Purchase $purchase) {
    if ($purchase->wasChanged('status') && $purchase->status === Purchase::STATUS_CANCELLED) {
        app(Stock::class)->cancelled($purchase);
    }
});
