<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\PurchaseItemResource;
use Illuminate\Http\Request;
use App\Models\Sale; // Import the Sale model
use App\Models\Shift;
use App\Http\Resources\SaleResource; // Reuse SaleResource for formatting
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\SaleItem;
use App\Services\Pdf\MyCustomTCPDF;
use App\Services\DailySalesPdfService;
use App\Services\InventoryPdfService;
use App\Services\SalesReportPdfService;
use App\Services\ShiftCostPdfService;
use App\Services\ShiftSalesReturnPdfService;
use Arr;
use DB;
use Carbon\Carbon; // Ensure correct Carbon namespace is used
use Illuminate\Validation\Rule; // For status validation
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

class ReportController extends Controller
{
    /**
     * Fetch Sales data based on filtering criteria for reporting.
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function salesReport(Request $request)
    {

        // --- Input Validation ---
        $validated = $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'client_id' => 'nullable|integer|exists:clients,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'shift_id' => 'nullable|integer|exists:shifts,id',
            'status' => ['nullable', 'string', Rule::in(['completed', 'pending', 'draft', 'cancelled'])],
            'per_page' => 'nullable|integer', // Control pagination size
            // Add validation for sort_by, sort_direction if implementing dynamic sorting
            'has_discount' => 'nullable|boolean',
            'product_id' => 'nullable|integer|exists:products,id',
        ]);

        // --- Authorization Check (Example - Needs Policy/Gate setup) ---
        // if ($request->user()->cannot('viewSalesReports')) {
        //     return response()->json(['message' => 'Forbidden'], 403);
        // }

        // --- Query Building ---
        $query = Sale::query()
            // Eager load necessary data for the resource and display
            // Select specific columns for performance
            ->with([
                'client:id,name', // Load only id and name from client
                'user:id,name',   // Load only id and name from user (salesperson)
                'payments.user:id,name,username' // Load payments with user relationship when filtering by shift
                // Only load items if report needs item-level detail (adds overhead)
                // 'items',
                // 'items.product:id,name,sku'
            ]);

        // --- Apply Filters ---
        // Date Range Filter - only apply if shift_id is NOT provided, or handle accordingly
        // Usually if a shift is selected, we want sales FOR THAT SHIFT regardless of the calendar date range
        if (empty($validated['shift_id'])) {
            if (!empty($validated['start_date'])) {
                $query->whereDate('sale_date', '>=', $validated['start_date']);
            }
            if (!empty($validated['end_date'])) {
                $query->whereDate('sale_date', '<=', $validated['end_date']);
            }
        }

        // Client Filter
        if (!empty($validated['client_id'])) {
            $query->where('client_id', $validated['client_id']);
        }

        // User (Salesperson) Filter
        if (!empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        // Shift Filter
        if (!empty($validated['shift_id'])) {
            $query->where('shift_id', $validated['shift_id']);
        }

        // Status Filter
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        // Product Filter
        if (!empty($validated['product_id'])) {
            $query->whereHas('items', function ($q) use ($validated) {
                $q->where('product_id', $validated['product_id']);
            });
        }

        // has_discount filter removed (discount_amount column dropped)

        // --- Sorting (Default: Newest first) ---
        $query->orderBy('sale_date', 'desc')->orderBy('id', 'desc'); // Sort by date then ID

        // --- Pagination ---
        $perPage = $validated['per_page'] ?? 25; // Default to 25 items per page
        $sales = $query->paginate($perPage);

        // --- Return Paginated Resource Collection ---
        return SaleResource::collection($sales);
    }


    /**
     * Get a summary of expenses and refunds for a given period.
     */
    public function expensesSummary(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'user_id' => 'nullable|integer',
        ]);

        $startDate = $validated['start_date'] ?? null;
        $endDate = $validated['end_date'] ?? null;
        $userId = $validated['user_id'] ?? null;

        // 1. Calculate Total Expenses from expenses table
        $expensesQuery = \App\Models\Expense::query();
        if ($startDate)
            $expensesQuery->whereDate('expense_date', '>=', $startDate);
        if ($endDate)
            $expensesQuery->whereDate('expense_date', '<=', $endDate);
        if ($userId)
            $expensesQuery->where('user_id', $userId);

        $totalExpenses = (float) $expensesQuery->sum('amount');

        // 2. Total refunds from sale_returns were removed; keep API shape but return 0
        $totalRefunds = 0.0;

        return response()->json([
            'total_expenses' => $totalExpenses,
            'total_refunds' => $totalRefunds,
        ]);
    }


    /**
     * Fetch Inventory data for reporting.
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function inventoryReport(Request $request)
    {

        $request->merge([
            'low_stock_only' => filter_var($request->input('low_stock_only'), FILTER_VALIDATE_BOOLEAN),
            'out_of_stock_only' => filter_var($request->input('out_of_stock_only'), FILTER_VALIDATE_BOOLEAN),
            'include_batches' => filter_var($request->input('include_batches'), FILTER_VALIDATE_BOOLEAN),
        ]);
        // --- Input Validation ---
        $validated = $request->validate([
            'search' => 'nullable|string|max:255', // Search by name, SKU
            // 'category_id' => 'nullable|integer|exists:categories,id', // Uncomment if categories are implemented
            'include_batches' => 'nullable|boolean', // New param to control if batches are included

            'low_stock_only' => 'nullable|boolean', // Filter for low stock items
            'out_of_stock_only' => 'nullable|boolean', // Filter for out of stock items
            'per_page' => 'nullable|integer|min:5|max:100',
            'sort_by' => 'nullable|string|in:name,sku,stock_quantity,created_at', // Allowed sort fields
            'sort_direction' => 'nullable|string|in:asc,desc',
        ]);

        // --- Authorization Check (Example) ---
        // if ($request->user()->cannot('viewInventoryReports')) { ... }

        // --- Query Building ---
        $query = Product::query();
        // Optionally include category if implemented and needed:
        // ->with('category:id,name');
        // If 'include_batches' is true, eager load available batches
        if (filter_var($validated['include_batches'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->with([
                'purchaseItems' => function ($query) {
                    $query->select(['id', 'product_id', 'batch_number', 'expiry_date', 'unit_cost', 'sale_price'])
                        ->orderBy('expiry_date', 'asc')
                        ->orderBy('created_at', 'asc');
                }
            ]);
        }
        // --- Apply Filters ---
        if (!empty($validated['search'])) {
            $searchTerm = $validated['search'];
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', "%{$searchTerm}%")
                    ->orWhere('sku', 'like', "%{$searchTerm}%");
            });
        }

        // if (!empty($validated['category_id'])) {
        //     $query->where('category_id', $validated['category_id']);
        // }

        if (filter_var($validated['low_stock_only'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNotNull('stock_alert_level')
                ->whereColumn('stock_quantity', '<=', 'stock_alert_level');
        }

        if (filter_var($validated['out_of_stock_only'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->where('stock_quantity', '<=', 0);
        }


        // --- Sorting ---
        $sortBy = $validated['sort_by'] ?? 'name'; // Default sort by name
        $sortDirection = $validated['sort_direction'] ?? 'asc';
        $query->orderBy($sortBy, $sortDirection);

        // --- Pagination ---
        $perPage = $validated['per_page'] ?? 25;
        $products = $query->with('purchaseItemsWithStock')->paginate($perPage);


        // --- Return Paginated Resource Collection ---
        // We can reuse ProductResource. It should include stock_quantity and stock_alert_level.
        // If you added accessors like latest_purchase_cost to Product model and resource, they'll be included.
        return ProductResource::collection($products);
    }
    /**
     * Fetch products/batches nearing their expiry date.
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function nearExpiryReport(Request $request)
    {
        // --- Input Validation ---
        $validated = $request->validate([
            'days_threshold' => 'nullable|integer|min:1|max:730', // e.g., 1 to 730 days (2 years)
            'product_id' => 'nullable|integer|exists:products,id',
            // 'category_id' => 'nullable|integer|exists:categories,id', // If categories implemented
            'per_page' => 'nullable|integer|min:5|max:100',
            'sort_by' => ['nullable', 'string', Rule::in(['expiry_date', 'products.name', 'purchase_items.created_at'])],
            'sort_direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);

        // --- Authorization Check ---
        // if ($request->user()->cannot('viewNearExpiryReport')) { // Define this permission
        //     abort(403, 'Unauthorized action.');
        // }

        $daysThreshold = $validated['days_threshold'] ?? 30; // Default to 30 days
        $today = Carbon::today()->toDateString(); // Get today's date as YYYY-MM-DD string
        $expiryCutoffDate = Carbon::today()->addDays($daysThreshold)->toDateString(); // Get cutoff date as string

        // --- Query Building ---
        $query = PurchaseItem::query()
            ->with(['product:id,name,sku'])
            ->where('is_moved_to_expired', false)
            ->whereNotNull('expiry_date')
            ->whereHas('product', fn($q) => $q->whereHas('warehouses', fn($q2) => $q2->where('product_warehouse.quantity', '>', 0)))
            ->whereBetween('expiry_date', [$today, $expiryCutoffDate]);

        // --- Apply Filters ---
        if (!empty($validated['product_id'])) {
            $query->where('product_id', $validated['product_id']);
        }
        // if (!empty($validated['category_id'])) {
        //     $query->whereHas('product.category', function ($q) use ($validated) {
        //         $q->where('categories.id', $validated['category_id']); // Ensure table name for ambiguity
        //     });
        // }

        // --- Sorting ---
        $sortBy = $validated['sort_by'] ?? 'expiry_date'; // Default sort by expiry date (soonest first)
        $sortDirection = $validated['sort_direction'] ?? 'asc';

        if ($sortBy === 'products.name') {
            // If sorting by product name, we need to join the products table
            $query->join('products', 'purchase_items.product_id', '=', 'products.id')
                ->orderBy('products.name', $sortDirection)
                ->select('purchase_items.*'); // Select all columns from purchase_items to avoid ambiguity
        } else {
            $query->orderBy($sortBy, $sortDirection);
        }
        // Add a secondary sort for consistency if primary sort values are the same
        if ($sortBy !== 'purchase_items.created_at' && $sortBy !== 'id') { // Avoid re-adding if already primary
            $query->orderBy('purchase_items.created_at', 'asc')->orderBy('purchase_items.id', 'asc');
        }


        // --- Pagination ---
        $perPage = $validated['per_page'] ?? 25;
        $nearExpiryItems = $query->paginate($perPage);

        // --- Return Paginated Resource Collection ---
        // PurchaseItemResource should already format expiry_date and include product info (name, sku)
        return PurchaseItemResource::collection($nearExpiryItems);
    }

    /**
     * Fetch products/batches that are already expired.
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function expiredProductsReport(Request $request)
    {
        // --- Input Validation ---
        $validated = $request->validate([
            'product_id' => 'nullable|integer|exists:products,id',
            'per_page' => 'nullable|integer|min:5|max:100',
            'sort_by' => ['nullable', 'string', Rule::in(['expiry_date', 'products.name', 'purchase_items.created_at'])],
            'sort_direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);

        $today = Carbon::today()->toDateString();

        // --- Query Building ---
        $query = PurchaseItem::query()
            ->with(['product:id,name,sku'])
            ->where('is_moved_to_expired', false)
            ->whereNotNull('expiry_date')
            ->whereHas('product', fn($q) => $q->whereHas('warehouses', fn($q2) => $q2->where('product_warehouse.quantity', '>', 0)))
            ->where('expiry_date', '<', $today);

        // --- Apply Filters ---
        if (!empty($validated['product_id'])) {
            $query->where('product_id', $validated['product_id']);
        }

        // --- Sorting ---
        $sortBy = $validated['sort_by'] ?? 'expiry_date';
        $sortDirection = $validated['sort_direction'] ?? 'asc';

        if ($sortBy === 'products.name') {
            $query->join('products', 'purchase_items.product_id', '=', 'products.id')
                ->orderBy('products.name', $sortDirection)
                ->select('purchase_items.*');
        } else {
            $query->orderBy($sortBy, $sortDirection);
        }

        if ($sortBy !== 'purchase_items.created_at' && $sortBy !== 'id') {
            $query->orderBy('purchase_items.created_at', 'asc')->orderBy('purchase_items.id', 'asc');
        }

        // --- Pagination ---
        $perPage = $validated['per_page'] ?? 25;
        $expiredItems = $query->paginate($perPage);

        // --- Return Paginated Resource Collection ---
        return PurchaseItemResource::collection($expiredItems);
    }

    /**
     * Get counts of near-expiring and expired products for badge display.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function expiryCountsSummary(Request $request)
    {
        // --- Input Validation ---
        $validated = $request->validate([
            'days_threshold' => 'nullable|integer|min:1|max:730',
        ]);

        $daysThreshold = $validated['days_threshold'] ?? 30;
        $today = Carbon::today()->toDateString();
        $expiryCutoffDate = Carbon::today()->addDays($daysThreshold)->toDateString();

        // Count near-expiring products (between today and cutoff date)
        $nearExpiringCount = PurchaseItem::query()
            ->where('is_moved_to_expired', false)
            ->whereNotNull('expiry_date')
            ->whereHas('product', fn($q) => $q->whereHas('warehouses', fn($q2) => $q2->where('product_warehouse.quantity', '>', 0)))
            ->whereBetween('expiry_date', [$today, $expiryCutoffDate])
            ->distinct('product_id')
            ->count('product_id');

        // Count expired products (before today)
        $expiredCount = PurchaseItem::query()
            ->where('is_moved_to_expired', false)
            ->whereNotNull('expiry_date')
            ->whereHas('product', fn($q) => $q->whereHas('warehouses', fn($q2) => $q2->where('product_warehouse.quantity', '>', 0)))
            ->where('expiry_date', '<', $today)
            ->distinct('product_id')
            ->count('product_id');

        return response()->json([
            'near_expiring_count' => $nearExpiringCount,
            'expired_count' => $expiredCount,
            'days_threshold' => $daysThreshold,
        ]);
    }

    /**
     * Fetch products that have been moved to expired (is_moved_to_expired = true).
     */
    public function movedExpiredProductsReport(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'nullable|integer|exists:products,id',
            'per_page' => 'nullable|integer|min:5|max:100',
            'sort_by' => ['nullable', 'string', Rule::in(['expiry_date', 'products.name', 'purchase_items.created_at'])],
            'sort_direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);

        $query = PurchaseItem::query()
            ->with(['product:id,name,sku'])
            ->where('is_moved_to_expired', true);

        if (!empty($validated['product_id'])) {
            $query->where('product_id', $validated['product_id']);
        }

        $sortBy = $validated['sort_by'] ?? 'expiry_date';
        $sortDirection = $validated['sort_direction'] ?? 'desc';

        if ($sortBy === 'products.name') {
            $query->join('products', 'purchase_items.product_id', '=', 'products.id')
                ->orderBy('products.name', $sortDirection)
                ->select('purchase_items.*');
        } else {
            $query->orderBy($sortBy, $sortDirection);
        }

        $perPage = $validated['per_page'] ?? 25;
        $movedItems = $query->paginate($perPage);

        return PurchaseItemResource::collection($movedItems);
    }

    /**
     * Move an expired product batch off the shelves by updating stock levels.
     */
    public function moveExpiredProduct($id, Request $request)
    {
        try {
            DB::beginTransaction();

            $purchaseItem = PurchaseItem::with('product')->findOrFail($id);

            // Validation: Ensure it's not already moved
            if ($purchaseItem->is_moved_to_expired) {
                return response()->json(['message' => 'Product batch has already been moved to expired.'], 400);
            }

            // Mark as moved
            $purchaseItem->is_moved_to_expired = true;
            $purchaseItem->save();

            // Deduct from ALL warehouses (or specific one, depending on how stock is currently managed)
            // Assuming we take it evenly out of wherever the stock currently resides up to the remaining items in batch.
            $product = clone $purchaseItem->product;

            // For simplicity and since we don't know EXACTLY which warehouse the batch is in, 
            // we will deduct product_warehouse quantity, starting from the first warehouse that has stock,
            // up to the total amount of this batch, or simply creating an adjustment 
            // based on what's available globally if we can't map batches to specific warehouses perfectly.
            // Since we don't have remaining_quantity on purchase_item anymore, we assume the whole batch's equivalent amount
            // needs to be removed from global stock.
            $quantityToRemove = $purchaseItem->quantity;

            if ($product && $quantityToRemove > 0) {
                $warehouses = DB::table('product_warehouse')
                    ->where('product_id', $product->id)
                    ->where('quantity', '>', 0)
                    ->orderBy('warehouse_id')
                    ->get();

                $remainingToRemove = $quantityToRemove;
                foreach ($warehouses as $wh) {
                    if ($remainingToRemove <= 0) break;

                    $deduct = min($wh->quantity, $remainingToRemove);
                    DB::table('product_warehouse')
                        ->where('product_id', $product->id)
                        ->where('warehouse_id', $wh->warehouse_id)
                        ->decrement('quantity', $deduct);

                    $remainingToRemove -= $deduct;
                }

                // Log the stock adjustment
                DB::table('stock_adjustments')->insert([
                    'product_id' => $product->id,
                    'purchase_item_id' => $purchaseItem->id,
                    'user_id' => $request->user() ? $request->user()->id : null,
                    'quantity_change' => -$quantityToRemove,
                    'quantity_before' => $product->stock_quantity, // Before all warehouse decrements (approximate)
                    'quantity_after' => max(0, $product->stock_quantity - $quantityToRemove),
                    'reason' => 'Expired - Moved',
                    'notes' => "Moved expired batch #{$purchaseItem->batch_number} off shelves.",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Product batch successfully moved to expired.',
                'data' => new PurchaseItemResource($purchaseItem)
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to move product.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Generate a Monthly Revenue Report with daily breakdown and payment method summary.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function monthlyRevenueReport(Request $request)
    {
        // --- Input Validation ---
        $validated = $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|min:2000|max:' . (Carbon::now()->year + 1),
            // Optional filters for specific client, user, etc.
            // 'client_id' => 'nullable|integer|exists:clients,id',
            // 'user_id' => 'nullable|integer|exists:users,id',
        ]);

        // --- Authorization ---
        // if ($request->user()->cannot('viewMonthlyRevenueReport')) { abort(403); }

        $year = $validated['year'];
        $month = $validated['month'];

        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth();

        // --- 1. Get Daily Total Sales (from sale_items) ---
        $dailySales = SaleItem::query()
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->select(
                DB::raw('DATE(COALESCE(sales.sale_date, sales.created_at)) as sale_day'),
                DB::raw('SUM(sale_items.total_price) as total_sales')
            )
            ->whereBetween(DB::raw('DATE(COALESCE(sales.sale_date, sales.created_at))'), [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('sale_day')
            ->get()
            ->keyBy('sale_day');

        // --- 2. Get Daily Payments by Method ---
        $dailyPaymentsQuery = Payment::query()
            ->join('sales', 'payments.sale_id', '=', 'sales.id')
            ->select(
                DB::raw('DATE(payments.payment_date) as payment_day'),
                'payments.method',
                DB::raw('SUM(payments.amount) as total_amount_by_method')
            )
            ->whereBetween(DB::raw("DATE(COALESCE(sales.sale_date, sales.created_at))"), [$startDate->toDateString(), $endDate->toDateString()])
            ->whereBetween('payments.payment_date', [$startDate, $endDate]);

        $dailyPaymentsByMethod = $dailyPaymentsQuery->groupBy('payment_day', 'payments.method')
            ->orderBy('payment_day', 'asc')
            ->orderBy('payments.method', 'asc')
            ->get()
            ->groupBy('payment_day')
            ->map(function ($paymentsOnDay) {
                return $paymentsOnDay->mapWithKeys(function ($paymentGroup) {
                    return [$paymentGroup->method => (float) $paymentGroup->total_amount_by_method];
                });
            });

        // --- 3. Get Daily Expenses ---
        $dailyExpenses = \App\Models\Expense::query()
            ->select(
                DB::raw('DATE(expense_date) as expense_day'),
                DB::raw('SUM(amount) as total_expense')
            )
            ->whereBetween('expense_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('expense_day')
            ->get()
            ->keyBy('expense_day');

        // --- 4. Combine Data for Each Day of the Month ---
        $report = [];
        $currentDay = $startDate->copy();
        $monthSummary = [
            'total_sales' => 0,
            'total_paid' => 0,
            'total_cash' => 0,
            'total_bank' => 0,
            'total_expense' => 0,
            'net' => 0,
        ];

        while ($currentDay->lte($endDate)) {
            $dayStr = $currentDay->toDateString();
            $paymentsForDay = $dailyPaymentsByMethod->get($dayStr) ?? collect([]);

            // Calculate daily totals
            $dailySalesEntry = $dailySales->get($dayStr);
            $dailyExpensesEntry = $dailyExpenses->get($dayStr);
            $dailyTotalSales = (float) ($dailySalesEntry->total_sales ?? 0);
            $dailyTotalPaid = (float) $paymentsForDay->sum();
            $dailyTotalCash = (float) ($paymentsForDay->get('cash') ?? 0);
            // Calculate bank total from all bank-related payment methods
            $bankMethods = ['bankak', 'fawry', 'ocash'];
            $dailyTotalBank = (float) $paymentsForDay->filter(function ($amount, $method) use ($bankMethods) {
                return in_array($method, $bankMethods);
            })->sum();
            $dailyTotalExpense = (float) ($dailyExpensesEntry->total_expense ?? 0);
            $dailyNet = $dailyTotalPaid - $dailyTotalExpense;

            $report[$dayStr] = [
                'date' => $dayStr,
                'total_sales' => $dailyTotalSales,
                'total_paid' => $dailyTotalPaid,
                'total_cash' => $dailyTotalCash,
                'total_bank' => $dailyTotalBank,
                'total_expense' => $dailyTotalExpense,
                'net' => $dailyNet,
            ];

            // Update month summary
            $monthSummary['total_sales'] += $dailyTotalSales;
            $monthSummary['total_paid'] += $dailyTotalPaid;
            $monthSummary['total_cash'] += $dailyTotalCash;
            $monthSummary['total_bank'] += $dailyTotalBank;
            $monthSummary['total_expense'] += $dailyTotalExpense;
            $monthSummary['net'] += $dailyNet;

            $currentDay->addDay();
        }

        return response()->json([
            'data' => [
                'year' => $year,
                'month' => $month,
                'month_name' => $startDate->isoFormat('MMMM YYYY'),
                'daily_breakdown' => array_values($report),
                'month_summary' => $monthSummary,
            ]
        ]);
    }

    /**
     * Export Monthly Revenue Report to Excel
     */
    public function monthlyRevenueExcel(Request $request)
    {
        $validated = $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|min:2000|max:' . (Carbon::now()->year + 1),
        ]);

        $year = $validated['year'];
        $month = $validated['month'];
        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth();

        // Get the same data as monthlyRevenueReport
        $dailySales = Sale::query()
            ->select(
                DB::raw('DATE(COALESCE(sale_date, created_at)) as sale_day'),
                DB::raw('SUM(total_amount) as total_sales')
            )
            ->whereBetween(DB::raw('DATE(COALESCE(sale_date, created_at))'), [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('sale_day')
            ->get()
            ->keyBy('sale_day');

        $dailyPaymentsQuery = Payment::query()
            ->join('sales', 'payments.sale_id', '=', 'sales.id')
            ->select(
                DB::raw('DATE(payments.payment_date) as payment_day'),
                'payments.method',
                DB::raw('SUM(payments.amount) as total_amount_by_method')
            )
            ->whereBetween(DB::raw("DATE(COALESCE(sales.sale_date, sales.created_at))"), [$startDate->toDateString(), $endDate->toDateString()])
            ->whereBetween('payments.payment_date', [$startDate, $endDate]);

        $dailyPaymentsByMethod = $dailyPaymentsQuery->groupBy('payment_day', 'payments.method')
            ->orderBy('payment_day', 'asc')
            ->orderBy('payments.method', 'asc')
            ->get()
            ->groupBy('payment_day')
            ->map(function ($paymentsOnDay) {
                return $paymentsOnDay->mapWithKeys(function ($paymentGroup) {
                    return [$paymentGroup->method => (float) $paymentGroup->total_amount_by_method];
                });
            });

        $dailyExpenses = \App\Models\Expense::query()
            ->select(
                DB::raw('DATE(expense_date) as expense_day'),
                DB::raw('SUM(amount) as total_expense')
            )
            ->whereBetween('expense_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('expense_day')
            ->get()
            ->keyBy('expense_day');

        // Build report data
        $report = [];
        $currentDay = $startDate->copy();
        $monthSummary = [
            'total_sales' => 0,
            'total_paid' => 0,
            'total_cash' => 0,
            'total_bank' => 0,
            'total_expense' => 0,
            'net' => 0,
        ];

        while ($currentDay->lte($endDate)) {
            $dayStr = $currentDay->toDateString();
            $paymentsForDay = $dailyPaymentsByMethod->get($dayStr) ?? collect([]);

            $dailySalesEntry = $dailySales->get($dayStr);
            $dailyExpensesEntry = $dailyExpenses->get($dayStr);
            $dailyTotalSales = (float) ($dailySalesEntry->total_sales ?? 0);
            $dailyTotalPaid = (float) $paymentsForDay->sum();
            $dailyTotalCash = (float) ($paymentsForDay->get('cash') ?? 0);
            // Calculate bank total from all bank-related payment methods
            $bankMethods = ['bankak', 'fawry', 'ocash'];
            $dailyTotalBank = (float) $paymentsForDay->filter(function ($amount, $method) use ($bankMethods) {
                return in_array($method, $bankMethods);
            })->sum();
            $dailyTotalExpense = (float) ($dailyExpensesEntry->total_expense ?? 0);
            $dailyNet = $dailyTotalPaid - $dailyTotalExpense;

            // Format date as dd,mm,yyyy
            $formattedDate = $currentDay->format('d,m,Y');

            $report[] = [
                'date' => $formattedDate,
                'total_sales' => $dailyTotalSales,
                'total_paid' => $dailyTotalPaid,
                'total_cash' => $dailyTotalCash,
                'total_bank' => $dailyTotalBank,
                'total_expense' => $dailyTotalExpense,
                'net' => $dailyNet,
            ];

            $monthSummary['total_sales'] += $dailyTotalSales;
            $monthSummary['total_paid'] += $dailyTotalPaid;
            $monthSummary['total_cash'] += $dailyTotalCash;
            $monthSummary['total_bank'] += $dailyTotalBank;
            $monthSummary['total_expense'] += $dailyTotalExpense;
            $monthSummary['net'] += $dailyNet;

            $currentDay->addDay();
        }

        // Create Excel file
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set document properties
        $spreadsheet->getProperties()
            ->setCreator('Sales System')
            ->setLastModifiedBy('Sales System')
            ->setTitle('تقرير المبيعات الشهري')
            ->setSubject('Monthly Sales Report')
            ->setDescription('تقرير المبيعات الشهري لشهر ' . $startDate->isoFormat('MMMM YYYY'));

        // Set RTL direction
        $sheet->setRightToLeft(true);

        // Header Row
        $row = 1;
        $headers = ['التاريخ', 'إجمالي المبيعات', 'إجمالي المدفوع', 'إجمالي النقدي', 'إجمالي البنكي', 'إجمالي المصروفات', 'صافي'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $row, $header);
            $col++;
        }

        // Style header row
        $headerStyle = [
            'font' => [
                'bold' => true,
                'size' => 12,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1976d2'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ];

        $lastCol = chr(ord('A') + count($headers) - 1);
        $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->applyFromArray($headerStyle);
        $sheet->getRowDimension($row)->setRowHeight(25);

        // Data rows
        $row = 2;
        foreach ($report as $dayData) {
            $col = 'A';
            $sheet->setCellValue($col . $row, $dayData['date']);
            $col++;
            $sheet->setCellValue($col . $row, number_format($dayData['total_sales'], 2));
            $col++;
            $sheet->setCellValue($col . $row, number_format($dayData['total_paid'], 2));
            $col++;
            $sheet->setCellValue($col . $row, number_format($dayData['total_cash'], 2));
            $col++;
            $sheet->setCellValue($col . $row, number_format($dayData['total_bank'], 2));
            $col++;
            $sheet->setCellValue($col . $row, number_format($dayData['total_expense'], 2));
            $col++;
            $sheet->setCellValue($col . $row, number_format($dayData['net'], 2));
            $row++;
        }

        // Total row
        $totalRow = $row;
        $col = 'A';
        $sheet->setCellValue($col . $totalRow, 'الإجمالي');
        $col++;
        $sheet->setCellValue($col . $totalRow, number_format($monthSummary['total_sales'], 2));
        $col++;
        $sheet->setCellValue($col . $totalRow, number_format($monthSummary['total_paid'], 2));
        $col++;
        $sheet->setCellValue($col . $totalRow, number_format($monthSummary['total_cash'], 2));
        $col++;
        $sheet->setCellValue($col . $totalRow, number_format($monthSummary['total_bank'], 2));
        $col++;
        $sheet->setCellValue($col . $totalRow, number_format($monthSummary['total_expense'], 2));
        $col++;
        $sheet->setCellValue($col . $totalRow, number_format($monthSummary['net'], 2));

        // Style total row
        $totalStyle = [
            'font' => [
                'bold' => true,
                'size' => 11,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'e3f2fd'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ];
        $sheet->getStyle('A' . $totalRow . ':' . $lastCol . $totalRow)->applyFromArray($totalStyle);

        // Set column widths
        $sheet->getColumnDimension('A')->setWidth(15); // Date
        $sheet->getColumnDimension('B')->setWidth(18); // Total Sales
        $sheet->getColumnDimension('C')->setWidth(18); // Total Paid
        $sheet->getColumnDimension('D')->setWidth(18); // Total Cash
        $sheet->getColumnDimension('E')->setWidth(18); // Total Bank
        $sheet->getColumnDimension('F')->setWidth(18); // Total Expense
        $sheet->getColumnDimension('G')->setWidth(18); // Net

        // Center align all cells
        $sheet->getStyle('A1:' . $lastCol . $totalRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A1:' . $lastCol . $totalRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        // Generate Excel file
        $writer = new Xlsx($spreadsheet);
        $fileName = 'daily_income_report_' . $year . '_' . str_pad($month, 2, '0', STR_PAD_LEFT) . '.xlsx';

        // Save to temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'excel_');
        $writer->save($tempFile);

        // Return file download
        return response()->download($tempFile, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Monthly Purchases Report (by total purchase cost per day within month)
     */
    public function monthlyPurchasesReport(Request $request)
    {
        $validated = $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|min:2000|max:' . (Carbon::now()->year + 1),
        ]);

        $year = $validated['year'];
        $month = $validated['month'];
        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth();

        // Assume purchases table exists with purchase_date and total_amount (cost)
        $daily = \App\Models\Purchase::query()
            ->select(
                DB::raw('DATE(COALESCE(purchase_date, created_at)) as day'),
                DB::raw('SUM(total_amount) as daily_total_cost')
            )
            ->whereBetween(DB::raw('DATE(COALESCE(purchase_date, created_at))'), [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('day')
            ->orderBy('day', 'asc')
            ->get()
            ->keyBy('day');

        $report = [];
        $summaryTotal = 0;
        $current = $startDate->copy();
        while ($current->lte($endDate)) {
            $dayStr = $current->toDateString();
            $row = $daily->get($dayStr);
            $amount = $row ? (float) $row->daily_total_cost : 0.0;
            $report[] = [
                'date' => $dayStr,
                'total_purchases_cost' => $amount,
            ];
            $summaryTotal += $amount;
            $current->addDay();
        }

        return response()->json([
            'data' => [
                'year' => $year,
                'month' => $month,
                'daily_breakdown' => $report,
                'month_summary' => [
                    'total_amount_purchases' => $summaryTotal,
                ],
            ]
        ]);
    }

    /**
     * Top selling products for a date range (default current month).
     * Returns product name and total quantity sold, ordered desc.
     */
    public function topSellingProducts(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $start = isset($validated['start_date']) ? Carbon::parse($validated['start_date'])->startOfDay() : Carbon::now()->startOfMonth();
        $end = isset($validated['end_date']) ? Carbon::parse($validated['end_date'])->endOfDay() : Carbon::now()->endOfMonth();
        $limit = $validated['limit'] ?? 10;

        $rows = SaleItem::query()
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->leftJoin('products', 'sale_items.product_id', '=', 'products.id')
            ->whereBetween(DB::raw('DATE(COALESCE(sales.sale_date, sales.created_at))'), [$start->toDateString(), $end->toDateString()])
            ->groupBy('sale_items.product_id', 'products.name')
            ->select(
                'sale_items.product_id',
                DB::raw('COALESCE(products.name, "Unknown Product") as product_name'),
                DB::raw('SUM(sale_items.quantity) as total_qty'),
                DB::raw('SUM(sale_items.total_price) as total_amount')
            )
            ->orderByDesc('total_qty')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'limit' => $limit,
            ],
        ]);
    }
    /**
     * Generate a Profit and Loss summary for a given period.
     * Calculates Revenue (from Sales) and COGS (from linked PurchaseItem batches).
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function profitLossReport(Request $request)
    {
        // --- Input Validation ---
        $validated = $request->validate([
            // Require date range for P&L
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            // Optional filters (e.g., filter profit for a specific product or client)
            'product_id' => 'nullable|integer|exists:products,id',
            'client_id' => 'nullable|integer|exists:clients,id',
        ]);

        // --- Authorization Check ---
        // if ($request->user()->cannot('viewProfitLossReport')) { ... } // Add permission if needed

        $startDate = Carbon::parse($validated['start_date'])->startOfDay();
        $endDate = Carbon::parse($validated['end_date'])->endOfDay();

        // --- Calculate Total Revenue (Based on Sales created within the period) ---
        $revenueQuery = Sale::whereBetween('sale_date', [$startDate, $endDate]);
        // Apply filters if provided
        if (!empty($validated['client_id'])) {
            $revenueQuery->where('client_id', $validated['client_id']);
        }
        // Note: Filtering by product_id for total revenue is complex, as a sale can have multiple products.
        // You'd typically calculate revenue per product separately if needed.

        $totalRevenue = (float) SaleItem::query()
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->whereBetween('sales.sale_date', [$startDate, $endDate])
            ->when(!empty($validated['client_id']), fn($q) => $q->where('sales.client_id', $validated['client_id']))
            ->sum('sale_items.total_price');


        // --- Calculate Cost of Goods Sold (COGS) ---
        // Sum the (quantity * unit_cost_from_batch) for all SaleItems linked to sales within the period.
        $cogsQuery = SaleItem::query()
            // Join with sales table to filter by sale_date
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            // Join with the *specific* purchase_item (batch) the sale item came from
            // Use leftJoin in case the link is somehow broken (though constraint should prevent)
            ->leftJoin('purchase_items', 'sale_items.purchase_item_id', '=', 'purchase_items.id')
            ->whereBetween('sales.sale_date', [$startDate, $endDate]);


        // Apply filters if provided
        if (!empty($validated['client_id'])) {
            // Filter SaleItems based on the client_id of their parent Sale
            $cogsQuery->where('sales.client_id', $validated['client_id']);
        }
        if (!empty($validated['product_id'])) {
            // Filter SaleItems directly by product_id
            $cogsQuery->where('sale_items.product_id', $validated['product_id']);
        }


        // Calculate COGS: Sum of (quantity sold * cost price from the specific batch)
        // Use purchase_items.unit_cost as the cost price for the batch
        $totalCOGS = $cogsQuery->sum('sale_items.cost_price_at_sale'); // <-- SIMPLIFIED COGS

        // --- Calculate Sale Returns ---
        $returnsQuery = \App\Models\SaleReturn::with(['items.product', 'sale.items'])
            ->whereBetween('created_at', [$startDate, $endDate]);

        if (!empty($validated['client_id'])) {
            $returnsQuery->whereHas('sale', fn($q) => $q->where('client_id', $validated['client_id']));
        }

        $returns = $returnsQuery->get();
        $totalReturnsValue = 0;
        $totalReturnsCost = 0;

        foreach ($returns as $return) {
            foreach ($return->items as $returnItem) {
                if (!empty($validated['product_id']) && $returnItem->product_id != $validated['product_id']) {
                    continue;
                }

                $totalReturnsValue += ($returnItem->price * $returnItem->quantity);

                // Calculate Return Cost based on original sale average cost
                $originalSaleItems = $return->sale->items->where('product_id', $returnItem->product_id);
                if ($originalSaleItems->isNotEmpty()) {
                    $totalSaleCost = $originalSaleItems->sum('cost_price_at_sale');
                    $totalSaleQty = $originalSaleItems->sum('quantity');
                    $avgCost = $totalSaleQty > 0 ? $totalSaleCost / $totalSaleQty : 0;
                    $totalReturnsCost += ($avgCost * $returnItem->quantity);
                } else {
                    $product = $returnItem->product;
                    $currentCost = $product ? $product->cost_price : 0;
                    $totalReturnsCost += ($currentCost * $returnItem->quantity);
                }
            }
        }

        // --- Calculate Net Values ---
        $netRevenue = $totalRevenue - $totalReturnsValue;
        $netCOGS = max(0, $totalCOGS - $totalReturnsCost);

        // --- Calculate Gross Profit ---
        $grossProfit = $netRevenue - $netCOGS;

        // --- Calculate Expenses ---
        $expensesQuery = \App\Models\Expense::whereBetween('expense_date', [$startDate, $endDate]);
        if (!empty($validated['user_id'])) { // If user filter is relevant for P&L context
            $expensesQuery->where('user_id', $validated['user_id']);
        }
        $totalExpenses = (float) $expensesQuery->sum('amount');

        // --- Calculate Net Profit ---
        $netProfit = $grossProfit - $totalExpenses;

        // --- (Optional) Calculate Total Purchase Costs (different from COGS) ---
        // This is the total value of goods *purchased* in the period, not necessarily sold.
        // $totalPurchaseCost = Purchase::whereBetween('purchase_date', [$startDate, $endDate])
        //                               ->where('status', 'received') // Only count received purchases
        //                               ->sum('total_amount');

        // --- Prepare Response Data ---
        $reportData = [
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'filters' => Arr::only($validated, ['client_id', 'product_id']), // Show applied filters
            'revenue' => (float) $netRevenue,
            'gross_revenue' => (float) $totalRevenue,
            'returns_value' => (float) $totalReturnsValue,
            'cost_of_goods_sold' => (float) $netCOGS,
            'gross_cogs' => (float) $totalCOGS,
            'returns_cost' => (float) $totalReturnsCost,
            'gross_profit' => (float) $grossProfit,
            'total_expenses' => (float) $totalExpenses,
            'net_profit' => (float) $netProfit,
            // 'total_purchase_cost' => (float) $totalPurchaseCost, // Optional
        ];

        return response()->json(['data' => $reportData]);
    }
    public function downloadSalesReportPDF(Request $request)
    {
        // Validate Input Parameters
        $validated = $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'client_id' => 'nullable|integer|exists:clients,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'shift_id' => 'nullable|integer|exists:shifts,id',
            'status' => ['nullable', 'string', Rule::in(['completed', 'pending', 'draft', 'cancelled'])],
            'has_discount' => 'nullable|boolean',
        ]);

        // Fetch and Filter Sales Data
        $query = Sale::query()->with([
            'client:id,name',
            'user:id,name',
            'payments.user:id,name,username'
        ]);

        $startDate = isset($validated['start_date'])
            ? Carbon::parse($validated['start_date'])->startOfDay()
            : null;
        $endDate = isset($validated['end_date'])
            ? Carbon::parse($validated['end_date'])->endOfDay()
            : null;

        if (!empty($validated['shift_id'])) {
            $query->where('shift_id', $validated['shift_id']);
        } else {
            if ($startDate) {
                $query->whereDate('sale_date', '>=', $startDate);
            }
            if ($endDate) {
                $query->whereDate('sale_date', '<=', $endDate);
            }
        }

        if (!empty($validated['client_id'])) {
            $query->where('client_id', $validated['client_id']);
        }
        if (!empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }
        if (!empty($validated['shift_id'])) {
            $query->where('shift_id', $validated['shift_id']);
        }
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (array_key_exists('has_discount', $validated)) {
            $hasDiscount = filter_var($validated['has_discount'], FILTER_VALIDATE_BOOLEAN);
            if ($hasDiscount) {
                $query->where('discount_amount', '>', 0);
            } else {
                $query->where(function ($q) {
                    $q->whereNull('discount_amount')->orWhere('discount_amount', '<=', 0);
                });
            }
        }
        $sales = $query->orderBy('sale_date', 'desc')->orderBy('id', 'desc')->get();
        $sales->load(['items', 'payments']);

        $totalAmount = (float) $sales->sum(fn($s) => $s->items->sum('total_price'));
        $totalPaid = (float) $sales->sum(fn($s) => $s->payments->sum('amount'));
        $totalSales = $sales->count();
        $totalDiscount = 0;
        $totalDue = max(0, $totalAmount - $totalPaid);

        // Calculate Total Expenses for the period
        $expensesQuery = \App\Models\Expense::query();

        if (!empty($validated['shift_id'])) {
            $expensesQuery->where('shift_id', $validated['shift_id']);
        } else {
            if ($startDate) {
                $expensesQuery->whereDate('expense_date', '>=', $startDate);
            }
            if ($endDate) {
                $expensesQuery->whereDate('expense_date', '<=', $endDate);
            }
        }
        // Apply user filter if provided
        if (!empty($validated['user_id'])) {
            $expensesQuery->where('user_id', $validated['user_id']);
        }
        $totalExpenses = (float) $expensesQuery->sum('amount');

        // Expense breakdown by payment method (cash / bank) for popup-style summary
        $expensesForBreakdown = \App\Models\Expense::query();
        if (!empty($validated['shift_id'])) {
            $expensesForBreakdown->where('shift_id', $validated['shift_id']);
        } else {
            if ($startDate) {
                $expensesForBreakdown->whereDate('expense_date', '>=', $startDate);
            }
            if ($endDate) {
                $expensesForBreakdown->whereDate('expense_date', '<=', $endDate);
            }
        }
        if (!empty($validated['user_id'])) {
            $expensesForBreakdown->where('user_id', $validated['user_id']);
        }
        $expensesByMethodData = $expensesForBreakdown->get();
        // Manually group to ensure all methods are covered
        $expensesByMethod = [
            'cash' => 0,
            'bankak' => 0,
            'fawry' => 0,
            'ocash' => 0,
            'bank' => 0 // Generic bank/visa
        ];
        foreach ($expensesByMethodData as $exp) {
            $method = $exp->payment_method ?? 'cash';
            if (!isset($expensesByMethod[$method])) $expensesByMethod[$method] = 0;
            $expensesByMethod[$method] += (float)$exp->amount;
        }

        // Sales Returns Breakdown
        $returnsQuery = \App\Models\SaleReturn::query();
        if (!empty($validated['shift_id'])) {
            $returnsQuery->where('shift_id', $validated['shift_id']);
        } else {
            if ($startDate) {
                $returnsQuery->whereDate('created_at', '>=', $startDate);
            }
            if ($endDate) {
                $returnsQuery->whereDate('created_at', '<=', $endDate);
            }
        }
        if (!empty($validated['user_id'])) {
            $returnsQuery->where('user_id', $validated['user_id']);
        }
        $returnsData = $returnsQuery->with('items')->get();

        $returnsByMethod = [
            'cash' => 0,
            'bankak' => 0,
            'fawry' => 0,
            'ocash' => 0
        ];
        $totalReturns = 0;

        foreach ($returnsData as $ret) {
            // Calculate total return amount from items
            $returnTotal = $ret->items->sum(fn($i) => $i->quantity * $i->price);
            $method = $ret->returned_payment_method ?? 'cash';

            if (!isset($returnsByMethod[$method])) $returnsByMethod[$method] = 0;
            $returnsByMethod[$method] += $returnTotal;
            $totalReturns += $returnTotal;
        }

        // Payment methods breakdown (sales payments: cash, bankak, etc.)
        $paymentMethods = [
            'cash' => 0,
            'bankak' => 0,
            'fawry' => 0,
            'ocash' => 0,
            'visa' => 0,
            'bank_transfer' => 0
        ];

        foreach ($sales as $sale) {
            foreach ($sale->payments as $payment) {
                $method = $payment->method ?? 'cash';
                if (!isset($paymentMethods[$method])) {
                    $paymentMethods[$method] = 0;
                }
                $paymentMethods[$method] += (float) $payment->amount;
            }
        }

        // Load shift when filtering by shift_id (for popup-style header)
        $shift = null;
        if (!empty($validated['shift_id'])) {
            $shift = Shift::with('user')->find($validated['shift_id']);
        }

        // Generate PDF using service
        $pdfService = new SalesReportPdfService();
        $summaryStats = [
            'totalSales' => $totalSales,
            'totalAmount' => $totalAmount,
            'totalPaid' => $totalPaid,
            'totalDue' => $totalDue,
            'totalDiscount' => $totalDiscount,
            'totalExpenses' => $totalExpenses,
            'totalReturns' => $totalReturns, // Add total returns
            'expenses_breakdown' => $expensesByMethod, // Pass full breakdown
            'returns_breakdown' => $returnsByMethod,   // Pass full breakdown
            'shift' => $shift ? [
                'id' => $shift->id,
                'opened_at' => $shift->opened_at?->format('Y-m-d H:i'),
                'user_name' => $shift->user?->name,
            ] : null,
        ];

        // Get base URL for hyperlinks
        $baseUrl = $request->getSchemeAndHttpHost() . $request->getBasePath();

        $pdfContent = $pdfService->generate(
            $sales,
            $validated,
            $summaryStats,
            $paymentMethods,
            $startDate,
            $endDate,
            $baseUrl
        );

        // Output PDF
        $pdfFileName = 'sales_report_' . now()->format('Y-m-d_His') . '.pdf';

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "inline; filename=\"{$pdfFileName}\"");
    }

    /**
     * Download PDF report for a single sale with full details
     *
     * @param Request $request
     * @param int $saleId
     * @return \Illuminate\Http\Response
     */
    public function downloadSaleDetailPDF(Request $request, int $saleId)
    {
        // Load sale with all relationships
        $sale = Sale::with([
            'client:id,name',
            'user:id,name',
            'items.product:id,name,sku',
            'payments.user:id,name'
        ])->findOrFail($saleId);

        // Generate PDF using service
        $pdfService = new \App\Services\SaleDetailPdfService();
        $pdfContent = $pdfService->generate($sale);

        // Output PDF
        $pdfFileName = 'sale_detail_' . $saleId . '_' . now()->format('Y-m-d_His') . '.pdf';

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "inline; filename=\"{$pdfFileName}\"");
    }

    private function calculateSalesSummary($sales)
    {
        $sales->load(['items', 'payments']);
        $totalSales = $sales->count();
        $totalAmount = (float) $sales->sum(fn($s) => $s->items->sum('total_price'));
        $totalPaid = (float) $sales->sum(fn($s) => $s->payments->sum('amount'));
        $totalDue = max(0, $totalAmount - $totalPaid);

        $paymentMethods = $sales->flatMap->payments->groupBy('method')->map->sum('amount');

        $topClients = $sales->groupBy('client_id')
            ->map(function ($clientSales) {
                return [
                    'name' => $clientSales->first()->client?->name ?? 'Unknown',
                    'total' => (float) $clientSales->sum(fn($s) => $s->items->sum('total_price')),
                    'count' => $clientSales->count()
                ];
            })
            ->sortByDesc('total')
            ->take(5);

        return [
            'total_sales' => $totalSales,
            'total_amount' => $totalAmount,
            'total_paid' => $totalPaid,
            'total_due' => $totalDue,
            'completion_rate' => 0,
            'status_breakdown' => [],
            'payment_methods' => $paymentMethods,
            'top_clients' => $topClients
        ];
    }

    private function generateProfessionalPDFHeader($pdf, $startDate, $endDate, $filters)
    {
        // Company Information
        $settings = (new \App\Services\SettingsService())->getAll();
        $companyName = $settings['company_name'] ?? 'Your Company';
        $companyAddress = $settings['company_address'] ?? '';
        $companyPhone = $settings['company_phone'] ?? '';
        $companyEmail = $settings['company_email'] ?? '';

        // Header with company logo area (left side)
        $pdf->SetY(20);
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 18);
        $pdf->Cell(0, 10, $companyName, 0, 1, 'C');

        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 10);
        if ($companyAddress) {
            $pdf->Cell(0, 6, $companyAddress, 0, 1, 'C');
        }
        if ($companyPhone) {
            $pdf->Cell(0, 6, 'Phone: ' . $companyPhone, 0, 1, 'C');
        }
        if ($companyEmail) {
            $pdf->Cell(0, 6, 'Email: ' . $companyEmail, 0, 1, 'C');
        }

        $pdf->Ln(5);

        // Report Title and Date Range
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 16);
        $pdf->Cell(0, 10, 'Professional Sales Report', 0, 1, 'C');

        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 12);
        $formattedStartDate = $startDate ? $startDate->format('F j, Y') : 'All Time';
        $formattedEndDate = $endDate ? $endDate->format('F j, Y') : 'All Time';
        $pdf->Cell(0, 8, "Period: {$formattedStartDate} to {$formattedEndDate}", 0, 1, 'C');

        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 10);
        $pdf->Cell(0, 6, 'Generated on: ' . now()->format('F j, Y \a\t g:i A'), 0, 1, 'C');

        // Applied Filters - Enhanced and more prominent
        $appliedFilters = [];
        if (!empty($filters['client_id'])) {
            $client = \App\Models\Client::find($filters['client_id']);
            $appliedFilters[] = 'Client: ' . ($client ? $client->name : 'Unknown');
        }
        if (!empty($filters['user_id'])) {
            $user = \App\Models\User::find($filters['user_id']);
            $appliedFilters[] = 'Salesperson: ' . ($user ? $user->name : 'Unknown');
        }
        if (!empty($filters['status'])) {
            $appliedFilters[] = 'Status: ' . ucfirst($filters['status']);
        }

        // Enhanced filters display
        if (!empty($appliedFilters)) {
            $pdf->Ln(5);
            $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 11);
            $pdf->SetFillColor(240, 240, 240);
            $pdf->Cell(0, 8, 'APPLIED FILTERS', 1, 1, 'C', true);

            $pdf->SetFont($pdf->getDefaultFontFamily(), '', 10);
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Cell(0, 6, implode(' | ', $appliedFilters), 1, 1, 'C', true);
        } else {
            $pdf->Ln(5);
            $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 11);
            $pdf->SetFillColor(240, 240, 240);
            $pdf->Cell(0, 8, 'FILTERS: No specific filters applied - showing all data', 1, 1, 'C', true);
        }

        $pdf->Ln(8);
    }

    private function generateTotalIncomeSection($pdf, $summaryStats)
    {
        // Total Income Section at the top
        $pdf->addSectionHeader('Total Income Summary');

        // Large prominent total income display
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 16);
        $pdf->SetFillColor(240, 248, 255); // Light blue background
        $pdf->Cell(0, 12, 'Total Revenue: ' . number_format($summaryStats['total_amount'], 0), 1, 1, 'C', true);
        $pdf->Ln(5);

        // Income breakdown in a table format
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 12);
        $pdf->Cell(0, 8, 'Income Breakdown', 0, 1, 'L');
        $pdf->Ln(2);

        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 10);
        $incomeData = [
            'Total Sales Amount' => number_format($summaryStats['total_amount'], 0),
            'Total Paid Amount' => number_format($summaryStats['total_paid'], 0),
            'Total Due Amount' => number_format($summaryStats['total_due'], 0),
            'Number of Sales' => $summaryStats['total_sales'],
            'Average Sale Value' => $summaryStats['total_sales'] > 0 ? number_format($summaryStats['total_amount'] / $summaryStats['total_sales'], 0) : '0'
        ];

        $pdf->addSummaryBox('Income Details', $incomeData, 2);
        $pdf->Ln(10);
    }

    private function generatePaymentsBreakdownSection($pdf, $summaryStats)
    {
        // Payments Breakdown Section
        $pdf->addSectionHeader('Payments Breakdown');

        // Payment Methods Distribution
        if ($summaryStats['payment_methods']->count() > 0) {
            $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 12);
            $pdf->Cell(0, 8, 'Payment Methods Distribution', 0, 1, 'L');
            $pdf->Ln(2);

            $paymentData = [];
            foreach ($summaryStats['payment_methods'] as $method => $amount) {
                $percentage = $summaryStats['total_paid'] > 0 ? ($amount / $summaryStats['total_paid']) * 100 : 0;
                $paymentData[ucfirst($method)] = number_format($amount, 0) . ' (' . number_format($percentage, 1) . '%)';
            }
            $pdf->addSummaryBox('Payment Methods', $paymentData, 1);
        }

        // Sales Status Breakdown
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 12);
        $pdf->Cell(0, 8, 'Sales Status Breakdown', 0, 1, 'L');
        $pdf->Ln(2);

        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 10);
        $statuses = [
            'completed' => ['Completed', 'green'],
            'pending' => ['Pending', 'orange'],
            'draft' => ['Draft', 'gray'],
            'cancelled' => ['Cancelled', 'red']
        ];

        foreach ($statuses as $status => $info) {
            $count = $summaryStats['status_breakdown'][$status] ?? 0;
            $percentage = $summaryStats['total_sales'] > 0 ? ($count / $summaryStats['total_sales']) * 100 : 0;

            $pdf->Cell(47, 6, $info[0] . ': ' . $count . ' (' . number_format($percentage, 1) . '%)', 1, 0, 'C');
        }
        $pdf->Ln(8);

        // Top Clients (if any)
        if ($summaryStats['top_clients']->count() > 0) {
            $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 12);
            $pdf->Cell(0, 8, 'Top 5 Clients by Revenue', 0, 1, 'L');
            $pdf->Ln(2);

            $clientData = [];
            foreach ($summaryStats['top_clients'] as $client) {
                $clientData[$client['name']] = number_format($client['total'], 0) . ' (' . $client['count'] . ' sales)';
            }
            $pdf->addSummaryBox('Top Clients', $clientData, 1);
        }

        $pdf->Ln(10);
    }

    private function generateSalesTable($pdf, $sales)
    {
        // Professional Section Header
        $pdf->addSectionHeader('Detailed Sales Transactions');

        // Add a brief description
        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 10);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 6, 'Comprehensive breakdown of all sales transactions with detailed financial and product information', 0, 1, 'L');
        $pdf->Ln(3);

        if ($sales->isEmpty()) {
            $pdf->SetFont($pdf->getDefaultFontFamily(), '', 12);
            $pdf->SetTextColor(128, 128, 128);
            $pdf->Cell(0, 15, 'No sales transactions found for the selected period.', 1, 1, 'C');
            return;
        }

        // Professional Table Headers with optimized column names
        $headers = [
            'ID',
            'Amount',
            'Paid',
            'Discount',
            'Date',
            'User',
            'Items'
        ];
        // Optimized column widths for landscape A4 (297mm width)
        // Total width: 20+25+20+20+30+25+35 = 175mm (leaving margin for borders)
        $columnWidths = [20, 25, 20, 20, 30, 25, 35];

        $pdf->addTableHeader($headers, $columnWidths);

        // Table Data with enhanced formatting
        $fill = false;

        foreach ($sales as $sale) {
            $saleTotal = (float) $sale->items->sum('total_price');
            $salePaid = (float) $sale->payments->sum('amount');

            $itemNames = $sale->items->map(function ($item) {
                $productName = $item->product?->name ?? 'Unknown';
                $quantity = $item->quantity ?? 1;
                return $productName . ' (x' . $quantity . ')';
            })->implode(', ');

            if (strlen($itemNames) > 30) {
                $itemNames = substr($itemNames, 0, 27) . '...';
            }

            $statusColor = $this->getStatusColor('completed');

            $totalAmount = number_format($saleTotal, 0);
            $paidAmount = number_format($salePaid, 0);
            $discountAmount = '0';

            $saleDate = Carbon::parse($sale->sale_date)->format('M d, H:i');

            $userName = $sale->user?->name ?? 'System';
            if (strlen($userName) > 20) {
                $userName = substr($userName, 0, 17) . '...';
            }

            $transactionId = '#' . $sale->id;

            $rowData = [
                $transactionId,
                $totalAmount,
                $paidAmount,
                $discountAmount,
                $saleDate,
                $userName,
                $itemNames
            ];

            $pdf->addTableRow($rowData, $columnWidths, 8, $fill, $statusColor);
            $fill = !$fill;
        }

        // Professional Summary Section
        $pdf->Ln(5);
        $this->generateProfessionalSummaryRow($pdf, $sales, $columnWidths);

        // Add additional statistics
        $this->generateSalesStatistics($pdf, $sales);
    }

    private function generateProfessionalSummaryRow($pdf, $sales, $columnWidths)
    {
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 10);
        $pdf->SetFillColor(70, 130, 180);
        $pdf->SetTextColor(255, 255, 255);

        $totalAmount = (float) $sales->sum(fn($s) => $s->items->sum('total_price'));
        $totalPaid = (float) $sales->sum(fn($s) => $s->payments->sum('amount'));
        $totalDiscount = 0;
        $totalDue = max(0, $totalAmount - $totalPaid);

        $summaryData = [
            'TOTAL',
            number_format($totalAmount, 0),
            number_format($totalPaid, 0),
            number_format($totalDiscount, 0),
            $sales->count() . ' sales',
            'All Users',
            'All Items'
        ];

        foreach ($summaryData as $i => $cellData) {
            $pdf->Cell($columnWidths[$i], 10, $cellData, 1, 0, 'C', true);
        }
        $pdf->Ln(12);

        // Reset colors
        $pdf->SetTextColor(0, 0, 0);
    }

    private function generateSalesStatistics($pdf, $sales)
    {
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 11);
        $pdf->Cell(0, 8, 'Transaction Analysis', 0, 1, 'L');
        $pdf->Ln(3);

        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 9);

        $totalAmount = (float) $sales->sum(fn($s) => $s->items->sum('total_price'));
        $totalPaid = (float) $sales->sum(fn($s) => $s->payments->sum('amount'));
        $totalDue = max(0, $totalAmount - $totalPaid);
        $avgSaleValue = $sales->count() > 0 ? $totalAmount / $sales->count() : 0;
        $paymentRate = $totalAmount > 0 ? ($totalPaid / $totalAmount) * 100 : 0;

        $completedCount = $sales->count();
        $pendingCount = 0;

        // Create statistics in a professional format
        $statsData = [
            'Average Transaction Value' => number_format($avgSaleValue, 0),
            'Payment Collection Rate' => number_format($paymentRate, 1) . '%',
            'Outstanding Amount' => number_format($totalDue, 0),
            'Completed Transactions' => $completedCount,
            'Pending Transactions' => $pendingCount,
            'Total Products Sold' => $sales->flatMap->items->sum('quantity')
        ];

        $pdf->addSummaryBox('Key Performance Metrics', $statsData, 2);
        $pdf->Ln(8);
    }

    private function getStatusColor($status)
    {
        switch ($status) {
            case 'completed':
                return [220, 255, 220]; // Professional light green
            case 'pending':
                return [255, 248, 220]; // Professional light yellow/cream
            case 'draft':
                return [245, 245, 245]; // Professional light gray
            case 'cancelled':
                return [255, 235, 235]; // Professional light red
            default:
                return [255, 255, 255]; // White
        }
    }

    private function generateFooter($pdf)
    {
        $pdf->SetY(-30);
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'I', 8);
        $pdf->SetTextColor(128);

        $pdf->Cell(0, 5, 'This report was generated automatically by the sales management system.', 0, 1, 'C');
        $pdf->Cell(0, 5, 'For questions or support, please contact your system administrator.', 0, 1, 'C');
        $pdf->Cell(0, 5, 'Report generated on: ' . now()->format('Y-m-d H:i:s'), 0, 1, 'C');
    }

    /**
     * Generate inventory PDF report
     *
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function inventoryPdf(Request $request)
    {


        // Validate request
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'low_stock_only' => 'nullable|boolean',
            'out_of_stock_only' => 'nullable|boolean',
        ]);

        // Convert string values to boolean
        $validated['low_stock_only'] = filter_var($validated['low_stock_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $validated['out_of_stock_only'] = filter_var($validated['out_of_stock_only'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            $inventoryPdfService = new InventoryPdfService();
            $pdfContent = $inventoryPdfService->generateInventoryPdf($validated);

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="inventory_report_' . now()->format('Y-m-d_H-i-s') . '.pdf"');
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to generate PDF: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Generate daily sales PDF report
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function dailySalesPdf(Request $request)
    {


        // Validate parameters
        $validated = $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'user_id' => 'nullable|integer|exists:users,id',
            'product_id' => 'nullable|integer|exists:products,id',
            'sale_id' => 'nullable|integer|exists:sales,id',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
        ]);

        // Generate PDF using the service with filters
        $pdfService = new DailySalesPdfService();
        $pdfContent = $pdfService->generateDailySalesPdf($validated);

        // Return PDF response
        $filename = 'sales_report_' . now()->format('Y-m-d_H-i-s') . '.pdf';

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "inline; filename=\"{$filename}\"");
    }

    /**
     * Get monthly expenses report grouped by day
     */
    public function monthlyExpenses(Request $request)
    {
        $validated = $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|min:2000|max:' . (Carbon::now()->year + 1),
        ]);

        $year = $validated['year'];
        $month = $validated['month'];
        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth();

        // Get all expenses for the month
        $expenses = \App\Models\Expense::query()
            ->with(['category:id,name', 'user:id,name'])
            ->whereBetween('expense_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->orderBy('expense_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // Group expenses by date
        $expensesByDate = $expenses->groupBy(function ($expense) {
            return Carbon::parse($expense->expense_date)->format('Y-m-d');
        });

        // Build daily breakdown
        $dailyBreakdown = [];
        $currentDay = $startDate->copy();
        $monthSummary = [
            'total' => 0,
            'cash_total' => 0,
            'bank_total' => 0,
        ];

        while ($currentDay->lte($endDate)) {
            $dayStr = $currentDay->toDateString();
            $dayExpenses = $expensesByDate->get($dayStr) ?? collect([]);

            $dayTotal = $dayExpenses->sum('amount');
            $dayCashTotal = $dayExpenses->where('payment_method', 'cash')->sum('amount');
            $dayBankTotal = $dayExpenses->where('payment_method', 'bank')->sum('amount');

            $dailyBreakdown[] = [
                'date' => $dayStr,
                'total' => (float) $dayTotal,
                'cash_total' => (float) $dayCashTotal,
                'bank_total' => (float) $dayBankTotal,
                'expenses' => $dayExpenses->map(function ($expense) {
                    return [
                        'id' => $expense->id,
                        'title' => $expense->title,
                        'description' => $expense->description,
                        'amount' => (float) $expense->amount,
                        'expense_date' => $expense->expense_date,
                        'payment_method' => $expense->payment_method,
                        'reference' => $expense->reference,
                        'expense_category_id' => $expense->expense_category_id,
                        'expense_category_name' => $expense->category?->name,
                        'user_id' => $expense->user_id,
                        'user_name' => $expense->user?->name,
                    ];
                })->values()->toArray(),
            ];

            $monthSummary['total'] += $dayTotal;
            $monthSummary['cash_total'] += $dayCashTotal;
            $monthSummary['bank_total'] += $dayBankTotal;

            $currentDay->addDay();
        }

        $monthName = $startDate->isoFormat('MMMM');

        return response()->json([
            'year' => $year,
            'month' => $month,
            'month_name' => $monthName,
            'daily_breakdown' => $dailyBreakdown,
            'month_summary' => $monthSummary,
        ]);
    }

    /**
     * Export Monthly Expenses Report to Excel
     */
    public function monthlyExpensesExcel(Request $request)
    {
        $validated = $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|min:2000|max:' . (Carbon::now()->year + 1),
        ]);

        $year = $validated['year'];
        $month = $validated['month'];
        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth();

        // Get the same data as monthlyExpenses
        $expenses = \App\Models\Expense::query()
            ->whereBetween('expense_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->orderBy('expense_date', 'asc')
            ->get();

        $expensesByDate = $expenses->groupBy(function ($expense) {
            return Carbon::parse($expense->expense_date)->format('Y-m-d');
        });

        // Build report data
        $report = [];
        $currentDay = $startDate->copy();
        $monthSummary = [
            'total' => 0,
            'cash_total' => 0,
            'bank_total' => 0,
        ];

        while ($currentDay->lte($endDate)) {
            $dayStr = $currentDay->toDateString();
            $dayExpenses = $expensesByDate->get($dayStr) ?? collect([]);

            $dayTotal = $dayExpenses->sum('amount');
            $dayCashTotal = $dayExpenses->where('payment_method', 'cash')->sum('amount');
            $dayBankTotal = $dayExpenses->where('payment_method', 'bank')->sum('amount');

            $report[] = [
                'date' => $dayStr,
                'total' => (float) $dayTotal,
                'cash_total' => (float) $dayCashTotal,
                'bank_total' => (float) $dayBankTotal,
            ];

            $monthSummary['total'] += $dayTotal;
            $monthSummary['cash_total'] += $dayCashTotal;
            $monthSummary['bank_total'] += $dayBankTotal;

            $currentDay->addDay();
        }

        // Create Excel file
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set document properties
        $spreadsheet->getProperties()
            ->setCreator('Sales System')
            ->setLastModifiedBy('Sales System')
            ->setTitle('تقرير المصروفات الشهري')
            ->setSubject('Monthly Expenses Report')
            ->setDescription('تقرير المصروفات الشهري لشهر ' . $startDate->isoFormat('MMMM YYYY'));

        // Set RTL direction
        $sheet->setRightToLeft(true);

        // Header Row
        $row = 1;
        $headers = ['التاريخ', 'إجمالي المصروفات', 'نقدي', 'بنكي'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $row, $header);
            $col++;
        }

        // Style header row
        $headerStyle = [
            'font' => [
                'bold' => true,
                'size' => 12,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1976d2'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ];

        $lastCol = chr(ord('A') + count($headers) - 1);
        $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->applyFromArray($headerStyle);
        $sheet->getRowDimension($row)->setRowHeight(25);

        // Data rows
        $row = 2;
        foreach ($report as $dayData) {
            $col = 'A';
            $sheet->setCellValue($col . $row, $dayData['date']);
            $col++;
            $sheet->setCellValue($col . $row, number_format($dayData['total'], 2));
            $col++;
            $sheet->setCellValue($col . $row, number_format($dayData['cash_total'], 2));
            $col++;
            $sheet->setCellValue($col . $row, number_format($dayData['bank_total'], 2));
            $row++;
        }

        // Summary row
        $row++;
        $sheet->setCellValue('A' . $row, 'الإجمالي');
        $sheet->setCellValue('B' . $row, number_format($monthSummary['total'], 2));
        $sheet->setCellValue('C' . $row, number_format($monthSummary['cash_total'], 2));
        $sheet->setCellValue('D' . $row, number_format($monthSummary['bank_total'], 2));

        // Style summary row
        $summaryStyle = [
            'font' => [
                'bold' => true,
                'size' => 12,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E3F2FD'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ];
        $sheet->getStyle('A' . $row . ':D' . $row)->applyFromArray($summaryStyle);

        // Auto-size columns
        foreach (range('A', 'D') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Set data format for numeric columns
        $sheet->getStyle('B2:D' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');

        // Generate Excel file
        $writer = new Xlsx($spreadsheet);
        $filename = 'monthly_expenses_' . $year . '_' . str_pad($month, 2, '0', STR_PAD_LEFT) . '.xlsx';

        $tempFile = tempnam(sys_get_temp_dir(), 'expenses_');
        $writer->save($tempFile);

        return response()->download($tempFile, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    // --- Placeholder for other report methods ---
    /*
    public function purchasesReport(Request $request) { ... }
    public function inventoryReport(Request $request) { ... }
    public function profitLossReport(Request $request) { ... }
    */

    /**
     * Get best selling products based on quantity sold in a given period.
     */
    public function bestSelling(Request $request)
    {
        $days = (int) $request->input('days', 30);
        $limit = (int) $request->input('limit', 10);

        $startDate = Carbon::now()->subDays($days)->startOfDay();

        $bestSelling = SaleItem::select('product_id', DB::raw('SUM(quantity) as total_quantity_sold'), DB::raw('SUM(total_price) as total_revenue'))
            ->where('created_at', '>=', $startDate)
            ->groupBy('product_id')
            ->orderByDesc('total_quantity_sold')
            ->limit($limit)
            ->with(['product' => function ($query) {
                // Ensure we select necessary columns and calculate total stock properly 
                $query->select('id', 'name', 'sku', 'image_url', 'category_id')->with('category:id,name', 'warehouses');
            }])
            ->get();

        // Map data to simpler structure
        $results = $bestSelling->map(function ($item) {
            $product = $item->product;
            return [
                'id' => $product ? $product->id : null,
                'name' => $product ? $product->name : 'Unknown Product',
                'sku' => $product ? $product->sku : null,
                'category_name' => ($product && $product->category) ? $product->category->name : 'N/A',
                'image_url' => $product ? $product->image_url : null,
                'total_quantity_sold' => (int) $item->total_quantity_sold,
                'total_revenue' => (float) $item->total_revenue,
                'current_stock' => $product ? $product->total_stock : 0,
            ];
        })->filter(function ($item) {
            return $item['id'] !== null;
        })->values();

        return response()->json(['data' => $results]);
    }

    /**
     * Get stagnant products that have stock but haven't been sold recently.
     */
    public function stagnant(Request $request)
    {
        $months = (int) $request->input('months', 3);
        $limit = (int) $request->input('limit', 20);

        $dateThreshold = Carbon::now()->subMonths($months)->startOfDay();

        $stagnantProducts = Product::whereHas('warehouses', function ($q) {
            // Must have stock
            $q->where('product_warehouse.quantity', '>', 0);
        })
            ->whereDoesntHave('saleItems', function ($query) use ($dateThreshold) {
                // Must NOT have sales since the threshold date
                $query->where('created_at', '>=', $dateThreshold);
            })
            ->with(['category:id,name', 'warehouses'])
            ->withSum('saleItems', 'quantity') // To see lifetime sales
            ->get(); // Get a collection and then we can sort by computed property

        $results = $stagnantProducts->map(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category_name' => $product->category ? $product->category->name : 'N/A',
                'stock_quantity' => $product->total_stock,
                'lifetime_sales' => (int) $product->sale_items_sum_quantity,
            ];
        })->sortByDesc('stock_quantity')->take($limit)->values();

        return response()->json(['data' => $results]);
    }

    /**
     * Get products expiring within the next X months.
     */
    public function expiring(Request $request)
    {
        $months = (int) $request->input('months', 3);
        $limit = (int) $request->input('limit', 20);

        $dateThreshold = Carbon::now()->addMonths($months)->endOfDay();
        $now = Carbon::now()->startOfDay();

        // Get products with stock
        $productsInStock = Product::whereHas('warehouses', function ($q) {
            $q->where('product_warehouse.quantity', '>', 0);
        })->with(['category:id,name', 'warehouses', 'purchaseItems'])->get();

        // Filter those with early expiry
        $expiring = $productsInStock->map(function ($product) {
            $earliest = $product->earliest_expiry_date;
            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category_name' => $product->category ? $product->category->name : 'N/A',
                'stock_quantity' => $product->total_stock,
                'earliest_expiry_date' => $earliest,
            ];
        })->filter(function ($item) use ($dateThreshold, $now) {
            if (!$item['earliest_expiry_date']) return false;
            try {
                $date = Carbon::parse($item['earliest_expiry_date']);
                // Return soon expiring items AND already expired items in stock
                return ltrim($date->format('Y-m-d'), "0") !== "" && $date->lte($dateThreshold);
            } catch (\Exception $e) {
                return false;
            }
        })->sortBy('earliest_expiry_date')->take($limit)->values();

        return response()->json(['data' => $expiring]);
    }

    /**
     * Download Shift Cost (Expenses) PDF
     */
    public function shiftCostPdf(Request $request, \App\Services\ShiftCostPdfService $pdfService)
    {
        $validated = $request->validate([
            'shift_id' => 'required|integer|exists:shifts,id',
        ]);

        $shift = Shift::with(['user', 'expenses.user', 'expenses.category'])->findOrFail($validated['shift_id']);

        $pdfContent = $pdfService->generate($shift);

        $filename = 'Shift_' . $shift->id . '_Costs_' . now()->format('Ymd_His') . '.pdf';

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="' . $filename . '"');
    }

    /**
     * Download Shift Sales Returns PDF
     */
    public function shiftReturnsPdf(Request $request, \App\Services\ShiftSalesReturnPdfService $pdfService)
    {
        $validated = $request->validate([
            'shift_id' => 'required|integer|exists:shifts,id',
        ]);

        $shift = Shift::with(['user', 'saleReturns.user', 'saleReturns.items', 'saleReturns.sale'])->findOrFail($validated['shift_id']);

        $pdfContent = $pdfService->generate($shift);

        $filename = 'Shift_' . $shift->id . '_Returns_' . now()->format('Ymd_His') . '.pdf';

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="' . $filename . '"');
    }

    /**
     * Download Shift Sold Items PDF (الأصناف المباعة)
     */
    public function shiftSoldItemsPdf(Request $request, \App\Services\ShiftSoldItemsPdfService $pdfService)
    {
        $validated = $request->validate([
            'shift_id' => 'required|integer|exists:shifts,id',
        ]);

        $shift = Shift::with(['user', 'sales.items.product'])->findOrFail($validated['shift_id']);

        $pdfContent = $pdfService->generate($shift);

        $filename = 'Shift_' . $shift->id . '_SoldItems_' . now()->format('Ymd_His') . '.pdf';

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="' . $filename . '"');
    }
}
