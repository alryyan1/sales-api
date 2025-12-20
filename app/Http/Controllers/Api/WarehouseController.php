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
}
