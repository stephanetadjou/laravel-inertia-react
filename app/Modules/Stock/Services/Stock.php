<?php

namespace App\Modules\Stock\Services;

use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Modules\Depenses\Models\Expense;
use App\Modules\Stock\Models\StockItem;
use Illuminate\Support\Facades\DB;

/** Mouvements de stock : vente, annulation, entrée de marchandise, inventaire. */
class Stock
{
    public function move(StockItem $item, int $quantity, string $reason, ?User $user = null, ?int $purchaseId = null, ?string $note = null): void
    {
        DB::transaction(function () use ($item, $quantity, $reason, $user, $purchaseId, $note) {
            $item->increment('quantity', $quantity);
            $item->movements()->create([
                'quantity' => $quantity,
                'quantity_after' => $item->quantity,
                'reason' => $reason,
                'purchase_id' => $purchaseId,
                'note' => $note ? mb_substr($note, 0, 255) : null,
                'user_id' => $user?->id,
            ]);
        });
    }

    /** Entrée de marchandise ; une dépense « Produits et fournitures » si le module Dépenses est actif. */
    public function receive(StockItem $item, int $quantity, User $user, ?int $unitCost = null, ?string $supplier = null, ?int $storeId = null): void
    {
        $this->move($item, $quantity, 'receipt', $user, note: $supplier);
        $item->update(array_filter(['cost_price' => $unitCost, 'supplier' => $supplier], fn ($v) => $v !== null));

        if ($unitCost && $storeId && $user->organization->hasModule('depenses')) {
            Expense::create([
                'organization_id' => $user->organization_id,
                'store_id' => $storeId,
                'spent_on' => today(),
                'amount' => $unitCost * $quantity,
                'category' => 'fournitures',
                'label' => "Réassort : {$quantity} × {$item->product->name}",
                'supplier' => $supplier,
                'method' => 'cash',
                'user_id' => $user->id,
            ]);
        }
    }

    /** Inventaire : la quantité comptée remplace la quantité théorique. */
    public function count(StockItem $item, int $counted, User $user): void
    {
        $delta = $counted - $item->quantity;

        if ($delta !== 0) {
            $this->move($item, $delta, 'adjustment', $user, note: "Inventaire : {$counted} compté(s)");
        }
    }

    /** Vente d'un produit suivi (appelé à la création d'une ligne de vente). */
    public function sold(PurchaseItem $line): void
    {
        $item = $this->itemFor($line);

        if ($item !== null) {
            $this->move($item, -max(1, (int) round((float) $line->quantity)), 'sale', purchaseId: $line->purchase_id);
        }
    }

    /** Vente annulée : les produits reviennent en stock. */
    public function cancelled(Purchase $purchase): void
    {
        foreach ($purchase->items as $line) {
            $item = $this->itemFor($line);
            if ($item !== null) {
                $this->move($item, max(1, (int) round((float) $line->quantity)), 'cancel', purchaseId: $purchase->id);
            }
        }
    }

    private function itemFor(PurchaseItem $line): ?StockItem
    {
        if ($line->catalog_item_id === null) {
            return null;
        }

        $item = StockItem::where('catalog_item_id', $line->catalog_item_id)->first();

        return $item && $item->organization?->hasModule('stock') ? $item : null;
    }
}
