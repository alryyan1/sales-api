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

    private function updateProductStock(PurchaseItem $purchaseItem): void
    {
        if ($purchaseItem->product_id) {
            // Refresh the purchase item to ensure we have the latest data
            $purchaseItem->refresh();
            
            // Get fresh product instance to avoid cached relationships
            $product = Product::withoutGlobalScopes()->find($purchaseItem->product_id);
            if ($product) {
                $oldStockQuantity = $product->stock_quantity;
                
                // remaining_quantity on PurchaseItem is now in sellable units
                // FIX: Only count stock from purchases that are 'received'
                // Use fresh query to ensure we get the latest data from database
                
                // First, let's check what purchase items exist and their status
                $allPurchaseItems = \App\Models\PurchaseItem::where('product_id', $product->id)->get();
                $purchaseItemsWithStock = \App\Models\PurchaseItem::where('product_id', $product->id)
                    ->where('remaining_quantity', '>', 0)
                    ->get();
                
                // Check purchase statuses
                $purchaseStatuses = [];
                foreach ($purchaseItemsWithStock as $pi) {
                    $purchase = $pi->purchase;
                    $purchaseStatuses[] = [
                        'purchase_item_id' => $pi->id,
                        'purchase_id' => $pi->purchase_id,
                        'purchase_status' => $purchase ? $purchase->status : 'null',
                        'remaining_quantity' => $pi->remaining_quantity,
                    ];
                }
                
                $totalSellableUnitsStock = \App\Models\PurchaseItem::where('product_id', $product->id)
                    ->whereHas('purchase', function ($q) {
                        $q->where('status', 'received');
                    })
                    ->sum('remaining_quantity');

                // FIX: Handle NULL case - sum() returns NULL when no rows match, cast to 0
                $totalSellableUnitsStock = $totalSellableUnitsStock ?? 0;
                
                // Also calculate without the status filter for comparison
                $totalWithoutStatusFilter = \App\Models\PurchaseItem::where('product_id', $product->id)
                    ->sum('remaining_quantity');
                $totalWithoutStatusFilter = $totalWithoutStatusFilter ?? 0;
                
                // Log detailed information for debugging
                Log::info("PurchaseItemObserver: Updating stock for Product ID {$product->id}", [
                    'purchase_item_id' => $purchaseItem->id,
                    'purchase_item_remaining_quantity' => $purchaseItem->remaining_quantity,
                    'old_stock_quantity' => $oldStockQuantity,
                    'calculated_stock_quantity' => $totalSellableUnitsStock,
                    'total_without_status_filter' => $totalWithoutStatusFilter,
                    'all_purchase_items_count' => $allPurchaseItems->count(),
                    'purchase_items_with_stock_count' => $purchaseItemsWithStock->count(),
                    'purchase_statuses' => $purchaseStatuses,
                    'product_name' => $product->name,
                ]);
                
                // Safety check: If old stock was > 0 and new stock is 0, verify this is correct
                // by checking if there are actually any purchase items with remaining quantity
                if ($oldStockQuantity > 0 && $totalSellableUnitsStock == 0) {
                    // Check with status filter
                    $purchaseItemsCountWithStatus = \App\Models\PurchaseItem::where('product_id', $product->id)
                        ->whereHas('purchase', function ($q) {
                            $q->where('status', 'received');
                        })
                        ->where('remaining_quantity', '>', 0)
                        ->count();
                    
                    // Check without status filter
                    $purchaseItemsCountWithoutStatus = \App\Models\PurchaseItem::where('product_id', $product->id)
                        ->where('remaining_quantity', '>', 0)
                        ->count();
                    
                    Log::warning("PurchaseItemObserver: Stock calculated as 0 but purchase items exist", [
                        'product_id' => $product->id,
                        'purchase_item_id' => $purchaseItem->id,
                        'old_stock' => $oldStockQuantity,
                        'purchase_items_with_status_filter' => $purchaseItemsCountWithStatus,
                        'purchase_items_without_status_filter' => $purchaseItemsCountWithoutStatus,
                        'total_without_status_filter' => $totalWithoutStatusFilter,
                    ]);
                    
                    // If there are purchase items without the status filter, use that value
                    if ($purchaseItemsCountWithoutStatus > 0 && $totalWithoutStatusFilter > 0) {
                        Log::info("PurchaseItemObserver: Using stock without status filter: {$totalWithoutStatusFilter} (purchase status may not be 'received')");
                        $totalSellableUnitsStock = $totalWithoutStatusFilter;
                    } else if ($purchaseItemsCountWithStatus > 0) {
                        // Recalculate using a different method to verify
                        $recalculatedStock = \App\Models\PurchaseItem::where('product_id', $product->id)
                            ->whereHas('purchase', function ($q) {
                                $q->where('status', 'received');
                            })
                            ->where('remaining_quantity', '>', 0)
                            ->sum('remaining_quantity');
                        
                        $recalculatedStock = $recalculatedStock ?? 0;
                        
                        if ($recalculatedStock > 0) {
                            Log::info("PurchaseItemObserver: Recalculated stock is {$recalculatedStock}, using this value instead");
                            $totalSellableUnitsStock = $recalculatedStock;
                        }
                    }
                }

                // Only update if the value has changed to avoid unnecessary writes
                if ($product->stock_quantity != $totalSellableUnitsStock) {
                    $product->stock_quantity = $totalSellableUnitsStock;
                    $product->saveQuietly();
                    Log::info("PurchaseItemObserver: Product ID {$product->id} stock updated from {$oldStockQuantity} to {$totalSellableUnitsStock}.");
                } else {
                    Log::debug("PurchaseItemObserver: Product ID {$product->id} stock unchanged at {$totalSellableUnitsStock}.");
                }
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
