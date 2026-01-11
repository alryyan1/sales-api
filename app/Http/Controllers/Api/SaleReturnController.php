<?php // app/Http/Controllers/Api/SaleReturnController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SaleReturn;
use App\Models\Sale; // To find original sale
use App\Models\PurchaseItem; // To update batch stock
use App\Models\Product; // To update total product stock if observer not used
use App\Models\SaleReturnItem;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
// Create SaleReturnResource and SaleReturnItemResource
// use App\Http\Resources\SaleReturnResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaleReturnController extends Controller
{
    public function index(Request $request)
    {
        DB::enableQueryLog(); // Enable query log

        $query = SaleReturn::with(['client:id,name', 'originalSale:id,invoice_number']);

        // Add filters
        if ($request->has('start_date')) {
            $query->where('return_date', '>=', $request->input('start_date'));
        }
        if ($request->has('end_date')) {
            $query->where('return_date', '<=', $request->input('end_date'));
        }
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->has('original_sale_id')) {
            $query->where('id', $request->input('original_sale_id'));
        }

        // Execute the query
        $returns = $query->latest()->paginate($request->input('per_page', 15));

        // Get the query log
        $queryLog = DB::getQueryLog();

        // The actual paginated query will be the second-to-last in the log
        // (Last query is usually the count for pagination)
        $mainQuery = $queryLog[count($queryLog) - 2] ?? end($queryLog);

        // Add raw query info to the response
        $response = [
            'data' => $returns->items(),
            'queryyy' => $queryLog,
            'meta' => [
                'current_page' => $returns->currentPage(),
                'last_page' => $returns->lastPage(),
                'per_page' => $returns->perPage(),
                'total' => $returns->total(),
            ],
            'query' => [
                'sql' => $mainQuery['query'],
                'bindings' => $mainQuery['bindings'],
                'time' => $mainQuery['time'] . 'ms',
            ]
        ];

        return response()->json($response);
    }

    // Store a new sale return
    public function store(Request $request)
    {
        // $this->authorize('create', SaleReturn::class); // Policy
        $validatedData = $request->validate([
            'original_sale_id' => 'required|exists:sales,id',
            'return_date' => 'required|date_format:Y-m-d',
            'return_reason' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:65535',
            'status' => ['required', Rule::in(['pending', 'completed', 'cancelled'])],
            'credit_action' => ['required', Rule::in(['refund', 'store_credit', 'none'])],
            'refunded_amount' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.original_sale_item_id' => 'required|exists:sale_items,id,sale_id,' . $request->input('original_sale_id'), // Item must belong to original sale
            'items.*.product_id' => 'required|exists:products,id', // Verify product exists
            'items.*.quantity_returned' => 'required|integer|min:1',
            'items.*.condition' => 'nullable|string|max:100', // e.g., 'resellable', 'damaged'
            // 'items.*.return_to_purchase_item_id' => 'nullable|exists:purchase_items,id', // If specifying which batch to return to
        ]);

        $originalSale = Sale::with('items')->findOrFail($validatedData['original_sale_id']);

        // Pre-validate quantities against original sale items
        foreach ($validatedData['items'] as $index => $returnItemData) {
            $originalSaleItem = $originalSale->items->find($returnItemData['original_sale_item_id']);
            if (!$originalSaleItem || $originalSaleItem->product_id != $returnItemData['product_id']) {
                throw ValidationException::withMessages(["items.{$index}.original_sale_item_id" => 'Invalid original sale item or product mismatch.']);
            }
            // Calculate quantity already returned for this original_sale_item_id
            $alreadyReturnedQty = SaleReturnItem::where('original_sale_item_id', $returnItemData['original_sale_item_id'])
                ->whereHas('saleReturn', fn($q) => $q->where('status', '!=', 'cancelled'))
                ->sum('quantity_returned');
            if ($returnItemData['quantity_returned'] > ($originalSaleItem->quantity - $alreadyReturnedQty)) {
                throw ValidationException::withMessages(["items.{$index}.quantity_returned" => "Return quantity exceeds quantity sold or previously returned for item '{$originalSaleItem->product->name}'. Max returnable: " . ($originalSaleItem->quantity - $alreadyReturnedQty)]);
            }
        }

        try {
            $saleReturn = DB::transaction(function () use ($validatedData, $request, $originalSale) {
                $saleReturnHeader = SaleReturn::create([
                    'original_sale_id' => $originalSale->id,
                    'client_id' => $originalSale->client_id,
                    'user_id' => $request->user()->id,
                    'return_date' => $validatedData['return_date'],
                    'return_reason' => $validatedData['return_reason'],
                    'notes' => $validatedData['notes'],
                    'status' => $validatedData['status'],
                    'credit_action' => $validatedData['credit_action'],
                    'refunded_amount' => $validatedData['credit_action'] === 'refund' ? ($validatedData['refunded_amount'] ?? 0) : 0,
                    'total_returned_amount' => 0, // Calculate below
                ]);

                $calculatedTotalReturnedAmount = 0;

                foreach ($validatedData['items'] as $itemData) {
                    $originalSaleItem = $originalSale->items->find($itemData['original_sale_item_id']);
                    $product = Product::findOrFail($itemData['product_id']); // Or $originalSaleItem->product

                    $quantityReturned = $itemData['quantity_returned'];
                    // Use price from original sale item
                    $unitPrice = $originalSaleItem->unit_price;
                    $totalReturnedValue = $quantityReturned * $unitPrice;
                    $calculatedTotalReturnedAmount += $totalReturnedValue;

                    // Determine which batch to return stock to (complex part)
                    // For simplicity now, we might just increment total stock or require manual batch selection for return.
                    // If original_sale_item has purchase_item_id (batch it came from), try returning there IF condition is 'resellable'.
                    $returnToBatchId = null;
                    if (($itemData['condition'] ?? 'resellable') === 'resellable' && $originalSaleItem->purchase_item_id) {
                        $returnToBatchId = $originalSaleItem->purchase_item_id;
                    }

                    $saleReturnItem = $saleReturnHeader->items()->create([
                        'product_id' => $product->id,
                        'original_sale_item_id' => $originalSaleItem->id,
                        'return_to_purchase_item_id' => $returnToBatchId,
                        'quantity_returned' => $quantityReturned,
                        'unit_price' => $unitPrice,
                        'total_returned_value' => $totalReturnedValue,
                        'condition' => $itemData['condition'] ?? 'resellable',
                    ]);

                    // --- Stock Increment Logic (ONLY IF 'completed' and 'resellable') ---
                    if ($saleReturnHeader->status === 'completed' && ($saleReturnItem->condition === 'resellable')) {
                        if ($returnToBatchId) {
                            $batchToReturnTo = PurchaseItem::lockForUpdate()->find($returnToBatchId);
                            if ($batchToReturnTo) {
                                $batchToReturnTo->increment('remaining_quantity', $quantityReturned);
                                // PurchaseItemObserver will update Product->stock_quantity
                            } else {
                                Log::warning("SaleReturn: Batch ID {$returnToBatchId} not found to return stock for product {$product->id}. Incrementing total stock instead.");
                                $product->increment('stock_quantity', $quantityReturned); // Fallback
                            }
                        } else {
                            // If no specific batch, increment total product stock (less accurate)
                            $product->increment('stock_quantity', $quantityReturned);
                            Log::info("SaleReturn: Incrementing total stock for product {$product->id} by {$quantityReturned}.");
                        }
                    }
                }

                $saleReturnHeader->total_returned_amount = $calculatedTotalReturnedAmount;

                // Create refund payment if credit_action is 'refund'
                if ($validatedData['credit_action'] === 'refund' && ($validatedData['refunded_amount'] ?? 0) > 0) {
                    // Create a negative payment record for the refund
                    $originalSale->payments()->create([
                        'user_id' => $request->user()->id,
                        'method' => 'refund', // Add 'refund' to payment methods if needed
                        'amount' => -$validatedData['refunded_amount'], // Negative amount for refund
                        'payment_date' => $validatedData['return_date'],
                        'reference_number' => 'REFUND-' . $saleReturnHeader->id,
                        'notes' => "Refund for sale return #{$saleReturnHeader->id}",
                    ]);
                }

                // Mark the original sale as returned
                if ($saleReturnHeader->status === 'completed') {
                    $originalSale->is_returned = true;
                    $originalSale->save();
                }

                $saleReturnHeader->save();
                return $saleReturnHeader;
            });

            // $saleReturn->load([...]);
            // return new SaleReturnResource($saleReturn);
            return response()->json(['message' => 'Sale return created successfully', 'sale_return' => $saleReturn], Response::HTTP_CREATED);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            Log::error('Error creating sale return: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'An unexpected error occurred. Please try again later.',
                'error' => [
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                    'stack' => $e->getTraceAsString(),
                ],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function show(SaleReturn $saleReturn) // Route model binding
    {
        // $this->authorize('view', $saleReturn); // Policy
        $saleReturn->load([
            'client:id,name',
            'originalSale:id,invoice_number',
            'user:id,name',
            'items',
            'items.product:id,name,sku',
            'items.originalSaleItem:id,unit_price', // original price
            'items.returnToPurchaseItemBatch:id,batch_number' // batch returned to
        ]);
        // return new SaleReturnResource($saleReturn);
        return response()->json($saleReturn); // Simplified
    }

    // Get total returned amount for a specific date
    public function getTotalReturnedAmount(Request $request)
    {
        $date = $request->input('date', now()->format('Y-m-d'));

        $totalReturnedAmount = SaleReturn::where('return_date', $date)
            ->where('status', 'completed')
            ->sum('total_returned_amount');

        return response()->json([
            'date' => $date,
            'total_returned_amount' => $totalReturnedAmount
        ]);
    }
}
