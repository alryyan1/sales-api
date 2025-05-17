<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product; // Use the Product model
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Resources\ProductResource; // Use the ProductResource
use App\Models\PurchaseItem;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    /**
     * Display a listing of the products.
     * Includes search, sorting, and pagination.
     */
    public function index(Request $request)
    {
        $query = Product::query();

        
        // Search by name, SKU, or description
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Optional: Filter by category if implemented
        // if ($categoryId = $request->input('category_id')) {
        //     $query->where('category_id', $categoryId);
        // }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at'); // Default sort field
        $sortDirection = $request->input('sort_direction', 'desc'); // Default sort direction

        // Validate sortable fields to prevent SQL injection or errors
        $sortableFields = ['name', 'sku', 'stock_quantity', 'created_at', 'updated_at'];
        if (in_array($sortBy, $sortableFields)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderBy('created_at', 'desc'); // Fallback default sort
        }

        $perPage = $request->input('per_page', 15); // Default items per page
        $products = $query->paginate($perPage);

        return ProductResource::collection($products);
    }

    /**
     * Store a newly created product in storage.
     * Purchase_price and sale_price are no longer stored directly on the product.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',

            'sku' => 'nullable|string|max:100|unique:products,sku', // SKU unique in products table
            'description' => 'nullable|string|max:65535',
            // Prices are removed from product creation
            'stock_quantity' => 'required|integer|min:0', // Initial stock (can be 0)
            'stock_alert_level' => 'nullable|integer|min:0',
            // 'unit' => 'nullable|string|max:50',
            'category_id' => 'nullable|exists:categories,id',
            'sellable_unit_name' => 'nullable|string|max:50',
            'units_per_stocking_unit' => 'nullable|integer|min:1',
            'stocking_unit_name' => 'nullable|string|max:50',

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
        // Optionally load relationships like category or available batches for display
        // $product->load('category', 'purchaseItemsWithStock');
        return response()->json(['product' => new ProductResource($product)]);
    }

    /**
     * Update the specified product in storage.
     * Purchase_price and sale_price are not updated here.
     * Stock_quantity is typically managed via Purchases/Sales/Adjustments, not direct edit.
     */
    public function update(Request $request, Product $product) // Route model binding
    {
        $validatedData = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'sku' => [
                'sometimes', // Validate only if present
                'nullable',
                'string',
                'max:100',
                Rule::unique('products')->ignore($product->id), // Ignore self for unique check
            ],
            'description' => 'sometimes|nullable|string|max:65535',
            // 'stock_quantity' => 'sometimes|required|integer|min:0', // BE VERY CAREFUL allowing direct stock edit
            'stock_alert_level' => 'sometimes|nullable|integer|min:0',
            // 'unit' => 'sometimes|nullable|string|max:50',
            'category_id' => 'sometimes|nullable|exists:categories,id',
        ]);

        // Important Note on stock_quantity:
        // Allowing direct update of stock_quantity here can break inventory consistency
        // if not handled very carefully. It bypasses the transactional stock updates
        // from Purchases and Sales. It's generally better to create dedicated
        // "Stock Adjustment" features if manual stock changes are needed.
        // If you DO allow it, ensure proper authorization and logging.
        // For this example, we'll allow stock_alert_level but comment out stock_quantity direct edit.

        $product->update($validatedData);

        // Return the updated resource
        return response()->json(['product' => new ProductResource($product->fresh())]);
    }

    /**
     * Remove the specified product from storage.
     */
    public function destroy(Product $product)
    {
        // Authorization check
        // if (auth()->user()->cannot('delete', $product)) { abort(403); }

        // Check for dependencies (e.g., if product is in purchase_items or sale_items)
        // The 'restrict' onDelete constraint in migrations for purchase_items and sale_items
        // should prevent deletion if related records exist, throwing a QueryException.
        try {
            $product->delete();
            return response()->json(['message' => 'Product deleted successfully.'], Response::HTTP_OK);
            // Or: return response()->noContent(); // 204
        } catch (\Illuminate\Database\QueryException $e) {
            // Check for foreign key constraint violation error code (varies by DB)
            // MySQL: 1451, PostgreSQL: 23503, SQLite: 19 (SQLITE_CONSTRAINT)
            $errorCode = $e->errorInfo[1] ?? null;
            if ($errorCode == 1451 || $errorCode == 23503 || (str_contains($e->getMessage(), 'FOREIGN KEY constraint failed'))) {
                Log::warning("Attempted to delete Product ID {$product->id} with existing relations.");
                return response()->json(['message' => 'Cannot delete product. It is associated with existing purchases or sales records.'], Response::HTTP_CONFLICT); // 409 Conflict
            }
            // Log other database errors
            Log::error("Error deleting product ID {$product->id}: " . $e->getMessage());
            return response()->json(['message' => 'Failed to delete product due to a database error.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Throwable $e) {
            Log::error("Unexpected error deleting product ID {$product->id}: " . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while deleting the product.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Autocomplete endpoint for products (example).
     * Returns a lightweight list of products for select dropdowns.
     */
    public function autocomplete(Request $request)
    {
        $search = $request->input('search', '');
        $limit = $request->input('limit', 15);

        // Return empty if search is too short (optional)
        if (strlen($search) < 1 && !$request->input('show_all_for_empty_search')) { // Adjust min length
            return response()->json(['data' => []]);
        }

        $query = Product::select('*'); // Include stock_quantity

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }
        // Optionally, always filter by stock > 0 for sale/purchase forms
        // if ($request->boolean('in_stock_only')) {
        //    $query->where('stock_quantity', '>', 0);
        // }


        $products = $query->orderBy('name')->limit($limit)->get();
        $products->each->append(['suggested_sale_price_per_sellable_unit', 'latest_cost_per_sellable_unit']);


        return response()->json(['data' => ProductResource::collection($products)]);
    }


    /**
     * Fetch multiple products by their IDs.
     * Useful for populating form selects/displays when editing related records.
     */
    public function getByIds(Request $request)
    {
        $validated = $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'integer|exists:products,id',
        ]);

        $products = Product::select(['id', 'name', 'sku', 'stock_quantity']) // Select necessary fields
            ->whereIn('id', $validated['ids'])
            ->get();

        return response()->json(['data' => $products]);
    }

    // In ProductController.php (or a new BatchController)
    public function getAvailableBatches(Product $product) // Route model binding for product
    {
        $batches = PurchaseItem::where('product_id', $product->id)
            ->where('remaining_quantity', '>', 0)
            ->orderBy('expiry_date', 'asc') // FIFO by expiry
            ->orderBy('created_at', 'asc')   // Then by purchase date
            ->select(['id', 'batch_number', 'remaining_quantity', 'expiry_date', 'sale_price', 'unit_cost']) // Select necessary fields
            ->get();
        return response()->json(['data' => $batches]);
    }
}

// store() and update() Validation:
// purchase_price and sale_price are removed from the validation rules and are no longer set or updated directly on the product.
// stock_quantity validation is kept for store() (initial stock).
// Direct update of stock_quantity in update() is commented out with a warning, as this should ideally be handled by dedicated stock adjustment transactions, purchases, or sales. Only stock_alert_level is typically safe to edit directly on the product master data.
// destroy() Method:
// Includes a try...catch block to specifically catch QueryException.
// It checks if the exception is due to a foreign key constraint violation (error codes differ by database, a string check for "FOREIGN KEY constraint failed" is a more general SQLite check).
// If it's a foreign key issue, it returns a 409 Conflict status with a user-friendly message, preventing deletion of products tied to existing records. This relies on the onDelete('restrict') set in purchase_items and sale_items migrations.
// autocomplete() Method: Added for frontend comboboxes.
// Accepts search and limit parameters.
// Selects only essential fields (id, name, sku, stock_quantity) for a lightweight response.
// Includes an optional filter for in_stock_only if you want the autocomplete to only show items with stock.
// getByIds() Method: Added to fetch multiple products by an array of IDs. This is useful for the "Edit Purchase/Sale" pages to pre-populate product information in the item rows.
// Validates that ids is an array of existing product integers.
// Selects only necessary fields.
// This ProductController is now aligned with the new inventory model where prices are not fixed on the product master record. Remember to add the new routes for autocomplete and getByIds to your routes/api.php