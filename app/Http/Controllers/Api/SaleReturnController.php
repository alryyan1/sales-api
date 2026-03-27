<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class SaleReturnController extends Controller
{
    /**
     * List sale returns for the current user with optional filters.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $query = SaleReturn::with([
            'user:id,name',
            'sale:id,number,sale_date',
            'shift:id,opened_at,closed_at',
            'items.product:id,name,sku',
        ])->where('user_id', $user->id);

        if ($shiftId = $request->input('shift_id')) {
            $query->where('shift_id', $shiftId);
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
     * Store a new sale return (generic product-based return).
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $validated = $request->validate([
            'sale_id' => 'required|exists:sales,id',
            'phone_number' => 'required|string|max:20',
            'reason' => 'nullable|string|max:255',
            'shift_id' => 'nullable|exists:shifts,id',
            'returned_payment_method' => [
                'required',
                'string',
                Rule::in(['cash', 'bankak', 'fawry', 'ocash']),
            ],
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
        ]);

        $sale = Sale::with('items')->find($validated['sale_id']);

        // Check if sale is fully paid
        $totalPaid = \Illuminate\Support\Facades\DB::table('payments')->where('sale_id', $sale->id)->sum('amount');
        $totalAmount = \Illuminate\Support\Facades\DB::table('sale_items')->where('sale_id', $sale->id)->sum('total_price') - ($sale->discount_amount ?? 0);
        if (round($totalPaid, 2) < round($totalAmount, 2)) {
            throw ValidationException::withMessages([
                'sale_id' => ['لا يمكن إرجاع أصناف من فاتورة غير مسددة بالكامل.']
            ]);
        }

        // Get previously returned quantities
        $alreadyReturned = DB::table('sale_return_items')
            ->join('sale_returns', 'sale_return_items.sale_return_id', '=', 'sale_returns.id')
            ->where('sale_returns.sale_id', $sale->id)
            ->select('product_id', DB::raw('SUM(quantity) as total_returned'))
            ->groupBy('product_id')
            ->pluck('total_returned', 'product_id');

        foreach ($validated['items'] as $index => $itemData) {
            $saleItem = $sale->items->firstWhere('product_id', (int) $itemData['product_id']);
            if (!$saleItem) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => ['المنتج غير موجود في الفاتورة المحددة.'],
                ]);
            }
            $returnQty = (int) $itemData['quantity'];
            $previouslyReturned = $alreadyReturned[$saleItem->product_id] ?? 0;
            $availableToReturn = $saleItem->quantity - $previouslyReturned;

            if ($returnQty > $availableToReturn) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => ['كمية الإرجاع المتاحة للصنف هي (' . $availableToReturn . ') فقط.'],
                ]);
            }
        }

        $warehouseId = $sale->warehouse_id ?? $user->warehouse_id ?? 1;

        try {
            $saleReturn = DB::transaction(function () use ($validated, $user, $warehouseId, $sale) {
                $header = SaleReturn::create([
                    'user_id' => $user->id,
                    'sale_id' => $validated['sale_id'],
                    'shift_id' => $validated['shift_id'] ?? null,
                    'reason' => $validated['reason'] ?? null,
                    'phone_number' => $validated['phone_number'] ?? null,
                    'returned_payment_method' => $validated['returned_payment_method'],
                ]);

                foreach ($validated['items'] as $itemData) {
                    $product = Product::findOrFail($itemData['product_id']);
                    $quantity = (int) $itemData['quantity'];
                    $price = (float) $itemData['price'];

                    $header->items()->create([
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                        'price' => $price,
                    ]);

                    // Increase stock in the user's warehouse
                    $product->incrementWarehouseStock($warehouseId, $quantity);
                }

                // Mark the original sale as returned
                $sale->update(['is_returned' => true]);

                return $header->load(['user:id,name', 'sale:id,number,sale_date', 'items.product:id,name,sku']);
            });

            return response()->json([
                'message' => 'Sale return created successfully',
                'sale_return' => $saleReturn,
            ], Response::HTTP_CREATED);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create sale return.',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
