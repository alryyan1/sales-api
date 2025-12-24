<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Http\Resources\WarehouseResource;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function index()
    {
        return WarehouseResource::collection(Warehouse::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string',
            'contact_info' => 'nullable|string',
            'header_text' => 'nullable|string',
            'footer_text' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $warehouse = Warehouse::create($validated);
        return new WarehouseResource($warehouse);
    }

    public function show(Warehouse $warehouse)
    {
        return new WarehouseResource($warehouse);
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'address' => 'nullable|string',
            'contact_info' => 'nullable|string',
            'header_text' => 'nullable|string',
            'footer_text' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $warehouse->update($validated);
        return new WarehouseResource($warehouse);
    }

    public function destroy(Warehouse $warehouse)
    {
        $warehouse->delete();
        return response()->noContent();
    }
    public function importMissingProducts(Warehouse $warehouse)
    {
        // Get all products that are NOT already attached to this warehouse
        $existingProductIds = $warehouse->products()->pluck('product_id')->toArray();

        $productsToAttach = \App\Models\Product::whereNotIn('id', $existingProductIds)->get();

        $count = 0;
        foreach ($productsToAttach as $product) {
            // Attach with current global stock quantity
            // Note: This logic assumes the user wants to initialize this warehouse 
            // with the currently recorded global stock.
            $warehouse->products()->attach($product->id, [
                'quantity' => $product->stock_quantity ?? 0,
                'min_stock_level' => 0
            ]);
            $count++;
        }

        return response()->json(['message' => "Imported $count products successfully", 'count' => $count]);
    }
}
