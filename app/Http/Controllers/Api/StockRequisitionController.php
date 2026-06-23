<?php // app/Http/Controllers/Api/StockRequisitionController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockRequisition;
use App\Models\StockRequisitionItem;
use App\Models\Product;
use App\Models\PurchaseItem; // For batch stock deduction
use App\Events\StockRequisitionCreated;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Resources\StockRequisitionResource; // Create this resource
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests; // For policies
use Carbon\Carbon;

class StockRequisitionController extends Controller
{
    use AuthorizesRequests; // For using policies

    /**
     * Display a listing of stock requisitions.
     * Filters can include status, requester_user_id, date range.
     */
    public function index(Request $request)
    {
        // $this->authorize('viewAny', StockRequisition::class); // Policy check

        $query = StockRequisition::with([
            'requesterUser:id,name', // User who made the request
            'approvedByUser:id,name', // User who processed it
            // 'items:id,stock_requisition_id,product_id,requested_quantity,issued_quantity', // Basic item info for list
            // 'items.product:id,name,sku' // Basic product info for items
        ]);

        // --- Apply Filters ---
        if ($status = $request->input('status')) {
            // Validate status if it's from a predefined list
            if (in_array($status, ['pending_approval', 'approved', 'rejected', 'partially_issued', 'issued', 'cancelled'])) {
                $query->where('status', $status);
            }
        }

        if ($requesterId = $request->input('requester_user_id')) {
            // If user is not admin and trying to filter by other users, restrict or use policy
            if (!$request->user()->hasRole('admin') && $request->user()->id != $requesterId && !$request->user()->can('view-all-stock-requisitions')) {
                $query->where('requester_user_id', $request->user()->id); // Force to own requests
            } else if ($request->user()->can('view-all-stock-requisitions') || $request->user()->hasRole('admin')) {
                $query->where('requester_user_id', $requesterId);
            } else {
                $query->where('requester_user_id', $request->user()->id); // Default to own requests
            }
        } elseif (!$request->user()->hasRole('admin') && !$request->user()->can('view-all-stock-requisitions')) {
            // Non-admins without special permission only see their own requests by default
            $query->where('requester_user_id', $request->user()->id);
        }


        if ($startDate = $request->input('start_date')) {
            $query->whereDate('request_date', '>=', $startDate);
        }
        if ($endDate = $request->input('end_date')) {
            $query->whereDate('request_date', '<=', $endDate);
        }

        if ($search = $request->input('search')) { // Search department/reason or notes
            $query->where(function ($q) use ($search) {
                $q->where('department_or_reason', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $requisitions = $query->latest('request_date')->latest('id')->paginate($request->input('per_page', 15));

        return response()->json([
            'data'         => StockRequisitionResource::collection($requisitions->items()),
            'current_page' => $requisitions->currentPage(),
            'last_page'    => $requisitions->lastPage(),
            'per_page'     => $requisitions->perPage(),
            'total'        => $requisitions->total(),
            'from'         => $requisitions->firstItem(),
            'to'           => $requisitions->lastItem(),
        ]);
    }

    /**
     * Store a newly created stock requisition.
     * (User makes a request for items)
     */
    public function store(Request $request)
    {
        // $this->authorize('create', StockRequisition::class); // Policy check or check 'request-stock' permission
        if ($request->user()->cannot('request-stock')) {
            abort(403, 'You do not have permission to request stock.');
        }

        $validatedData = $request->validate([
            'department_or_reason' => 'required|string|max:255',
            'request_date' => 'required|date_format:Y-m-d',
            'notes' => 'nullable|string|max:65535',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.requested_quantity' => 'required|integer|min:1',
            'items.*.item_notes' => 'nullable|string|max:1000',
        ]);

        try {
            $requisition = DB::transaction(function () use ($validatedData, $request) {
                $header = StockRequisition::create([
                    'requester_user_id' => $request->user()->id,
                    'department_or_reason' => $validatedData['department_or_reason'],
                    'request_date' => $validatedData['request_date'],
                    'notes' => $validatedData['notes'] ?? null,
                    'status' => 'pending_approval', // Initial status
                ]);

                foreach ($validatedData['items'] as $itemData) {
                    $header->items()->create([
                        'product_id' => $itemData['product_id'],
                        'requested_quantity' => $itemData['requested_quantity'],
                        'issued_quantity' => 0, // Initially 0
                        'status' => 'pending',   // Item status
                        'item_notes' => $itemData['item_notes'] ?? null,
                    ]);
                }
                return $header;
            });

            $requisition->load(['requesterUser:id,name', 'items.product:id,name,sku']);

            // Fire event for notifications
            event(new StockRequisitionCreated($requisition, 'created'));

            return response()->json(['stock_requisition' => new StockRequisitionResource($requisition)], Response::HTTP_CREATED);

        } catch (\Throwable $e) {
            Log::error("Stock Requisition creation failed: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json(['message' => 'Failed to create stock requisition. ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified stock requisition.
     */
    public function show(Request $request, StockRequisition $stockRequisition) // Route model binding
    {
        // $this->authorize('view', $stockRequisition); // Policy check
        // Check if user can view this specific requisition (own or if admin/manager)
        if (!$request->user()->hasRole('admin') && !$request->user()->can('view-all-stock-requisitions') && $stockRequisition->requester_user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to view this requisition.');
        }


        $stockRequisition->load([
            'requesterUser:id,name',
            'approvedByUser:id,name',
            'items.product:id,name,sku',
            'items.issuedFromPurchaseItemBatch:id,batch_number,expiry_date',
        ]);
        return new StockRequisitionResource($stockRequisition);
    }

    /**
     * Process a stock requisition (approve/issue items).
     * (Inventory Manager action)
     */
    public function processRequisition(Request $request, StockRequisition $stockRequisition)
    {
        // $this->authorize('process', $stockRequisition); // Policy or 'process-stock-requisitions' permission
        if ($request->user()->cannot('process-stock-requisitions')) {
            abort(403, 'You do not have permission to process stock requisitions.');
        }

        // Prevent processing already completed/cancelled/rejected requisitions
        if (in_array($stockRequisition->status, ['issued', 'rejected', 'cancelled'])) {
            return response()->json(['message' => "This requisition is already '{$stockRequisition->status}' and cannot be processed further."], Response::HTTP_BAD_REQUEST);
        }

        $validatedData = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected', 'partially_issued', 'issued', 'cancelled'])],
            'issue_date' => 'nullable|required_if:status,issued|required_if:status,partially_issued|date_format:Y-m-d',
            'notes' => 'nullable|string|max:65535', // Manager's overall notes
            'items' => 'present|array', // Items must be present, can be empty if rejecting all
            'items.*.id' => 'required|integer|exists:stock_requisition_items,id,stock_requisition_id,' . $stockRequisition->id, // ID of existing StockRequisitionItem
            'items.*.issued_quantity' => 'required|integer|min:0',
            'items.*.issued_from_purchase_item_id' => 'nullable|integer|exists:purchase_items,id', // Batch ID
            'items.*.status' => ['required', Rule::in(['pending', 'issued', 'rejected_item'])],
            'items.*.item_notes' => 'nullable|string|max:1000', // Manager's notes for this item
        ]);

        // --- Stock Validation for Issued Items ---
        $itemStockErrors = [];
        foreach ($validatedData['items'] as $index => $procItemData) {
            if ($procItemData['status'] === 'issued' && $procItemData['issued_quantity'] > 0) {
                $originalReqItem = $stockRequisition->items()->find($procItemData['id']);
                if ($procItemData['issued_quantity'] > $originalReqItem->requested_quantity) {
                    $itemStockErrors["items.{$index}.issued_quantity"] = ["Issued quantity cannot exceed requested quantity ({$originalReqItem->requested_quantity})."];
                }

                if (empty($procItemData['issued_from_purchase_item_id'])) {
                    $itemStockErrors["items.{$index}.issued_from_purchase_item_id"] = ["A batch must be selected to issue stock (for warehouse reference)."];
                } else {
                    $batch = PurchaseItem::with('purchase')->find($procItemData['issued_from_purchase_item_id']);
                    if (!$batch || $batch->product_id !== $originalReqItem->product_id) {
                        $itemStockErrors["items.{$index}.issued_from_purchase_item_id"] = ["Invalid batch selected for product."];
                    } else {
                        $warehouseId = $batch->purchase->warehouse_id ?? null;
                        if (!$warehouseId) {
                            $itemStockErrors["items.{$index}.issued_from_purchase_item_id"] = ["Selected batch has no warehouse."];
                        } else {
                            $product = Product::find($originalReqItem->product_id);
                            $available = $product ? $product->countStock($warehouseId) : 0;
                            if ($available < $procItemData['issued_quantity']) {
                                $itemStockErrors["items.{$index}.issued_quantity"] = ["Insufficient stock in warehouse for '{$originalReqItem->product->name}'. Available: {$available}, requested: {$procItemData['issued_quantity']}."];
                            }
                        }
                    }
                }
            }
        }
        if (!empty($itemStockErrors)) {
            throw ValidationException::withMessages($itemStockErrors);
        }
        // --- End Stock Validation ---


        try {
            $processedRequisition = DB::transaction(function () use ($validatedData, $request, $stockRequisition) {
                // Update each StockRequisitionItem
                foreach ($validatedData['items'] as $procItemData) {
                    $itemToUpdate = StockRequisitionItem::find($procItemData['id']);
                    if (!$itemToUpdate)
                        continue; // Should not happen due to validation

                    $itemToUpdate->status = $procItemData['status'];
                    $itemToUpdate->item_notes = $procItemData['item_notes'] ?? $itemToUpdate->item_notes; // Keep old if not provided

                    if ($procItemData['status'] === 'issued' && $procItemData['issued_quantity'] > 0) {
                        $batch = PurchaseItem::with('purchase')->find($procItemData['issued_from_purchase_item_id']);
                        if (!$batch || $batch->product_id !== $itemToUpdate->product_id) {
                            throw ValidationException::withMessages(["items" => ["Invalid or missing batch for an issued item."]]);
                        }
                        $warehouseId = $batch->purchase->warehouse_id ?? null;
                        if (!$warehouseId) {
                            throw ValidationException::withMessages(["items" => ["Selected batch has no warehouse."]]);
                        }
                        $product = Product::find($itemToUpdate->product_id);
                        $available = $product ? $product->countStock($warehouseId) : 0;
                        if ($available < $procItemData['issued_quantity']) {
                            throw ValidationException::withMessages(["items" => ["Stock became unavailable for an item during processing."]]);
                        }

                        $itemToUpdate->issued_quantity = $procItemData['issued_quantity'];
                        $itemToUpdate->issued_from_purchase_item_id = $batch->id;
                        $itemToUpdate->issued_batch_number = $batch->batch_number;

                        $product->decrementWarehouseStock($warehouseId, $procItemData['issued_quantity']);
                        Log::info("Stock requisition {$stockRequisition->id}, Item {$itemToUpdate->id}: Issued {$itemToUpdate->issued_quantity} of Product {$itemToUpdate->product_id} from warehouse {$warehouseId} (batch ref: {$batch->batch_number}).");
                    } elseif ($procItemData['status'] === 'rejected_item') {
                        // If an item was previously issued and now rejected, stock should be reversed.
                        // This part is complex if allowing re-processing. For now, assume initial processing.
                        $itemToUpdate->issued_quantity = 0; // Ensure issued is 0 if rejected
                        $itemToUpdate->issued_from_purchase_item_id = null;
                        $itemToUpdate->issued_batch_number = null;
                    }
                    $itemToUpdate->save();
                }

                // Update Requisition Header
                $stockRequisition->status = $validatedData['status'];
                $stockRequisition->approved_by_user_id = $request->user()->id;
                if (in_array($validatedData['status'], ['issued', 'partially_issued'])) {
                    $stockRequisition->issue_date = $validatedData['issue_date'] ?? Carbon::today()->toDateString();
                }
                $stockRequisition->notes = $validatedData['notes'] ?? $stockRequisition->notes; // Manager's overall notes
                $stockRequisition->save();

                return $stockRequisition;
            });

            $processedRequisition->load(['requesterUser:id,name', 'approvedByUser:id,name', 'items.product:id,name,sku', 'items.issuedFromPurchaseItemBatch:id,batch_number']);

            // Fire event for notifications based on status
            $action = match ($processedRequisition->status) {
                'approved' => 'approved',
                'rejected' => 'rejected',
                'issued', 'partially_issued' => 'fulfilled',
                default => 'updated',
            };
            event(new StockRequisitionCreated($processedRequisition, $action));

            return response()->json(['message' => 'Stock requisition processed successfully.', 'stock_requisition' => new StockRequisitionResource($processedRequisition)]);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            Log::error("Stock Requisition processing failed: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json(['message' => 'Failed to process requisition. ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Issue stock: supports full or partial issuance per item.
     * Pass optional `items` array with {item_id, issued_quantity} to override per-item quantities.
     * If omitted, issues each item at its full requested_quantity.
     */
    public function issueAll(Request $request, StockRequisition $stockRequisition)
    {
        if ($request->user()->cannot('process-stock-requisitions')) {
            abort(403, 'You do not have permission to process stock requisitions.');
        }

        if (!in_array($stockRequisition->status, ['pending_approval', 'approved', 'partially_issued'])) {
            return response()->json(['message' => "لا يمكن صرف هذا الطلب لأن حالته '{$stockRequisition->status}'."], Response::HTTP_BAD_REQUEST);
        }

        $validated = $request->validate([
            'notes'                   => 'nullable|string|max:65535',
            'items'                   => 'nullable|array',
            'items.*.item_id'         => 'required_with:items|integer|exists:stock_requisition_items,id',
            'items.*.issued_quantity' => 'required_with:items|integer|min:0',
        ]);

        $stockRequisition->load(['items.product.warehouses']);

        // Build quantity map: item_id => issued_quantity
        $qtyMap = [];
        foreach ($validated['items'] ?? [] as $itemData) {
            $qtyMap[$itemData['item_id']] = $itemData['issued_quantity'];
        }

        // Validate stock availability before any changes
        foreach ($stockRequisition->items as $item) {
            $qtyToIssue = $qtyMap[$item->id] ?? $item->requested_quantity;
            if ($qtyToIssue <= 0) continue;

            $product = $item->product;
            $totalAvailable = $product ? $product->countStock() : 0;
            if ($totalAvailable < $qtyToIssue) {
                return response()->json([
                    'message' => "المخزون غير كافٍ للمنتج: {$product->name}. المتاح: {$totalAvailable}, المطلوب للصرف: {$qtyToIssue}."
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        try {
            DB::transaction(function () use ($stockRequisition, $request, $validated, $qtyMap) {
                $allFullyIssued = true;

                foreach ($stockRequisition->items as $item) {
                    $qtyToIssue = $qtyMap[$item->id] ?? $item->requested_quantity;

                    if ($qtyToIssue < $item->requested_quantity) {
                        $allFullyIssued = false;
                    }

                    if ($qtyToIssue > 0) {
                        $bestWarehouse = $item->product->warehouses()
                            ->orderByPivot('quantity', 'desc')
                            ->first();

                        if ($bestWarehouse) {
                            $item->product->decrementWarehouseStock($bestWarehouse->id, $qtyToIssue);
                        }

                        $item->update([
                            'issued_quantity' => $qtyToIssue,
                            'warehouse_id'    => $bestWarehouse?->id,
                            'status'          => $qtyToIssue >= $item->requested_quantity ? 'issued' : 'partial',
                        ]);
                    }
                }

                $stockRequisition->update([
                    'status'              => $allFullyIssued ? 'issued' : 'partially_issued',
                    'approved_by_user_id' => $request->user()->id,
                    'issue_date'          => Carbon::today()->toDateString(),
                    'notes'               => $validated['notes'] ?? $stockRequisition->notes,
                ]);
            });

            $stockRequisition->load(['requesterUser:id,name', 'approvedByUser:id,name', 'items.product:id,name,sku']);

            return response()->json([
                'message'           => 'تم صرف الطلب بنجاح.',
                'stock_requisition' => new StockRequisitionResource($stockRequisition),
            ]);
        } catch (\Throwable $e) {
            Log::error("Stock Requisition issue-all failed: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json(['message' => 'فشل صرف الطلب. ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Cancel a pending stock requisition (by the requester themselves).
     */
    public function cancelRequisition(Request $request, StockRequisition $stockRequisition)
    {
        // Only the requester (or admin) can cancel their own request
        if ($stockRequisition->requester_user_id !== $request->user()->id && !$request->user()->hasRole(['admin', 'ادمن'])) {
            abort(403, 'لا يمكنك إلغاء طلب لم تقم بإنشائه.');
        }

        if ($stockRequisition->status !== 'pending_approval') {
            return response()->json(['message' => "لا يمكن إلغاء هذا الطلب لأن حالته '{$stockRequisition->status}'."], Response::HTTP_BAD_REQUEST);
        }

        $stockRequisition->update(['status' => 'cancelled']);

        return response()->json(['message' => 'تم إلغاء الطلب.']);
    }

    /**
     * Reject a pending stock requisition.
     */
    public function rejectRequisition(Request $request, StockRequisition $stockRequisition)
    {
        if ($request->user()->cannot('process-stock-requisitions')) {
            abort(403, 'You do not have permission to process stock requisitions.');
        }

        if (!in_array($stockRequisition->status, ['pending_approval', 'approved'])) {
            return response()->json(['message' => "لا يمكن رفض هذا الطلب لأن حالته '{$stockRequisition->status}'."], Response::HTTP_BAD_REQUEST);
        }

        $validatedData = $request->validate([
            'notes' => 'nullable|string|max:65535',
        ]);

        $stockRequisition->update([
            'status' => 'rejected',
            'approved_by_user_id' => $request->user()->id,
            'notes' => $validatedData['notes'] ?? $stockRequisition->notes,
        ]);

        $stockRequisition->load(['requesterUser:id,name', 'approvedByUser:id,name', 'items.product:id,name,sku']);

        return response()->json([
            'message' => 'تم رفض الطلب.',
            'stock_requisition' => new StockRequisitionResource($stockRequisition),
        ]);
    }
}