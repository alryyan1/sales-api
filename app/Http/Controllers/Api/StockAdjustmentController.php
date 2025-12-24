<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\StockAdjustment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Response;
// Add Resource if needed for listing adjustments
// use App\Http\Resources\StockAdjustmentResource;

class StockAdjustmentController extends Controller
{
    /**
     * Store a new stock adjustment.
     */
    /**
     * Store a new stock adjustment.
     */
    public function store(Request $request)
    {
        // Authorization
        if ($request->user()->cannot('adjust-stock')) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'product_id' => 'required|integer|exists:products,id',
            'purchase_item_id' => 'nullable|integer|exists:purchase_items,id', // Optional: ID of the batch to adjust
            'quantity_change' => 'required|integer|not_in:0', // Must be non-zero integer (+ or -)
            'reason' => 'required|string|max:255', // Make reason mandatory
            'notes' => 'nullable|string|max:65535',
        ]);

        $warehouseId = $validated['warehouse_id'];
        $productId = $validated['product_id'];
        $batchId = $validated['purchase_item_id'] ?? null;
        $quantityChange = $validated['quantity_change'];
        $reason = $validated['reason'];
        $notes = $validated['notes'] ?? null;
        $userId = $request->user()->id;

        try {
            $result = DB::transaction(function () use ($warehouseId, $productId, $batchId, $quantityChange, $reason, $notes, $userId) {
                $product = Product::lockForUpdate()->findOrFail($productId);
                $purchaseItem = null;
                $quantityBefore = $product->stock_quantity; // Global stock before change (for historical record)
                // However, for warehouse specific adjustments, we might want to record warehouse stock before?
                // For now, let's keep standard fields but logic is warehouse specific.

                // 1. Validate Warehouse Connection
                // Ensure product is attached to this warehouse
                if (!$product->warehouses()->where('warehouse_id', $warehouseId)->exists()) {
                    // Auto-attach if missing? Or error? Standard flow implies it should exist if we are adjusting it.
                    // Let's attach if missing to be safe, starting with 0.
                    $product->warehouses()->attach($warehouseId, ['quantity' => 0]);
                }

                // Get current warehouse quantity
                $currentWarehouseStock = $product->warehouses()->where('warehouse_id', $warehouseId)->first()->pivot->quantity;
                $newWarehouseStock = $currentWarehouseStock + $quantityChange;

                if ($newWarehouseStock < 0) {
                    throw ValidationException::withMessages(['quantity_change' => "Adjustment results in negative stock for this warehouse. Available: {$currentWarehouseStock}."]);
                }

                // 2. Adjust Specific Batch (if provided)
                if ($batchId) {
                    $purchaseItem = PurchaseItem::lockForUpdate()->findOrFail($batchId);

                    // Verify batch matches product
                    if ($purchaseItem->product_id !== $product->id) {
                        throw ValidationException::withMessages(['purchase_item_id' => 'Selected batch does not belong to the selected product.']);
                    }

                    // Verify batch belongs to the warehouse (if we added warehouse_id to purchase_items/purchases)
                    // Currently Purchase has warehouse_id. The batch belongs to a Purchase.
                    if ($purchaseItem->purchase && $purchaseItem->purchase->warehouse_id != $warehouseId) {
                        throw ValidationException::withMessages(['purchase_item_id' => 'Selected batch belongs to a different warehouse.']);
                    }

                    $batchQuantityBefore = $purchaseItem->remaining_quantity;
                    $newBatchQuantity = $batchQuantityBefore + $quantityChange;

                    if ($newBatchQuantity < 0) {
                        throw ValidationException::withMessages(['quantity_change' => "Adjustment results in negative stock for batch #{$purchaseItem->batch_number}. Available: {$batchQuantityBefore}."]);
                    }

                    // Update batch
                    $purchaseItem->remaining_quantity = $newBatchQuantity;
                    $purchaseItem->save(); // Observer updates global Product stock

                    // We also need to MANUALLY update the warehouse pivot, because the Observer might only update Global stock.
                    // If PurchaseItem matches Warehouse -> Product Stock (Global) is sum of batches?
                    // Usually: Global Stock = Sum of all Warehouse Pivot Stocks.
                    // And Warehouse Pivot Stock = Sum of Batches in that Warehouse.

                    // So we update the pivot:
                    $product->warehouses()->updateExistingPivot($warehouseId, ['quantity' => $newWarehouseStock]);
                } else {
                    // 3. General Warehouse Adjustment (No specific batch)
                    // This is risky if strict batch tracking is on, but allowed for "found" items or non-batch products.

                    // Update Warehouse Pivot
                    $product->warehouses()->updateExistingPivot($warehouseId, ['quantity' => $newWarehouseStock]);

                    // Start of Global update (direct or via observer?)
                    // If we update pivot, we should also update global count to keep in sync.
                    $product->stock_quantity += $quantityChange;
                    $product->save();
                }

                // 4. Create Adjustment Record
                $adjustment = StockAdjustment::create([
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'purchase_item_id' => $batchId,
                    'user_id' => $userId,
                    'quantity_change' => $quantityChange,
                    'quantity_before' => $currentWarehouseStock, // Recording WAREHOUSE specific before/after makes more sense here
                    'quantity_after' => $newWarehouseStock,
                    'reason' => $reason,
                    'notes' => $notes,
                ]);

                return ['adjustment' => $adjustment, 'product' => $product->fresh()];
            }); // End Transaction

            return response()->json([
                'message' => 'Stock adjusted successfully.',
                'adjustment' => $result['adjustment'],
                'product' => new ProductResource($result['product']->load('purchaseItems')),
            ], Response::HTTP_OK);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            Log::error("Stock adjustment failed: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json(['message' => 'Failed to adjust stock. ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display a listing of stock adjustments (History).
     */
    public function index(Request $request)
    {
        // Authorization check
        if ($request->user()->cannot('view-stock-adjustments')) { // Define this permission
            abort(403);
        }

        $query = StockAdjustment::with(['user:id,name', 'product:id,name,sku', 'purchaseItemBatch:id,batch_number', 'warehouse:id,name']); // Eager load

        // Add Filtering
        if ($warehouseId = $request->input('warehouse_id')) {
            $query->where('warehouse_id', $warehouseId);
        }

        if ($productId = $request->input('product_id')) {
            $query->where('product_id', $productId);
        }

        if ($userId = $request->input('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($startDate = $request->input('start_date')) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        if ($endDate = $request->input('end_date')) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $adjustments = $query->latest()->paginate($request->input('per_page', 20));

        // Create StockAdjustmentResource if needed for formatting
        // return StockAdjustmentResource::collection($adjustments);
        return response()->json($adjustments); // Return raw paginated data for now
    }
}
