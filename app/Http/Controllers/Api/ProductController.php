<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource; // Use the ProductResource
use App\Models\Product; // Use the Product model
use App\Models\PurchaseItem;
use App\Services\ProductPdfService;
use App\Services\ProductExcelService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @OA\Tag(
 *     name="Products",
 *     description="Product management endpoints"
 * )
 */
class ProductController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/products",
     *     summary="List all products",
     *     description="Get a paginated list of products with optional filtering, searching, and sorting",
     *     operationId="getProducts",
     *     tags={"Products"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search by name, SKU, scientific name, or description",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="category_id",
     *         in="query",
     *         description="Filter by category ID",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="in_stock_only",
     *         in="query",
     *         description="Show only products with stock > 0",
     *         required=false,
     *         @OA\Schema(type="boolean")
     *     ),
     *     @OA\Parameter(
     *         name="low_stock_only",
     *         in="query",
     *         description="Show only products with stock at or below alert level",
     *         required=false,
     *         @OA\Schema(type="boolean")
     *     ),
     *     @OA\Parameter(
     *         name="out_of_stock_only",
     *         in="query",
     *         description="Show only products with stock <= 0",
     *         required=false,
     *         @OA\Schema(type="boolean")
     *     ),
     *     @OA\Parameter(
     *         name="sort_by",
     *         in="query",
     *         description="Sort field (name, sku, stock_quantity, created_at, updated_at)",
     *         required=false,
     *         @OA\Schema(type="string", default="created_at")
     *     ),
     *     @OA\Parameter(
     *         name="sort_direction",
     *         in="query",
     *         description="Sort direction (asc, desc)",
     *         required=false,
     *         @OA\Schema(type="string", default="desc")
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Items per page",
     *         required=false,
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Products retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="current_page", type="integer"),
     *             @OA\Property(property="per_page", type="integer"),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        $query = Product::query();

        // Load relationships needed for the ProductResource
        $query->with(['category', 'stockingUnit', 'sellableUnit', 'latestPurchaseItem', 'warehouses'])
            ->withSum('purchaseItems', 'quantity')
            ->withSum('saleItems', 'quantity');

        // Search by name, SKU, or description
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('scientific_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter by category
        if ($categoryId = $request->input('category_id')) {
            $query->where('category_id', $categoryId);
        }

        // Filter by stock availability
        if ($request->boolean('in_stock_only')) {
            $query->where('stock_quantity', '>', 0);
        }

        // Filter by low stock (products where stock is at or below alert level)
        if ($request->boolean('low_stock_only')) {
            $query->where(function ($q) {
                $q->whereNotNull('stock_alert_level')
                    ->where('stock_quantity', '<=', \DB::raw('stock_alert_level'));
            });
        }

        // Filter by out of stock only
        if ($request->boolean('out_of_stock_only')) {
            $query->where('stock_quantity', '<=', 0);
        }

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
     * @OA\Post(
     *     path="/api/products",
     *     summary="Create a new product",
     *     description="Create a new product in the system",
     *     operationId="createProduct",
     *     tags={"Products"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "stock_quantity"},
     *             @OA\Property(property="name", type="string", example="Product Name", description="Product name"),
     *             @OA\Property(property="scientific_name", type="string", nullable=true, example="Scientific Name", description="Scientific name"),
     *             @OA\Property(property="sku", type="string", nullable=true, example="SKU123", description="Stock Keeping Unit (must be unique)"),
     *             @OA\Property(property="description", type="string", nullable=true, example="Product description", description="Product description"),
     *             @OA\Property(property="stock_quantity", type="integer", example=100, description="Initial stock quantity"),
     *             @OA\Property(property="stock_alert_level", type="integer", nullable=true, example=10, description="Stock alert level"),
     *             @OA\Property(property="category_id", type="integer", nullable=true, example=1, description="Category ID"),
     *             @OA\Property(property="stocking_unit_id", type="integer", nullable=true, example=1, description="Stocking unit ID"),
     *             @OA\Property(property="sellable_unit_id", type="integer", nullable=true, example=2, description="Sellable unit ID"),
     *             @OA\Property(property="units_per_stocking_unit", type="integer", nullable=true, example=10, description="Units per stocking unit")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Product created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="product", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'scientific_name' => 'nullable|string|max:255',
            'sku' => 'nullable|string|max:100|unique:products,sku',
            'description' => 'nullable|string|max:65535',
            'stock_quantity' => 'required|integer|min:0',
            'stock_alert_level' => 'nullable|integer|min:0',
            'category_id' => 'nullable|exists:categories,id',
            'stocking_unit_id' => 'nullable|exists:units,id',
            'sellable_unit_id' => 'nullable|exists:units,id',
            'units_per_stocking_unit' => 'nullable|integer|min:1',
        ]);

        $product = Product::create($validatedData);

        // Load relationships needed for the ProductResource
        $product->load(['category', 'stockingUnit', 'sellableUnit']);

        // Return 201 Created with the new product resource
        return response()->json(['product' => new ProductResource($product)], Response::HTTP_CREATED);
    }

    /**
     * @OA\Get(
     *     path="/api/products/{id}",
     *     summary="Get a single product",
     *     description="Retrieve details of a specific product by ID",
     *     operationId="getProduct",
     *     tags={"Products"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Product ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Product retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="product", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Product not found"
     *     )
     * )
     */
    public function show(Product $product) // Route model binding
    {
        // Load relationships needed for the ProductResource
        $product->load(['category', 'stockingUnit', 'sellableUnit', 'warehouses']);

        return response()->json(['product' => new ProductResource($product)]);
    }

    /**
     * @OA\Put(
     *     path="/api/products/{id}",
     *     summary="Update a product",
     *     description="Update an existing product. SKU cannot be changed after creation.",
     *     operationId="updateProduct",
     *     tags={"Products"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Product ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="Updated Product Name", description="Product name"),
     *             @OA\Property(property="scientific_name", type="string", nullable=true, example="Updated Scientific Name"),
     *             @OA\Property(property="description", type="string", nullable=true, example="Updated description"),
     *             @OA\Property(property="stock_alert_level", type="integer", nullable=true, example=15),
     *             @OA\Property(property="category_id", type="integer", nullable=true, example=1),
     *             @OA\Property(property="stocking_unit_id", type="integer", nullable=true, example=1),
     *             @OA\Property(property="sellable_unit_id", type="integer", nullable=true, example=2),
     *             @OA\Property(property="units_per_stocking_unit", type="integer", nullable=true, example=10)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Product updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="product", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Product not found"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error or SKU change attempt",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function update(Request $request, Product $product) // Route model binding
    {
        // Prevent changing SKU once set
        if ($request->has('sku')) {
            $incomingSku = $request->input('sku');
            if ($incomingSku !== $product->sku) {
                throw ValidationException::withMessages([
                    'sku' => ['SKU cannot be changed after creation.']
                ]);
            }
        }

        $validatedData = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'scientific_name' => 'sometimes|nullable|string|max:255',
            // 'sku' is intentionally not editable after creation
            'description' => 'sometimes|nullable|string|max:65535',
            'stock_alert_level' => 'sometimes|nullable|integer|min:0',
            'category_id' => 'sometimes|nullable|exists:categories,id',
            'stocking_unit_id' => 'sometimes|nullable|exists:units,id',
            'sellable_unit_id' => 'sometimes|nullable|exists:units,id',
            'units_per_stocking_unit' => 'sometimes|nullable|integer|min:1',
        ]);

        // Important Note on stock_quantity:
        // Allowing direct update of stock_quantity here can break inventory consistency
        // if not handled very carefully. It bypasses the transactional stock updates
        // from Purchases and Sales. It's generally better to create dedicated
        // "Stock Adjustment" features if manual stock changes are needed.
        // If you DO allow it, ensure proper authorization and logging.
        // For this example, we'll allow stock_alert_level but comment out stock_quantity direct edit.

        $product->update($validatedData);

        // Load relationships needed for the ProductResource
        $product->load(['category', 'stockingUnit', 'sellableUnit']);

        // Return the updated resource
        return response()->json(['product' => new ProductResource($product)]);
    }

    /**
     * @OA\Delete(
     *     path="/api/products/{id}",
     *     summary="Delete a product",
     *     description="Delete a product. Cannot delete if product has associated purchases or sales.",
     *     operationId="deleteProduct",
     *     tags={"Products"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Product ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Product deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Product deleted successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Product not found"
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Cannot delete product with associated records",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Cannot delete product. It is associated with existing purchases or sales records.")
     *         )
     *     )
     * )
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
     * @OA\Get(
     *     path="/api/products/autocomplete",
     *     summary="Product autocomplete",
     *     description="Get a lightweight list of products for autocomplete/search dropdowns",
     *     operationId="autocompleteProducts",
     *     tags={"Products"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search term (name, SKU, or scientific name)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Maximum number of results",
     *         required=false,
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Parameter(
     *         name="show_all_for_empty_search",
     *         in="query",
     *         description="Show all products if search is empty",
     *         required=false,
     *         @OA\Schema(type="boolean", default=false)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Products retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     )
     * )
     */
    public function autocomplete(Request $request)
    {
        $search = $request->input('search', '');
        $limit = $request->input('limit', 15);

        // Return empty if search is too short (optional)
        if (strlen($search) < 1 && !$request->input('show_all_for_empty_search')) { // Adjust min length
            return response()->json(['data' => []]);
        }

        $query = Product::select('*')->with(['stockingUnit:id,name', 'sellableUnit:id,name', 'category:id,name', 'latestPurchaseItem']); // Include relations for names

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('scientific_name', 'like', "%{$search}%");
            });
        }
        // Optionally, always filter by stock > 0 for sale/purchase forms
        // if ($request->boolean('in_stock_only')) {
        //    $query->where('stock_quantity', '>', 0);
        // }


        $products = $query->orderBy('name')->limit($limit)->get();

        $warehouseId = $request->input('warehouse_id'); // Get warehouse_id

        $products->each(function ($product) use ($warehouseId) {
            $product->append(['suggested_sale_price_per_sellable_unit', 'latest_cost_per_sellable_unit']);

            if ($warehouseId) {
                // Override total stock with warehouse specific stock
                $product->stock_quantity = $product->countStock($warehouseId);
            }
        });


        return response()->json(['data' => ProductResource::collection($products)]);
    }


    /**
     * @OA\Post(
     *     path="/api/product/by-ids",
     *     summary="Get products by IDs",
     *     description="Fetch multiple products by their IDs. Useful for populating form selects/displays.",
     *     operationId="getProductsByIds",
     *     tags={"Products"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"ids"},
     *             @OA\Property(
     *                 property="ids",
     *                 type="array",
     *                 @OA\Items(type="integer"),
     *                 example={1, 2, 3},
     *                 description="Array of product IDs"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Products retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function getByIds(Request $request)
    {
        $validated = $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'integer|exists:products,id',
        ]);

        $products = Product::whereIn('id', $validated['ids'])
            ->with(['category', 'stockingUnit', 'sellableUnit', 'latestPurchaseItem', 'warehouses'])
            ->withSum('purchaseItems', 'quantity')
            ->withSum('saleItems', 'quantity')
            ->get();

        return ProductResource::collection($products);
    }

    /**
     * @OA\Get(
     *     path="/api/products/{id}/available-batches",
     *     summary="Get available batches for a product",
     *     description="Get all available batches (with remaining quantity > 0) for a specific product, ordered by expiry date (FIFO)",
     *     operationId="getAvailableBatches",
     *     tags={"Products"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Product ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Batches retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="batch_number", type="string", nullable=true),
     *                     @OA\Property(property="remaining_quantity", type="integer"),
     *                     @OA\Property(property="expiry_date", type="string", format="date", nullable=true),
     *                     @OA\Property(property="sale_price", type="number", format="float"),
     *                     @OA\Property(property="unit_cost", type="number", format="float")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Product not found"
     *     )
     * )
     */
    public function getAvailableBatches(Request $request, Product $product) // Route model binding for product
    {
        $warehouseId = $request->query('warehouse_id');

        $query = PurchaseItem::where('product_id', $product->id)
            ->where('remaining_quantity', '>', 0);

        if ($warehouseId) {
            $query->whereHas('purchase', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        $batches = $query->orderBy('expiry_date', 'asc') // FIFO by expiry
            ->orderBy('id', 'asc')   // Then by purchase date (proxy via ID for stability)
            ->select(['id', 'batch_number', 'remaining_quantity', 'expiry_date', 'sale_price', 'unit_cost']) // Select necessary fields
            ->get();

        return response()->json(['data' => $batches]);
    }

    /**
     * @OA\Get(
     *     path="/api/products/export/pdf",
     *     summary="Export products to PDF",
     *     description="Generate and download a PDF report of products with optional filters",
     *     operationId="exportProductsPdf",
     *     tags={"Products"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search filter",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="category_id",
     *         in="query",
     *         description="Category filter",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="in_stock_only",
     *         in="query",
     *         description="Show only in-stock products",
     *         required=false,
     *         @OA\Schema(type="boolean")
     *     ),
     *     @OA\Parameter(
     *         name="low_stock_only",
     *         in="query",
     *         description="Show only low stock products",
     *         required=false,
     *         @OA\Schema(type="boolean")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="PDF file generated successfully",
     *         @OA\MediaType(
     *             mediaType="application/pdf",
     *             @OA\Schema(type="string", format="binary")
     *         )
     *     )
     * )
     */
    public function exportPdf(Request $request)
    {
        // Get filters from request
        $filters = [
            'search' => $request->input('search'),
            'category_id' => $request->input('category_id'),
            'in_stock_only' => $request->boolean('in_stock_only'),
            'low_stock_only' => $request->boolean('low_stock_only'),
        ];

        $pdfService = new ProductPdfService();
        $pdfContent = $pdfService->generateProductsPdf($filters);

        // Check if this is a web route by checking the URL path
        $isWebRoute = str_contains($request->path(), 'products/export/pdf');

        if ($isWebRoute) {
            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="products_report.pdf"');
        } else {
            // For API routes, download the file
            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="products_report.pdf"');
        }
    }

    /**
     * @OA\Get(
     *     path="/api/products/export/excel",
     *     summary="Export products to Excel",
     *     description="Generate and download an Excel file of products with optional filters",
     *     operationId="exportProductsExcel",
     *     tags={"Products"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search filter",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="category_id",
     *         in="query",
     *         description="Category filter",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="in_stock_only",
     *         in="query",
     *         description="Show only in-stock products",
     *         required=false,
     *         @OA\Schema(type="boolean")
     *     ),
     *     @OA\Parameter(
     *         name="low_stock_only",
     *         in="query",
     *         description="Show only low stock products",
     *         required=false,
     *         @OA\Schema(type="boolean")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Excel file generated successfully",
     *         @OA\MediaType(
     *             mediaType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
     *             @OA\Schema(type="string", format="binary")
     *         )
     *     )
     * )
     */
    public function exportExcel(Request $request)
    {
        // Get filters from request
        $filters = [
            'search' => $request->input('search'),
            'category_id' => $request->input('category_id'),
            'in_stock_only' => $request->boolean('in_stock_only'),
            'low_stock_only' => $request->boolean('low_stock_only'),
        ];

        $excelService = new ProductExcelService();
        $excelContent = $excelService->generateProductsExcel($filters);

        // Check if this is a web route by checking the URL path
        $isWebRoute = str_contains($request->path(), 'products/export/excel');

        if ($isWebRoute) {
            return response($excelContent)
                ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->header('Content-Disposition', 'inline; filename="products_report.xlsx"');
        } else {
            // For API routes, download the file
            return response($excelContent)
                ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->header('Content-Disposition', 'attachment; filename="products_report.xlsx"');
        }
    }

    /**
     * @OA\Post(
     *     path="/api/products/import",
     *     summary="Upload Excel file for import",
     *     description="Upload an Excel file to get column headers for mapping. First step in the import process.",
     *     operationId="importProductsExcel",
     *     tags={"Products"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"file"},
     *                 @OA\Property(
     *                     property="file",
     *                     type="string",
     *                     format="binary",
     *                     description="Excel file (xlsx, xls) - max 10MB"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="File uploaded successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="headers", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="message", type="string", example="Excel file uploaded successfully. Please map the columns.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Error reading file",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string")
     *         )
     *     )
     * )
     */
    public function importExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240', // 10MB max
        ]);

        try {
            $file = $request->file('file');
            $excelService = new ProductExcelService();

            // Read the Excel file and get column headers
            $headers = $excelService->getExcelHeaders($file);

            return response()->json([
                'success' => true,
                'headers' => $headers,
                'message' => 'Excel file uploaded successfully. Please map the columns.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error reading Excel file: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/products/preview-import",
     *     summary="Preview imported Excel data",
     *     description="Preview the imported Excel data with column mapping before processing. Second step in the import process.",
     *     operationId="previewProductsImport",
     *     tags={"Products"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"file", "columnMapping"},
     *                 @OA\Property(
     *                     property="file",
     *                     type="string",
     *                     format="binary",
     *                     description="Excel file"
     *                 ),
     *                 @OA\Property(
     *                     property="columnMapping",
     *                     type="object",
     *                     description="Column mapping object (e.g., {'A': 'name', 'B': 'sku'})"
     *                 ),
     *                 @OA\Property(
     *                     property="skipHeader",
     *                     type="boolean",
     *                     description="Skip first row (header)",
     *                     default=true
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Preview generated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="preview", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Error previewing import",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string")
     *         )
     *     )
     * )
     */
    public function previewImport(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
            'columnMapping' => 'required|array',
            'columnMapping.*' => 'required|string',
            'skipHeader' => 'nullable',
        ]);

        try {
            $file = $request->file('file');
            $columnMapping = $request->input('columnMapping');
            $skipHeader = filter_var($request->input('skipHeader', '1'), FILTER_VALIDATE_BOOLEAN);

            $excelService = new ProductExcelService();
            $previewData = $excelService->previewProducts($file, $columnMapping, $skipHeader);

            return response()->json([
                'success' => true,
                'preview' => $previewData
            ]);
        } catch (\Exception $e) {
            \Log::error('Product preview failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error previewing import: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/products/process-import",
     *     summary="Process imported Excel data",
     *     description="Process and import products from Excel file with column mapping. Final step in the import process.",
     *     operationId="processProductsImport",
     *     tags={"Products"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"file", "columnMapping"},
     *                 @OA\Property(
     *                     property="file",
     *                     type="string",
     *                     format="binary",
     *                     description="Excel file"
     *                 ),
     *                 @OA\Property(
     *                     property="columnMapping",
     *                     type="object",
     *                     description="Column mapping object"
     *                 ),
     *                 @OA\Property(
     *                     property="skipHeader",
     *                     type="boolean",
     *                     description="Skip first row (header)",
     *                     default=true
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Import completed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Import completed successfully. 10 products imported, 0 errors."),
     *             @OA\Property(property="imported", type="integer", example=10),
     *             @OA\Property(property="errors", type="integer", example=0),
     *             @OA\Property(property="errorDetails", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Error processing import",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string")
     *         )
     *     )
     * )
     */
    public function processImport(Request $request)
    {
        // Set timeout and memory limits for large imports
        set_time_limit(300); // 5 minutes
        ini_set('memory_limit', '512M');

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
            'columnMapping' => 'required|array',
            'columnMapping.*' => 'required|string',
            'skipHeader' => 'nullable',
        ]);

        try {
            $file = $request->file('file');
            $columnMapping = $request->input('columnMapping');
            $skipHeader = filter_var($request->input('skipHeader', '1'), FILTER_VALIDATE_BOOLEAN);

            // Log import start
            \Log::info('Starting product import', [
                'file_size' => $file->getSize(),
                'file_name' => $file->getClientOriginalName(),
                'mapping' => $columnMapping
            ]);

            $excelService = new ProductExcelService();
            $result = $excelService->importProducts($file, $columnMapping, $skipHeader);

            // Log import completion
            \Log::info('Product import completed', [
                'imported' => $result['imported'],
                'errors' => $result['errors']
            ]);

            return response()->json([
                'success' => true,
                'message' => "Import completed successfully. {$result['imported']} products imported, {$result['errors']} errors.",
                'imported' => $result['imported'],
                'errors' => $result['errors'],
                'errorDetails' => $result['errorDetails'] ?? []
            ]);
        } catch (\Exception $e) {
            \Log::error('Product import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $errorMessage = 'Error processing import: ' . $e->getMessage();

            // Provide more specific error messages
            if (str_contains($e->getMessage(), 'memory')) {
                $errorMessage = 'Import failed due to memory limitations. Please try with a smaller file.';
            } elseif (str_contains($e->getMessage(), 'timeout')) {
                $errorMessage = 'Import timed out. Please try with a smaller file.';
            }

            return response()->json([
                'success' => false,
                'message' => $errorMessage
            ], 400);
        }
    }
    public function purchaseHistory(Request $request, Product $product)
    {
        $limit = $request->input('per_page', 10);
        $history = $product->purchaseItems()
            ->with(['purchase.supplier'])
            ->orderBy('created_at', 'desc')
            ->paginate($limit);

        return \App\Http\Resources\PurchaseItemResource::collection($history);
    }

    public function salesHistory(Request $request, Product $product)
    {
        $limit = $request->input('per_page', 10);
        $history = $product->saleItems()
            ->with(['sale.client', 'sale.user'])
            ->orderBy('created_at', 'desc')
            ->paginate($limit);

        return \App\Http\Resources\SaleItemResource::collection($history);
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