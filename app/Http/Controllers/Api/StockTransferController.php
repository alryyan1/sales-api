<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockTransferController extends Controller
{
    /**
     * @OA\Tag(
     *     name="Stock Transfers",
     *     description="API Endpoints for managing Stock Transfers between warehouses"
     * )
     */

    /**
     * @OA\Get(
     *     path="/api/stock-transfers",
     *     summary="List stock transfers",
     *     tags={"Stock Transfers"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="from_warehouse_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="to_warehouse_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="product_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="List of transfers")
     * )
     */
    public function index(Request $request)
    {
        $query = StockTransfer::with(['fromWarehouse', 'toWarehouse', 'product', 'user']);

        if ($request->has('from_warehouse_id')) {
            $query->where('from_warehouse_id', $request->from_warehouse_id);
        }
        if ($request->has('to_warehouse_id')) {
            $query->where('to_warehouse_id', $request->to_warehouse_id);
        }
        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        return $query->latest()->paginate(15);
    }

    /**
     * @OA\Post(
     *     path="/api/stock-transfers",
     *     summary="Create stock transfer",
     *     tags={"Stock Transfers"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"from_warehouse_id", "to_warehouse_id", "product_id", "quantity", "transfer_date"},
     *             @OA\Property(property="from_warehouse_id", type="integer"),
     *             @OA\Property(property="to_warehouse_id", type="integer"),
     *             @OA\Property(property="product_id", type="integer"),
     *             @OA\Property(property="quantity", type="number"),
     *             @OA\Property(property="transfer_date", type="string", format="date"),
     *             @OA\Property(property="notes", type="string")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Transfer created"),
     *     @OA\Response(response=422, description="Validation Error or Insufficient Stock")
     * )
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_warehouse_id' => 'required|exists:warehouses,id',
            'to_warehouse_id' => 'required|exists:warehouses,id|different:from_warehouse_id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:0.01',
            'transfer_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $product = Product::findOrFail($validated['product_id']);

        // Check stock in source warehouse
        // We use the product_warehouse pivot table as the source of truth for quantity
        $sourceStock = $product->warehouses()
            ->where('warehouse_id', $validated['from_warehouse_id'])
            ->first();

        $currentQty = $sourceStock ? $sourceStock->pivot->quantity : 0;

        if ($currentQty < $validated['quantity']) {
            throw ValidationException::withMessages([
                'quantity' => ["Insufficient stock in source warehouse. Available: {$currentQty}"],
            ]);
        }

        DB::transaction(function () use ($validated, $request, $currentQty, $product) {
            // Deduct from source
            $product->warehouses()->updateExistingPivot($validated['from_warehouse_id'], [
                'quantity' => $currentQty - $validated['quantity'],
            ]);

            // Add to destination
            $destStock = $product->warehouses()
                ->where('warehouse_id', $validated['to_warehouse_id'])
                ->first();

            if ($destStock) {
                $product->warehouses()->updateExistingPivot($validated['to_warehouse_id'], [
                    'quantity' => $destStock->pivot->quantity + $validated['quantity'],
                ]);
            } else {
                $product->warehouses()->attach($validated['to_warehouse_id'], [
                    'quantity' => $validated['quantity'],
                ]);
            }

            // Record Transfer
            StockTransfer::create([
                'from_warehouse_id' => $validated['from_warehouse_id'],
                'to_warehouse_id' => $validated['to_warehouse_id'],
                'product_id' => $validated['product_id'],
                'quantity' => $validated['quantity'],
                'transfer_date' => $validated['transfer_date'],
                'notes' => $validated['notes'],
                'user_id' => $request->user() ? $request->user()->id : null,
            ]);
        });

        return response()->json(['message' => 'Stock transfer completed successfully'], 201);
    }
}
