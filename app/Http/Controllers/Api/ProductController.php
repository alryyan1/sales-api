<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProductDeletionService;
use App\Http\Resources\ProductResource; // Use the ProductResource
use App\Models\Product; // Use the Product model
use App\Models\PurchaseItem;
use App\Services\ProductPdfService;
use App\Services\ProductExcelService;
use App\Services\PriceListPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\FirebaseService;

class ProductController extends Controller
{
    protected $deletionService;

    public function __construct(\App\Services\ProductDeletionService $deletionService)
    {
        $this->deletionService = $deletionService;
    }

    public function index(Request $request)
    {
        $query = Product::query()->select('products.*');

        // Add subqueries for expensive attributes to avoid N+1 queries
        $query->addSelect([
            'earliest_expiry_date' => PurchaseItem::selectRaw('MIN(expiry_date)')
                ->whereColumn('product_id', 'products.id')
                ->where('is_moved_to_expired', false),

            'latest_purchase_cost_raw' => PurchaseItem::select('unit_cost')
                ->whereColumn('product_id', 'products.id')
                ->latest('created_at')
                ->limit(1),

            'last_sale_price_raw' => PurchaseItem::select('sale_price')
                ->whereColumn('product_id', 'products.id')
                ->whereNotNull('sale_price')
                ->latest('created_at')
                ->limit(1),

            'last_purchase_currency' => PurchaseItem::select('purchases.currency')
                ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
                ->whereColumn('purchase_items.product_id', 'products.id')
                ->latest('purchase_items.created_at')
                ->limit(1),
        ]);

        if ($warehouseId = $request->input('warehouse_id')) {
            $query->addSelect([
                'current_stock_quantity' => \Illuminate\Support\Facades\DB::table('product_warehouse')
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('product_id', 'products.id')
                    ->where('warehouse_id', $warehouseId)
                    ->limit(1)
            ]);
        }

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
            $query->hasStock();
        }

        // Filter by low stock (products where stock is at or below alert level)
        if ($request->boolean('low_stock_only')) {
            $query->lowStock();
        }

