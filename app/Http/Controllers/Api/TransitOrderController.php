<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransitOrderResource;
use App\Models\Product;
use App\Models\TransitOrder;
use App\Models\TransitOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransitOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = TransitOrder::with(['warehouse', 'supplier', 'user', 'items.product']);

        if ($request->has('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }
        if ($request->has('product_id')) {
            $query->whereHas('items', fn($q) => $q->where('product_id', $request->product_id));
        }

        $transitOrders = $query->latest()->paginate(15);

        $transitOrders->getCollection()->each(function (TransitOrder $order) {
            $order->items->each(function (TransitOrderItem $item) use ($order) {
                if ($item->product) {
                    $item->current_stock_at_warehouse = $item->product->getWarehouseStock($order->warehouse_id);
                }
            });
        });

        return $transitOrders;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'order_date' => 'nullable|date',
            'eta_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
        ]);

        $transitOrder = DB::transaction(function () use ($validated, $request) {
            $order = TransitOrder::create([
                'warehouse_id' => $validated['warehouse_id'],
                'supplier_id' => $validated['supplier_id'] ?? null,
                'order_date' => $validated['order_date'] ?? now()->toDateString(),
                'eta_date' => $validated['eta_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'in_transit',
                'user_id' => $request->user()?->id,
            ]);

            foreach ($validated['items'] as $item) {
                TransitOrderItem::create([
                    'transit_order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'is_received' => false,
                ]);

                Product::find($item['product_id'])
                    ->incrementWarehouseTransitStock($validated['warehouse_id'], $item['quantity']);
            }

            return $order;
        });

        return response()->json(
            new TransitOrderResource($transitOrder->load(['warehouse', 'supplier', 'user', 'items.product'])),
            201
        );
    }
}
