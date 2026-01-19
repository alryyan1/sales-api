<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryCount;
use App\Models\InventoryCountItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class InventoryCountController extends Controller
{
    /**
     * Display a listing of inventory counts
     */
    public function index(Request $request)
    {
        $query = InventoryCount::with(['warehouse:id,name', 'user:id,name', 'approvedBy:id,name']);

        // Filter by warehouse
        if ($warehouseId = $request->input('warehouse_id')) {
            $query->byWarehouse($warehouseId);
        }

        // Filter by status
        if ($status = $request->input('status')) {
            $query->byStatus($status);
        }

        // Filter by date range
        if ($startDate = $request->input('start_date')) {
            $query->whereDate('count_date', '>=', $startDate);
        }
        if ($endDate = $request->input('end_date')) {
            $query->whereDate('count_date', '<=', $endDate);
        }

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('notes', 'like', "%{$search}%")
                    ->orWhereHas('warehouse', function ($warehouseQuery) use ($search) {
                        $warehouseQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $counts = $query->latest('count_date')->latest('id')->paginate($request->input('per_page', 15));

        return response()->json($counts);
    }

    /**
     * Store a newly created inventory count
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'count_date' => 'required|date',
            'notes' => 'nullable|string|max:65535',
        ]);

        try {
            $count = InventoryCount::create([
                'warehouse_id' => $validatedData['warehouse_id'],
                'user_id' => $request->user()->id,
                'count_date' => $validatedData['count_date'],
                'notes' => $validatedData['notes'] ?? null,
                'status' => 'draft',
            ]);

            $count->load(['warehouse:id,name', 'user:id,name']);

            return response()->json(['count' => $count], Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            Log::error("Inventory count creation error: " . $e->getMessage());
            return response()->json(['message' => 'Failed to create inventory count.'], 500);
        }
    }

    /**
     * Display the specified inventory count
     */
    public function show(InventoryCount $inventoryCount)
    {
        $inventoryCount->load([
            'warehouse:id,name',
            'user:id,name',
            'approvedBy:id,name',
            'items.product:id,name,sku'
        ]);

        return response()->json(['count' => $inventoryCount]);
    }

    /**
     * Update the specified inventory count
     */
    public function update(Request $request, InventoryCount $inventoryCount)
    {
        // Only allow updating if status is draft or in_progress
        if (!in_array($inventoryCount->status, ['draft', 'in_progress'])) {
            return response()->json(['message' => 'Cannot update count with status: ' . $inventoryCount->status], 403);
        }

        $validatedData = $request->validate([
            'count_date' => 'sometimes|date',
            'notes' => 'nullable|string|max:65535',
            'status' => ['sometimes', Rule::in(['draft', 'in_progress', 'completed'])],
        ]);

        try {
            $inventoryCount->update($validatedData);
            $inventoryCount->load(['warehouse:id,name', 'user:id,name', 'items.product:id,name,sku']);

            return response()->json(['count' => $inventoryCount]);
        } catch (\Throwable $e) {
            Log::error("Inventory count update error: " . $e->getMessage());
            return response()->json(['message' => 'Failed to update inventory count.'], 500);
        }
    }

    /**
     * Remove the specified inventory count
     */
    public function destroy(InventoryCount $inventoryCount)
    {
        // Only allow deletion if status is draft
        if ($inventoryCount->status !== 'draft') {
            return response()->json(['message' => 'Can only delete draft counts.'], 403);
        }

        try {
            $inventoryCount->delete();
            return response()->json(['message' => 'Inventory count deleted successfully.']);
        } catch (\Throwable $e) {
            Log::error("Inventory count deletion error: " . $e->getMessage());
            return response()->json(['message' => 'Failed to delete inventory count.'], 500);
        }
    }

    /**
     * Add an item to the inventory count
     */
    public function addItem(Request $request, InventoryCount $inventoryCount)
    {
        // Only allow adding items if status is draft or in_progress
        if (!in_array($inventoryCount->status, ['draft', 'in_progress'])) {
            return response()->json(['message' => 'Cannot add items to count with status: ' . $inventoryCount->status], 403);
        }

        $validatedData = $request->validate([
            'product_id' => 'required|exists:products,id',
            'actual_quantity' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:65535',
        ]);

        try {
            // Get expected quantity from warehouse
            $product = Product::findOrFail($validatedData['product_id']);
            $pivot = $product->warehouses()->where('warehouse_id', $inventoryCount->warehouse_id)->first();
            $expectedQuantity = $pivot ? $pivot->pivot->quantity : 0;

            $item = $inventoryCount->items()->create([
                'product_id' => $validatedData['product_id'],
                'expected_quantity' => $expectedQuantity,
                'actual_quantity' => $validatedData['actual_quantity'] ?? null,
                'notes' => $validatedData['notes'] ?? null,
            ]);

            $item->load('product:id,name,sku');

            return response()->json(['item' => $item], Response::HTTP_CREATED);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                return response()->json(['message' => 'This product is already in the count.'], 409);
            }
            Log::error("Add count item error: " . $e->getMessage());
            return response()->json(['message' => 'Failed to add item to count.'], 500);
        } catch (\Throwable $e) {
            Log::error("Add count item error: " . $e->getMessage());
            return response()->json(['message' => 'Failed to add item to count.'], 500);
        }
    }

    /**
     * Update a count item
     */
    public function updateItem(Request $request, InventoryCount $inventoryCount, InventoryCountItem $item)
    {
        // Verify item belongs to count
        if ($item->inventory_count_id !== $inventoryCount->id) {
            return response()->json(['message' => 'Item does not belong to this count.'], 404);
        }

        // Only allow updating if status is draft or in_progress
        if (!in_array($inventoryCount->status, ['draft', 'in_progress'])) {
            return response()->json(['message' => 'Cannot update items in count with status: ' . $inventoryCount->status], 403);
        }

        $validatedData = $request->validate([
            'actual_quantity' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:65535',
        ]);

        try {
            $item->update($validatedData);
            $item->load('product:id,name,sku');

            return response()->json(['item' => $item]);
        } catch (\Throwable $e) {
            Log::error("Update count item error: " . $e->getMessage());
            return response()->json(['message' => 'Failed to update item.'], 500);
        }
    }

    /**
     * Remove an item from the count
     */
    public function deleteItem(InventoryCount $inventoryCount, InventoryCountItem $item)
    {
        // Verify item belongs to count
        if ($item->inventory_count_id !== $inventoryCount->id) {
            return response()->json(['message' => 'Item does not belong to this count.'], 404);
        }

        // Only allow deleting if status is draft or in_progress
        if (!in_array($inventoryCount->status, ['draft', 'in_progress'])) {
            return response()->json(['message' => 'Cannot delete items from count with status: ' . $inventoryCount->status], 403);
        }

        try {
            $item->delete();
            return response()->json(['message' => 'Item removed from count successfully.']);
        } catch (\Throwable $e) {
            Log::error("Delete count item error: " . $e->getMessage());
            return response()->json(['message' => 'Failed to delete item.'], 500);
        }
    }

    /**
     * Approve the inventory count and adjust inventory
     */
    public function approve(Request $request, InventoryCount $inventoryCount)
    {
        if ($inventoryCount->status !== 'completed') {
            return response()->json(['message' => 'Can only approve completed counts.'], 403);
        }

        try {
            DB::transaction(function () use ($inventoryCount, $request) {
                $inventoryCount->approve($request->user()->id);
            });

            $inventoryCount->load(['warehouse:id,name', 'user:id,name', 'approvedBy:id,name', 'items.product:id,name,sku']);

            return response()->json(['count' => $inventoryCount, 'message' => 'Inventory count approved and inventory adjusted.']);
        } catch (\Throwable $e) {
            Log::error("Approve count error: " . $e->getMessage());
            return response()->json(['message' => 'Failed to approve count.'], 500);
        }
    }

    /**
     * Reject the inventory count
     */
    public function reject(Request $request, InventoryCount $inventoryCount)
    {
        if ($inventoryCount->status !== 'completed') {
            return response()->json(['message' => 'Can only reject completed counts.'], 403);
        }

        try {
            $inventoryCount->reject($request->user()->id);
            $inventoryCount->load(['warehouse:id,name', 'user:id,name', 'approvedBy:id,name']);

            return response()->json(['count' => $inventoryCount, 'message' => 'Inventory count rejected.']);
        } catch (\Throwable $e) {
            Log::error("Reject count error: " . $e->getMessage());
            return response()->json(['message' => 'Failed to reject count.'], 500);
        }
    }
}