        // Filter by out of stock only
        if ($request->boolean('out_of_stock_only')) {
            $query->whereDoesntHave('warehouses', function ($q) {
                $q->where('product_warehouse.quantity', '>', 0);
            });
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at'); // Default sort field
        $sortDirection = $request->input('sort_direction', 'desc'); // Default sort direction

        // Validate sortable fields to prevent SQL injection or errors
        $sortableFields = ['name', 'sku', 'created_at', 'updated_at'];
        if ($sortBy === 'stock_quantity') {
            $query->orderBy(
                \DB::table('product_warehouse')
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('product_id', 'products.id'),
                $sortDirection
            );
        } elseif ($sortBy === 'latest_cost_per_sellable_unit') {
            $query->orderBy(
                PurchaseItem::select('unit_cost')
                    ->whereColumn('product_id', 'products.id')
                    ->latest('created_at')
                    ->limit(1),
                $sortDirection
            );
        } elseif ($sortBy === 'suggested_sale_price_per_sellable_unit') {
            $query->orderBy(
                PurchaseItem::select('sale_price')
                    ->whereColumn('product_id', 'products.id')
                    ->whereNotNull('sale_price')
                    ->latest('created_at')
                    ->limit(1),
                $sortDirection
            );
        } elseif (in_array($sortBy, $sortableFields)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderBy('created_at', 'desc'); // Fallback default sort
        }

        $perPage = $request->input('per_page', 15); // Default items per page
        $products = $query->paginate($perPage);

        return ProductResource::collection($products);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'scientific_name' => 'nullable|string|max:255',
            'sku' => 'nullable|string|max:100|unique:products,sku',
            'description' => 'nullable|string|max:65535',
            'image_url' => 'nullable|string|max:500',
            'stock_quantity' => 'required|integer|min:0',
            'stock_alert_level' => 'nullable|integer|min:0',
            'category_id' => 'nullable|exists:categories,id',
            'stocking_unit_id' => 'nullable|exists:units,id',
            'sellable_unit_id' => 'nullable|exists:units,id',
            'units_per_stocking_unit' => 'nullable|integer|min:1',
            'has_expiry_date' => 'nullable|boolean',
            'sale_price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'expire_date' => 'nullable|date',
        ]);

        $product = Product::create($validatedData);

        // Load relationships needed for the ProductResource
        $product->load(['category', 'stockingUnit', 'sellableUnit']);

        // Return 201 Created with the new product resource
        return response()->json(['product' => new ProductResource($product)], Response::HTTP_CREATED);
    }

    public function show(Product $product)
    {
        // Load relationships needed for the ProductResource
        $product->load(['category', 'stockingUnit', 'sellableUnit', 'warehouses']);

        return response()->json(['product' => new ProductResource($product)]);
    }

    public function update(Request $request, Product $product)
    {
        $validatedData = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'scientific_name' => 'sometimes|nullable|string|max:255',
            'sku' => 'sometimes|nullable|string|max:100|unique:products,sku,' . $product->id, // Allow SKU update, ignore current ID
            'description' => 'sometimes|nullable|string|max:65535',
            'image_url' => 'sometimes|nullable|string|max:500',
            'stock_alert_level' => 'sometimes|nullable|integer|min:0',
            'category_id' => 'sometimes|nullable|exists:categories,id',
            'stocking_unit_id' => 'sometimes|nullable|exists:units,id',
            'sellable_unit_id' => 'sometimes|nullable|exists:units,id',
            'units_per_stocking_unit' => 'sometimes|nullable|integer|min:1',
            'has_expiry_date' => 'sometimes|boolean',
            'sale_price' => 'sometimes|nullable|numeric|min:0',
            'cost_price' => 'sometimes|nullable|numeric|min:0',
            'expire_date' => 'sometimes|nullable|date',
            'preferred_currency' => 'sometimes|nullable|in:SDG,USD',
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

    public function destroy(Product $product, Request $request)
    {
        // Authorization check
        // if (auth()->user()->cannot('delete', $product)) { abort(403); }

        $forceDelete = $request->query('force', false) === 'true' || $request->input('force') === true;

        // Check for dependencies (e.g., if product is in purchase_items or sale_items)
        // The 'restrict' onDelete constraint in migrations for purchase_items and sale_items
        // should prevent deletion if related records exist, throwing a QueryException.
        try {
            if ($forceDelete) {
                $this->deletionService->forceDeleteProduct($product);
            } else {
                $product->delete();
            }

            return response()->json(['message' => 'Product deleted successfully.'], Response::HTTP_OK);
        } catch (\Illuminate\Database\QueryException $e) {
            // Check for foreign key constraint violation error code (varies by DB)
            // MySQL: 1451, PostgreSQL: 23503, SQLite: 19 (SQLITE_CONSTRAINT)
            $errorCode = $e->errorInfo[1] ?? null;
            if ($errorCode == 1451 || $errorCode == 23503 || (str_contains($e->getMessage(), 'FOREIGN KEY constraint failed'))) {
                Log::warning("Attempted to delete Product ID {$product->id} with existing relations.");
                return response()->json([
                    'message' => 'Cannot delete product. It is associated with existing purchases or sales records.',
                    'can_force_delete' => true, // Flag to tell frontend it can ask for force delete
                    'confirmation_message' => 'هذا المنتج مرتبط بعمليات بيع أو شراء سابقة. هل تريد بالتأكيد حذفه وحذف جميع العمليات المرتبطة به؟ هذا الإجراء لا يمكن التراجع عنه.'
                ], Response::HTTP_CONFLICT); // 409 Conflict
            }
            // Log other database errors
            Log::error("Error deleting product ID {$product->id}: " . $e->getMessage());
            return response()->json(['message' => 'Failed to delete product due to a database error.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Throwable $e) {
            Log::error("Unexpected error deleting product ID {$product->id}: " . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while deleting the product.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function autocomplete(Request $request)
    {
        $search = $request->input('search', '');
        $limit = $request->input('limit', 15);

        // Return empty if search is too short (optional)
        if (strlen($search) < 1 && !$request->input('show_all_for_empty_search')) { // Adjust min length
            return response()->json(['data' => []]);
        }

        $warehouseId = $request->input('warehouse_id');

        $query = Product::select('products.*')->with(['stockingUnit:id,name', 'sellableUnit:id,name', 'category:id,name', 'latestPurchaseItem.purchase:id,currency']);

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('scientific_name', 'like', "%{$search}%");
            });
        }

        if ($warehouseId) {
            
            // Only return products that have stock > 0 in the user's warehouse
            $query->inStockAt((int) $warehouseId);
        }

        $products = $query->orderBy('name')->limit($limit)->get();

        $products->each(function ($product) use ($warehouseId) {
            $product->append(['suggested_sale_price_per_sellable_unit', 'latest_cost_per_sellable_unit']);

            if ($warehouseId) {
                // Override total stock with warehouse-specific stock
                $product->current_stock_quantity = $product->countStock($warehouseId);
            }
        });


        return response()->json(['data' => ProductResource::collection($products)]);
    }

    public function getByIds(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:products,id',
        ]);

        $products = Product::whereIn('id', $validated['ids'])
            ->with(['category', 'stockingUnit', 'sellableUnit', 'latestPurchaseItem.purchase:id,currency', 'warehouses'])
            ->withSum('purchaseItems', 'quantity')
            ->withSum('saleItems', 'quantity')
            ->get();

        return ProductResource::collection($products);
    }

    public function getAvailableBatches(Request $request, Product $product)
    {
        $warehouseId = $request->query('warehouse_id');

        $query = PurchaseItem::where('product_id', $product->id)
            ->where('is_moved_to_expired', false);

        if ($warehouseId) {
            $query->whereHas('purchase', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        $batches = $query->orderBy('expiry_date', 'asc')
            ->orderBy('id', 'asc')
            ->select(['id', 'batch_number', 'expiry_date', 'sale_price', 'unit_cost'])
            ->get();

        return response()->json(['data' => $batches]);
    }

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

    public function priceListPdf(Request $request)
    {
        $pdfService = new PriceListPdfService();
        $pdfContent = $pdfService->generatePriceListPdf();

        return response($pdfContent)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="pricelist.pdf"');
    }

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

    public function uploadImage(Request $request, Product $product)
    {
        $validated = $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:2048', // 2MB max
        ]);

        try {
            // Store image in public/products directory
            $path = $request->file('image')->store('products', 'public');

            // Store relative path in database
            $imageUrl = '/storage/' . $path;

            Log::info('Image uploaded', [
                'product_id' => $product->id,
                'path' => $path,
                'image_url' => $imageUrl
            ]);

            // Update product
            $updated = $product->update(['image_url' => $imageUrl]);
            
            Log::info('Product update result', [
                'product_id' => $product->id,
                'updated' => $updated,
                'image_url_set' => $imageUrl
            ]);

            // Verify the update
            $product->refresh();
            Log::info('Product after refresh', [
                'product_id' => $product->id,
                'image_url' => $product->image_url
            ]);

            $product->load(['category', 'stockingUnit', 'sellableUnit']);

            return response()->json([
                'message' => 'Image uploaded successfully',
                'product' => new ProductResource($product),
                'image_url' => $product->image_url ? (str_starts_with($product->image_url, 'http') ? $product->image_url : asset($product->image_url)) : null,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            Log::error('Failed to upload product image: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to upload image',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    public function bulkUpdateUnits(Request $request)
    {
        $validatedData = $request->validate([
            'unit_id' => 'required|exists:units,id',
        ]);

        $unitId = $validatedData['unit_id'];

        // Update all products to use this unit for both stocking and sellable
        Product::query()->update([
            'stocking_unit_id' => $unitId,
            'sellable_unit_id' => $unitId,
            'units_per_stocking_unit' => 1 // Reset to 1 since it's the same unit
        ]);

        return response()->json([
            'message' => 'All products updated successfully to the selected unit.'
        ]);
    }

    public function clearSalePrice(Product $product)
    {
        $product->update(['sale_price' => null]);
        return response()->json(['message' => 'تم مسح سعر البيع', 'sale_price' => null]);
    }

    public function bulkUpdateSalePrice(Request $request)
    {
        $validatedData = $request->validate([
            'percentage' => 'required|numeric|min:0',
        ]);

        $multiplier = 1 + ($validatedData['percentage'] / 100);
        $updatedCount = 0;

        Product::all()->each(function (Product $product) use ($multiplier, &$updatedCount) {
            $lastPrice = $product->last_sale_price_per_sellable_unit;
            if ($lastPrice !== null && $lastPrice > 0) {
                $product->sale_price = round($lastPrice * $multiplier, 2);
                $product->save();
                $updatedCount++;
            }
        });

        return response()->json([
            'message' => "تم تحديث سعر البيع لـ {$updatedCount} منتج بنجاح.",
            'updated_count' => $updatedCount,
        ]);
    }

    /**
     * Sync all products to Firestore.
     *
     * POST /api/products/sync-to-firestore
     *
     * Optional body param:
     *   collection_name  — overrides the firebase_collection_name setting
     */
    public function syncToFirestore(Request $request)
    {
        $projectId = config('firebase.project_id');
        if (!$projectId) {
            return response()->json(['message' => 'Firebase project ID not configured.'], 500);
        }

        $accessToken = FirebaseService::getAccessToken();
        if (!$accessToken) {
            return response()->json(['message' => 'Failed to obtain Firebase access token.'], 500);
        }

        $collectionName = $request->input('collection_name');
        if (!$collectionName) {
            $settings = (new \App\Services\SettingsService())->getAll();
            $collectionName = $settings['firebase_collection_name'] ?? 'none';
        }

        // Load products with category; accessors handle stock/price/cost/expiry
        $products = Product::with('category')->get();

        $syncedCount = 0;
        $batchSize   = 450;
        $now         = now()->toIso8601String();

        foreach ($products->chunk($batchSize) as $chunk) {
            $writes = [];

            foreach ($chunk as $product) {
                $docPath = "projects/{$projectId}/databases/(default)/documents/pharmacies/{$collectionName}/products/{$product->id}";

                $writes[] = [
                    'update' => [
                        'name'   => $docPath,
                        'fields' => [
                            'id'              => ['integerValue'   => (string) $product->id],
                            'name'            => ['stringValue'    => (string) ($product->name ?? '')],
                            'scientific_name' => ['stringValue'    => (string) ($product->scientific_name ?? '')],
                            'sku'             => ['stringValue'    => (string) ($product->sku ?? '')],
                            'description'     => ['stringValue'    => (string) ($product->description ?? '')],
                            'category_name'   => ['stringValue'    => (string) ($product->category?->name ?? $product->category_name ?? '')],
                            'stock_quantity'  => ['integerValue'   => (string) ($product->current_stock_quantity ?? $product->stock_quantity ?? 0)],
                            'sale_price'      => ['doubleValue'    => (float) ($product->suggested_sale_price_per_sellable_unit ?? 0)],
                            'cost'            => ['doubleValue'    => (float) ($product->latest_cost_per_sellable_unit ?? 0)],
                            'expiry_date'     => ['stringValue'    => (string) ($product->earliest_expiry_date ?? '')],
                            'updated_at'      => ['timestampValue' => $now],
                            'synced_at'       => ['timestampValue' => $now],
                        ],
                    ],
                ];
            }

            if (empty($writes)) {
                continue;
            }

            $commitUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents:commit";
            $response  = Http::withToken($accessToken)->post($commitUrl, ['writes' => $writes]);

            if (!$response->successful()) {
                Log::error('ProductController@syncToFirestore: Firestore batch write failed.', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return response()->json([
                    'message'       => 'Firestore batch write failed.',
                    'synced_so_far' => $syncedCount,
                    'error'         => $response->json(),
                ], 502);
            }

            $syncedCount += count($writes);
        }

        Log::info("ProductController@syncToFirestore: synced {$syncedCount} products to Firestore collection '{$collectionName}'.");

        return response()->json([
            'message'         => "تمت مزامنة {$syncedCount} منتج بنجاح.",
            'synced_count'    => $syncedCount,
            'collection_name' => $collectionName,
        ]);
    }

    /**
     * Generate a barcode label PDF for a product using TCPDF.
     * Query params: width (mm), height (mm), copies
     */
    public function barcodeLabelPdf(Request $request, Product $product)
    {
        $width  = max(20, min(200, (float)$request->input('width',  60)));
        $height = max(10, min(200, (float)$request->input('height', 30)));
        $copies = max(1, min(100, (int)$request->input('copies',    1)));

        $barcodeValue = $product->sku ?: (string)$product->id;

        $pdf = new \TCPDF('L', 'mm', [$height, $width], true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(2, 2, 2);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetFont('helvetica', '', 7);

        $barcodeStyle = [
            'position'     => 'C',
            'align'        => 'C',
            'stretch'      => false,
            'fitwidth'     => true,
            'cellfitalign' => '',
            'border'       => false,
            'hpadding'     => 1,
            'vpadding'     => 0.5,
            'fgcolor'      => [0, 0, 0],
            'bgcolor'      => false,
            'text'         => true,
            'font'         => 'helvetica',
            'fontsize'     => 7,
            'stretchtext'  => 4,
        ];

        for ($i = 0; $i < $copies; $i++) {
            $pdf->AddPage();

            // Product name (top, centred)
            $pdf->SetFont('helvetica', 'B', 7);
            $pdf->Cell(0, 4, $product->name, 0, 1, 'C');

            // Barcode (Code 128)
            $barcodeH = $height * 0.45;
            $pdf->write1DBarcode($barcodeValue, 'C128', '', '', 0, $barcodeH, 0.4, $barcodeStyle, 'N');

            // SKU / price line (bottom)
            $pdf->SetFont('helvetica', '', 6);
            $price = number_format((float)($product->sale_price ?? 0), 2);
            $pdf->Cell(0, 3, $barcodeValue . '   ' . $price, 0, 1, 'C');
        }

        $filename = 'barcode_' . $product->id . '.pdf';
        $content  = $pdf->Output($filename, 'S');

        return response($content, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control'       => 'no-cache',
        ]);
    }
}
