<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\PurchaseItem; // Ensure this is used
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Resources\PurchaseResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;


class PurchaseController extends Controller
{
    /**
     * Display a listing of the purchases.
     */
    public function index(Request $request)
    {
        $query = Purchase::with(['supplier:id,name', 'user:id,name']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('reference_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', function ($supplierQuery) use ($search) {
                        $supplierQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }
        if ($status = $request->input('status')) {
            if (in_array($status, ['received', 'pending', 'ordered'])) {
                $query->where('status', $status);
            }
        }
        if ($startDate = $request->input('start_date')) {
            $query->whereDate('purchase_date', '>=', $startDate);
        }
        if ($endDate = $request->input('end_date')) {
            $query->whereDate('purchase_date', '<=', $endDate);
        }

        $purchases = $query->latest('purchase_date')->latest('id')->paginate($request->input('per_page', 15));
        return PurchaseResource::collection($purchases);
    }

    /**
     * Store a newly created purchase in storage.
     * Handles creating purchase header, items, setting remaining_quantity, and updating stock via observer.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'purchase_date' => 'required|date_format:Y-m-d',
            'reference_number' => 'nullable|string|max:255|unique:purchases,reference_number',
            'status' => ['required', Rule::in(['received', 'pending', 'ordered'])],
            'notes' => 'nullable|string|max:65535',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.batch_number' => 'nullable|string|max:100', // Max length for batch number
            'items.*.quantity' => 'required|integer|min:1', // Quantity of stocking units (e.g., boxes)
            'items.*.unit_cost' => 'required|numeric|min:0',   // Cost per stocking unit
            'items.*.sale_price' => 'nullable|numeric|min:0', // Intended sale price PER SELLABLE UNIT
            'items.*.expiry_date' => 'nullable|date_format:Y-m-d|after_or_equal:purchase_date', // Expiry date after purchase date
        ]);

        try {
            $purchase = DB::transaction(function () use ($validatedData, $request) {
                // 1. Create the Purchase header record
                $purchaseHeaderData = [
                    'supplier_id' => $validatedData['supplier_id'],
                    'user_id' => $request->user()->id,
                    'purchase_date' => $validatedData['purchase_date'],
                    'reference_number' => $validatedData['reference_number'] ?? null,
                    'status' => $validatedData['status'],
                    'notes' => $validatedData['notes'] ?? null,
                    'total_amount' => 0, // Initialize total amount
                ];
                $purchase = Purchase::create($purchaseHeaderData);

                $calculatedTotalAmount = 0;

                // 2. Loop through items, create PurchaseItem, and set remaining_quantity
                foreach ($validatedData['items'] as $itemData) {
                    $product = Product::findOrFail($itemData['product_id']); // Load the product
                    $unitsPerStockingUnit = $product->units_per_stocking_unit ?: 1;

                    $quantityInStockingUnits = $itemData['quantity'];
                    $unitCostPerStockingUnit = $itemData['unit_cost'];
                    $totalCostForStockingUnits = $quantityInStockingUnits * $unitCostPerStockingUnit;

                    // Calculate values in sellable units
                    $totalSellableUnitsPurchased = $quantityInStockingUnits * $unitsPerStockingUnit;
                    $costPerSellableUnit = ($unitsPerStockingUnit > 0) ? ($unitCostPerStockingUnit / $unitsPerStockingUnit) : 0;

                    $calculatedTotalAmount += $totalCostForStockingUnits; // Overall purchase total

                    $purchase->items()->create([
                        'product_id' => $product->id,
                        'batch_number' => $itemData['batch_number'] ?? null,
                        'quantity' => $quantityInStockingUnits,          // Store original purchased qty in stocking units
                        'remaining_quantity' => $totalSellableUnitsPurchased, // Store remaining in SELLABLE units
                        'unit_cost' => $unitCostPerStockingUnit,        // Store cost per STOCKING unit
                        'cost_per_sellable_unit' => $costPerSellableUnit, // Store cost per SELLABLE unit
                        'total_cost' => $totalCostForStockingUnits,     // Total cost for this line
                        'sale_price' => $itemData['sale_price'] ?? null, // Intended sale price PER SELLABLE UNIT
                        'expiry_date' => $itemData['expiry_date'] ?? null,
                    ]);
                    // Product.stock_quantity (total sellable units) is updated by PurchaseItemObserver
                }

                // 3. Update the total amount on the Purchase header
                $purchase->total_amount = $calculatedTotalAmount;
                $purchase->save();

                return $purchase;
            }); // End DB::transaction

            // Eager load relations for the response
            $purchase->load(['supplier:id,name', 'user:id,name', 'items', 'items.product:id,name,sku']);

            return response()->json(['purchase' => new PurchaseResource($purchase)], Response::HTTP_CREATED);
        } catch (ValidationException $e) {
            Log::warning("Purchase creation validation failed: " . json_encode($e->errors()));
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            Log::error("Purchase creation critical error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json(['message' => 'Failed to create purchase. ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified purchase.
     */
    public function show(Purchase $purchase)
    {
        // Eager load relationships for the detailed view, including batch info on items
        $purchase->load([
            'supplier:id,name,email,phone',
            'user:id,name',
            'items',
            'items.product' // Basic product info for each item
        ]);
        return response()->json(['purchase' => new PurchaseResource($purchase)]);
    }

    /**
     * Update the specified purchase in storage.
     * Typically, purchases are not updated once finalized due to stock and accounting implications.
     * If updates are allowed, they must handle stock reversal and re-application carefully.
     * This example only allows updating non-item related fields like notes or status if it doesn't affect stock.
     */
    public function update(Request $request, Purchase $purchase)
    {
        // Only allow updating certain fields for a purchase, e.g., notes, status (if it doesn't trigger stock changes)
        // Modifying items, supplier, or date after creation can be problematic.
        $validatedData = $request->validate([
            'reference_number' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('purchases')->ignore($purchase->id)],
            'status' => ['sometimes', 'required', Rule::in(['received', 'pending', 'ordered'])],
            'notes' => 'sometimes|nullable|string|max:65535',
            // DO NOT allow updating 'items' array here without extremely complex logic
            // for stock reversal and re-application.
        ]);

        // Business logic: What happens if status changes from 'pending' to 'received'?
        // If stock was not added on 'pending', it should be added now.
        // This simplified update does not handle such stock logic changes for status updates.

        $purchase->update($validatedData);

        $purchase->load(['supplier:id,name', 'user:id,name', 'items', 'items.product:id,name,sku']);
        return response()->json(['purchase' => new PurchaseResource($purchase->fresh())]);
    }

    /**
     * Remove the specified purchase from storage.
     * CAUTION: This is highly discouraged for completed purchases due to stock and accounting.
     * If implemented, stock quantities for all items in the purchase MUST be reversed.
     */
    public function destroy(Purchase $purchase)
    {
        // Option 1: Disallow (Recommended)
        return response()->json(['message' => 'Deleting purchase records is generally not allowed. Consider a cancellation status instead.'], Response::HTTP_FORBIDDEN);

        // Option 2: Implement deletion WITH stock reversal and item deletion (Complex)
        /*
        try {
            DB::transaction(function () use ($purchase) {
                // Before deleting the purchase items (which will happen by cascade or manually),
                // reverse the stock that was added by these items.
                foreach ($purchase->items as $item) {
                    $product = $item->product; // Assumes product relationship exists
                    if ($product) {
                        // Ensure stock doesn't go negative, though for purchases it's less likely
                        $product->decrement('stock_quantity', $item->quantity);
                         Log::info("Stock reversed for product {$product->id} due to purchase deletion. Removed: {$item->quantity}. Purchase ID: {$purchase->id}");
                    }
                    // PurchaseItemObserver would also trigger on item deletion to update product stock.
                    // If not using observer, product stock update must be explicit here.
                }
                // Items are deleted by cascade constraint defined in migration
                // OR $purchase->items()->delete(); (if no cascade)
                $purchase->delete();
            });
            return response()->json(['message' => 'Purchase deleted successfully and stock reversed.'], Response::HTTP_OK);
        } catch (\Throwable $e) {
            Log::error("Purchase deletion critical error for ID {$purchase->id}: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json(['message' => 'Failed to delete purchase. ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        */
    }
}

// Key Changes and Considerations:
// store() Method:
// Validation: Includes items.*.batch_number, items.*.sale_price (intended sale price for this batch), and items.*.expiry_date.
// Item Creation: When creating PurchaseItem records, it now includes:
// batch_number: From the request or generated if necessary (though usually user-supplied or derived).
// remaining_quantity: Set to the initial quantity purchased.
// sale_price: The intended sale price for items from this specific batch.
// expiry_date.
// Stock Update: The direct $product->increment('stock_quantity', $quantity); line is still present. However, if you have correctly implemented the PurchaseItemObserver to update Product->stock_quantity based on the sum of remaining_quantity of its purchase_items, this direct increment in the controller becomes redundant and potentially causes double counting.
// If PurchaseItemObserver is active and correct: You can remove $product->increment('stock_quantity', $quantity); from this controller. The observer will handle the total product stock update when PurchaseItem is created/saved.
// If not using an observer for aggregate stock: Keep the $product->increment() line, but understand that Product.stock_quantity is a direct sum and PurchaseItem.remaining_quantity is for batch-level tracking.
// update() Method (Simplified):
// The example provided only allows updating non-item related header fields like notes or status.
// It explicitly DOES NOT handle modifications to the items array (adding, removing, or changing quantities/costs of items within an existing purchase). Modifying items in a finalized purchase is complex because it requires:
// Reversing the original stock additions for changed/removed items.
// Applying new stock additions for new/modified items.
// All within a transaction.
// If item updates are truly needed for purchases, this method would need to be significantly expanded with logic similar to the (very complex) SaleController@update we discussed for sales, but for incrementing stock.
// destroy() Method: Remains strongly discouraged. The example of stock reversal logic is provided but commented out.
// This complete PurchaseController now accommodates the batch number and other new fields for purchase items. Ensure your PurchaseItemObserver is correctly implemented if you want the aggregate Product.stock_quantity to be automatically maintained based on batch remaining_quantity.