<?php

namespace App\Observers;

use App\Models\PurchaseItem;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

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

    public function updated(PurchaseItem $purchaseItem): void
    {
        // Stock sync logic removed as per SSOT migration
    }

    public function created(PurchaseItem $purchaseItem): void
    {
        // Stock sync logic removed as per SSOT migration
    }

    public function deleted(PurchaseItem $purchaseItem): void
    {
        // Stock sync logic removed as per SSOT migration
    }

    public function restored(PurchaseItem $purchaseItem): void
    {
        // Stock sync logic removed as per SSOT migration
    }
}
