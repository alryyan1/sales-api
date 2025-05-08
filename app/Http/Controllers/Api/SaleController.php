<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem; // Though items are created via relationship
use App\Models\Product;
use App\Models\PurchaseItem; // Needed for batch selection
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Resources\SaleResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    /**
     * Display a listing of the sales.
     */
    public function index(Request $request)
    {
        $query = Sale::with(['client:id,name', 'user:id,name']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhereHas('client', fn($clientQuery) => $clientQuery->where('name', 'like', "%{$search}%"));
            });
        }
        if ($status = $request->input('status')) {
            if (in_array($status, ['completed', 'pending', 'draft', 'cancelled'])) {
                $query->where('status', $status);
            }
        }
        if ($startDate = $request->input('start_date')) { $query->whereDate('sale_date', '>=', $startDate); }
        if ($endDate = $request->input('end_date')) { $query->whereDate('sale_date', '<=', $endDate); }

        $sales = $query->latest('sale_date')->latest('id')->paginate($request->input('per_page', 15));
        return SaleResource::collection($sales);
    }

    /**
     * Store a newly created sale in storage, deducting stock from specific batches (FIFO).
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'sale_date' => 'required|date_format:Y-m-d',
            'invoice_number' => 'nullable|string|max:255|unique:sales,invoice_number',
            'status' => ['required', Rule::in(['completed', 'pending', 'draft', 'cancelled'])],
            'paid_amount' => 'required|numeric|min:0|max:99999999.99',
            'notes' => 'nullable|string|max:65535',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0|max:99999999.99',
        ]);

        // --- Stock Pre-Check (Optional but recommended for better UX) ---
        $stockErrors = [];
        foreach ($validatedData['items'] as $index => $itemData) {
            $product = Product::find($itemData['product_id']); // Find the generic product
            if ($product) {
                // Sum of remaining_quantity from all batches for this product
                $totalAvailableStock = $product->purchaseItems()->sum('remaining_quantity');
                if ($totalAvailableStock < $itemData['quantity']) {
                    $stockErrors["items.{$index}.quantity"] = ["Insufficient total stock for product '{$product->name}'. Available: {$totalAvailableStock}, Requested: {$itemData['quantity']}."];
                }
            } else {
                 $stockErrors["items.{$index}.product_id"] = ["Product ID {$itemData['product_id']} not found."]; // Should be caught by 'exists' rule
            }
        }
        if (!empty($stockErrors)) {
            throw ValidationException::withMessages($stockErrors);
        }
        // --- End Stock Pre-Check ---

        try {
            $sale = DB::transaction(function () use ($validatedData, $request) {
                // 1. Create Sale Header
                $saleHeader = Sale::create([
                    'client_id' => $validatedData['client_id'],
                    'user_id' => $request->user()->id,
                    'sale_date' => $validatedData['sale_date'],
                    'invoice_number' => $validatedData['invoice_number'] ?? null,
                    'status' => $validatedData['status'],
                    'paid_amount' => $validatedData['paid_amount'],
                    'notes' => $validatedData['notes'] ?? null,
                    'total_amount' => 0, // Initialize
                ]);

                $newTotalSaleAmount = 0;

                // 2. Process Sale Items (FIFO logic)
                foreach ($validatedData['items'] as $itemData) {
                    $product = Product::findOrFail($itemData['product_id']);
                    $quantityToSellForThisItem = $itemData['quantity'];
                    $unitPrice = $itemData['unit_price'];
                    $quantityFulfilled = 0;

                    // Get available batches for this product, ordered by FIFO (e.g., oldest expiry or created_at)
                    // and lock them for update to prevent race conditions.
                    $availableBatches = PurchaseItem::where('product_id', $product->id)
                        ->where('remaining_quantity', '>', 0)
                        ->orderBy('expiry_date', 'asc') // Or purchase_date/created_at of purchase_item
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate() // IMPORTANT for concurrent sales
                        ->get();

                    // Double check total available stock for this item within transaction
                    $currentTotalStockForItem = $availableBatches->sum('remaining_quantity');
                    if ($currentTotalStockForItem < $quantityToSellForThisItem) {
                         throw ValidationException::withMessages([
                             'items' => ["Transaction Error: Insufficient stock for '{$product->name}'. Available: {$currentTotalStockForItem}, Requested: {$quantityToSellForThisItem}."]
                         ]);
                    }

                    foreach ($availableBatches as $batch) {
                        if ($quantityFulfilled >= $quantityToSellForThisItem) break;

                        $canSellFromBatch = min($quantityToSellForThisItem - $quantityFulfilled, $batch->remaining_quantity);

                        if ($canSellFromBatch > 0) {
                            // Create SaleItem linked to this specific PurchaseItem (batch)
                            $saleHeader->items()->create([
                                'product_id' => $product->id,
                                'purchase_item_id' => $batch->id, // Link to the batch
                                'batch_number_sold' => $batch->batch_number, // Store for easy display
                                'quantity' => $canSellFromBatch,
                                'unit_price' => $unitPrice,
                                'total_price' => $canSellFromBatch * $unitPrice,
                            ]);

                            // Decrement remaining_quantity of the batch
                            $batch->decrement('remaining_quantity', $canSellFromBatch);
                            // Product total stock will be updated by PurchaseItemObserver via this save.

                            $newTotalSaleAmount += $canSellFromBatch * $unitPrice;
                            $quantityFulfilled += $canSellFromBatch;
                        }
                    }

                    if ($quantityFulfilled < $quantityToSellForThisItem) {
                        // This should not be reached if pre-check and transactional check are correct
                        // but acts as a final safeguard within the loop.
                        throw new \Exception("Logical error during FIFO stock allocation for product '{$product->name}'. Could not fulfill requested quantity.");
                    }
                }

                // 3. Update Sale Header Total
                $saleHeader->total_amount = $newTotalSaleAmount;
                 // Ensure paid amount doesn't exceed new total (Zod refine should catch this on frontend)
                if ($saleHeader->paid_amount > $newTotalSaleAmount && $newTotalSaleAmount >=0) { // Check total is not negative
                    // Adjust paid amount or throw error based on business rule
                    // For now, let's assume paid_amount can't exceed total. Frontend should prevent this.
                     Log::warning("Sale ID {$saleHeader->id}: Paid amount {$saleHeader->paid_amount} exceeds calculated total {$newTotalSaleAmount}. This should be caught by frontend validation.");
                     // throw ValidationException::withMessages(['paid_amount' => ['Paid amount cannot exceed calculated total.']]);
                }
                $saleHeader->save();

                return $saleHeader;
            }); // End DB::transaction

            $sale->load(['client:id,name', 'user:id,name', 'items', 'items.product:id,name,sku', 'items.purchaseItemBatch:id,batch_number,unit_cost']);
            return response()->json(['sale' => new SaleResource($sale)], Response::HTTP_CREATED);

        } catch (ValidationException $e) {
            Log::warning("Sale creation validation failed: " . json_encode($e->errors()));
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            Log::error("Sale creation failed: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json(['message' => 'Failed to create sale. ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified sale.
     */
    public function show(Sale $sale)
    {
        $sale->load([
            'client:id,name,email',
            'user:id,name',
            'items',
            'items.product:id,name,sku',
            'items.purchaseItemBatch:id,batch_number,unit_cost,expiry_date' // Load batch info for each sale item
        ]);
        return response()->json(['sale' => new SaleResource($sale)]);
    }

    /**
     * Update the specified sale in storage.
     * SIMPLIFIED: Only allows updating header info, NOT items.
     * Full item update with stock reversal is very complex and often replaced by credit notes.
     */
    public function update(Request $request, Sale $sale)
    {
        // Prevent editing if sale is, for example, cancelled or too old
        // if ($sale->status === 'cancelled' || $sale->sale_date < Carbon::now()->subMonths(1)) {
        //     return response()->json(['message' => 'This sale cannot be updated.'], Response::HTTP_FORBIDDEN);
        // }

        $validatedData = $request->validate([
            'client_id' => 'sometimes|required|exists:clients,id',
            'sale_date' => 'sometimes|required|date_format:Y-m-d',
            'invoice_number' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('sales')->ignore($sale->id)],
            'status' => ['sometimes', 'required', Rule::in(['completed', 'pending', 'draft', 'cancelled'])],
            'paid_amount' => 'sometimes|required|numeric|min:0|max:99999999.99', // Validate against new total if items were editable
            'notes' => 'sometimes|nullable|string|max:65535',
            // 'items' validation would be here if item editing was allowed
        ]);

        // If items were editable, you'd need complex logic here to:
        // 1. Calculate current total_amount before item changes.
        // 2. Calculate stock changes (revert old items, apply new items).
        // 3. Update/delete/create items.
        // 4. Recalculate new total_amount.
        // 5. ALL WITHIN A TRANSACTION.

        // For this simplified version, we only update header fields.
        // The frontend form should also restrict item editing for completed/non-draft sales.
        if (isset($validatedData['paid_amount']) && $validatedData['paid_amount'] > $sale->total_amount) {
            // If items are not editable, total_amount doesn't change.
            // If total_amount could change, this check needs to be against the new total.
            throw ValidationException::withMessages(['paid_amount' => ['Paid amount cannot exceed the sale total.']]);
        }

        $sale->update($validatedData);

        $sale->load(['client:id,name', 'user:id,name', 'items', 'items.product:id,name,sku', 'items.purchaseItemBatch:id,batch_number,unit_cost']);
        return response()->json(['sale' => new SaleResource($sale->fresh())]);
    }


    /**
     * Remove the specified sale from storage.
     * Strongly discouraged. Implement stock reversal if allowed.
     */
    public function destroy(Sale $sale)
    {
        return response()->json(['message' => 'Deleting sales records is generally not allowed due to inventory and accounting implications. Consider cancelling the sale instead.'], Response::HTTP_FORBIDDEN);
        // If deletion with stock reversal is implemented, it would be similar to PurchaseController::destroy
        // but incrementing stock on PurchaseItem batches.
    }
}


