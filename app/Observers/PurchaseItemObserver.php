<?php

namespace App\Observers;

use App\Models\PurchaseItem;
// app/Observers/PurchaseItemObserver.php
namespace App\Observers;

use App\Models\PurchaseItem;
use App\Models\Product;

class PurchaseItemObserver
{
    private function updateProductStock(PurchaseItem $purchaseItem)
    {
        $product = $purchaseItem->product;
        if ($product) {
            $product->stock_quantity = $product->purchaseItems()->sum('remaining_quantity');
            $product->saveQuietly(); // saveQuietly to avoid triggering other events recursively
        }
    }

    public function updated(PurchaseItem $purchaseItem): void
    {
        $this->updateProductStock($purchaseItem);
    }

    public function saved(PurchaseItem $purchaseItem): void
    {
        $this->updateProductStock($purchaseItem);
    }
    public function deleted(PurchaseItem $purchaseItem): void
    {
        $this->updateProductStock($purchaseItem);
    }
    public function restored(PurchaseItem $purchaseItem): void
    {
        $this->updateProductStock($purchaseItem);
    }
}
