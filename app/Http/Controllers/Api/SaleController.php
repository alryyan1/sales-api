<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SaleItemResource;
use App\Models\Sale;
use App\Models\SaleItem; // Though items are created via relationship
use App\Models\Product;
use App\Models\PurchaseItem; // Needed for batch selection
use App\Models\Shift;
use App\Services\SettingsService;
use App\Services\WhatsAppService;
use App\Events\SaleCreated;
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
    public function index(Request $request)
    {
        $query = Sale::with(['client:id,name', 'user:id,name']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('id', $search)
                    ->orWhere('number', 'like', "%{$search}%")
                    ->orWhereHas('client', fn($clientQuery) => $clientQuery->where('name', 'like', "%{$search}%"));
            });
        }
        // Status filtering removed because the status column was dropped.
        if ($request->boolean('today_only')) {
            $query->whereDate('sale_date', Carbon::today());
            // For today's sales, load items and payments and return all without pagination
            $query->with([
                'items.product' => function ($query) {
                    $query->with(['category', 'stockingUnit', 'sellableUnit', 'purchaseItemsWithStock:id,product_id,batch_number,expiry_date,sale_price,unit_cost']);
                },
                'payments.user:id,name,username' // Load payments with user relationship for today's sales
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
            // Load payments when filtering by date (for POS days mode)
            $query->with(['payments.user:id,name,username']);
        }
        if ($endDate = $request->input('end_date')) {
            $query->whereDate('sale_date', '<=', $endDate);
            // Load payments when filtering by date (for POS days mode)
            $query->with(['payments.user:id,name,username']);
        }
        if ($userId = $request->input('user_id')) {
            $query->where('user_id', $userId);
        }
        if ($saleId = $request->input('sale_id')) {
            $query->where('id', $saleId);
        }
        if ($productId = $request->input('product_id')) {
            $query->whereHas('items', function ($q) use ($productId) {
                $q->where('product_id', $productId);
            });
        }
        if ($startTime = $request->input('start_time')) {
            $query->whereTime('created_at', '>=', $startTime);
        }
        if ($endTime = $request->input('end_time')) {
            $query->whereTime('created_at', '<=', $endTime);
        }

        if ($shiftId = $request->input('shift_id')) {
            $query->where('shift_id', $shiftId);
            // Load payments when filtering by shift_id (for offline POS)
            $query->with(['payments.user:id,name,username']);
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
     * Return all sales without pagination: by shift_id or by current date (today_only).
     * Uses server date only; no front-end date params.
     */
    public function listAll(Request $request)
    {
        $query = Sale::with([
            'client:id,name',
            'user:id,name',
            'payments.user:id,name,username',
            'items.product:id,name,sku,scientific_name',
            'items.purchaseItemBatch:id,batch_number,unit_cost,expiry_date',
        ]);

        if ($shiftId = $request->input('shift_id')) {
            $query->where('shift_id', $shiftId);
        } elseif ($request->boolean('today_only')) {
            $query->whereDate('sale_date', Carbon::today());
        } else {
            return SaleResource::collection(collect());
        }

        $sales = $query->orderBy('id', 'desc')->get();

        $sales->transform(function ($sale) {
            $sale->has_returns = $sale->hasReturns();
            return $sale;
        });

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
            'items.product' => function ($query) {
                $query->with(['category', 'stockingUnit', 'sellableUnit', 'purchaseItemsWithStock:id,product_id,batch_number,expiry_date,sale_price,unit_cost']);
            },
            'payments.user:id,name,username' // Load payments with user relationship
        ])
            ->whereDate('created_at', Carbon::today());

        $sales = $query->latest('created_at')->latest('id')->get();
        return response()->json(['data' => SaleResource::collection($sales)]);
    }

    public function getReturnableItems(Sale $sale)
    {
        // $this->authorize('createReturn', $sale); // Policy check if user can create return for this sale

        // Fetch items, calculate already returned quantity for each original sale item
        $items = $sale->items()->with('product:id,name,sku,scientific_name')->get()->map(function ($saleItem) {
            $alreadyReturnedQty = SaleReturnItem::where('original_sale_item_id', $saleItem->id)
                ->whereHas('saleReturn', fn($q) => $q->where('status', '!=', 'cancelled'))
                ->sum('quantity_returned');
            $saleItem->max_returnable_quantity = $saleItem->quantity - $alreadyReturnedQty;
            $saleItem->age = 99;
            return $saleItem;
        })->filter(fn($item) => $item->max_returnable_quantity > 0); // Only items that can still be returned

        return SaleItemResource::collection($items); // Or a custom resource
    }

    public function createEmptySale(Request $request)
    {
        $validatedData = $request->validate([
            'client_id' => 'nullable|exists:clients,id',
            'sale_date' => 'nullable|date_format:Y-m-d',
        ]);

        try {
            // Check for open shift before transaction
            $settings = (new SettingsService())->getAll();
            
            $posMode = $settings['pos_mode'] ?? 'shift';
            // return $settings;
            $currentShift = null;
            if ($posMode === 'shift') {
                $currentShift = Shift::orderBy('id', 'desc')
                    ->first();

                if (!$currentShift) {
                    return response()->json([
                        'message' => 'لا توجد وردية مفتوحة. يرجى فتح وردية أولاً.',
                    ], Response::HTTP_BAD_REQUEST);
                }
            }

            $sale = DB::transaction(function () use ($validatedData, $request, $currentShift) {
                // Create sale header (status column was removed)
                $saleHeader = Sale::create([
                    'client_id' => $validatedData['client_id'],
                    'user_id' => $request->user()->id,
                    'warehouse_id' => $request->user()->warehouse_id ?? 1,
                    'shift_id' => $currentShift->id,
                    'sale_date' => $validatedData['sale_date'] ?? now()->toDateString(),
                ]);

                return $saleHeader;
            });

            $sale->load([
                'client:id,name',
                'user:id,name',
            ]);

            // Fire event for notifications
            event(new SaleCreated($sale));

            return response()->json(['sale' => new SaleResource($sale)], Response::HTTP_CREATED);
        } catch (\Throwable $e) {
    

            return response()->json($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(Request $request)
    {
        $validatedData = $this->validateSaleRequest($request);


        // Check for open shift only if pos_mode is 'shift'
        $settings = (new SettingsService())->getAll();
        $posMode = $settings['pos_mode'] ?? 'shift';
        // return $settings;

        $currentShift = null;
        if ($posMode === 'shift') {
            $currentShift = Shift::where('user_id', $request->user()->id)
                ->whereNull('closed_at')
                ->orderBy('id', 'desc')
                ->first();

            if (!$currentShift) {
                return response()->json([
                    'message' => 'لا توجد وردية مفتوحة. يرجى فتح وردية أولاً.',
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        $this->performStockPreCheck($validatedData);

        $calculatedTotals = $this->calculateTotals($validatedData);

        $this->validatePaidAmount($calculatedTotals);

        try {
            $sale = DB::transaction(function () use ($validatedData, $request, $calculatedTotals, $currentShift) {
                $saleHeader = $this->createSaleHeader($validatedData, $request, $calculatedTotals, $currentShift);

                // --- Calculate Total Sale Amount from items in THIS request ---
                $newTotalSaleAmount = 0;
                $this->processSaleItems($validatedData, $saleHeader, $newTotalSaleAmount);

                $this->createPaymentRecords($validatedData, $saleHeader, $request);

                // total_amount, paid_amount, discount_amount columns dropped; derived from items and payments

                return $saleHeader;
            });

            $sale->load([
                'client:id,name',
                'user:id,name',
                'items.product:id,name,sku,scientific_name',
                'items.purchaseItemBatch:id,batch_number,unit_cost',
                'payments.user:id,name'
            ]);

            // Fire event for notifications
            event(new SaleCreated($sale));

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
            'warehouse_id' => 'nullable|exists:warehouses,id', // Warehouse for the sale
            'client_id' => 'nullable|exists:clients,id', // Made nullable for POS sales
            'shift_id' => 'nullable|exists:shifts,id', // Shift ID - null for days mode, set for shift mode
            'sale_date' => 'nullable|date_format:Y-m-d',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1', // Quantity of sellable units
            'items.*.unit_price' => 'required|numeric|min:0', // Sale price PER SELLABLE UNIT
            'discount_amount' => 'nullable|numeric|min:0', // Discount amount (fixed); percentage computed from request if needed
            'payments' => 'present|array',
            'payments.*.method' => [
                'required_with:payments.*.amount',
                Rule::in(['cash', 'bankak', 'fawry', 'ocash'])
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

        // Find the latest sale by this user.
        $lastSale = Sale::where('user_id', $user->id)
            ->orderBy('updated_at', 'desc')
            ->select('id')
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
        $warehouseId = $validatedData['warehouse_id'] ?? request()->user()->warehouse_id ?? 1;

        // Stock Pre-Check (checks Product.stock_quantity which is total sellable units)
        foreach ($validatedData['items'] as $index => $itemData) {
            $product = Product::find($itemData['product_id']);
            if ($product) {
                // Check if product has any stock in THIS warehouse
                $availableInWarehouse = $product->countStock($warehouseId);

                if ($availableInWarehouse <= 0) {
                    $stockErrors["items.{$index}.product_id"] = ["Product '{$product->name}' is out of stock in Warehouse {$warehouseId}. Available: 0"];
                }
                // Check if requested quantity exceeds available stock
                elseif ($availableInWarehouse < $itemData['quantity']) {
                    $stockErrors["items.{$index}.quantity"] = ["Insufficient stock for '{$product->name}' in Warehouse {$warehouseId}. Available: {$availableInWarehouse} {$product->sellable_unit_name_plural}, Requested: {$itemData['quantity']}."];
                }
            }
        }
        if (!empty($stockErrors))
            throw ValidationException::withMessages($stockErrors);
    }

    private function calculateTotals(array $validatedData)
    {
        // Calculate subtotal from items (not stored in DB anymore)
        $subtotal = 0;
        foreach ($validatedData['items'] as $itemData) {
            $subtotal += ($itemData['quantity'] * $itemData['unit_price']);
        }

        // Calculate discount
        $discountAmount = 0;
        if (isset($validatedData['discount_amount']) && $validatedData['discount_amount'] > 0) {
            $discountAmount = min((float) $validatedData['discount_amount'], $subtotal); // Fixed discount, cap at subtotal
        }

        // Calculate amount after discount (net amount)
        $amountAfterDiscount = $subtotal - $discountAmount;

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
            'totalPaidAmount' => $calculatedTotalPaidAmount,
        ];
    }

    private function validatePaidAmount(array $calculatedTotals)
    {
        // Paid amount cannot exceed NET amount (gross - discount)
        $netAmount = (float) $calculatedTotals['amountAfterDiscount'];
        if ($calculatedTotals['totalPaidAmount'] > $netAmount) {
            throw ValidationException::withMessages(['payments' => ['Total paid amount cannot exceed the net sale amount after discount.']]);
        }
    }

    private function createSaleHeader(array $validatedData, Request $request, array $calculatedTotals, ?Shift $currentShift = null)
    {
        // Determine shift_id: use provided shift_id, or auto-fetch if not provided and not explicitly null
        $shiftId = null;

        if (array_key_exists('shift_id', $validatedData)) {
            // shift_id was explicitly provided (can be null for days mode)
            // Use array_key_exists instead of isset because isset returns false for null values
            $shiftId = $validatedData['shift_id'];
        } elseif (!$currentShift) {
            // shift_id not provided and no currentShift passed - auto-fetch open shift
            // This maintains backward compatibility for shift mode
            $currentShift = Shift::where('user_id', $request->user()->id)
                ->whereNull('closed_at')
                ->orderBy('id', 'desc')
                ->first();
            $shiftId = $currentShift?->id;
        } else {
            // currentShift was passed as parameter
            $shiftId = $currentShift?->id;
        }

        $saleData = [
            'warehouse_id' => $validatedData['warehouse_id'] ?? $request->user()->warehouse_id ?? 1,
            'client_id' => $validatedData['client_id'] ?? null,
            'user_id' => $request->user()->id,
            'shift_id' => $shiftId, // Can be null for days mode
            'sale_date' => $validatedData['sale_date'],
        ];

        return Sale::create($saleData);
    }

    private function processSaleItems(array $validatedData, Sale $saleHeader, &$newTotalSaleAmount)
    {
        $warehouseId = $saleHeader->warehouse_id;

        // --- MERGE DUPLICATE PRODUCTS ---
        $mergedItems = [];
        foreach ($validatedData['items'] as $item) {
            $productId = $item['product_id'];
            if (isset($mergedItems[$productId])) {
                // If price is different, we could either average it or keep one. 
                // Usually in POS, if you add the same item twice, it's just more quantity.
                // We'll add the quantity.
                $mergedItems[$productId]['quantity'] += $item['quantity'];
                // We'll keep the last unit_price for the merged item (or we could use the first).
                $mergedItems[$productId]['unit_price'] = $item['unit_price'];
            } else {
                $mergedItems[$productId] = $item;
            }
        }

        // 2. Process Sale Items and Stock (Total stock check, FIFO allocation or direct stock decrement)
        foreach ($mergedItems as $itemData) {
            $product = Product::findOrFail($itemData['product_id']);
            $requestedSellableUnits = (int) $itemData['quantity'];
            $unitSalePrice = (float) $itemData['unit_price'];

            // --- Stock check (product_warehouse SSOT) ---
            // Refresh product to get latest status (though countStock does a fresh query usually)
            $availableStock = $product->countStock($warehouseId);

            // Check if product has any stock
            if ($availableStock <= 0) {
                $msg = "Product '{$product->name}' is out of stock in Warehouse {$warehouseId}. Available quantity: 0";

                $pendingStock = $product->countPendingStock($warehouseId);
                if ($pendingStock > 0) {
                    $msg .= " (Found {$pendingStock} units in PENDING purchases. mark purchase as RECEIVED.)";
                }

                throw ValidationException::withMessages([
                    'items' => [$msg]
                ]);
            }

            if ($availableStock < $requestedSellableUnits) {
                $msg = "Insufficient stock for product '{$product->name}' in Warehouse {$warehouseId}. Available: {$availableStock} {$product->sellable_unit_name_plural}, Requested: {$requestedSellableUnits}.";

                $pendingStock = $product->countPendingStock($warehouseId);
                if ($pendingStock > 0) {
                    $msg .= " (Note: {$pendingStock} additional units are in PENDING purchases.)";
                }

                throw ValidationException::withMessages([
                    'items' => [$msg]
                ]);
            }

            // Stock is in product_warehouse only. Optionally pick a batch for cost/expiry reference.
            $refBatch = PurchaseItem::where('product_id', $product->id)
                ->whereHas('purchase', function ($q) use ($warehouseId) {
                    $q->where('warehouse_id', $warehouseId);
                })
                ->orderBy('expiry_date', 'asc')
                ->orderBy('created_at', 'asc')
                ->first();

            $costPriceAtSale = $refBatch ? ($refBatch->cost_per_sellable_unit ?? 0) : 0;
            $batchNumberSold = $refBatch ? $refBatch->batch_number : null;
            $purchaseItemId = $refBatch ? $refBatch->id : null;

            $saleHeader->items()->create([
                'product_id' => $product->id,
                'purchase_item_id' => $purchaseItemId,
                'batch_number_sold' => $batchNumberSold,
                'quantity' => $requestedSellableUnits,
                'unit_price' => $unitSalePrice,
                'cost_price_at_sale' => $costPriceAtSale,
                'total_price' => $requestedSellableUnits * $unitSalePrice,
            ]);

            $product->decrementWarehouseStock($warehouseId, $requestedSellableUnits);
            $newTotalSaleAmount += $requestedSellableUnits * $unitSalePrice;
        } // End loop through merged items

    }
    private function createPaymentRecords(array $validatedData, Sale $saleHeader, Request $request)
    {
        if (!empty($validatedData['payments'])) {
            foreach ($validatedData['payments'] as $paymentData) {
                if (isset($paymentData['amount']) && is_numeric($paymentData['amount']) && (float) $paymentData['amount'] > 0) {
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
            'items.product:id,name,sku,scientific_name,stock_alert_level,sellable_unit_id',
            'items.product.sellableUnit:id,name',
            'items.product.purchaseItemsWithStock:id,product_id,batch_number,expiry_date,sale_price,unit_cost',
            'items.purchaseItemBatch:id,batch_number,unit_cost,expiry_date', // Load batch info for each sale item
            'payments.user:id,name,username' // Load user relationship for payments to get user_name
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
        ]);

        $sale->update($validatedData);

        $sale->load([
            'client:id,name',
            'user:id,name',
            'items',
            'items.product:id,name,sku,scientific_name,stock_alert_level,sellable_unit_id',
            'items.product.sellableUnit:id,name',
            'items.product.purchaseItemsWithStock:id,product_id,batch_number,expiry_date,sale_price,unit_cost',
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
            // Note: status column no longer exists, so we can't check it
            // Sales can be deleted if they have no payments or if business logic allows
            // For now, allow deletion but warn if there are payments
            if ($sale->payments()->count() > 0) {
                return response()->json([
                    'message' => 'Cannot delete sales with payments. Consider creating a return instead.'
                ], Response::HTTP_FORBIDDEN);
            }

            DB::transaction(function () use ($sale) {
                // Return stock to inventory for each sale item
                foreach ($sale->items as $saleItem) {
                    $product = $saleItem->product;
                    $quantity = $saleItem->quantity;

                    // Return stock to warehouse (SSOT)
                    if ($sale->warehouse_id) {
                        $product->incrementWarehouseStock($sale->warehouse_id, $quantity);
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
            'payments.*.method' => 'nullable|string|in:cash,bankak,fawry,ocash',
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
            });

            // Reload the sale with payments
            $sale->load([
                'client:id,name',
                'user:id,name',
                'items',
                'items.product:id,name,sku,scientific_name,stock_alert_level,sellable_unit_id',
                'items.product.sellableUnit:id,name',
                'items.product.purchaseItemsWithStock:id,product_id,batch_number,expiry_date,sale_price,unit_cost',
                'payments.user:id,name,username' // Load user relationship for payments
            ]);

            $message = empty($validatedData['payments']) ? 'All payments cleared successfully' : 'Payment(s) added successfully';

            return response()->json([
                'message' => $message,
                'sale' => new SaleResource($sale)
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
            });

            // Reload the sale with payments
            $sale->load([
                'client:id,name',
                'user:id,name',
                'items',
                'items.product:id,name,sku,scientific_name,stock_alert_level,sellable_unit_id',
                'items.product.sellableUnit:id,name',
                'items.product.purchaseItemsWithStock:id,product_id,batch_number,expiry_date,sale_price,unit_cost',
                'payments.user:id,name,username' // Load user relationship for payments
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

    public function addSinglePayment(Request $request, Sale $sale)
    {
        $validatedData = $request->validate([
            'method' => 'required|string|in:cash,bankak,fawry,ocash',
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

    public function updateDiscount(Request $request, Sale $sale)
    {
        $validated = $request->validate([
            'discount_amount' => 'required|numeric|min:0',
            'discount_type' => ['required', Rule::in(['percentage', 'fixed'])],
        ]);

        try {
            DB::transaction(function () use ($validated, $sale) {
                // Always compute subtotal from items to ensure accuracy
                $subtotal = (float) $sale->items()->sum('total_price');

                // Compute discount value (absolute) from input
                $discountValue = 0.0;
                if ($validated['discount_type'] === 'percentage') {
                    // Cap percentage to 100
                    if ($validated['discount_amount'] > 100) {
                        throw ValidationException::withMessages([
                            'discount_amount' => ['Discount percentage cannot exceed 100%']
                        ]);
                    }
                    $discountValue = $subtotal * ((float) $validated['discount_amount'] / 100);
                } else { // fixed
                    $discountValue = min((float) $validated['discount_amount'], $subtotal);
                }

                // Ensure discount does not reduce total below amount already paid
                $paidAmount = (float) $sale->payments()->sum('amount');
                $maxDiscountAllowed = max(0.0, $subtotal - $paidAmount);
                if ($discountValue > $maxDiscountAllowed) {
                    throw ValidationException::withMessages([
                        'discount_amount' => [
                            "Discount cannot exceed remaining due. Max allowed: {$maxDiscountAllowed}"
                        ]
                    ]);
                }

                $totalAfterDiscount = $subtotal - $discountValue;

                // discount_amount column dropped; discount not persisted (due = items total - payments)
            });

            // Reload relevant relations for client consumption
            $sale->load([
                'client:id,name',
                'user:id,name',
                'items.product:id,name,sku,scientific_name,stock_alert_level,sellable_unit_id',
                'items.product.purchaseItemsWithStock:id,product_id,batch_number,expiry_date,sale_price,unit_cost',
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

    public function deleteSinglePayment(Sale $sale, $paymentId)
    {
        try {
            DB::transaction(function () use ($sale, $paymentId) {
                // Find and delete the specific payment
                $payment = $sale->payments()->findOrFail($paymentId);
                $payment->delete();
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
                    // Increase quantity of existing item
                    $warehouseId = $sale->warehouse_id ?? 1;
                    $oldQuantity = $existingSaleItem->quantity;
                    $additionalQuantity = $validatedData['quantity'];
                    $newQuantity = $oldQuantity + $additionalQuantity;

                    // Check stock availability for the additional quantity
                    $availableInWarehouse = $product->countStock($warehouseId);
                    $currentInOtherItems = $sale->items()
                        ->where('product_id', $product->id)
                        ->where('id', '!=', $existingSaleItem->id)
                        ->sum('quantity');
                    $availableForThisItem = $availableInWarehouse - $currentInOtherItems;

                    if ($availableForThisItem < $newQuantity) {
                        throw ValidationException::withMessages([
                            'quantity' => "Insufficient stock for '{$product->name}'. Available in warehouse: {$availableForThisItem}, requested total: {$newQuantity}"
                        ]);
                    }

                    // Decrement warehouse stock by the additional quantity
                    $product->decrementWarehouseStock($warehouseId, $additionalQuantity);

                    // Update unit price if provided (otherwise keep existing)
                    $resolvedUnitPrice = (float) ($validatedData['unit_price'] ?? 0);
                    if ($resolvedUnitPrice > 0) {
                        $existingSaleItem->unit_price = $resolvedUnitPrice;
                    } else {
                        $resolvedUnitPrice = (float) $existingSaleItem->unit_price;
                    }

                    // Update quantity and total price
                    $existingSaleItem->quantity = $newQuantity;
                    $existingSaleItem->total_price = $newQuantity * $resolvedUnitPrice;
                    $existingSaleItem->save();

                    $newTotal = (float) $sale->items()->sum('total_price');
                    $paidAmount = (float) $sale->payments()->sum('amount');
                    $newDueAmount = max(0, $newTotal - $paidAmount);

                    return [
                        'sale_items' => [$existingSaleItem],
                        'new_total' => $newTotal,
                        'new_due_amount' => $newDueAmount,
                        'quantity_increased' => true
                    ];
                }

                $warehouseId = $sale->warehouse_id ?? 1;
                $availableInWarehouse = $product->countStock($warehouseId);
                $currentQuantityInThisSale = $sale->items()->where('product_id', $product->id)->sum('quantity');
                $availableForThisAdd = $availableInWarehouse - $currentQuantityInThisSale;

                if ($availableForThisAdd <= 0) {
                    throw ValidationException::withMessages([
                        'product_id' => "Product '{$product->name}' is out of stock in warehouse. Available: 0"
                    ]);
                }
                if ($validatedData['quantity'] > $availableForThisAdd) {
                    throw ValidationException::withMessages([
                        'quantity' => "Insufficient stock. Available: {$availableForThisAdd}, Requested: {$validatedData['quantity']}"
                    ]);
                }

                $resolvedUnitPrice = (float) ($validatedData['unit_price'] ?? 0);
                if ($resolvedUnitPrice <= 0) {
                    $resolvedUnitPrice = $product->last_sale_price_per_sellable_unit > 0
                        ? (float) $product->last_sale_price_per_sellable_unit
                        : 0;
                }

                $saleItem = $sale->items()->create([
                    'product_id' => $product->id,
                    'purchase_item_id' => null,
                    'batch_number_sold' => null,
                    'quantity' => $validatedData['quantity'],
                    'unit_price' => $resolvedUnitPrice,
                    'total_price' => $validatedData['quantity'] * $resolvedUnitPrice,
                    'cost_price_at_sale' => 0,
                ]);
                $saleItems = [$saleItem];

                $product->decrementWarehouseStock($warehouseId, $validatedData['quantity']);

                $newTotal = (float) $sale->items()->sum('total_price');
                $paidAmount = (float) $sale->payments()->sum('amount');
                $newDueAmount = max(0, $newTotal - $paidAmount);

                return [
                    'sale_items' => $saleItems,
                    'new_total' => $newTotal,
                    'new_due_amount' => $newDueAmount
                ];
            });

            // Load the updated sale with items
            $sale->load([
                'client:id,name',
                'user:id,name',
                'items.product:id,name,sku,scientific_name,stock_alert_level,sellable_unit_id',
                'items.product.purchaseItemsWithStock:id,product_id,batch_number,expiry_date,sale_price,unit_cost',
                'items.purchaseItemBatch:id,batch_number,unit_cost',
                'payments.user:id,name'
            ]);

            // Check if quantity was increased for existing item
            if (isset($result['quantity_increased']) && $result['quantity_increased']) {
                return response()->json([
                    'message' => 'Sale item quantity increased successfully',
                    'sale' => new SaleResource($sale),
                    'added_items' => SaleItemResource::collection($result['sale_items'])
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
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            $payload = [
                'message' => 'Failed to add sale item. Please try again.',
                'error' => $e->getMessage()
            ];
            if (config('app.debug')) {
                $payload['file'] = $e->getFile();
                $payload['line'] = $e->getLine();
            }
            return response()->json($payload, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function updateSaleItem(Request $request, Sale $sale, $saleItemId)
    {
        $validatedData = $request->validate([
            'quantity' => 'required|integer|min:1',
            'unit_price' => 'required|numeric|min:0',
            'purchase_item_id' => 'nullable|integer|exists:purchase_items,id',
        ]);

        try {
            $result = DB::transaction(function () use ($validatedData, $sale, $saleItemId) {
                $saleItem = $sale->items()->findOrFail($saleItemId);
                $product = $saleItem->product;
                $batch = $saleItem->purchaseItemBatch; // May be null when no specific batch was used

                $oldQuantity = $saleItem->quantity;
                $newQuantity = $validatedData['quantity'];
                $quantityDifference = $newQuantity - $oldQuantity;

                $warehouseId = $sale->warehouse_id ?? 1;
                if ($quantityDifference > 0) {
                    $availableInWarehouse = $product->countStock($warehouseId);
                    $currentInOtherItems = $sale->items()
                        ->where('product_id', $product->id)
                        ->where('id', '!=', $saleItem->id)
                        ->sum('quantity');
                    $availableForThisItem = $availableInWarehouse - $currentInOtherItems;
                    if ($availableForThisItem < $newQuantity) {
                        throw ValidationException::withMessages([
                            'quantity' => "Insufficient stock for '{$product->name}'. Available in warehouse: {$availableForThisItem}, requested: {$newQuantity}"
                        ]);
                    }
                }

                if ($quantityDifference !== 0) {
                    if ($quantityDifference > 0) {
                        $product->decrementWarehouseStock($warehouseId, $quantityDifference);
                    } else {
                        $product->incrementWarehouseStock($warehouseId, abs($quantityDifference));
                    }
                }

                // Optional: update batch reference (cost/expiry only; no batch quantity)
                if (array_key_exists('purchase_item_id', $validatedData)) {
                    $newBatchId = $validatedData['purchase_item_id'];
                    if ($newBatchId) {
                        $newBatch = PurchaseItem::whereKey($newBatchId)
                            ->where('product_id', $product->id)
                            ->whereHas('purchase', fn($q) => $q->where('warehouse_id', $warehouseId))
                            ->first();
                        if ($newBatch) {
                            $saleItem->purchase_item_id = $newBatch->id;
                            $saleItem->batch_number_sold = $newBatch->batch_number;
                            $saleItem->cost_price_at_sale = $newBatch->cost_per_sellable_unit ?? $newBatch->unit_cost ?? 0;
                        }
                    } else {
                        $saleItem->purchase_item_id = null;
                        $saleItem->batch_number_sold = null;
                        $saleItem->cost_price_at_sale = 0;
                    }
                }

                // Update the sale item after stock adjustments
                $saleItem->quantity = $newQuantity;
                $saleItem->unit_price = $validatedData['unit_price'];
                $saleItem->total_price = $newQuantity * $validatedData['unit_price'];
                $saleItem->save();
                \Log::info('updateSaleItem: item saved');

                $newSubtotal = (float) $sale->items()->sum('total_price');
                $paidAmount = (float) $sale->payments()->sum('amount');
                $newDueAmount = max(0, $newSubtotal - $paidAmount);
                \Log::info('updateSaleItem: sale header updated');

                return [
                    'updated_item' => $saleItem,
                    'new_total' => $newSubtotal,
                    'new_due_amount' => $newDueAmount
                ];
            });

            // Load the updated sale with items
            $sale->load([
                'client:id,name',
                'user:id,name',
                'items.product:id,name,sku,scientific_name,stock_alert_level,sellable_unit_id',
                'items.product.purchaseItemsWithStock:id,product_id,batch_number,expiry_date,sale_price,unit_cost',
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

                // Return quantity to warehouse (SSOT)
                $warehouseId = $sale->warehouse_id ?? 1;
                $product->incrementWarehouseStock($warehouseId, $saleItem->quantity);

                // Delete the sale item first
                $deletedQuantity = $saleItem->quantity;
                $productName = $product ? $product->name : 'Unknown Product';



                $saleItem->delete();

                // Recalculate sale totals after deletion
                $sale->refresh(); // Refresh to get updated items
                $remainingItems = $sale->items()->get(); // Always fresh from DB



                $newSubtotal = $remainingItems->sum('total_price');
                $newTotalAmount = $newSubtotal; // No tax calculation for now

                // Totals are calculated from items/payments, not stored in DB
                // No need to update sale record

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
                    'remaining_items_count' => $remainingItems->count()
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
            'items.product:id,name,sku,scientific_name', // Product details for each item
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
        $pdf->SetTitle('فاتورة مبيعات - ' . $sale->id);
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
        $pdf->Cell(0, 6, 'رقم الفاتورة: ' . ($invoicePrefix . $sale->id), 0, 1, 'L'); // Align left

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

            $pdf->Cell($w_items[0], $lineHeight, number_format((float) $item->total_price, 0), 'LRB', 0, 'R', $fill);
            $pdf->Cell($w_items[1], $lineHeight, number_format((float) $item->unit_price, 0), 'LRB', 0, 'R', $fill);
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

        $subtotalValue = (float) ($sale->items?->sum('total_price') ?? 0);
        $paidValue = (float) ($sale->payments?->sum('amount') ?? 0);
        $netTotal = $subtotalValue;
        $due = max(0, $netTotal - $paidValue);

        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 9);
        $pdf->Cell($col1Width, 6, 'المجموع الفرعي:', 'LTR', 0, 'L', false);
        $pdf->Cell($col2Width, 6, number_format($subtotalValue, 0), 'TR', 1, 'R', false);

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
                $paymentText .= "  |  المبلغ: " . number_format((float) $payment->amount, 0);
                $paymentText .= "  |  التاريخ: " . Carbon::parse($payment->payment_date)->format('Y-m-d');
                if ($payment->reference_number)
                    $paymentText .= "  |  مرجع: " . $payment->reference_number;
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
        $pdfFileName = 'invoice_' . $sale->id . '_' . now()->format('Ymd') . '.pdf';
        $pdfContent = $pdf->Output($pdfFileName, 'S'); // 'S' returns as string

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "inline; filename=\"{$pdfFileName}\""); // inline to display in browser
        //  ->header('Content-Disposition', "attachment; filename=\"{$pdfFileName}\""); // to force download
    }
    public function downloadThermalInvoicePDF(Request $request, Sale $sale)
    {
        try {
            // Authorization check
            // if ($request->user()->cannot('printThermalInvoice', $sale)) { abort(403); }

            $sale->load([
                'client:id,name', // Load only what's needed for receipt
                'user:id,name',
                'items.product:id,name,sku,scientific_name',
                // No need to load purchaseItemBatch for thermal receipt unless showing batch no.
            ]);

            // --- PDF Setup for Thermal (e.g., 80mm width) ---
            // Dynamic height: base + items + payments + space for barcode at bottom
            $itemsCount = $sale->items?->count() ?? 0;
            $paymentsCount = $sale->payments?->count() ?? 0;
            $pageHeightMm = 125 + (5 * $itemsCount) + (5 * $paymentsCount);

            // Log::info("Generating thermal PDF for Sale {$sale->id}. Height: {$pageHeightMm}mm");

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
                    @$pdf->Image($logoPath, $x + 20, $y, $w, 0, '', '', 'T', false, 300, '', false, false, 0, false, false, false);
                    $pdf->Ln(h: 20);
                } catch (\Throwable $e) {
                    // ignore logo errors on thermal
                }
            }

            $pdf->SetFont('arial', 'B', 10); // Or your preferred Arabic thermal font
            $pdf->MultiCell(0, 5, $companyName, 0, 'C', 0, 1);
            if ($companyPhone) {
                $pdf->SetFont('arial', '', 8);
                $pdf->MultiCell(0, 4, 'الهاتف: ' . $companyPhone, 0, 'C', 0, 1);
            }
            // if ($vatNumber) {
            //     $pdf->MultiCell(0, 4, 'الرقم الضريبي: ' . $vatNumber, 0, 'C', 0, 1);
            // }
            $pdf->Ln(2);

            // --- Invoice Info ---
            $pdf->SetFont('arial', '', 9);
            $pdf->Cell(0, 4, 'فاتورة رقم: S-' . $sale->id, 0, 1, 'R');
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
            $pdf->SetFont('arial', 'B', 9);
            $pdf->Cell(18, 5, 'الإجمالي', 0, 0, 'R'); // Total
            $pdf->Cell(18, 5, 'السعر', 0, 0, 'R');    // Price
            $pdf->Cell(10, 5, 'كمية', 0, 0, 'C');   // Qty
            $pdf->Cell(26, 5, 'الصنف', 0, 1, 'R');    // Item Name (remaining width)
            $pdf->Ln(0.5);
            $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->getPageWidth() - $pdf->GetX(), $pdf->GetY()); // Separator
            $pdf->Ln(0.5);

            // --- Items Loop ---
            $pdf->SetFont('arial', '', 9);
            foreach ($sale->items as $item) {
                $productName = $item->product?->name ?: 'Product N/A';
                // Truncate or wrap product name if too long for thermal width
                if (mb_strlen($productName) > 20) { // Example length check
                    $productName = mb_substr($productName, 0, 18) . '..';
                }

                $itemTotal = number_format((float) $item->total_price, 0);
                $itemPrice = number_format((float) $item->unit_price, 0);
                $itemQty = (string) $item->quantity;

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

            $itemsSubtotal = (float) ($sale->items?->sum('total_price') ?? 0);
            $paidAmount = (float) ($sale->payments?->sum('amount') ?? 0);
            $finalTotal = $itemsSubtotal;
            $due = max(0, $finalTotal - $paidAmount);

            $pdf->SetFont('arial', 'B', 9);
            $pdf->Cell(46, 5, 'الإجمالي الفرعي:', 0, 0, 'R');
            $pdf->Cell(26, 5, number_format($itemsSubtotal, 0), 0, 1, 'R');

            $pdf->SetFont('arial', 'B', 9);
            $pdf->Cell(46, 6, 'الإجمالي النهائي:', 0, 0, 'R');
            $pdf->Cell(26, 6, number_format($finalTotal, 0), 0, 1, 'R');

            $pdf->SetFont('arial', '', 8);
            $pdf->Cell(46, 5, 'المدفوع:', 0, 0, 'R');
            $pdf->Cell(26, 5, number_format($paidAmount, 0), 0, 1, 'R');
            $pdf->SetFont('arial', 'B', 8);
            $pdf->Cell(46, 5, 'المتبقي:', 0, 0, 'R');
            $pdf->Cell(26, 5, number_format($due, 0), 0, 1, 'R');
            $pdf->Ln(1);

            // --- Payment Methods Used ---
            if ($sale->payments && $sale->payments->count() > 0) {
                $pdf->SetFont('arial', 'B', 7);
                $pdf->Cell(0, 4, 'طرق الدفع:', 0, 1, 'R');
                $pdf->SetFont('arial', '', 7);
                foreach ($sale->payments as $payment) {
                    $methodLabel = $payment->method;
                    if (function_exists('config')) {
                        $methodLabel = config('app_settings.payment_methods_ar.' . $payment->method, $payment->method);
                    }
                    $pdf->Cell(46, 4, $methodLabel . ':', 0, 0, 'R');
                    $pdf->Cell(26, 4, number_format((float) $payment->amount, 0), 0, 1, 'R');
                }
                $pdf->Ln(1);
            }


            // --- Footer Message ---
            $pdf->SetFont('arial', '', 7);
            $thermalFooter = $settingsThermal['invoice_thermal_footer'] ?? config('app_settings.invoice_thermal_footer', 'شكراً لزيارتكم!');
            $pdf->MultiCell(0, 4, $thermalFooter, 0, 'C', 0, 1);

            // --- Barcode at bottom (invoice/sale identifier for scanning) ---
            $pdf->Ln(3);
            $barcodeCode = $sale->id;
            $barcodeW = 60;
            $barcodeH = 10;
            $pageW = $pdf->getPageWidth();
            $barcodeX = ($pageW - $barcodeW) / 2;
            $barcodeY = $pdf->GetY();
            $pdf->write1DBarcode("$barcodeCode", 'C128', 50, $barcodeY, $barcodeW, $barcodeH, null, [], 'N');
            $pdf->Ln(2);
            $pdf->SetFont('arial', '', 6);
            $pdf->Cell(0, 3, $barcodeCode, 0, 1, 'C');

            // --- Output PDF ---
            $pdfFileName = 'thermal_invoice_' . $sale->id . '.pdf';
            $pdfContent = $pdf->Output($pdfFileName, 'S'); // 'S' returns as string

            return response($pdfContent, 200)
                ->header('Content-Type', 'application/pdf');
            // No 'Content-Disposition: attachment' - frontend will handle display

        } catch (\Throwable $e) {
            Log::error("Error generating thermal invoice PDF for sale {$sale->id}: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json([
                'message' => 'Failed to generate thermal invoice PDF.',
                'error' => $e->getMessage(), // Show error in dev
            ], 500);
        }
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
            ->with(['payments.user:id,name,username', 'user:id,name']);

        if ($userId) {
            $query->where('user_id', $userId);
        }

        $sales = $query->get();

        // Debug logging
        \Log::info('Calculator API Debug', [
            'date' => $date,
            'user_id' => $userId,
            'total_sales_found' => $sales->count(),
            'sales_details' => $sales->map(function ($sale) {
                $totalAmount = (float) ($sale->items?->sum('total_price') ?? 0);
                return [
                    'id' => $sale->id,
                    'total_amount' => $totalAmount,
                    'user_id' => $sale->user_id,
                    'user_name' => $sale->user?->name,
                    'payments_count' => $sale->payments->count(),
                    'payments_sum' => $sale->payments->sum('amount')
                ];
            })
        ]);

        // Calculate total income based on actual payments received
        $totalIncome = $sales->sum(function ($sale) {
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
                'total_amount' => (float) $userSales->sum(function ($sale) {
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

                        $warehouseId = $sale->warehouse_id ?? 1;
                        $availableInWarehouse = $product->countStock($warehouseId);
                        $currentQuantityInThisSale = $sale->items()->where('product_id', $product->id)->sum('quantity');
                        $availableForThisAdd = $availableInWarehouse - $currentQuantityInThisSale;

                        if ($availableForThisAdd <= 0) {
                            $errors[] = "Product '{$product->name}' is out of stock in warehouse. Available: 0";
                            continue;
                        }
                        if ($itemData['quantity'] > $availableForThisAdd) {
                            $errors[] = "Insufficient stock for '{$product->name}'. Available: {$availableForThisAdd}, Requested: {$itemData['quantity']}";
                            continue;
                        }

                        $resolvedUnitPrice = (float) ($itemData['unit_price'] ?? 0);
                        if ($resolvedUnitPrice <= 0) {
                            $resolvedUnitPrice = $product->last_sale_price_per_sellable_unit > 0
                                ? (float) $product->last_sale_price_per_sellable_unit
                                : 0;
                        }

                        $refBatch = PurchaseItem::where('product_id', $product->id)
                            ->whereHas('purchase', fn($q) => $q->where('warehouse_id', $warehouseId))
                            ->orderBy('expiry_date', 'asc')
                            ->orderBy('created_at', 'asc')
                            ->first();
                        $costPriceAtSale = $refBatch ? (float) ($refBatch->cost_per_sellable_unit ?? $refBatch->unit_cost ?? 0) : 0;
                        $batchNumberSold = $refBatch ? $refBatch->batch_number : null;
                        $purchaseItemId = $refBatch ? $refBatch->id : null;

                        $saleItem = $sale->items()->create([
                            'product_id' => $product->id,
                            'purchase_item_id' => $purchaseItemId,
                            'batch_number_sold' => $batchNumberSold,
                            'quantity' => $itemData['quantity'],
                            'unit_price' => $resolvedUnitPrice,
                            'total_price' => $itemData['quantity'] * $resolvedUnitPrice,
                            'cost_price_at_sale' => $costPriceAtSale,
                        ]);
                        $addedItems[] = $saleItem;
                        $totalAdded++;

                        $product->decrementWarehouseStock($warehouseId, $itemData['quantity']);
                    } catch (\Exception $e) {
                        $errors[] = "Failed to add product at index {$index}: " . $e->getMessage();
                    }
                }

                $newTotal = (float) $sale->items()->sum('total_price');
                $paidAmount = (float) $sale->payments()->sum('amount');
                $newDueAmount = max(0, $newTotal - $paidAmount);

                return [
                    'added_items' => $addedItems,
                    'total_added' => $totalAdded,
                    'errors' => $errors,
                    'new_total' => $newTotal,
                    'new_due_amount' => $newDueAmount
                ];
            });

            // Load the updated sale with items
            $sale->load([
                'client:id,name',
                'user:id,name',
                'items.product:id,name,sku,scientific_name,stock_alert_level,sellable_unit_id',
                'items.product.purchaseItemsWithStock:id,product_id,batch_number,expiry_date,sale_price,unit_cost',
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

    /**
     * Generate and download A4 invoice PDF (English, TCPDF)
     */
    public function downloadA4InvoicePdf(Sale $sale)
    {
        $invoiceService = app(\App\Services\InvoicePdfService::class);
        return $invoiceService->downloadInvoice($sale);
    }

    /**
     * View A4 invoice PDF in browser (English, TCPDF)
     */
    public function viewA4InvoicePdf(Sale $sale)
    {
        $invoiceService = app(\App\Services\InvoicePdfService::class);
        return $invoiceService->viewInvoice($sale);
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