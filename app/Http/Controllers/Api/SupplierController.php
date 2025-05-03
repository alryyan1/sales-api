<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier; // Use Supplier model
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Resources\SupplierResource; // Use SupplierResource
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule; // For unique rule on update

class SupplierController extends Controller
{
    /**
     * Display a listing of the suppliers.
     */
    public function index(Request $request)
    {
        // Add basic search capability (example)
        $query = Supplier::query();

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('contact_person', 'like', "%{$search}%");
        }

        $suppliers = $query->latest()->paginate($request->input('per_page', 15));

        return $suppliers;
    }

    /**
     * Store a newly created supplier in storage.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|string|email|max:255|unique:suppliers,email', // Unique in suppliers table
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:65535',
            // Add validation for other fields if needed
        ]);

        $supplier = Supplier::create($validatedData);

        // Return created resource
        return response()->json(['supplier' => new SupplierResource($supplier)], Response::HTTP_CREATED);
    }

    /**
     * Display the specified supplier.
     */
    public function show(Supplier $supplier) // Route model binding
    {
        // Optionally load relationships: $supplier->load('purchases');
        return response()->json(['supplier' => new SupplierResource($supplier)]);
    }

    /**
     * Update the specified supplier in storage.
     */
    public function update(Request $request, Supplier $supplier) // Route model binding
    {
        $validatedData = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'contact_person' => 'sometimes|nullable|string|max:255',
            'email' => [
                'sometimes',
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('suppliers')->ignore($supplier->id), // Ignore self on update
            ],
            'phone' => 'sometimes|nullable|string|max:30',
            'address' => 'sometimes|nullable|string|max:65535',
            // Add validation for other fields if needed
        ]);

        $supplier->update($validatedData);

        // Return updated resource (use fresh() to get updated timestamps)
        return response()->json(['supplier' => new SupplierResource($supplier->fresh())]);
    }

    /**
     * Remove the specified supplier from storage.
     */
    public function destroy(Supplier $supplier) // Route model binding
    {
        // Add authorization checks if necessary (e.g., using Policies)

        try {
            // Add check if supplier has related records (e.g., purchases) before deleting if needed
            // if ($supplier->purchases()->exists()) {
            //     return response()->json(['message' => 'Cannot delete supplier with existing purchases.'], Response::HTTP_CONFLICT); // 409 Conflict
            // }

            $supplier->delete();
            return response()->json(['message' => 'Supplier deleted successfully.'], Response::HTTP_OK);
            // Or return response()->noContent(); // 204 No Content

        } catch (\Exception $e) {
            // Log the error
            Log::error('Error deleting supplier: '.$supplier->id.'. Error: '.$e->getMessage());
            return response()->json(['message' => 'Failed to delete supplier.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}