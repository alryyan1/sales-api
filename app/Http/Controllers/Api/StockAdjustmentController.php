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
    public function store(Request $request)
    {
        // Authorization
        if ($request->user()->cannot('adjust-stock')) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'purchase_item_id' => 'nullable|integer|exists:purchase_items,id', // Optional: ID of the batch to adjust
            'quantity_change' => 'required|integer|not_in:0', // Must be non-zero integer (+ or -)
            'reason' => 'required|string|max:255', // Make reason mandatory
            'notes' => 'nullable|string|max:65535',
        ]);

        $productId = $validated['product_id'];
        $batchId = $validated['purchase_item_id'] ?? null;
        $quantityChange = $validated['quantity_change'];
        $reason = $validated['reason'];
        $notes = $validated['notes'] ?? null;
        $userId = $request->user()->id;

        try {
            $result = DB::transaction(function () use ($productId, $batchId, $quantityChange, $reason, $notes, $userId) {
                $product = Product::lockForUpdate()->findOrFail($productId);
                $purchaseItem = null;
                $quantityBefore = $product->stock_quantity; // Assume total stock before change

                // Adjusting specific batch OR general product stock
                if ($batchId) {
                    $purchaseItem = PurchaseItem::lockForUpdate()->findOrFail($batchId);
                    // Verify batch belongs to the product
                    if ($purchaseItem->product_id !== $product->id) {
                         throw ValidationException::withMessages(['purchase_item_id' => 'Selected batch does not belong to the selected product.']);
                    }
                    $quantityBefore = $purchaseItem->remaining_quantity; // Batch quantity before
                    $newBatchQuantity = $quantityBefore + $quantityChange;

                    if ($newBatchQuantity < 0) {
                        throw ValidationException::withMessages(['quantity_change' => "Adjustment results in negative stock for batch #{$purchaseItem->batch_number}. Available: {$quantityBefore}."]);
                    }
                    // Update batch remaining quantity
                    $purchaseItem->remaining_quantity = $newBatchQuantity;
                    $purchaseItem->save(); // The Observer will update Product->stock_quantity

                    $quantityAfter = $newBatchQuantity;

                } else {
                    // Adjusting general product stock (use observer if possible, otherwise direct update)
                    $newProductQuantity = $quantityBefore + $quantityChange;
                    if ($newProductQuantity < 0) {
                        throw ValidationException::withMessages(['quantity_change' => "Adjustment results in negative total stock for product '{$product->name}'. Available: {$quantityBefore}."]);
                    }

                    // If using PurchaseItemObserver, this direct update is problematic as it bypasses batch logic.
                    // It's better to REQUIRE batch selection for adjustments if using batch tracking,
                    // or create a "dummy" PurchaseItem batch for adjustments if absolutely needed.
                    // For now, let's assume direct update if no batch ID is given, BUT WARN about inconsistency potential.
                    Log::warning("Direct stock adjustment on Product ID {$productId} without batch specification. Batch tracking might become inconsistent.");
                    $product->stock_quantity = $newProductQuantity;
                    $product->save();

                    $quantityAfter = $newProductQuantity;
                }

                // Log the adjustment
                $adjustment = StockAdjustment::create([
                    'product_id' => $productId,
                    'purchase_item_id' => $batchId, // Null if adjusting general product stock
                    'user_id' => $userId,
                    'quantity_change' => $quantityChange,
                    'quantity_before' => $quantityBefore, // Record quantity BEFORE change
                    'quantity_after' => $quantityAfter,   // Record quantity AFTER change
                    'reason' => $reason,
                    'notes' => $notes,
                ]);

                return ['adjustment' => $adjustment, 'product' => $product->fresh()]; // Return adjustment and updated product

            }); // End Transaction

            return response()->json([
                'message' => 'Stock adjusted successfully.',
                'adjustment' => $result['adjustment'],
                'product' => new ProductResource($result['product']->load('purchaseItems')), // Return updated product potentially with batches
            ], Response::HTTP_OK);

        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], Response::HTTP_UNPROCESSABLE_ENTITY); // 422
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

         $query = StockAdjustment::with(['user:id,name', 'product:id,name,sku', 'purchaseItemBatch:id,batch_number']); // Eager load

         // Add Filtering (by date, product, user, reason) if needed
         // ...

         $adjustments = $query->latest()->paginate($request->input('per_page', 20));

         // Create StockAdjustmentResource if needed for formatting
         // return StockAdjustmentResource::collection($adjustments);
         return response()->json($adjustments); // Return raw paginated data for now
     }
}