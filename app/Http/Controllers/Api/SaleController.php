<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Resources\SaleResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException; // For throwing validation exceptions manually

class SaleController extends Controller
{
    /**
     * Display a listing of the sales.
     */
    public function index(Request $request)
    {
        // Eager load basic client/user info for the list
        $query = Sale::with(['client:id,name', 'user:id,name']);

        // --- Filtering/Searching Examples ---
        if ($search = $request->input('search')) {
             $query->where(function($q) use ($search) {
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
         // --- End Filtering ---


        $sales = $query->latest()->paginate($request->input('per_page', 15));

        return SaleResource::collection($sales);
    }

    /**
     * Store a newly created sale in storage.
     * Handles creating sale header, items, and decrementing stock.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'sale_date' => 'required|date_format:Y-m-d',
            'invoice_number' => 'nullable|string|max:255|unique:sales,invoice_number',
            'status' => ['required', Rule::in(['completed', 'pending', 'draft', 'cancelled'])],
            'paid_amount' => 'required|numeric|min:0|max:99999999.99', // Validate paid amount
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0|max:99999999.99',
        ]);

        // --- Additional Stock Check BEFORE Transaction (Optional but good UX) ---
        // This pre-check can give immediate feedback without starting a transaction if stock is insufficient.
        $stockErrors = [];
        foreach ($validatedData['items'] as $index => $itemData) {
            $product = Product::find($itemData['product_id']);
            if ($product && $product->stock_quantity < $itemData['quantity']) {
                 $stockErrors["items.{$index}.quantity"] = ["Insufficient stock for product '{$product->name}'. Available: {$product->stock_quantity}."];
            }
        }
        if (!empty($stockErrors)) {
            throw ValidationException::withMessages($stockErrors); // Throw 422 error immediately
        }
        // --- End Stock Pre-Check ---


        try {
            $sale = DB::transaction(function () use ($validatedData, $request) {

                // 1. Create the Sale header record
                $sale = Sale::create([
                    'client_id' => $validatedData['client_id'],
                    'user_id' => $request->user()->id, // Authenticated user
                    'sale_date' => $validatedData['sale_date'],
                    'invoice_number' => $validatedData['invoice_number'] ?? null,
                    'status' => $validatedData['status'],
                    'paid_amount' => $validatedData['paid_amount'],
                    'notes' => $validatedData['notes'] ?? null,
                    'total_amount' => 0, // Initialize total amount
                ]);

                $totalAmount = 0;

                // 2. Loop through items, create SaleItem, update stock
                foreach ($validatedData['items'] as $itemData) {
                    // Use lockForUpdate to prevent race conditions on stock quantity
                    $product = Product::lockForUpdate()->find($itemData['product_id']);

                    if (!$product) { // Should not happen due to validation, but check anyway
                        throw new \Exception("Product with ID {$itemData['product_id']} not found.");
                    }

                    $quantity = $itemData['quantity'];
                    $unitPrice = $itemData['unit_price'];

                    // --- CRITICAL: Check stock again inside transaction ---
                    if ($product->stock_quantity < $quantity) {
                        // Throw exception to rollback transaction
                        throw ValidationException::withMessages([
                            'items' // Associate error with items array generally or specific item index
                             => ["Insufficient stock for product '{$product->name}' during transaction. Available: {$product->stock_quantity}."]
                        ]);
                    }

                    $totalPrice = $quantity * $unitPrice;
                    $totalAmount += $totalPrice;

                    // Create SaleItem
                    $sale->items()->create([
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'total_price' => $totalPrice,
                    ]);

                    // --- Decrement Product Stock ---
                    $product->decrement('stock_quantity', $quantity);
                     // Log::info("Stock decremented for product {$product->id}. Sold: {$quantity}. New Stock: {$product->fresh()->stock_quantity}");
                }

                // 3. Update the total amount on the Sale header
                $sale->total_amount = $totalAmount;
                // Validate paid amount against total (optional, depends on business logic)
                // if ($sale->paid_amount > $totalAmount) {
                //     throw ValidationException::withMessages(['paid_amount' => ['Paid amount cannot exceed total amount.']]);
                // }
                $sale->save();

                return $sale; // Return created sale from transaction
            }); // End DB::transaction

            // Eager load relations for the response
            $sale->load(['client:id,name', 'user:id,name', 'items', 'items.product:id,name,sku']);

            return response()->json(['sale' => new SaleResource($sale)], Response::HTTP_CREATED);

        } catch (ValidationException $e) {
             // Catch specific validation exceptions (like stock check failure)
             Log::warning("Sale creation validation failed: " . json_encode($e->errors()));
             return response()->json([
                 'message' => $e->getMessage(), // General validation message
                 'errors' => $e->errors()       // Specific field errors
             ], Response::HTTP_UNPROCESSABLE_ENTITY); // 422 status
        } catch (\Throwable $e) { // Catch any other exception
            Log::error("Sale creation failed: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json(['message' => 'Failed to create sale. ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified sale.
     */
    public function show(Sale $sale) // Route model binding
    {
        // Eager load relationships for detail view
        $sale->load(['client:id,name,email,phone', 'user:id,name', 'items', 'items.product:id,name,sku,sale_price']); // Load more product details maybe

        return response()->json(['sale' => new SaleResource($sale)]);
    }


    // NOTE: update() is typically excluded for Sales.

    /**
     * Remove the specified sale from storage.
     * CAUTION: Decide if deletion is allowed and how to handle stock reversal.
     */
    public function destroy(Sale $sale)
    {
        // Option 1: Disallow deletion (Recommended)
        return response()->json(['message' => 'Deleting sales records is not allowed.'], Response::HTTP_FORBIDDEN);

        // Option 2: Implement deletion WITH stock reversal (Complex & needs transaction)
        /*
        try {
            DB::transaction(function () use ($sale) {
                // Increment stock for each item BEFORE deleting items/sale
                foreach ($sale->items as $item) {
                    $product = $item->product;
                    if ($product) {
                        $product->increment('stock_quantity', $item->quantity);
                        Log::info("Stock reversed for product {$product->id} due to sale deletion. Added back: {$item->quantity}. New Stock: {$product->fresh()->stock_quantity}");
                    }
                }
                // Items will be deleted by cascade
                $sale->delete();
            });
            return response()->json(['message' => 'Sale deleted successfully.'], Response::HTTP_OK);
        } catch (\Throwable $e) {
             Log::error("Sale deletion failed: " . $e->getMessage() . "\n" . $e->getTraceAsString());
             return response()->json(['message' => 'Failed to delete sale. ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        */
    }
}