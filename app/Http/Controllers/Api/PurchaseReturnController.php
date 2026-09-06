<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseReturnController extends Controller
{
    /**
     * List purchase returns with optional filters (supplier, purchase, user, date range).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $query = PurchaseReturn::with([
            'user:id,name',
            'purchase:id,reference_number,purchase_date,currency',
            'supplier:id,name',
            'items.product:id,name,sku',
        ]);

        if ($supplierId = $request->input('supplier_id')) {
            $query->where('supplier_id', $supplierId);
        }
        if ($purchaseId = $request->input('purchase_id')) {
            $query->where('purchase_id', $purchaseId);
        }
        if ($userId = $request->input('user_id')) {
            $query->where('user_id', $userId);
        }
        if ($start = $request->input('start_date')) {
            $query->whereDate('created_at', '>=', $start);
        }
        if ($end = $request->input('end_date')) {
            $query->whereDate('created_at', '<=', $end);
        }

        $perPage = (int) $request->input('per_page', 15);
        $returns = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        return response()->json($returns);
    }

    /**
     * Store a new purchase return.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $validated = $request->validate([
            'purchase_id' => 'required|exists:purchases,id',
            'reason' => 'required|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $purchase = Purchase::with('items')->find($validated['purchase_id']);

        if ($purchase->status !== 'received') {
            throw ValidationException::withMessages([
                'purchase_id' => ['لا يمكن إرجاع أصناف من فاتورة غير مستلمة.'],
            ]);
        }

        // Get previously returned quantities per product for this purchase
        $alreadyReturned = DB::table('purchase_return_items')
            ->join('purchase_returns', 'purchase_return_items.purchase_return_id', '=', 'purchase_returns.id')
            ->where('purchase_returns.purchase_id', $purchase->id)
            ->select('product_id', DB::raw('SUM(quantity) as total_returned'))
            ->groupBy('product_id')
            ->pluck('total_returned', 'product_id');

        $warehouseId = $purchase->warehouse_id;

        foreach ($validated['items'] as $index => $itemData) {
            $purchaseItem = $purchase->items->firstWhere('product_id', (int) $itemData['product_id']);
            if (!$purchaseItem) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => ['المنتج غير موجود في هذه الفاتورة.'],
                ]);
            }

            $returnQty = (int) $itemData['quantity'];
            $previouslyReturned = $alreadyReturned[$purchaseItem->product_id] ?? 0;
            $availableToReturn = $purchaseItem->quantity - $previouslyReturned;

            if ($returnQty > $availableToReturn) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => ['كمية الإرجاع المتاحة للصنف هي (' . $availableToReturn . ') فقط.'],
                ]);
            }

            $currentStock = DB::table('product_warehouse')
                ->where('product_id', $purchaseItem->product_id)
                ->where('warehouse_id', $warehouseId)
                ->value('quantity') ?? 0;

            if ($returnQty > $currentStock) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => ['الكمية غير متوفرة في المخزون الحالي (المتوفر: ' . $currentStock . ').'],
                ]);
            }
        }

        try {
            $purchaseReturn = DB::transaction(function () use ($validated, $user, $purchase, $warehouseId) {
                $header = PurchaseReturn::create([
                    'user_id' => $user->id,
                    'purchase_id' => $purchase->id,
                    'supplier_id' => $purchase->supplier_id,
                    'warehouse_id' => $warehouseId,
                    'reason' => $validated['reason'] ?? null,
                ]);

                foreach ($validated['items'] as $itemData) {
                    $purchaseItem = $purchase->items->firstWhere('product_id', (int) $itemData['product_id']);
                    $product = Product::findOrFail($itemData['product_id']);
                    $quantity = (int) $itemData['quantity'];

                    $header->items()->create([
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                        'unit_cost' => $purchaseItem->unit_cost,
                    ]);

                    // Decrease stock in the purchase's warehouse
                    $product->decrementWarehouseStock($warehouseId, $quantity);
                }

                return $header->load(['user:id,name', 'purchase:id,reference_number,purchase_date,currency', 'supplier:id,name', 'items.product:id,name,sku']);
            });

            return response()->json([
                'message' => 'Purchase return created successfully',
                'purchase_return' => $purchaseReturn,
            ], Response::HTTP_CREATED);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create purchase return.',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
