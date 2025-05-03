<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Resources\PurchaseResource;
use Illuminate\Support\Facades\DB; // Import DB facade for transactions
use Illuminate\Support\Facades\Log; // Import Log facade
use Illuminate\Validation\Rule; // For Enum validation

class PurchaseController extends Controller
{
    /**
     * Display a listing of the purchases.
     */
    public function index(Request $request)
    {
        $query = Purchase::with(['supplier:id,name', 'user:id,name']); // Eager load supplier/user name+id

        // Example Search (on reference number or supplier name)
        if ($search = $request->input('search')) {
            $query->where(function($q) use ($search) {
                $q->where('reference_number', 'like', "%{$search}%")
                  ->orWhereHas('supplier', function ($supplierQuery) use ($search) {
                      $supplierQuery->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Example Filtering by status
        if ($status = $request->input('status')) {
            // Add validation for allowed statuses if needed
             if (in_array($status, ['received', 'pending', 'ordered'])) {
                 $query->where('status', $status);
             }
        }

         // Example Date Range Filtering
         if ($startDate = $request->input('start_date')) {
            $query->whereDate('purchase_date', '>=', $startDate);
         }
         if ($endDate = $request->input('end_date')) {
             $query->whereDate('purchase_date', '<=', $endDate);
         }


        $purchases = $query->latest()->paginate($request->input('per_page', 15));

        return $purchases;
    }

    /**
     * Store a newly created purchase in storage.
     * Handles creating purchase header, items, and updating stock.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'purchase_date' => 'required|date_format:Y-m-d',
            'reference_number' => 'nullable|string|max:255|unique:purchases,reference_number',
            'status' => ['required', Rule::in(['received', 'pending', 'ordered'])],
            'notes' => 'nullable|string',
            // Validate the items array
            'items' => 'required|array|min:1', // Must have at least one item
            // Validate each item within the array
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1', // Quantity must be at least 1
            'items.*.unit_cost' => 'required|numeric|min:0|max:99999999.99', // Validate unit cost
        ]);

        try {
            $purchase = DB::transaction(function () use ($validatedData, $request) {

                // 1. Create the Purchase header record
                $purchase = Purchase::create([
                    'supplier_id' => $validatedData['supplier_id'],
                    'user_id' => $request->user()->id, // Get authenticated user ID
                    'purchase_date' => $validatedData['purchase_date'],
                    'reference_number' => $validatedData['reference_number'] ?? null,
                    'status' => $validatedData['status'],
                    'notes' => $validatedData['notes'] ?? null,
                    'total_amount' => 0, // Initialize total amount
                ]);

                $totalAmount = 0;

                // 2. Loop through items, create PurchaseItem, update stock
                foreach ($validatedData['items'] as $itemData) {
                    $product = Product::find($itemData['product_id']); // Find the product
                     if (!$product) {
                        // This should ideally not happen due to 'exists' validation, but double-check
                         throw new \Exception("Product with ID {$itemData['product_id']} not found during transaction.");
                    }

                    $quantity = $itemData['quantity'];
                    $unitCost = $itemData['unit_cost'];
                    $totalCost = $quantity * $unitCost;
                    $totalAmount += $totalCost;

                    // Create PurchaseItem
                    $purchase->items()->create([ // Use relationship to set purchase_id automatically
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                        'unit_cost' => $unitCost,
                        'total_cost' => $totalCost,
                    ]);

                    // --- Update Product Stock ---
                    // Use increment for atomicity and better performance
                    $product->increment('stock_quantity', $quantity);
                     // Log::info("Stock updated for product {$product->id}. Added: {$quantity}. New Stock: {$product->fresh()->stock_quantity}");

                }

                // 3. Update the total amount on the Purchase header
                $purchase->total_amount = $totalAmount;
                $purchase->save();

                return $purchase; // Return the created purchase from the transaction closure
            }); // End DB::transaction

            // Eager load relations needed for the resource before returning
            $purchase->load(['supplier:id,name', 'user:id,name', 'items', 'items.product:id,name,sku']);

            return response()->json(['purchase' => new PurchaseResource($purchase)], Response::HTTP_CREATED);

        } catch (\Throwable $e) { // Catch any exception during the transaction
            Log::error("Purchase creation failed: " . $e->getMessage() . "\n" . $e->getTraceAsString()); // Log detailed error
            return response()->json(['message' => 'Failed to create purchase. ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified purchase.
     */
    public function show(Purchase $purchase) // Route model binding
    {
        // Eager load relationships needed for the detailed view
        $purchase->load(['supplier:id,name,email,phone', 'user:id,name', 'items', 'items.product:id,name,sku']);

        return response()->json(['purchase' => new PurchaseResource($purchase)]);
    }


    // NOTE: update() is typically excluded for Purchases as defined in routes.
    // If needed, implement carefully considering stock implications.

    /**
     * Remove the specified purchase from storage.
     * CAUTION: Decide if deletion is allowed and how to handle stock reversal.
     */
    public function destroy(Purchase $purchase)
    {
        // Option 1: Disallow deletion (Recommended for financial records)
         return response()->json(['message' => 'Deleting purchases is not allowed.'], Response::HTTP_FORBIDDEN); // 403 Forbidden

        // Option 2: Implement deletion WITH stock reversal (Complex & needs transaction)
        /*
        try {
            DB::transaction(function () use ($purchase) {
                // Decrement stock for each item BEFORE deleting items/purchase
                foreach ($purchase->items as $item) {
                    $product = $item->product;
                    if ($product) {
                        // Ensure stock doesn't go negative if possible, or handle error
                        $newStock = $product->stock_quantity - $item->quantity;
                        if ($newStock < 0) {
                             throw new \Exception("Cannot reverse stock for product ID {$product->id}, would result in negative stock.");
                        }
                        $product->decrement('stock_quantity', $item->quantity);
                        Log::info("Stock reversed for product {$product->id}. Removed: {$item->quantity}. New Stock: {$product->fresh()->stock_quantity}");
                    }
                }
                // Items will be deleted by cascade (defined in migration) when purchase is deleted
                $purchase->delete();
            });

            return response()->json(['message' => 'Purchase deleted successfully.'], Response::HTTP_OK);

        } catch (\Throwable $e) {
            Log::error("Purchase deletion failed: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json(['message' => 'Failed to delete purchase. ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        */
    }
}