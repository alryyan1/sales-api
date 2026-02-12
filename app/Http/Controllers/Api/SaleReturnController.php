<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
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

        $warehouseId = $user->warehouse_id ?? 1;

        try {
            $saleReturn = DB::transaction(function () use ($validated, $user, $warehouseId) {
                $header = SaleReturn::create([
                    'user_id' => $user->id,
                    'shift_id' => $validated['shift_id'] ?? null,
                    'reason' => $validated['reason'] ?? null,
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

                return $header->load(['user:id,name', 'items.product:id,name,sku']);
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

