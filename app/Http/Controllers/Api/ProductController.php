<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product; // Use the Product model
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Resources\ProductResource; // Use the ProductResource
use Illuminate\Validation\Rule; // For unique validation

class ProductController extends Controller
{
    /**
     * Display a listing of the products.
     */
    public function index(Request $request)
    {
        $query = Product::query(); // Start query builder

        // Example Search (adjust fields as needed)
        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
        }

        // Example Filtering (e.g., by category if added later)
        // if ($categoryId = $request->input('category_id')) {
        //     $query->where('category_id', $categoryId);
        // }

        // Example Sorting (default to latest)
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        // Add validation for allowed sort fields if needed
        if (in_array($sortBy, ['name', 'sku', 'sale_price', 'stock_quantity', 'created_at'])) {
             $query->orderBy($sortBy, $sortDirection);
        } else {
             $query->orderBy('created_at', 'desc'); // Default sort
        }


        $products = $query->paginate($request->input('per_page', 15)); // Paginate results

        return $products;
    }

    /**
     * Store a newly created product in storage.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            // SKU: nullable in DB, unique if provided
            'sku' => 'nullable|string|max:100|unique:products,sku',
            'description' => 'nullable|string|max:65535',
            // Prices: required, must be numeric, minimum 0
            'purchase_price' => 'required|numeric|min:0|max:99999999.99', // Adjust max based on decimal(10,2)
            'sale_price' => 'required|numeric|min:0|max:99999999.99',
            // Stock: required, must be integer, minimum 0
            'stock_quantity' => 'required|integer|min:0',
            // Alert Level: nullable, must be integer if provided, minimum 0
            'stock_alert_level' => 'nullable|integer|min:0',
            // 'unit' => 'nullable|string|max:50', // Example if added
            // 'category_id' => 'nullable|exists:categories,id', // Example if added
        ]);

        $product = Product::create($validatedData);

        // Return 201 Created with the new product resource
        return response()->json(['product' => new ProductResource($product)], Response::HTTP_CREATED);
    }

    /**
     * Display the specified product.
     */
    public function show(Product $product) // Route model binding
    {
        // Optionally load relationships: $product->load('category');
        return response()->json(['product' => new ProductResource($product)]);
    }

    /**
     * Update the specified product in storage.
     */
    public function update(Request $request, Product $product) // Route model binding
    {
        // 'sometimes' means validate only if field is present in request
        $validatedData = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'sku' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                Rule::unique('products')->ignore($product->id), // Ignore self for unique check
            ],
            'description' => 'sometimes|nullable|string|max:65535',
            'purchase_price' => 'sometimes|required|numeric|min:0|max:99999999.99',
            'sale_price' => 'sometimes|required|numeric|min:0|max:99999999.99',
            'stock_quantity' => 'sometimes|required|integer|min:0', // Note: Usually stock is updated via Purchases/Sales, not directly here
            'stock_alert_level' => 'sometimes|nullable|integer|min:0',
            // 'unit' => 'sometimes|nullable|string|max:50',
            // 'category_id' => 'sometimes|nullable|exists:categories,id',
        ]);

        // Warning: Directly updating stock_quantity here might bypass stock tracking logic
        // It's often better to have dedicated endpoints or events for stock adjustments.
        // For a simple CRUD, we allow it, but be aware of implications.

        $product->update($validatedData);

        return response()->json(['product' => new ProductResource($product->fresh())]); // Use fresh() for updated timestamps
    }

    /**
     * Remove the specified product from storage.
     */
    public function destroy(Product $product) // Route model binding
    {
        // Add authorization checks if necessary

        // Important Consideration: Check for related records before deleting?
        // If a product is part of existing Sales or Purchases, deleting it could cause issues.
        // Option 1: Prevent deletion if relations exist
         if ($product->purchaseItems()->exists() || $product->saleItems()->exists()) {
             return response()->json(['message' => 'Cannot delete product with existing purchase or sale records.'], Response::HTTP_CONFLICT); // 409 Conflict
         }

        // Option 2: Soft Deletes (Add SoftDeletes trait to Product model and migration)
        // $product->delete(); // This would soft delete if trait is used

        // Option 3: Hard delete (as implemented below) - use with caution if relations exist
         try {
             $product->delete();
             return response()->json(['message' => 'Product deleted successfully.'], Response::HTTP_OK);
             // Or: return response()->noContent(); // 204
         } catch (\Exception $e) {
             \Log::error('Error deleting product: '.$product->id.'. Error: '.$e->getMessage());
             return response()->json(['message' => 'Failed to delete product.'], Response::HTTP_INTERNAL_SERVER_ERROR);
         }
    }

    // Potential method for dedicated stock adjustments (Example)
    /*
    public function adjustStock(Request $request, Product $product)
    {
        $validated = $request->validate([
            'change_quantity' => 'required|integer', // Can be positive or negative
            'reason' => 'nullable|string|max:255', // Reason for adjustment
        ]);

        $newQuantity = $product->stock_quantity + $validated['change_quantity'];

        if ($newQuantity < 0) {
             return response()->json(['message' => 'Stock quantity cannot be negative.'], Response::HTTP_UNPROCESSABLE_ENTITY); // 422
        }

        $product->stock_quantity = $newQuantity;
        $product->save();

        // Optional: Log the stock adjustment
        // StockAdjustmentLog::create([...]);

        return response()->json(['product' => new ProductResource($product->fresh())]);
    }
    */
    public function autocomplete(Request $request)
{
    $search = $request->input('search', '');
    $limit = $request->input('limit', 15); // Limit results per request

    if (empty($search)) {
        // Optionally return popular/recent items or empty array if search is required
        return response()->json(['data' => []]);
    }

    $products = Product::select(['id', 'name', 'sku', 'sale_price', 'stock_quantity']) // Select only needed fields
        ->where(function ($query) use ($search) {
            $query->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
        })
         // ->where('stock_quantity', '>', 0) // Optionally filter by stock > 0
         ->orderBy('name') // Or order by relevance
         ->limit($limit)
         ->get();

    // No pagination needed here, just return the limited flat list
    return response()->json(['data' => $products]);
}
}