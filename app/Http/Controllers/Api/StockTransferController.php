<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockTransferController extends Controller
{
    public function index(Request $request)
    {
        $query = StockTransfer::with([
            'fromWarehouse',
            'toWarehouse',
            'user',
            'items.product',
        ]);

        if ($request->has('from_warehouse_id')) {
            $query->where('from_warehouse_id', $request->from_warehouse_id);
        }
        if ($request->has('to_warehouse_id')) {
            $query->where('to_warehouse_id', $request->to_warehouse_id);
        }
        if ($request->has('product_id')) {
            $query->whereHas('items', fn($q) => $q->where('product_id', $request->product_id));
        }

        return $query->latest()->paginate(15);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_warehouse_id'   => 'required|exists:warehouses,id',
            'to_warehouse_id'     => 'required|exists:warehouses,id|different:from_warehouse_id',
            'transfer_date'       => 'required|date',
            'notes'               => 'nullable|string',
            'items'               => 'required|array|min:1',
            'items.*.product_id'  => 'required|exists:products,id',
            'items.*.quantity'    => 'required|numeric|min:0.01',
        ]);

        // Check stock availability for every item before making any changes
        $stockChecks = [];
        foreach ($validated['items'] as $index => $item) {
            $product = Product::findOrFail($item['product_id']);
            $sourceStock = $product->warehouses()
                ->where('warehouse_id', $validated['from_warehouse_id'])
                ->first();

            $currentQty = $sourceStock ? (float) $sourceStock->pivot->quantity : 0;

            if ($currentQty < $item['quantity']) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => [
                        "مخزون غير كافٍ للمنتج \"{$product->name}\". المتاح: {$currentQty}",
                    ],
                ]);
            }

            $stockChecks[] = [
                'product'    => $product,
                'currentQty' => $currentQty,
                'quantity'   => (float) $item['quantity'],
            ];
        }

        DB::transaction(function () use ($validated, $request, $stockChecks) {
            $transfer = StockTransfer::create([
                'from_warehouse_id' => $validated['from_warehouse_id'],
                'to_warehouse_id'   => $validated['to_warehouse_id'],
                'transfer_date'     => $validated['transfer_date'],
                'notes'             => $validated['notes'] ?? null,
                'user_id'           => $request->user()?->id,
            ]);

            foreach ($stockChecks as $check) {
                /** @var Product $product */
                $product    = $check['product'];
                $qty        = $check['quantity'];
                $currentQty = $check['currentQty'];

                // Deduct from source warehouse
                $product->warehouses()->updateExistingPivot($validated['from_warehouse_id'], [
                    'quantity' => $currentQty - $qty,
                ]);

                // Add to destination warehouse
                $destStock = $product->warehouses()
                    ->where('warehouse_id', $validated['to_warehouse_id'])
                    ->first();

                if ($destStock) {
                    $product->warehouses()->updateExistingPivot($validated['to_warehouse_id'], [
                        'quantity' => $destStock->pivot->quantity + $qty,
                    ]);
                } else {
                    $product->warehouses()->attach($validated['to_warehouse_id'], [
                        'quantity' => $qty,
                    ]);
                }

                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id'        => $product->id,
                    'quantity'          => $qty,
                ]);
            }
        });

        return response()->json(['message' => 'تم تحويل المخزون بنجاح'], 201);
    }
}
