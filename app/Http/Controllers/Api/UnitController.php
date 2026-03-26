<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Unit::query();

        // Search functionality
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Include inactive units
        if (!$request->boolean('include_inactive', false)) {
            $query->where('is_active', true);
        }

        // Pagination
        $perPage = $request->get('per_page', 15);
        $units = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => $units->items(),
            'meta' => [
                'current_page' => $units->currentPage(),
                'last_page' => $units->lastPage(),
                'per_page' => $units->perPage(),
                'total' => $units->total(),
            ],
        ]);
    }

    /**
     * Get all active units
     */
    public function all(Request $request): JsonResponse
    {
        $units = Unit::where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $units,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:units,name',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // If setting as default, unset other defaults
        if ($request->boolean('is_default', false)) {
            Unit::where('is_default', true)
                ->update(['is_default' => false]);
        }

        $unit = Unit::create($request->all());

        return response()->json([
            'message' => 'Unit created successfully',
            'data' => $unit,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Unit $unit): JsonResponse
    {
        return response()->json([
            'data' => $unit,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Unit $unit): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', Rule::unique('units', 'name')->ignore($unit->id)],
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // If setting as default, unset other defaults
        if ($request->boolean('is_default', false)) {
            Unit::where('is_default', true)
                ->where('id', '!=', $unit->id)
                ->update(['is_default' => false]);
        }

        $unit->update($request->all());

        return response()->json([
            'message' => 'Unit updated successfully',
            'data' => $unit,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Unit $unit): JsonResponse
    {
        // Check if unit is being used by any products
        $stockingProductsCount = $unit->stockingProducts()->count();
        $sellableProductsCount = $unit->sellableProducts()->count();

        if ($stockingProductsCount > 0 || $sellableProductsCount > 0) {
            return response()->json([
                'message' => 'Cannot delete unit. It is being used by ' .
                    ($stockingProductsCount + $sellableProductsCount) . ' product(s).',
            ], 422);
        }

        $unit->delete();

        return response()->json([
            'message' => 'Unit deleted successfully',
        ]);
    }
}
