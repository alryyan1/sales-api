<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared sale-creation logic (stock check, item creation, discount application)
 * used by both the POS sale endpoint and the scheduled-sale executor. Deliberately
 * excludes payments — callers that need payments (e.g. POS) create them separately
 * after this returns.
 */
class SaleCreationService
{
    /**
     * @param  array{client_id?: ?int, user_id: int, warehouse_id: ?int, shift_id: ?int, sale_date: mixed, source?: string, discount_amount?: float|null, discount_type?: string|null}  $header
     * @param  array<int, array{product_id: int, purchase_item_id?: ?int, quantity: int, unit_price: float}>  $items
     */
    public function createSaleWithItems(array $header, array $items): Sale
    {
        return DB::transaction(function () use ($header, $items) {
            $sale = Sale::create([
                'client_id' => $header['client_id'] ?? null,
                'user_id' => $header['user_id'],
                'warehouse_id' => $header['warehouse_id'] ?? null,
                'shift_id' => $header['shift_id'] ?? null,
                'sale_date' => $header['sale_date'],
                'source' => $header['source'] ?? 'pos',
            ]);

            $warehouseId = $sale->warehouse_id ?? 1;

            foreach ($items as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);

                $availableInWarehouse = $product->countStock($warehouseId);
                $currentQuantityInThisSale = $sale->items()->where('product_id', $product->id)->sum('quantity');
                $availableForThisAdd = $availableInWarehouse - $currentQuantityInThisSale;

                if ($availableForThisAdd < $itemData['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => ["Insufficient stock for '{$product->name}'. Available: {$availableForThisAdd}, Requested: {$itemData['quantity']}"],
                    ]);
                }

                $unitPrice = (float) $itemData['unit_price'];
                if ($unitPrice <= 0) {
                    $unitPrice = $product->last_sale_price_per_sellable_unit > 0
                        ? (float) $product->last_sale_price_per_sellable_unit
                        : 0;
                }

                $sale->items()->create([
                    'product_id' => $product->id,
                    'purchase_item_id' => $itemData['purchase_item_id'] ?? null,
                    'batch_number_sold' => null,
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $unitPrice,
                    'total_price' => $itemData['quantity'] * $unitPrice,
                    'cost_price_at_sale' => $this->resolveCostPrice($product),
                ]);

                $product->decrementWarehouseStock($warehouseId, $itemData['quantity']);
            }

            // Discount — same math as SaleController::updateDiscount(), persisted only as
            // an absolute amount (there is no discount_type column; the type is only used
            // to compute the amount here).
            if (! empty($header['discount_amount']) && $header['discount_amount'] > 0) {
                $subtotal = (float) $sale->items()->sum('total_price');
                $discountType = $header['discount_type'] ?? 'fixed';

                if ($discountType === 'percentage') {
                    if ($header['discount_amount'] > 100) {
                        throw ValidationException::withMessages([
                            'discount_amount' => ['Discount percentage cannot exceed 100%'],
                        ]);
                    }
                    $discountValue = $subtotal * ((float) $header['discount_amount'] / 100);
                } else {
                    $discountValue = min((float) $header['discount_amount'], $subtotal);
                }

                $sale->update(['discount_amount' => round($discountValue, 2)]);
            }

            return $sale;
        });
    }

    private function resolveCostPrice(Product $product): float
    {
        $lastItem = PurchaseItem::where('purchase_items.product_id', $product->id)
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->orderBy('purchases.purchase_date', 'desc')
            ->orderBy('purchase_items.created_at', 'desc')
            ->select('purchase_items.*')
            ->first();

        if ($lastItem) {
            return (float) ($lastItem->cost_per_sellable_unit > 0
                ? $lastItem->cost_per_sellable_unit
                : ($lastItem->unit_cost ?? 0));
        }

        return (float) ($product->cost_price ?? 0);
    }
}
