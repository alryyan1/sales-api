<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class ProductDeletionService
{
    /**
     * Force delete a product and all its associated records (Sales, Purchases, etc.)
     * keeping financial data consistent by adjusting payments.
     *
     * @param Product $product
     * @return void
     * @throws \Exception
     */
    public function forceDeleteProduct(Product $product): void
    {
        // Nuclear Option: Disable foreign key checks for this operation
        Schema::disableForeignKeyConstraints();

        try {
            DB::transaction(function () use ($product) {
                // 1. Collect Parent IDs and Calculate Reductions
                $saleIds = $product->saleItems()->pluck('sale_id')->unique();
                $purchaseIds = $product->purchaseItems()->pluck('purchase_id')->unique();
                $purchaseItemIds = $product->purchaseItems()->pluck('id');

                $saleReductions = []; // Sale ID => Amount to reduce from payments

                // Get items linked directly to product
                $directItems = $product->saleItems()->get();
                foreach ($directItems as $item) {
                    if (!isset($saleReductions[$item->sale_id])) {
                        $saleReductions[$item->sale_id] = 0;
                    }
                    $saleReductions[$item->sale_id] += $item->total_price;
                }

                // Get items linked via purchase batches (find duplicates/missed items)
                // We use the ID to ensure uniqueness in our calculation
                $itemsToDeleteIds = DB::table('sale_items')
                    ->where('product_id', $product->id)
                    ->orWhereIn('purchase_item_id', $purchaseItemIds)
                    ->pluck('id')
                    ->unique();

                // Re-calculate reductions based on unique IDs to be safe and accurate
                $saleReductions = [];
                $itemsToDelete = \App\Models\SaleItem::whereIn('id', $itemsToDeleteIds)->get();
                foreach ($itemsToDelete as $item) {
                    if (!isset($saleReductions[$item->sale_id])) {
                        $saleReductions[$item->sale_id] = 0;
                    }
                    $saleReductions[$item->sale_id] += $item->total_price;
                }

                // 2. Delete Dependencies of Sales/Purchases
                DB::table('sale_return_items')->where('product_id', $product->id)->delete();
                DB::table('stock_requisition_items')->where('product_id', $product->id)->delete();
                DB::table('inventory_count_items')->where('product_id', $product->id)->delete();
                \App\Models\StockAdjustment::where('product_id', $product->id)->delete();

                // Aggressively cleanup ANY items linked to these purchase batches
                DB::table('sale_items')->whereIn('purchase_item_id', $purchaseItemIds)->delete();
                DB::table('sale_return_items')->whereIn('return_to_purchase_item_id', $purchaseItemIds)->delete();
                DB::table('stock_requisition_items')->whereIn('issued_from_purchase_item_id', $purchaseItemIds)->delete();

                // 3. Delete Sale Items (Standard cleanup)
                $product->saleItems()->delete();

                // 4. Delete Purchase Items
                $product->purchaseItems()->delete();

                // 5. Delete from product_warehouse pivot
                $product->warehouses()->detach();

                // 6. Cleanup/Update Parent Sales & Payments
                foreach ($saleIds as $saleId) {
                    $sale = \App\Models\Sale::find($saleId);
                    if ($sale) {
                        if ($sale->items()->count() === 0) {
                            $sale->delete(); // Cascades payments usually, but if not:
                        } else {
                            // Update Sale Total
                            $sale->total_amount = $sale->items()->sum('total_price');
                            $sale->save();

                            // Adjust Payments
                            if (isset($saleReductions[$saleId]) && $saleReductions[$saleId] > 0) {
                                $remainingReduction = $saleReductions[$saleId];
                                // Get payments (latest first) to deduct
                                $payments = $sale->payments()->orderBy('id', 'desc')->get();

                                foreach ($payments as $payment) {
                                    if ($remainingReduction <= 0)
                                        break;

                                    if ($payment->amount <= $remainingReduction) {
                                        $remainingReduction -= $payment->amount; // Consumed this payment
                                        $payment->delete();
                                    } else {
                                        $payment->amount -= $remainingReduction;
                                        $remainingReduction = 0;
                                        $payment->save();
                                    }
                                }
                            }
                        }
                    }
                }

                // 7. Cleanup/Update Parent Purchases
                foreach ($purchaseIds as $purchaseId) {
                    $purchase = \App\Models\Purchase::find($purchaseId);
                    if ($purchase) {
                        if ($purchase->items()->count() === 0) {
                            $purchase->delete();
                        } else {
                            $newTotal = $purchase->items()->sum('total_cost');
                            $purchase->total_amount = $newTotal;
                            $purchase->save();
                        }
                    }
                }

                $product->delete();
            });
        } finally {
            // ALways re-enable FK checks
            Schema::enableForeignKeyConstraints();
        }
    }
}