// Key Changes and Considerations for Batch Tracking in store():
//     Stock Pre-Check (Optional): Added an initial check before the transaction to see if the total stock for each product is sufficient. This provides faster feedback to the user.
//     Transaction: The core logic is wrapped in DB::transaction.
//     FIFO Batch Selection:
//     Inside the loop for each item in the sale request:
//     It fetches PurchaseItem records (batches) for the given product_id that have remaining_quantity > 0.
//     It orders these batches by expiry_date (oldest first) and then created_at (oldest purchase first) to achieve FIFO.
//     It uses lockForUpdate() on these batches to prevent race conditions if multiple sales are processed concurrently for the same product.
//     It iterates through these fetched batches, fulfilling the requested quantity for the sale item from the oldest batches first.
//     Stock Check Inside Transaction: A critical stock check is performed again inside the transaction for the sum of available batches for that specific product to ensure atomicity.
//     Create SaleItem: For each portion taken from a batch, a new SaleItem record is created.
//     It now stores purchase_item_id to link back to the specific batch.
//     It stores batch_number_sold (copied from the PurchaseItem batch) for easier display on invoices/reports.
//     Decrement PurchaseItem.remaining_quantity: Instead of decrementing Product.stock_quantity directly, we now decrement remaining_quantity on the specific PurchaseItem (batch) that the stock was taken from.
//     PurchaseItemObserver: This observer (created earlier) should automatically listen for saves/updates on PurchaseItem and recalculate and save the total stock_quantity on the parent Product model. This keeps the products.stock_quantity as an accurate aggregate.
//     Error Handling: If stock is insufficient at any point within the transaction (either total for the product or from available batches), a ValidationException is thrown, which rolls back the entire transaction.
//     update() Method (Simplified):
//     The provided update() method only allows updating header information of the sale (like status, notes, client, date).
//     It explicitly DOES NOT handle item modifications (add/edit/delete items within an existing sale). This is because the logic for reversing stock from original batches and reapplying stock for new/modified items, while ensuring stock availability, is extremely complex and prone to errors.
//     For a real-world system, if item modification on completed sales is required, it's usually handled by:
//     A "Return" process (creates a new return record, increments stock).
//     Issuing a "Credit Note".
//     Then creating a new, corrected Sale.
//     Or, only allowing item edits if the sale is in a "Draft" status.
//     destroy(): Remains a forbidden action.
//     This SaleController with FIFO batch logic is significantly more complex but provides accurate stock depletion from specific batches. The frontend will need to be adapted if you want users to manually select which batch to sell from; otherwise, this FIFO logic handles it automatically on the backend.