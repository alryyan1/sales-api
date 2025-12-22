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
     * @OA\Tag(
     *     name="Suppliers",
     *     description="API Endpoints for managing Suppliers"
     * )
     */
    /**
     * @OA\Get(
     *     path="/api/suppliers",
     *     summary="List all suppliers",
     *     description="Retrieve a paginated list of suppliers with optional search.",
     *     tags={"Suppliers"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search by name, email, or contact person",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Supplier")),
     *             @OA\Property(property="links", type="object"),
     *             @OA\Property(property="meta", type="object")
     *         )
     *     )
     * )
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
     * @OA\Post(
     *     path="/api/suppliers",
     *     summary="Create a new supplier",
     *     description="Store a newly created supplier in storage.",
     *     tags={"Suppliers"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name", type="string", example="ABC Supplies"),
     *             @OA\Property(property="contact_person", type="string", example="John Doe"),
     *             @OA\Property(property="email", type="string", format="email", example="contact@abc.com"),
     *             @OA\Property(property="phone", type="string", example="1234567890"),
     *             @OA\Property(property="address", type="string", example="123 Main St")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Supplier created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="supplier", ref="#/components/schemas/Supplier")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
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
     * @OA\Get(
     *     path="/api/suppliers/{supplier}",
     *     summary="Get supplier details",
     *     description="Display the specified supplier.",
     *     tags={"Suppliers"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="supplier",
     *         in="path",
     *         description="Supplier ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             @OA\Property(property="supplier", ref="#/components/schemas/Supplier")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Supplier not found"
     *     )
     * )
     */
    public function show(Supplier $supplier) // Route model binding
    {
        // Optionally load relationships: $supplier->load('purchases');
        return response()->json(['supplier' => new SupplierResource($supplier)]);
    }

    /**
     * @OA\Put(
     *     path="/api/suppliers/{supplier}",
     *     summary="Update supplier details",
     *     description="Update the specified supplier in storage.",
     *     tags={"Suppliers"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="supplier",
     *         in="path",
     *         description="Supplier ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="ABC Supplies Updated"),
     *             @OA\Property(property="contact_person", type="string"),
     *             @OA\Property(property="email", type="string", format="email"),
     *             @OA\Property(property="phone", type="string"),
     *             @OA\Property(property="address", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Supplier updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="supplier", ref="#/components/schemas/Supplier")
     *         )
     *     )
     * )
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
     * @OA\Delete(
     *     path="/api/suppliers/{supplier}",
     *     summary="Delete a supplier",
     *     description="Remove the specified supplier from storage.",
     *     tags={"Suppliers"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="supplier",
     *         in="path",
     *         description="Supplier ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Supplier deleted successfully"
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Conflict (e.g. existing purchases)"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server Error"
     *     )
     * )
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
            Log::error('Error deleting supplier: ' . $supplier->id . '. Error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to delete supplier.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
