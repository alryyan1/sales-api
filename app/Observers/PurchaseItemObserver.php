<?php

namespace App\Observers;

use App\Models\PurchaseItem;
// app/Observers/PurchaseItemObserver.php
namespace App\Observers;

use App\Models\PurchaseItem;
use App\Models\Product;
use Log;

class PurchaseItemObserver
{
    // private function updateProductStock(PurchaseItem $purchaseItem)
    // {
    //     $product = $purchaseItem->product;
    //     if ($product) {
    //         $product->stock_quantity = $product->purchaseItems()->sum('remaining_quantity');
    //         $product->saveQuietly(); // saveQuietly to avoid triggering other events recursively
    //     }
    // }
    // private function updateProductStock(PurchaseItem $purchaseItem): void
    // {
    //     if ($purchaseItem->product_id) {
    //         $product = Product::find($purchaseItem->product_id); // Load with 'units_per_stocking_unit'
    //         if ($product) {
    //             $totalSellableUnits = 0;
    //             // Sum remaining sellable units from all batches
    //             foreach ($product->purchaseItems()->where('remaining_quantity', '>', 0)->get() as $batch) {
    //                 $totalSellableUnits += $batch->remaining_quantity * ($product->units_per_stocking_unit ?: 1);
    //             }
    //             $product->stock_quantity = $totalSellableUnits;
    //             $product->saveQuietly();
    //         }
    //     }
    // }

    private function updateProductStock(PurchaseItem $purchaseItem): void
    {
        if ($purchaseItem->product_id) {
            $product = Product::find($purchaseItem->product_id);
            if ($product) {
                // remaining_quantity on PurchaseItem is now in sellable units
                // FIX: Only count stock from purchases that are 'received'
                $totalSellableUnitsStock = $product->purchaseItems()
                    ->whereHas('purchase', function ($q) {
                        $q->where('status', 'received');
                    })
                    ->sum('remaining_quantity');

                $product->stock_quantity = $totalSellableUnitsStock; // This is now sum of sellable units
                $product->saveQuietly();
                Log::info("Observer: Product ID {$product->id} stock (sellable units) updated to {$totalSellableUnitsStock}.");
            }
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
