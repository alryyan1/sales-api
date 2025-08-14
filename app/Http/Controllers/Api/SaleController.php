<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SaleItemResource;
use App\Models\Sale;
use App\Models\SaleItem; // Though items are created via relationship
use App\Models\Product;
use App\Models\PurchaseItem; // Needed for batch selection
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Resources\SaleResource;
use App\Models\SaleReturnItem;
use App\Services\Pdf\MyCustomTCPDF;
use Carbon\Carbon;
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
        if ($request->boolean('today_only')) {
            $query->whereDate('sale_date', Carbon::today());
            // For today's sales, load items and payments and return all without pagination
            $query->with([
                'items.product' => function($query) {
                    $query->with(['category', 'stockingUnit', 'sellableUnit']);
                },
                'payments' // Load payments for today's sales
            ]);
            $sales = $query->latest('sale_date')->latest('id')->get();
            return SaleResource::collection($sales);
        }
        if ($request->boolean('for_current_user')) {
            $query->where('user_id', $request->user()->id);
        }

        if ($clientId = $request->input('client_id')) {
            $query->where('client_id', $clientId);
        }
        if ($startDate = $request->input('start_date')) {
            $query->whereDate('sale_date', '>=', $startDate);
        }
        if ($endDate = $request->input('end_date')) {
            $query->whereDate('sale_date', '<=', $endDate);
        }
        if ($userId = $request->input('user_id')) {
            $query->where('user_id', $userId);
        }
        if ($saleId = $request->input('sale_id')) {
            $query->where('id', $saleId);
        }
        if ($productId = $request->input('product_id')) {
            $query->whereHas('items', function($q) use ($productId) {
                $q->where('product_id', $productId);
            });
        }
        if ($startTime = $request->input('start_time')) {
            $query->whereTime('created_at', '>=', $startTime);
        }
        if ($endTime = $request->input('end_time')) {
            $query->whereTime('created_at', '<=', $endTime);
        }

        $sales = $query->latest('id')->paginate($request->input('per_page', 15));
        
        // Add return information to each sale
        if ($sales instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            $sales->getCollection()->transform(function ($sale) {
                $sale->has_returns = $sale->hasReturns();
                return $sale;
            });
        }
        
        return SaleResource::collection($sales);
    }

    /**
     * Get today's sales by created_at (for POS TodaySalesColumn)
     */
    public function getTodaySalesByCreatedAt(Request $request)
    {
        $query = Sale::with([
            // Load full client timestamps to avoid null created_at in ClientResource
            'client:id,name,email,phone,address,created_at,updated_at',
            'user:id,name',
            'items.product' => function($query) {
                $query->with(['category', 'stockingUnit', 'sellableUnit']);
            },
            'payments'
        ])
        ->whereDate('created_at', Carbon::today());

        $sales = $query->latest('created_at')->latest('id')->get();
        return response()->json(['data' => SaleResource::collection($sales)]);
    }

    public function getReturnableItems(Sale $sale)
    {
        // $this->authorize('createReturn', $sale); // Policy check if user can create return for this sale

        // Fetch items, calculate already returned quantity for each original sale item
        $items = $sale->items()->with('product:id,name,sku')->get()->map(function ($saleItem) {
            $alreadyReturnedQty = SaleReturnItem::where('original_sale_item_id', $saleItem->id)
                ->whereHas('saleReturn', fn($q) => $q->where('status', '!=', 'cancelled'))
                ->sum('quantity_returned');
            $saleItem->max_returnable_quantity = $saleItem->quantity - $alreadyReturnedQty;
            $saleItem->age = 99;
            return $saleItem;
        })->filter(fn($item) => $item->max_returnable_quantity > 0); // Only items that can still be returned

        return SaleItemResource::collection($items); // Or a custom resource
    }

    /**
     * Create an empty sale (draft) for POS operations.
     * This method creates a sale header without any items or payments.
     */
    public function createEmptySale(Request $request)
    {
        $validatedData = $request->validate([
            'client_id' => 'nullable|exists:clients,id',
            'sale_date' => 'required|date_format:Y-m-d',
            'notes' => 'nullable|string|max:65535',
        ]);

        try {
            $sale = DB::transaction(function () use ($validatedData, $request) {
                // Create sale header with draft status
                $saleHeader = Sale::create([
                    'client_id' => $validatedData['client_id'],
                    'user_id' => $request->user()->id,
                    'sale_date' => $validatedData['sale_date'],
                    'invoice_number' => null, // Will be generated when completed
                    'status' => 'draft',
                    'total_amount' => 0,
                    'paid_amount' => 0,
                    'due_amount' => 0,
                    'notes' => $validatedData['notes'] ?? null,
                ]);

                return $saleHeader;
            });

            $sale->load([
                'client:id,name',
                'user:id,name',
                'items.product:id,name,sku',
                'items.purchaseItemBatch:id,batch_number,unit_cost',
                'payments.user:id,name'
            ]);

            return response()->json(['sale' => new SaleResource($sale)], Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            Log::error("Empty sale creation error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json(['message' => 'Failed to create empty sale. ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Store a newly created sale in storage.
     * Handles creating sale header, items (FIFO from batches), payments, and decrementing stock.
     */
    public function store(Request $request)
    {
        $validatedData = $this->validateSaleRequest($request);

        $this->performStockPreCheck($validatedData);

        $calculatedTotals = $this->calculateTotals($validatedData);

        $this->validatePaidAmount($calculatedTotals);

        try {
            $sale = DB::transaction(function () use ($validatedData, $request, $calculatedTotals) {
                $saleHeader = $this->createSaleHeader($validatedData, $request, $calculatedTotals);

                // --- Calculate Total Sale Amount from items in THIS request ---
                $newTotalSaleAmount = 0;
                $this->processSaleItems($validatedData, $saleHeader, $newTotalSaleAmount);
                // 3. Final Update/Verification of Sale Header Total
                // The $saleHeader->total_amount was already set using $calculatedTotalSaleAmountFromItems.
                // $newTotalSaleAmount (sum of created sale_items.total_price) should match this.
                // If they don't match, it indicates a potential issue in calculation logic.
                if (abs($saleHeader->total_amount - $newTotalSaleAmount) > 0.001) { // Check with a small tolerance for float comparisons
                    Log::error("SaleController@store: Discrepancy between initial total calculation and sum of sale items. Initial: {$saleHeader->total_amount}, Sum of Items: {$newTotalSaleAmount}. Sale ID: {$saleHeader->id}");
                    // Optionally, you could update $saleHeader->total_amount to $newTotalSaleAmount here if you trust the item sum more,
                    // or throw an exception. For now, we'll trust the initial calculation based on request data.
                    // $saleHeader->total_amount = $newTotalSaleAmount; // If choosing to update
                }
                $this->createPaymentRecords($validatedData, $saleHeader, $request);

                return $saleHeader;
            });

            $sale->load([
                'client:id,name',
                'user:id,name',
                'items.product:id,name,sku',
                'items.purchaseItemBatch:id,batch_number,unit_cost',
                'payments.user:id,name'
            ]);

            return response()->json(['sale' => new SaleResource($sale)], Response::HTTP_CREATED);
        } catch (ValidationException $e) {
            Log::warning("Sale creation validation failed: " . json_encode($e->errors()));
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            Log::error("Sale creation critical error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json(['message' => 'Failed to create sale. ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function validateSaleRequest(Request $request)
    {
        return $request->validate([
            'client_id' => 'nullable|exists:clients,id', // Made nullable for POS sales
            'sale_date' => 'required|date_format:Y-m-d',
            'invoice_number' => 'nullable|string|max:255|unique:sales,invoice_number',
            'status' => ['required', Rule::in(['completed', 'pending', 'draft', 'cancelled'])],
            'notes' => 'nullable|string|max:65535',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1', // Quantity of sellable units
            'items.*.unit_price' => 'required|numeric|min:0', // Sale price PER SELLABLE UNIT
            'discount_amount' => 'nullable|numeric|min:0', // Discount amount
            'discount_type' => 'nullable|in:percentage,fixed', // Discount type

            'payments' => 'present|array',
            'payments.*.method' => [
                'required_with:payments.*.amount',
                Rule::in(['cash', 'visa', 'mastercard', 'bank_transfer', 'mada', 'other', 'store_credit'])
            ],
            'payments.*.amount' => 'required_with:payments.*.method|numeric|min:0.01',
            'payments.*.payment_date' => 'required_with:payments.*.amount|date_format:Y-m-d',
            'payments.*.reference_number' => 'nullable|string|max:255',
            'payments.*.notes' => 'nullable|string|max:65535',
        ]);
    }

    /**
     * Get the ID of the last completed sale for the authenticated user.
     */
    public function getLastCompletedSaleId(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        // Find the latest sale by this user with a 'completed' status
        // Order by 'created_at' or 'id' to get the most recent. 'updated_at' if sales can be completed later.
        $lastSale = Sale::where('user_id', $user->id)
                        ->where('status', 'completed')
                        ->orderBy('updated_at', 'desc') // Or 'created_at' or 'id'
                        ->select('id') // Only need the ID
                        ->first();

        if ($lastSale) {
            return response()->json(['data' => ['last_sale_id' => $lastSale->id]]);
        }

        return response()->json(['data' => ['last_sale_id' => null], 'message' => 'No completed sales found for this user.'], Response::HTTP_OK);
        // Or return 404 if no sale found:
        // return response()->json(['message' => 'No completed sales found for this user.'], Response::HTTP_NOT_FOUND);
    }
    private function performStockPreCheck(array $validatedData)
    {
        $stockErrors = [];
        // Stock Pre-Check (checks Product.stock_quantity which is total sellable units)
        foreach ($validatedData['items'] as $index => $itemData) {
            $product = Product::find($itemData['product_id']);
            if ($product) {
                // Check if product has any stock
                if ($product->stock_quantity <= 0) {
                    $stockErrors["items.{$index}.product_id"] = ["Product '{$product->name}' is out of stock. Available quantity: 0"];
                }
                // Check if requested quantity exceeds available stock
                elseif ($product->stock_quantity < $itemData['quantity']) {
                    $stockErrors["items.{$index}.quantity"] = ["Insufficient stock for '{$product->name}'. Available: {$product->stock_quantity} {$product->sellable_unit_name_plural}, Requested: {$itemData['quantity']}."];
                }
            }
        }
        if (!empty($stockErrors)) throw ValidationException::withMessages($stockErrors);
    }

    private function calculateTotals(array $validatedData)
    {
        // Calculate subtotal from items
        $subtotal = 0;
        foreach ($validatedData['items'] as $itemData) {
            $subtotal += ($itemData['quantity'] * $itemData['unit_price']);
        }

        // Calculate discount
        $discountAmount = 0;
        if (isset($validatedData['discount_amount']) && $validatedData['discount_amount'] > 0) {
            if (isset($validatedData['discount_type']) && $validatedData['discount_type'] === 'percentage') {
                $discountAmount = $subtotal * ($validatedData['discount_amount'] / 100);
            } else {
                $discountAmount = min($validatedData['discount_amount'], $subtotal); // Ensure discount doesn't exceed subtotal
            }
        }

        // Calculate amount after discount
        $amountAfterDiscount = $subtotal - $discountAmount;

        // Calculate final total to STORE in DB as GROSS (pre-discount)
        // Note: We keep gross in total_amount and use discount_amount to derive net
        $calculatedTotalSaleAmount = $subtotal;

        // Calculate total paid amount
        $calculatedTotalPaidAmount = 0;
        if (!empty($validatedData['payments'])) {
            foreach ($validatedData['payments'] as $paymentData) {
                if (isset($paymentData['amount']) && is_numeric($paymentData['amount'])) {
                    $calculatedTotalPaidAmount += (float) $paymentData['amount'];
                }
            }
        }

        return [
            'subtotal' => $subtotal,
            'discountAmount' => $discountAmount,
            'amountAfterDiscount' => $amountAfterDiscount,
            'totalSaleAmount' => $calculatedTotalSaleAmount,
            'totalPaidAmount' => $calculatedTotalPaidAmount,
        ];
    }

    private function validatePaidAmount(array $calculatedTotals)
    {
        // Paid amount cannot exceed NET amount (gross - discount)
        $netAmount = (float)$calculatedTotals['amountAfterDiscount'];
        if ($calculatedTotals['totalPaidAmount'] > $netAmount) {
            throw ValidationException::withMessages(['payments' => ['Total paid amount cannot exceed the net sale amount after discount.']]);
        }
    }

    private function createSaleHeader(array $validatedData, Request $request, array $calculatedTotals)
    {
        return Sale::create([
            'client_id' => $validatedData['client_id'] ?? null,
            'user_id' => $request->user()->id,
            'sale_date' => $validatedData['sale_date'],
            'invoice_number' => $validatedData['invoice_number'] ?? null,
            'status' => $validatedData['status'],
            'notes' => $validatedData['notes'] ?? null,
            'subtotal' => $calculatedTotals['subtotal'],
            'discount_amount' => $calculatedTotals['discountAmount'],
            'discount_type' => $validatedData['discount_type'] ?? null,

            'total_amount' => $calculatedTotals['totalSaleAmount'],
            'paid_amount' => $calculatedTotals['totalPaidAmount'],
        ]);
    }

    private function processSaleItems(array $validatedData, Sale $saleHeader, $newTotalSaleAmount)
    {
        // 2. Process Sale Items and Stock (Total stock check, FIFO allocation or direct stock decrement)
        foreach ($validatedData['items'] as $itemData) {
            $product = Product::findOrFail($itemData['product_id']); // Ensure product exists
            $requestedSellableUnits = (int) $itemData['quantity']; // Quantity customer wants, in sellable units
            $unitSalePrice = (float) $itemData['unit_price']; // Price per sellable unit from request
            $quantityFulfilledInSellableUnits = 0;

            // --- Total Stock Check (already done in performStockPreCheck, but double-check here) ---
            // Refresh product to get latest stock_quantity
            $product->refresh();
            
            // Check if product has any stock
            if ($product->stock_quantity <= 0) {
                throw ValidationException::withMessages([
                    'items' => ["Product '{$product->name}' is out of stock. Available quantity: 0"]
                ]);
            }
            
            if ($product->stock_quantity < $requestedSellableUnits) {
                throw ValidationException::withMessages([
                    'items' => ["Insufficient stock for product '{$product->name}'. Available: {$product->stock_quantity} {$product->sellable_unit_name_plural}, Requested: {$requestedSellableUnits}."]
                ]);
            }

            // Fetch available batches for this product, ordered for FIFO allocation
            // (e.g., oldest expiry first, then oldest purchase_item created_at)
            // and lock them for update to prevent race conditions during concurrent sales.
            $availableBatches = PurchaseItem::where('product_id', $product->id)
                ->where('remaining_quantity', '>', 0) // remaining_quantity is in SELLABLE UNITS
                ->orderBy('expiry_date', 'asc')        // Prioritize expiring soonest
                ->orderBy('created_at', 'asc')         // Then oldest purchase batch
                ->lockForUpdate()                      // CRITICAL for concurrent stock updates
                ->get();

            // Check if we have any batches available
            if ($availableBatches->count() > 0) {
                // --- Allocate from Batches (FIFO) ---
                foreach ($availableBatches as $batch) {
                    // If we've fulfilled the requested quantity for this sale item, stop processing batches
                    if ($quantityFulfilledInSellableUnits >= $requestedSellableUnits) {
                        break;
                    }

                    // Determine how many units can be sold from this current batch
                    $canSellFromThisBatchInSellableUnits = min(
                        $requestedSellableUnits - $quantityFulfilledInSellableUnits, // How many more we still need to fulfill
                        $batch->remaining_quantity                               // How many are actually left in this batch
                    );

                    if ($canSellFromThisBatchInSellableUnits > 0) {
                        // Create the SaleItem record, linking it to the specific PurchaseItem (batch)
                        $saleHeader->items()->create([
                            'product_id'         => $product->id,
                            'purchase_item_id'   => $batch->id, // Link to the specific batch
                            'batch_number_sold'  => $batch->batch_number, // Store batch number for easy display
                            'quantity'           => $canSellFromThisBatchInSellableUnits, // Quantity sold in SELLABLE units
                            'unit_price'         => $unitSalePrice, // Sale price per sellable unit (from request)
                            'cost_price_at_sale' => $batch->cost_per_sellable_unit, // COGS component from the batch
                            'total_price'        => $canSellFromThisBatchInSellableUnits * $unitSalePrice,
                        ]);

                        // Decrement the remaining quantity of the batch (which is in sellable units)
                        $batch->decrement('remaining_quantity', $canSellFromThisBatchInSellableUnits);
                        // The PurchaseItemObserver is responsible for listening to this 'saved' event on PurchaseItem
                        // and then recalculating and updating the total Product->stock_quantity (which is also in sellable units).

                        // Update totals for the current sale
                        $newTotalSaleAmount += $canSellFromThisBatchInSellableUnits * $unitSalePrice;
                        $quantityFulfilledInSellableUnits += $canSellFromThisBatchInSellableUnits;

                        Log::info("SaleItem created: Product ID {$product->id}, Batch ID {$batch->id}, Qty Sold: {$canSellFromThisBatchInSellableUnits}, Remaining in Batch: {$batch->fresh()->remaining_quantity}. Sale ID: {$saleHeader->id}");
                    }
                } // End loop through available batches

                // Check if we still need to fulfill more quantity (batch allocation was insufficient)
                if ($quantityFulfilledInSellableUnits < $requestedSellableUnits) {
                    $remainingQuantity = $requestedSellableUnits - $quantityFulfilledInSellableUnits;
                    
                    // Create a sale item for the remaining quantity without batch tracking
                    $saleHeader->items()->create([
                        'product_id'         => $product->id,
                        'purchase_item_id'   => null, // No batch tracking for this portion
                        'batch_number_sold'  => null, // No batch number
                        'quantity'           => $remainingQuantity, // Quantity sold in SELLABLE units
                        'unit_price'         => $unitSalePrice, // Sale price per sellable unit (from request)
                        'cost_price_at_sale' => 0, // No cost tracking for non-batch items
                        'total_price'        => $remainingQuantity * $unitSalePrice,
                    ]);

                    // Decrement directly from product stock
                    $product->decrement('stock_quantity', $remainingQuantity);
                    
                    // Update totals for the current sale
                    $newTotalSaleAmount += $remainingQuantity * $unitSalePrice;
                    $quantityFulfilledInSellableUnits += $remainingQuantity;

                    Log::info("SaleItem created (no batch): Product ID {$product->id}, Qty Sold: {$remainingQuantity}, Direct stock decrement. Sale ID: {$saleHeader->id}");
                }
            } else {
                // --- No batches available, decrement directly from product stock ---
                $saleHeader->items()->create([
                    'product_id'         => $product->id,
                    'purchase_item_id'   => null, // No batch tracking
                    'batch_number_sold'  => null, // No batch number
                    'quantity'           => $requestedSellableUnits, // Quantity sold in SELLABLE units
                    'unit_price'         => $unitSalePrice, // Sale price per sellable unit (from request)
                    'cost_price_at_sale' => 0, // No cost tracking for non-batch items
                    'total_price'        => $requestedSellableUnits * $unitSalePrice,
                ]);

                // Decrement directly from product stock
                $product->decrement('stock_quantity', $requestedSellableUnits);
                
                // Update totals for the current sale
                $newTotalSaleAmount += $requestedSellableUnits * $unitSalePrice;
                $quantityFulfilledInSellableUnits = $requestedSellableUnits;

                Log::info("SaleItem created (no batch): Product ID {$product->id}, Qty Sold: {$requestedSellableUnits}, Direct stock decrement. Sale ID: {$saleHeader->id}");
            }

            // Final verification that the requested quantity was fully met
            if ($quantityFulfilledInSellableUnits < $requestedSellableUnits) {
                Log::critical("Stock allocation discrepancy for Product ID {$product->id} on Sale ID {$saleHeader->id}. Requested: {$requestedSellableUnits}, Fulfilled: {$quantityFulfilledInSellableUnits}.");
                throw new \Exception("Could not fulfill requested quantity for product '{$product->name}' due to an unexpected stock allocation issue. Please try again.");
            }
        } // End loop through $validatedData['items'] (main sale items from request)

    }
    private function createPaymentRecords(array $validatedData, Sale $saleHeader, Request $request)
    {
        if (!empty($validatedData['payments'])) {
            foreach ($validatedData['payments'] as $paymentData) {
                if (isset($paymentData['amount']) && is_numeric($paymentData['amount']) && (float)$paymentData['amount'] > 0) {
                    $saleHeader->payments()->create([
                        'user_id' => $request->user()->id,
                        'method' => $paymentData['method'],
                        'amount' => (float) $paymentData['amount'],
                        'payment_date' => $paymentData['payment_date'],
                        'reference_number' => $paymentData['reference_number'] ?? null,
                        'notes' => $paymentData['notes'] ?? null,
                    ]);
                }
            }
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
            'items.product:id,name,sku,stock_quantity,stock_alert_level,sellable_unit_id',
            'items.product.sellableUnit:id,name',
            'items.purchaseItemBatch:id,batch_number,unit_cost,expiry_date', // Load batch info for each sale item
            'payments'
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
        // Paid amount should not exceed net amount (gross - discount)
        $currentNet = max(0, (float)($sale->total_amount ?? 0) - (float)($sale->discount_amount ?? 0));
        if (isset($validatedData['paid_amount']) && $validatedData['paid_amount'] > $currentNet) {
            // If items are not editable, total_amount doesn't change.
            // If total_amount could change, this check needs to be against the new net.
            throw ValidationException::withMessages(['paid_amount' => ['Paid amount cannot exceed the net sale amount after discount.']]);
        }

        $sale->update($validatedData);

                    $sale->load([
                'client:id,name',
                'user:id,name',
                'items',
                'items.product:id,name,sku,stock_quantity,stock_alert_level,sellable_unit_id',
                'items.product.sellableUnit:id,name',
                'items.purchaseItemBatch:id,batch_number,unit_cost,expiry_date'
            ]);
            return response()->json(['sale' => new SaleResource($sale->fresh())]);
    }


    /**
     * Remove the specified sale from storage.
     * Strongly discouraged. Implement stock reversal if allowed.
     */
    public function destroy(Sale $sale)
    {
        try {
            // Check if sale can be deleted (only draft or pending sales)
            if ($sale->status === 'completed') {
                return response()->json([
                    'message' => 'Cannot delete completed sales. Consider creating a return instead.'
                ], Response::HTTP_FORBIDDEN);
            }

            DB::transaction(function () use ($sale) {
                // Return stock to inventory for each sale item
                foreach ($sale->items as $saleItem) {
                    $product = $saleItem->product;
                    $quantity = $saleItem->quantity;
                    
                    // If the sale item has a specific batch, return to that batch
                    if ($saleItem->purchase_item_id) {
                        $purchaseItem = PurchaseItem::find($saleItem->purchase_item_id);
                        if ($purchaseItem) {
                            $purchaseItem->increment('remaining_quantity', $quantity);
                            // PurchaseItemObserver will update Product->stock_quantity
                        } else {
                            // Fallback: increment total product stock
                            $product->increment('stock_quantity', $quantity);
                        }
                    } else {
                        // No specific batch, increment total product stock
                        $product->increment('stock_quantity', $quantity);
                    }
                }

                // Delete all payments for this sale
                $sale->payments()->delete();
                
                // Delete all sale items
                $sale->items()->delete();
                
                // Delete the sale header
                $sale->delete();
            });

            return response()->json([
                'message' => 'Sale deleted successfully. Stock has been returned to inventory.'
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            Log::error('Error deleting sale: ' . $e->getMessage(), [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to delete sale. Please try again.',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Add a payment to an existing sale.
     */
    public function addPayment(Request $request, Sale $sale)
    {
        $validatedData = $request->validate([
            'payments' => 'required|array',
            'payments.*.method' => 'nullable|string|in:cash,visa,mastercard,bank_transfer,mada,store_credit,other,refund',
            'payments.*.amount' => 'nullable|numeric|min:0.01',
            'payments.*.payment_date' => 'nullable|date_format:Y-m-d',
            'payments.*.reference_number' => 'nullable|string|max:255',
            'payments.*.notes' => 'nullable|string|max:65535',
        ]);

        try {
            DB::transaction(function () use ($validatedData, $sale, $request) {
                // Delete existing payments for this sale (to replace them all)
                $sale->payments()->delete();
                
                // Create new payment records only if payments array is not empty
                if (!empty($validatedData['payments'])) {
                    foreach ($validatedData['payments'] as $paymentData) {
                        // Only create payment if all required fields are present
                        if (isset($paymentData['method']) && isset($paymentData['amount']) && isset($paymentData['payment_date'])) {
                            $sale->payments()->create([
                                'user_id' => $request->user()->id,
                                'method' => $paymentData['method'],
                                'amount' => $paymentData['amount'],
                                'payment_date' => $paymentData['payment_date'],
                                'reference_number' => $paymentData['reference_number'] ?? null,
                                'notes' => $paymentData['notes'] ?? null,
                            ]);
                        }
                    }
                }

                // Update the sale's paid_amount
                $totalPaid = $sale->payments()->sum('amount');
                $sale->update(['paid_amount' => $totalPaid]);
            });

            // Reload the sale with payments
            $sale->load([
                'client:id,name',
                'user:id,name',
                'items',
                'items.product:id,name,sku,stock_quantity,stock_alert_level,sellable_unit_id',
                'items.product.sellableUnit:id,name',
                'payments'
            ]);
            
            $message = empty($validatedData['payments']) ? 'All payments cleared successfully' : 'Payment(s) added successfully';
            
            return response()->json([
                'message' => $message,
                'sale' => new SaleResource($sale->fresh())
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            Log::error('Error adding payment to sale: ' . $e->getMessage(), [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to add payment. Please try again.',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete all payments from an existing sale.
     */
    public function deletePayments(Request $request, Sale $sale)
    {
        try {
            DB::transaction(function () use ($sale) {
                // Delete all existing payments for this sale
                $sale->payments()->delete();
                
                // Update the sale's paid_amount to 0
                $sale->update(['paid_amount' => 0]);
            });

            // Reload the sale with payments
            $sale->load([
                'client:id,name',
                'user:id,name',
                'items',
                'items.product:id,name,sku,stock_quantity,stock_alert_level,sellable_unit_id',
                'items.product.sellableUnit:id,name',
                'payments'
            ]);
            
            return response()->json([
                'message' => 'All payments deleted successfully',
                'sale' => new SaleResource($sale->fresh())
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            Log::error('Error deleting payments from sale: ' . $e->getMessage(), [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to delete payments. Please try again.',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Add a single payment to an existing sale.
     */
    public function addSinglePayment(Request $request, Sale $sale)
    {
        $validatedData = $request->validate([
            'method' => 'required|string|in:cash,visa,mastercard,bank_transfer,mada,store_credit,other,refund',
            'amount' => 'required|numeric|min:0.01',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:65535',
        ]);

        try {
            DB::transaction(function () use ($validatedData, $sale, $request) {
                // Create a single payment record
                $sale->payments()->create([
                    'user_id' => $request->user()->id,
                    'method' => $validatedData['method'],
                    'amount' => $validatedData['amount'],
                    'payment_date' => now()->format('Y-m-d'),
                    'reference_number' => $validatedData['reference_number'] ?? null,
                    'notes' => $validatedData['notes'] ?? null,
                ]);

                // Update the sale's paid_amount
                $totalPaid = $sale->payments()->sum('amount');
                $sale->update(['paid_amount' => $totalPaid]);
            });

            return response()->json([
                'message' => 'Payment added successfully',
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            Log::error('Error adding single payment to sale: ' . $e->getMessage(), [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to add payment. Please try again.',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }




    /**
     * Update discount on a sale instantly (amount and type) and recalculate totals.
     * - discount_amount: numeric value; interpreted as percent when type=percentage, absolute when type=fixed
     * - discount_type: 'percentage' | 'fixed'
     */
    public function updateDiscount(Request $request, Sale $sale)
    {
        $validated = $request->validate([
            'discount_amount' => 'required|numeric|min:0',
            'discount_type' => ['required', Rule::in(['percentage', 'fixed'])],
        ]);

        try {
            DB::transaction(function () use ($validated, $sale) {
                // Always compute subtotal from items to ensure accuracy
                $subtotal = (float)$sale->items()->sum('total_price');

                // Compute discount value (absolute) from input
                $discountValue = 0.0;
                if ($validated['discount_type'] === 'percentage') {
                    // Cap percentage to 100
                    if ($validated['discount_amount'] > 100) {
                        throw ValidationException::withMessages([
                            'discount_amount' => ['Discount percentage cannot exceed 100%']
                        ]);
                    }
                    $discountValue = $subtotal * ((float)$validated['discount_amount'] / 100);
                } else { // fixed
                    $discountValue = min((float)$validated['discount_amount'], $subtotal);
                }

                // Ensure discount does not reduce total below amount already paid
                $paidAmount = (float)$sale->payments()->sum('amount');
                $maxDiscountAllowed = max(0.0, $subtotal - $paidAmount);
                if ($discountValue > $maxDiscountAllowed) {
                    throw ValidationException::withMessages([
                        'discount_amount' => [
                            "Discount cannot exceed remaining due. Max allowed: {$maxDiscountAllowed}"
                        ]
                    ]);
                }

                $totalAfterDiscount = $subtotal - $discountValue;

                // Persist changes
                $sale->update([
                    'subtotal' => $subtotal,              // store the computed subtotal from items
                    'discount_amount' => $discountValue,  // absolute discount value
                    'discount_type' => $validated['discount_type'],
                    'total_amount' => $subtotal,          // keep GROSS in DB
                    // paid_amount unchanged; due = gross - discount - paid (via accessor)
                ]);
            });

            // Reload relevant relations for client consumption
            $sale->load([
                'client:id,name',
                'user:id,name',
                'items.product:id,name,sku,stock_quantity,stock_alert_level,sellable_unit_id',
                'items.purchaseItemBatch:id,batch_number,unit_cost,expiry_date',
                'payments.user:id,name'
            ]);

            return response()->json([
                'message' => 'Discount updated successfully',
                'sale' => new SaleResource($sale)
            ], Response::HTTP_OK);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            Log::error('Failed to update sale discount', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to update discount. Please try again.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a single payment from an existing sale.
     */
    public function deleteSinglePayment(Sale $sale, $paymentId)
    {
        try {
            DB::transaction(function () use ($sale, $paymentId) {
                // Find and delete the specific payment
                $payment = $sale->payments()->findOrFail($paymentId);
                $payment->delete();
                
                // Update the sale's paid_amount
                $totalPaid = $sale->payments()->sum('amount');
                $sale->update(['paid_amount' => $totalPaid]);
            });

            return response()->json([
                'message' => 'Payment deleted successfully',
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            Log::error('Error deleting single payment from sale: ' . $e->getMessage(), [
                'sale_id' => $sale->id,
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to delete payment. Please try again.',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

        /**
     * Add a new item to an existing sale.
     * 
     * @param Request $request
     * @param Sale $sale
     * @return \Illuminate\Http\JsonResponse
     */
    public function addSaleItem(Request $request, Sale $sale)
    {
        $validatedData = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'unit_price' => 'required|numeric|min:0',
        ]);

        try {
            $result = DB::transaction(function () use ($validatedData, $sale) {
                $product = Product::findOrFail($validatedData['product_id']);
                
                // Check if product already exists in the sale
                $existingSaleItem = $sale->items()
                    ->where('product_id', $validatedData['product_id'])
                    ->first();
                
                if ($existingSaleItem) {
                    // Product already exists - do nothing
                    return [
                        'sale_items' => [],
                        'new_total' => $sale->total_amount,
                        'new_due_amount' => max(0, ($sale->total_amount - ($sale->discount_amount ?? 0)) - $sale->paid_amount),
                        'product_already_exists' => true
                    ];
                }
                
                // Check if product has any stock
                if ($product->stock_quantity <= 0) {
                    throw ValidationException::withMessages([
                        'product_id' => "Product '{$product->name}' is out of stock. Available quantity: 0"
                    ]);
                }
                
                // Check stock availability - consider current sale items
                $currentQuantityInThisSale = $sale->items()
                    ->where('product_id', $product->id)
                    ->sum('quantity');
                $totalQuantityAfterAdd = $currentQuantityInThisSale + $validatedData['quantity'];
                $originalStockQuantity = $product->stock_quantity + $currentQuantityInThisSale;
                
                if ($totalQuantityAfterAdd > $originalStockQuantity) {
                    throw ValidationException::withMessages([
                        'quantity' => "Insufficient stock. Available: {$originalStockQuantity}, Requested total: {$totalQuantityAfterAdd}"
                    ]);
                }

                // Determine unit price (fallback to product defaults if request sent 0)
                $resolvedUnitPrice = (float)($validatedData['unit_price'] ?? 0);
                if ($resolvedUnitPrice <= 0) {
                    $fallback = $product->last_sale_price_per_sellable_unit
                        ?? $product->suggested_sale_price_per_sellable_unit
                        ?? 0;
                    $resolvedUnitPrice = (float)$fallback;
                }

                // Find available batches (FIFO)
                $availableBatches = PurchaseItem::where('product_id', $product->id)
                    ->where('remaining_quantity', '>', 0)
                    ->orderBy('expiry_date', 'asc')
                    ->orderBy('created_at', 'asc')
                    ->get();

                $remainingQuantity = $validatedData['quantity'];
                $totalCost = 0;
                $saleItems = [];

                // Allocate from batches first
                foreach ($availableBatches as $batch) {
                    if ($remainingQuantity <= 0) break;

                    $canSellFromThisBatch = min($remainingQuantity, $batch->remaining_quantity);
                    
                    // Create sale item for this batch
                    $saleItem = $sale->items()->create([
                        'product_id' => $product->id,
                        'purchase_item_id' => $batch->id,
                        'batch_number_sold' => $batch->batch_number,
                        'quantity' => $canSellFromThisBatch,
                        'unit_price' => $resolvedUnitPrice,
                        'total_price' => $canSellFromThisBatch * $resolvedUnitPrice,
                        'cost_price_at_sale' => $batch->unit_cost,
                    ]);

                    $saleItems[] = $saleItem;
                    $totalCost += $canSellFromThisBatch * $batch->unit_cost;
                    $remainingQuantity -= $canSellFromThisBatch;

                    // Update batch remaining quantity
                    $batch->remaining_quantity -= $canSellFromThisBatch;
                    $batch->save();
                }

                // If there's still remaining quantity, create item without batch
                if ($remainingQuantity > 0) {
                    $saleItem = $sale->items()->create([
                        'product_id' => $product->id,
                        'purchase_item_id' => null,
                        'batch_number_sold' => null,
                        'quantity' => $remainingQuantity,
                        'unit_price' => $resolvedUnitPrice,
                        'total_price' => $remainingQuantity * $resolvedUnitPrice,
                        'cost_price_at_sale' => 0,
                    ]);
                    $saleItems[] = $saleItem;
                }

                // Update product stock
                $product->stock_quantity -= $validatedData['quantity'];
                $product->save();

                // Update sale totals
                $sale->total_amount += ($validatedData['quantity'] * $resolvedUnitPrice);
                $sale->save();

                return [
                    'sale_items' => $saleItems,
                    'new_total' => $sale->total_amount,
                    'new_due_amount' => max(0, ($sale->total_amount - ($sale->discount_amount ?? 0)) - $sale->paid_amount)
                ];
            });

            // Load the updated sale with items
            $sale->load([
                'client:id,name',
                'user:id,name',
                'items.product:id,name,sku,stock_quantity,stock_alert_level,sellable_unit_id',
                'items.purchaseItemBatch:id,batch_number,unit_cost',
                'payments.user:id,name'
            ]);

            // Check if product already existed
            if (isset($result['product_already_exists']) && $result['product_already_exists']) {
                return response()->json([
                    'message' => 'Product already exists in sale',
                    'sale' => new SaleResource($sale),
                    'added_items' => []
                ], Response::HTTP_OK);
            }

            return response()->json([
                'message' => 'Sale item added successfully',
                'sale' => new SaleResource($sale),
                'added_items' => SaleItemResource::collection($result['sale_items'])
            ], Response::HTTP_CREATED);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            Log::error('Failed to add sale item', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to add sale item. Please try again.', 'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update an existing sale item.
     * 
     * @param Request $request
     * @param Sale $sale
     * @param int $saleItemId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSaleItem(Request $request, Sale $sale, $saleItemId)
    {
        $validatedData = $request->validate([
            'quantity' => 'required|integer|min:1',
            'unit_price' => 'required|numeric|min:0',
        ]);

        try {
            $result = DB::transaction(function () use ($validatedData, $sale, $saleItemId) {
                $saleItem = $sale->items()->findOrFail($saleItemId);
                $product = $saleItem->product;
                $batch = $saleItem->purchaseItemBatch; // May be null when no specific batch was used
                
                $oldQuantity = $saleItem->quantity;
                $newQuantity = $validatedData['quantity'];
                $quantityDifference = $newQuantity - $oldQuantity;

                // If increasing quantity, check stock availability
                if ($quantityDifference > 0) {
                    if ($batch) {
                        // Lock the batch row to ensure consistent check/update
                        $lockedBatch = PurchaseItem::whereKey($batch->id)->lockForUpdate()->first();
                        if (!$lockedBatch) {
                            throw ValidationException::withMessages([
                                'quantity' => 'Associated batch not found.'
                            ]);
                        }
                        // Must have enough remaining in this specific batch
                        if ($lockedBatch->remaining_quantity < $quantityDifference) {
                            throw ValidationException::withMessages([
                                'quantity' => "Insufficient batch stock. Batch '{$lockedBatch->batch_number}' available: {$lockedBatch->remaining_quantity}, requested additional: {$quantityDifference}"
                            ]);
                        }
                    } else {
                        // No batch: rely on product total stock
                        if ($product->stock_quantity < $quantityDifference) {
                            throw ValidationException::withMessages([
                                'quantity' => "Insufficient stock for '{$product->name}'. Available: {$product->stock_quantity}, requested additional: {$quantityDifference}"
                            ]);
                        }
                    }
                }

                // Handle stock changes first (so observers update product totals where applicable)
                if ($quantityDifference !== 0) {
                    if ($batch) {
                        // Adjust the specific batch (lock again to be safe)
                        $lockedBatch = PurchaseItem::whereKey($batch->id)->lockForUpdate()->first();
                        if ($quantityDifference > 0) {
                            $lockedBatch->remaining_quantity -= $quantityDifference;
                        } else {
                            $lockedBatch->remaining_quantity += abs($quantityDifference);
                        }
                        $lockedBatch->save(); // Observer will update Product.stock_quantity
                    } else {
                        // No batch tracking: adjust product stock directly
                        if ($quantityDifference > 0) {
                            $product->decrement('stock_quantity', $quantityDifference);
                        } else {
                            $product->increment('stock_quantity', abs($quantityDifference));
                        }
                    }
                }

                // Update the sale item after stock adjustments
                $saleItem->quantity = $newQuantity;
                $saleItem->unit_price = $validatedData['unit_price'];
                $saleItem->total_price = $newQuantity * $validatedData['unit_price'];
                $saleItem->save();

                // Update sale totals
                $sale->total_amount = $sale->items()->sum('total_price');
                $sale->save();

                return [
                    'updated_item' => $saleItem,
                    'new_total' => $sale->total_amount,
                    'new_due_amount' => max(0, ($sale->total_amount - ($sale->discount_amount ?? 0)) - $sale->paid_amount)
                ];
            });

            // Load the updated sale with items
            $sale->load([
                'client:id,name',
                'user:id,name',
                'items.product:id,name,sku,stock_quantity,stock_alert_level,sellable_unit_id',
                'items.purchaseItemBatch:id,batch_number,unit_cost',
                'payments.user:id,name'
            ]);

            return response()->json([
                'message' => 'Sale item updated successfully',
                'sale' => new SaleResource($sale)
            ], Response::HTTP_OK);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            Log::error('Failed to update sale item', [
                'sale_id' => $sale->id,
                'sale_item_id' => $saleItemId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to update sale item. Please try again.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a specific sale item and return inventory quantity.
     * 
     * @param Request $request
     * @param Sale $sale
     * @param int $saleItemId
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteSaleItem(Request $request, Sale $sale, $saleItemId)
    {
        try {
            // Find the sale item
            $saleItem = $sale->items()->findOrFail($saleItemId);
            
         
            // Start transaction
            DB::beginTransaction();

            try {
                // Get the product and purchase item batch
                $product = $saleItem->product;
                $purchaseItem = $saleItem->purchaseItemBatch;

                // Return quantity to inventory
                if ($purchaseItem) {
                    // Return to the specific batch
                    $purchaseItem->remaining_quantity += $saleItem->quantity;
                    $purchaseItem->save();
                    
                    Log::info("Returned {$saleItem->quantity} units to batch {$purchaseItem->batch_number} for product " . ($product ? $product->name : 'Unknown'));
                } else {
                    // If no specific batch, we need to handle this case
                    // For now, we'll log a warning
                    Log::warning("Sale item {$saleItem->id} has no associated purchase item batch");
                }

                // Update product stock quantity (this will be handled by the observer)
                // The PurchaseItemObserver will automatically update product.stock_quantity

                // Delete the sale item first
                $deletedQuantity = $saleItem->quantity;
                $productName = $product ? $product->name : 'Unknown Product';
                

                
                $saleItem->delete();

                // Recalculate sale totals after deletion
                $sale->refresh(); // Refresh to get updated items
                $remainingItems = $sale->items()->get(); // Always fresh from DB
                

                
                $newSubtotal = $remainingItems->sum('total_price');
                $newTotalAmount = $newSubtotal; // No tax calculation for now
                
                // Update sale totals (gross)
                $sale->total_amount = $newTotalAmount;
                $sale->paid_amount = $sale->payments->sum('amount');
                // due_amount is calculated, not stored in database
                $sale->save();

                // Note: Sale status is not automatically changed when items are deleted
                // The sale will maintain its current status regardless of item count
                Log::info("Sale {$sale->id} has {$remainingItems->count()} items remaining - status unchanged");

                DB::commit();



                return response()->json([
                    'message' => "Sale item deleted successfully. {$deletedQuantity} units of {$productName} returned to inventory.",
                    'deleted_quantity' => $deletedQuantity,
                    'product_name' => $productName,
                    'returned_to_batch' => $purchaseItem ? $purchaseItem->batch_number : null,
                    'new_sale_total' => $newTotalAmount,
                    'remaining_items_count' => $remainingItems->count(),
                    'sale_status' => $sale->status
                ], Response::HTTP_OK);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Sale item not found.',
                'error' => 'Sale item does not exist'
            ], Response::HTTP_NOT_FOUND);
        } catch (\Exception $e) {
            Log::error('Failed to delete sale item', [
                'sale_id' => $sale->id,
                'sale_item_id' => $saleItemId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to delete sale item. Please try again.',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Generate and download a PDF invoice for a specific sale.
     *
     * @param Request $request
     * @param Sale $sale (Route Model Binding)
     * @return \Illuminate\Http\Response
     */
    public function downloadInvoicePDF(Request $request, Sale $sale)
    {
        // Authorization check (e.g., can user view this sale's invoice?)
        // if ($request->user()->cannot('viewInvoice', $sale)) { // Define 'viewInvoice' in SalePolicy
        //     abort(403, 'Unauthorized to view this invoice.');
        // }

        // Eager load all necessary data for the invoice
        $sale->load([
            'client:id,name,email,phone,address', // Load more client details
            'user:id,name', // Salesperson
            'items.product:id,name,sku', // Product details for each item
            'items.purchaseItemBatch:id,batch_number', // Batch number sold from
            'payments' // Load payments made against this invoice
        ]);

        // --- Create PDF using your custom TCPDF class ---
        // P for Portrait, L for Landscape
        $pdf = new MyCustomTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // --- Company & Invoice Info (from config and Sale) ---
        $settings = (new \App\Services\SettingsService())->getAll();
        $companyName = $settings['company_name'] ?? 'Your Company LLC';
        $companyAddress = $settings['company_address'] ?? '123 Business Rd, Suite 404, City, Country';
        $companyPhone = $settings['company_phone'] ?? 'N/A';
        $companyEmail = $settings['company_email'] ?? 'N/A';
        $invoicePrefix = $settings['invoice_prefix'] ?? 'INV-';


        // --- Set PDF Metadata ---
        $pdf->SetTitle('فاتورة مبيعات - ' . ($sale->invoice_number ?: $sale->id));
        $pdf->SetSubject('فاتورة مبيعات');
        // SetAuthor is done in MyCustomTCPDF constructor

        $pdf->AddPage();
        $pdf->setRTL(true); // Ensure RTL for the content

        // --- Invoice Header ---
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 16);
        $pdf->Cell(0, 12, 'فاتورة مبيعات', 0, 1, 'C'); // Sales Invoice
        $pdf->Ln(5);

        // Company Details (Right Side in RTL)
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 10);
        $pdf->Cell(90, 6, $companyName, 0, 0, 'R'); // Width 90mm, align right
        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 9);
        $pdf->Cell(0, 6, 'رقم الفاتورة: ' . ($sale->invoice_number ?: $invoicePrefix . $sale->id), 0, 1, 'L'); // Align left

        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 9);
        $pdf->MultiCell(90, 5, $companyAddress, 0, 'R', 0, 0, null, null, true, 0, false, true, 0, 'T');
        $pdf->Cell(0, 5, 'تاريخ الفاتورة: ' . Carbon::parse($sale->sale_date)->format('Y-m-d'), 0, 1, 'L');

        $currentY = $pdf->GetY(); // Store Y after address
        $pdf->SetXY(15, $currentY); // Reset X for next line on right, use stored Y
        $pdf->Cell(90, 5, 'الهاتف: ' . $companyPhone, 0, 0, 'R');
        $pdf->SetXY(105, $currentY); // Move X for next cell on left, use stored Y
        $pdf->Cell(0, 5, 'تاريخ الاستحقاق: ' . Carbon::parse($sale->sale_date)->format('Y-m-d'), 0, 1, 'L'); // Assuming due date is sale date for now

        $currentY = $pdf->GetY();
        $pdf->SetXY(15, $currentY);
        $pdf->Cell(90, 5, 'البريد الإلكتروني: ' . $companyEmail, 0, 0, 'R');
        // Optional: VAT Number if applicable
        // $pdf->Cell(0, 5, 'الرقم الضريبي: ' . config('app_settings.vat_number', 'N/A'), 0, 1, 'L');
        $pdf->Ln(8);


        // --- Bill To (Client Details) ---
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 10);
        $pdf->Cell(0, 7, 'فاتورة إلى:', 0, 1, 'R'); // "Bill To:"
        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 9);
        if ($sale->client) {
            $pdf->Cell(0, 5, $sale->client->name, 0, 1, 'R');
            if ($sale->client->address) {
                $pdf->MultiCell(0, 5, $sale->client->address, 0, 'R', 0, 1, null, null, true, 0, false, true, 0, 'T');
            }
            if ($sale->client->phone) {
                $pdf->Cell(0, 5, 'الهاتف: ' . $sale->client->phone, 0, 1, 'R');
            }
            if ($sale->client->email) {
                $pdf->Cell(0, 5, 'البريد الإلكتروني: ' . $sale->client->email, 0, 1, '');
            }
        } else {
            $pdf->Cell(0, 5, 'عميل نقدي / غير محدد', 0, 1, 'R');
        }
        $pdf->Ln(8);

        // --- Items Table ---
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 9);
        $pdf->SetFillColor(220, 220, 220); // Header fill color
        $pdf->SetTextColor(0);
        $pdf->SetDrawColor(128, 128, 128);
        $pdf->SetLineWidth(0.1);

        // Column widths for items table (adjust as needed)
        // Desc, Qty, Unit Price, Total
        $w_items = [35, 20, 30, 100];
        $header_items = ['الإجمالي', 'سعر الوحدة', 'الكمية', 'الوصف / المنتج']; // Reversed for RTL

        for ($i = 0; $i < count($header_items); ++$i) {
            $pdf->Cell($w_items[$i], 7, $header_items[$i], 1, 0, 'C', true);
        }
        $pdf->Ln();

        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 8);
        $pdf->SetFillColor(245, 245, 245); // Row fill
        $fill = false;
        foreach ($sale->items as $item) {
            // Product Name & SKU & Batch
            $productDescription = $item->product?->name ?: 'منتج غير معروف';
            if ($item->product?->sku) {
                $productDescription .= "\n" . ' (SKU: ' . $item->product->sku . ')';
            }
            if ($item->batch_number_sold) {
                $productDescription .= "\n" . 'دفعة: ' . $item->batch_number_sold;
            }

            $lineHeight = $pdf->getStringHeight($w_items[3], $productDescription); // Calculate height needed for description
            $lineHeight = max(6, $lineHeight); // Minimum height of 6

                    $pdf->Cell($w_items[0], $lineHeight, number_format((float)$item->total_price, 0), 'LRB', 0, 'R', $fill);
        $pdf->Cell($w_items[1], $lineHeight, number_format((float)$item->unit_price, 0), 'LRB', 0, 'R', $fill);
            $pdf->Cell($w_items[2], $lineHeight, $item->quantity, 'LRB', 0, 'C', $fill);

            $x = $pdf->GetX();
            $y = $pdf->GetY(); // Store current position
            $pdf->MultiCell($w_items[3], $lineHeight, $productDescription, 'LRB', 'R', $fill, 1, $x, $y, true, 0, false, true, $lineHeight, 'M');
            // $pdf->Ln($lineHeight); // MultiCell with ln=1 already adds line break

            $fill = !$fill;
        }
        // $pdf->Cell(array_sum($w_items), 0, '', 'T'); // Top border for summary
        $pdf->Ln(0.1); // Tiny break to ensure totals are visually distinct

        // --- Totals Section ---
        $yBeforeTotals = $pdf->GetY();
        $col1Width = array_sum($w_items) - $w_items[0]; // Width for labels
        $col2Width = $w_items[0]; // Width for amounts

        // Compute totals using gross (total_amount) and discount
        $subtotalValue = (float) ($sale->total_amount ?? 0);
        $discountValue = (float) ($sale->discount_amount ?? 0);
        $netTotal = max(0, $subtotalValue - $discountValue);
        $paidValue = (float) ($sale->paid_amount ?? 0);
        $due = max(0, $netTotal - $paidValue);

        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 9);
        $pdf->Cell($col1Width, 6, 'المجموع الفرعي:', 'LTR', 0, 'L', false);
        $pdf->Cell($col2Width, 6, number_format($subtotalValue, 0), 'TR', 1, 'R', false);

        if ($discountValue > 0) {
            $pdf->Cell($col1Width, 6, 'الخصم:', 'LR', 0, 'L', false);
            $pdf->Cell($col2Width, 6, '-' . number_format($discountValue, 0), 'R', 1, 'R', false);
        }

        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 10);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell($col1Width, 7, 'الإجمالي المستحق:', 'LTRB', 0, 'L', true);
        $pdf->Cell($col2Width, 7, number_format($netTotal, 0), 'TRB', 1, 'R', true);

        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 9);
        $pdf->Cell($col1Width, 6, 'المبلغ المدفوع:', 'LR', 0, 'L', false);
        $pdf->Cell($col2Width, 6, number_format($paidValue, 0), 'R', 1, 'R', false);

        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 10);
        $pdf->Cell($col1Width, 7, 'المبلغ المتبقي:', 'LTRB', 0, 'L', false);
        $pdf->Cell($col2Width, 7, number_format($due, 0), 'TRB', 1, 'R', false);


        // --- Payments Information ---
        if ($sale->payments && $sale->payments->count() > 0) {
            $pdf->Ln(6);
            $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 10);
            $pdf->Cell(0, 7, 'تفاصيل الدفع:', 0, 1, 'R');
            $pdf->SetFont($pdf->getDefaultFontFamily(), '', 8);
            foreach ($sale->payments as $payment) {
                $paymentText = "طريقة الدفع: " . config('app_settings.payment_methods.' . $payment->method, $payment->method); // Assuming payment_methods in config
                $paymentText .= "  |  المبلغ: " . number_format((float)$payment->amount, 0);
                $paymentText .= "  |  التاريخ: " . Carbon::parse($payment->payment_date)->format('Y-m-d');
                if ($payment->reference_number) $paymentText .= "  |  مرجع: " . $payment->reference_number;
                $pdf->MultiCell(0, 5, $paymentText, 0, 'R', 0, 1);
            }
        }


        // --- Notes / Terms & Conditions ---
        if ($sale->notes) {
            $pdf->Ln(6);
            $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 9);
            $pdf->Cell(0, 6, 'ملاحظات:', 0, 1, 'R');
            $pdf->SetFont($pdf->getDefaultFontFamily(), '', 8);
            $pdf->MultiCell(0, 5, $sale->notes, 0, 'R', 0, 1);
        }
        $pdf->Ln(10);
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'I', 8);
        $terms = $settings['invoice_terms'] ?? 'شكراً لتعاملكم معنا. تطبق الشروط والأحكام.';
        $pdf->MultiCell(0, 5, $terms, 0, 'C', 0, 1);

        // --- Output PDF ---
        $pdfFileName = 'invoice_' . ($sale->invoice_number ?: $sale->id) . '_' . now()->format('Ymd') . '.pdf';
        $pdfContent = $pdf->Output($pdfFileName, 'S'); // 'S' returns as string

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "inline; filename=\"{$pdfFileName}\""); // inline to display in browser
        //  ->header('Content-Disposition', "attachment; filename=\"{$pdfFileName}\""); // to force download
    }
    public function downloadThermalInvoicePDF(Request $request, Sale $sale)
    {
        // Authorization check
        // if ($request->user()->cannot('printThermalInvoice', $sale)) { abort(403); }

        $sale->load([
            'client:id,name', // Load only what's needed for receipt
            'user:id,name',
            'items.product:id,name,sku',
            // No need to load purchaseItemBatch for thermal receipt unless showing batch no.
        ]);

        // --- PDF Setup for Thermal (e.g., 80mm width) ---
        // Dynamic height: base + items + payments
        $itemsCount = $sale->items?->count() ?? 0;
        $paymentsCount = $sale->payments?->count() ?? 0;
        $pageHeightMm = 105 + (5 * $itemsCount) + (5 * $paymentsCount);
        $pdf = new MyCustomTCPDF('P', 'mm', [80, $pageHeightMm], true, 'UTF-8', false); // Custom page size [width, height]
        // $pdf->setThermalDefaults(80, 250); // Or use your preset method

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(4, 5, 4); // L, T, R
        $pdf->SetAutoPageBreak(TRUE, 5); // Bottom margin
        $pdf->AddPage();
        $pdf->setRTL(true); // Ensure RTL for Arabic content

        // --- Company Info (Simplified for Thermal) ---
        $settingsThermal = (new \App\Services\SettingsService())->getAll();
        $companyName = $settingsThermal['company_name'] ?? 'Your Company';
        $companyPhone = $settingsThermal['company_phone'] ?? '';
        $companyLogoUrl = $settingsThermal['company_logo_url'] ?? null;
        // $vatNumber = config('app_settings.vat_number', ''); // If applicable

        // Draw logo if exists
        if (!empty($companyLogoUrl) && is_string($companyLogoUrl)) {
            try {
                $path = parse_url($companyLogoUrl, PHP_URL_PATH) ?: '';
                $logoPath = $companyLogoUrl;
                if ($path) {
                    $storagePos = strpos($path, '/storage/');
                    if ($storagePos !== false) {
                        $relative = substr($path, $storagePos + strlen('/storage/'));
                        $candidate = public_path('storage/' . ltrim($relative, '/'));
                        if (file_exists($candidate)) {
                            $logoPath = $candidate;
                        }
                    }
                }
                $w = 20; // mm
                $x = ($pdf->getPageWidth() - $w) / 2;
                $y = 5;
                @$pdf->Image($logoPath, $x +20, $y, $w, 0, '', '', 'T', false, 300, '', false, false, 0, false, false, false);
                $pdf->Ln(h: 20);
            } catch (\Throwable $e) {
                // ignore logo errors on thermal
            }
        }

        $pdf->SetFont('dejavusans', 'B', 10); // Or your preferred Arabic thermal font
        $pdf->MultiCell(0, 5, $companyName, 0, 'C', 0, 1);
        if ($companyPhone) {
            $pdf->SetFont('dejavusans', '', 8);
            $pdf->MultiCell(0, 4, 'الهاتف: ' . $companyPhone, 0, 'C', 0, 1);
        }
        // if ($vatNumber) {
        //     $pdf->MultiCell(0, 4, 'الرقم الضريبي: ' . $vatNumber, 0, 'C', 0, 1);
        // }
        $pdf->Ln(2);

        // --- Invoice Info ---
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->Cell(0, 4, 'فاتورة رقم: ' . ($sale->invoice_number ?: 'S-' . $sale->id), 0, 1, 'R');
        $pdf->Cell(0, 4, 'التاريخ: ' . Carbon::parse($sale->sale_date)->format('Y/m/d') . ' ' . Carbon::parse($sale->created_at)->format('H:i'), 0, 1, 'R');
        if ($sale->client) {
            $pdf->Cell(0, 4, 'العميل: ' . $sale->client->name, 0, 1, 'R');
        }
        if ($sale->user) {
            $pdf->Cell(0, 4, 'البائع: ' . $sale->user->name, 0, 1, 'R');
        }
        $pdf->Ln(1);
        $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->getPageWidth() - $pdf->GetX(), $pdf->GetY()); // Separator
        $pdf->Ln(1);

        // --- Items Header ---
        // Text: Item | Qty | Price | Total
        // Align: R  | C   | R     | R
        $pdf->SetFont('dejavusans', 'B', 7);
        $pdf->Cell(18, 5, 'الإجمالي', 0, 0, 'R'); // Total
        $pdf->Cell(18, 5, 'السعر', 0, 0, 'R');    // Price
        $pdf->Cell(10, 5, 'كمية', 0, 0, 'C');   // Qty
        $pdf->Cell(26, 5, 'الصنف', 0, 1, 'R');    // Item Name (remaining width)
        $pdf->Ln(0.5);
        $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->getPageWidth() - $pdf->GetX(), $pdf->GetY()); // Separator
        $pdf->Ln(0.5);

        // --- Items Loop ---
        $pdf->SetFont('dejavusans', '', 7);
        foreach ($sale->items as $item) {
            $productName = $item->product?->name ?: 'Product N/A';
            // Truncate or wrap product name if too long for thermal width
            if (mb_strlen($productName) > 20) { // Example length check
                $productName = mb_substr($productName, 0, 18) . '..';
            }

                    $itemTotal = number_format((float)$item->total_price, 0);
        $itemPrice = number_format((float)$item->unit_price, 0);
            $itemQty = (string)$item->quantity;

            // Using MultiCell for name to handle potential (though short) wrapping
            $currentY = $pdf->GetY();
            $pdf->Cell(18, 4, $itemTotal, 0, 0, 'R');
            $pdf->Cell(18, 4, $itemPrice, 0, 0, 'R');
            $pdf->Cell(10, 4, $itemQty, 0, 0, 'C');
            $pdf->MultiCell(26, 4, $productName, 0, 'C', 0, 1); // Set X explicitly
            $pdf->SetX(4); // Reset X for next line to start from left margin (for RTL Cell flow)
            // $pdf->Ln(0.5); // Small space between items
        }
        $pdf->Ln(0.5);
        $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->getPageWidth() - $pdf->GetX(), $pdf->GetY()); // Separator
        $pdf->Ln(1);

        // --- Totals --- (with discount) using gross total_amount and discount_amount
        $itemsSubtotal = (float) ($sale->items?->sum('total_price') ?? 0);
        $gross = (float) ($sale->total_amount ?? $itemsSubtotal);
        $discountAmount = (float) ($sale->discount_amount ?? 0);
        $finalTotal = max(0, $gross - $discountAmount);

        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->Cell(46, 5, 'الإجمالي الفرعي:', 0, 0, 'R');
        $pdf->Cell(26, 5, number_format($itemsSubtotal, 0), 0, 1, 'R');

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->Cell(46, 5, 'الخصم:', 0, 0, 'R');
        $pdf->Cell(26, 5, '-' . number_format($discountAmount, 0), 0, 1, 'R');

        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->Cell(46, 6, 'الإجمالي النهائي:', 0, 0, 'R');
        $pdf->Cell(26, 6, number_format($finalTotal, 0), 0, 1, 'R');

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->Cell(46, 5, 'المدفوع:', 0, 0, 'R');
        $pdf->Cell(26, 5, number_format((float)$sale->paid_amount, 0), 0, 1, 'R');

        $due = (float)$finalTotal - (float)$sale->paid_amount;
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->Cell(46, 5, 'المتبقي:', 0, 0, 'R');
        $pdf->Cell(26, 5, number_format($due, 0), 0, 1, 'R');
        $pdf->Ln(1);

        // --- Payment Methods Used ---
        if ($sale->payments && $sale->payments->count() > 0) {
            $pdf->SetFont('dejavusans', 'B', 7);
            $pdf->Cell(0, 4, 'طرق الدفع:', 0, 1, 'R');
            $pdf->SetFont('dejavusans', '', 7);
            foreach ($sale->payments as $payment) {
                $methodLabel = $payment->method;
                if (function_exists('config')) {
                    $methodLabel = config('app_settings.payment_methods_ar.' . $payment->method, $payment->method);
                }
                $pdf->Cell(46, 4, $methodLabel . ':', 0, 0, 'R');
                $pdf->Cell(26, 4, number_format((float)$payment->amount, 0), 0, 1, 'R');
            }
            $pdf->Ln(1);
        }


        // --- Footer Message ---
        $pdf->SetFont('dejavusans', '', 7);
        $thermalFooter = $settingsThermal['invoice_thermal_footer'] ?? config('app_settings.invoice_thermal_footer', 'شكراً لزيارتكم!');
        $pdf->MultiCell(0, 4, $thermalFooter, 0, 'C', 0, 1);

        // --- Barcode/QR Code (Optional) ---
        // if ($sale->invoice_number) {
        //     $style = array(
        //         'border' => false,
        //         'padding' => 1,
        //         'fgcolor' => array(0,0,0),
        //         'bgcolor' => false, //array(255,255,255)
        //     );
        //     $pdf->Ln(2);
        //     // $pdf->write1DBarcode($sale->invoice_number, 'C128', '', '', '', 12, 0.4, $style, 'N');
        //      $pdf->write2DBarcode('SaleID:'.$sale->id . ';Invoice:'.$sale->invoice_number, 'QRCODE,M', '', '', 25, 25, $style, 'N');
        //      $pdf->Cell(0, 0, 'امسح للمزيد من التفاصيل', 0, 1, 'C');
        // }


        // --- Output PDF ---
        $pdfFileName = 'thermal_invoice_' . ($sale->invoice_number ?: $sale->id) . '.pdf';
        $pdfContent = $pdf->Output($pdfFileName, 'S'); // 'S' returns as string

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            // No 'Content-Disposition: attachment' - frontend will handle display
        ;
    }

    /**
     * Get financial calculator data for a specific date
     */
    public function calculator(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        $date = $request->input('date');
        $userId = $request->input('user_id');

        $query = Sale::whereDate('sale_date', $date)
            ->whereIn('status', ['completed', 'pending', 'draft']) // Include all relevant statuses
            ->with(['payments', 'user:id,name']);

        if ($userId) {
            $query->where('user_id', $userId);
        }

        $sales = $query->get();
        
        // Debug logging
        \Log::info('Calculator API Debug', [
            'date' => $date,
            'user_id' => $userId,
            'total_sales_found' => $sales->count(),
            'sales_by_status' => $sales->groupBy('status')->map->count(),
            'sales_details' => $sales->map(function($sale) {
                return [
                    'id' => $sale->id,
                    'status' => $sale->status,
                    'total_amount' => $sale->total_amount,
                    'user_id' => $sale->user_id,
                    'user_name' => $sale->user?->name,
                    'payments_count' => $sale->payments->count(),
                    'payments_sum' => $sale->payments->sum('amount')
                ];
            })
        ]);

        // Calculate total income based on actual payments received
        $totalIncome = $sales->sum(function($sale) {
            return $sale->payments->sum('amount');
        });
        $totalSales = $sales->count();

        // Payment breakdown
        $paymentBreakdown = [];
        $paymentMethods = $sales->flatMap->payments->groupBy('method');
        
        foreach ($paymentMethods as $method => $payments) {
            $paymentBreakdown[] = [
                'method' => $method,
                'amount' => (float) $payments->sum('amount'),
                'count' => $payments->count(),
            ];
        }

        // User breakdown
        $userPayments = $sales->groupBy('user_id')->map(function ($userSales, $userId) {
            $user = $userSales->first()->user;
            return [
                'user_id' => (int) $userId,
                'user_name' => $user ? $user->name : 'Unknown User',
                'total_amount' => (float) $userSales->sum(function($sale) {
                    return $sale->payments->sum('amount');
                }),
                'payment_count' => $userSales->count(),
            ];
        })->values();

        return response()->json([
            'total_income' => (float) $totalIncome,
            'total_sales' => $totalSales,
            'payment_breakdown' => $paymentBreakdown,
            'user_payments' => $userPayments,
        ]);
    }

    /**
     * Add multiple items to an existing sale.
     * 
     * @param Request $request
     * @param Sale $sale
     * @return \Illuminate\Http\JsonResponse
     */
    public function addMultipleSaleItems(Request $request, Sale $sale)
    {
        $validatedData = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        try {
            $result = DB::transaction(function () use ($validatedData, $sale) {
                $addedItems = [];
                $errors = [];
                $totalAdded = 0;

                foreach ($validatedData['items'] as $index => $itemData) {
                    try {
                        $product = Product::findOrFail($itemData['product_id']);
                        
                        // Check if product already exists in the sale
                        $existingSaleItem = $sale->items()
                            ->where('product_id', $itemData['product_id'])
                            ->first();
                        
                        if ($existingSaleItem) {
                            $errors[] = "Product '{$product->name}' already exists in sale";
                            continue;
                        }
                        
                        // Check if product has any stock
                        if ($product->stock_quantity <= 0) {
                            $errors[] = "Product '{$product->name}' is out of stock. Available quantity: 0";
                            continue;
                        }
                        
                        // Check stock availability - consider current sale items
                        $currentQuantityInThisSale = $sale->items()
                            ->where('product_id', $product->id)
                            ->sum('quantity');
                        $totalQuantityAfterAdd = $currentQuantityInThisSale + $itemData['quantity'];
                        $originalStockQuantity = $product->stock_quantity + $currentQuantityInThisSale;
                        
                        if ($totalQuantityAfterAdd > $originalStockQuantity) {
                            $errors[] = "Insufficient stock for '{$product->name}'. Available: {$originalStockQuantity}, Requested total: {$totalQuantityAfterAdd}";
                            continue;
                        }

                        // Resolve unit price with backend fallback if 0/empty comes from client
                        $resolvedUnitPrice = (float)($itemData['unit_price'] ?? 0);
                        if ($resolvedUnitPrice <= 0) {
                            $fallback = $product->last_sale_price_per_sellable_unit
                                ?? $product->suggested_sale_price_per_sellable_unit
                                ?? 0;
                            $resolvedUnitPrice = (float)$fallback;
                        }

                        // Find available batches (FIFO)
                        $availableBatches = PurchaseItem::where('product_id', $product->id)
                            ->where('remaining_quantity', '>', 0)
                            ->orderBy('expiry_date', 'asc')
                            ->orderBy('created_at', 'asc')
                            ->get();

                        $remainingQuantity = $itemData['quantity'];
                        $saleItems = [];

                        // Allocate from batches first
                        foreach ($availableBatches as $batch) {
                            if ($remainingQuantity <= 0) break;

                            $canSellFromThisBatch = min($remainingQuantity, $batch->remaining_quantity);
                            
                            // Create sale item for this batch
                            $saleItem = $sale->items()->create([
                                'product_id' => $product->id,
                                'purchase_item_id' => $batch->id,
                                'batch_number_sold' => $batch->batch_number,
                                'quantity' => $canSellFromThisBatch,
                                'unit_price' => $resolvedUnitPrice,
                                'total_price' => $canSellFromThisBatch * $resolvedUnitPrice,
                                'cost_price_at_sale' => $batch->unit_cost,
                            ]);

                            $saleItems[] = $saleItem;
                            $remainingQuantity -= $canSellFromThisBatch;

                            // Update batch remaining quantity
                            $batch->remaining_quantity -= $canSellFromThisBatch;
                            $batch->save();
                        }

                        // If there's still remaining quantity, create item without batch
                        if ($remainingQuantity > 0) {
                            $saleItem = $sale->items()->create([
                                'product_id' => $product->id,
                                'purchase_item_id' => null,
                                'batch_number_sold' => null,
                                'quantity' => $remainingQuantity,
                                'unit_price' => $resolvedUnitPrice,
                                'total_price' => $remainingQuantity * $resolvedUnitPrice,
                                'cost_price_at_sale' => 0,
                            ]);
                            $saleItems[] = $saleItem;
                        }

                        // Update product stock
                        $product->stock_quantity -= $itemData['quantity'];
                        $product->save();

                        // Update sale totals
                        $sale->total_amount += ($itemData['quantity'] * $resolvedUnitPrice);
                        
                        $addedItems = array_merge($addedItems, $saleItems);
                        $totalAdded++;

                    } catch (\Exception $e) {
                        $errors[] = "Failed to add product at index {$index}: " . $e->getMessage();
                    }
                }

                // Save the updated sale totals
                $sale->save();

                return [
                    'added_items' => $addedItems,
                    'total_added' => $totalAdded,
                    'errors' => $errors,
                    'new_total' => $sale->total_amount,
                    'new_due_amount' => max(0, ($sale->total_amount - ($sale->discount_amount ?? 0)) - $sale->paid_amount)
                ];
            });

            // Load the updated sale with items
            $sale->load([
                'client:id,name',
                'user:id,name',
                'items.product:id,name,sku,stock_quantity,stock_alert_level,sellable_unit_id',
                'items.purchaseItemBatch:id,batch_number,unit_cost',
                'payments.user:id,name'
            ]);

            $message = $result['total_added'] > 0 
                ? "Successfully added {$result['total_added']} item(s)" 
                : "No items were added";

            if (!empty($result['errors'])) {
                $message .= ". Errors: " . implode(', ', $result['errors']);
            }

            return response()->json([
                'message' => $message,
                'sale' => new SaleResource($sale),
                'added_items' => SaleItemResource::collection($result['added_items']),
                'total_added' => $result['total_added'],
                'errors' => $result['errors']
            ], Response::HTTP_CREATED);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            Log::error('Failed to add multiple sale items', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to add sale items. Please try again.',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
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