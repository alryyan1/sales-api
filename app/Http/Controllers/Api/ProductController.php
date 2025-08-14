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

class ProductController extends Controller
{
    /**
     * Display a listing of the products.
     * Includes search, sorting, and pagination.
     */
    public function index(Request $request)
    {
        $query = Product::query();

        // Load relationships needed for the ProductResource
        $query->with(['category', 'stockingUnit', 'sellableUnit']);
        
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
     * Store a newly created product in storage.
     * Purchase_price and sale_price are no longer stored directly on the product.
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
     * Display the specified product.
     */
    public function show(Product $product) // Route model binding
    {
        // Load relationships needed for the ProductResource
        $product->load(['category', 'stockingUnit', 'sellableUnit']);
        
        return response()->json(['product' => new ProductResource($product)]);
    }

    /**
     * Update the specified product in storage.
     * Purchase_price and sale_price are not updated here.
     * Stock_quantity is typically managed via Purchases/Sales/Adjustments, not direct edit.
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
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('scientific_name', 'like', "%{$search}%");
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
     * Accepts POST request with 'ids' array in request body.
     */
    public function getByIds(Request $request)
    {
        $validated = $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'integer|exists:products,id',
        ]);

        $products = Product::whereIn('id', $validated['ids'])
            ->with(['category', 'stockingUnit', 'sellableUnit'])
            ->get();

        return ProductResource::collection($products);
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

    /**
     * Export products to PDF.
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
     * Export products to Excel.
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
     * Import products from Excel file.
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
     * Preview the imported Excel data with column mapping.
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
     * Process the imported Excel data with column mapping.
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