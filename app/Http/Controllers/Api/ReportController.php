<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\PurchaseItemResource;
use Illuminate\Http\Request;
use App\Models\Sale; // Import the Sale model
use App\Http\Resources\SaleResource; // Reuse SaleResource for formatting
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\SaleItem;
use App\Services\Pdf\MyCustomTCPDF;
use App\Services\DailySalesPdfService;
use App\Services\InventoryPdfService;
use Arr;
use DB;
use Carbon\Carbon; // Ensure correct Carbon namespace is used
use Illuminate\Validation\Rule; // For status validation

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
        if ($request->user()->cannot('view-reports')) {
            abort(403, 'You do not have permission to view reports.');
        }
        // --- Input Validation ---
        $validated = $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'client_id' => 'nullable|integer|exists:clients,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'status' => ['nullable', 'string', Rule::in(['completed', 'pending', 'draft', 'cancelled'])],
            'per_page' => 'nullable|integer|min:5|max:100', // Control pagination size
            // Add validation for sort_by, sort_direction if implementing dynamic sorting
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
                'user:id,name'    // Load only id and name from user (salesperson)
                // Only load items if report needs item-level detail (adds overhead)
                // 'items',
                // 'items.product:id,name,sku'
            ]);

        // --- Apply Filters ---
        // Date Range Filter
        if (!empty($validated['start_date'])) {
            $query->whereDate('sale_date', '>=', $validated['start_date']);
        }
        if (!empty($validated['end_date'])) {
            $query->whereDate('sale_date', '<=', $validated['end_date']);
        }

        // Client Filter
        if (!empty($validated['client_id'])) {
            $query->where('client_id', $validated['client_id']);
        }

        // User (Salesperson) Filter
        if (!empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        // Status Filter
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        // --- Sorting (Default: Newest first) ---
        $query->orderBy('sale_date', 'desc')->orderBy('id', 'desc'); // Sort by date then ID

        // --- Pagination ---
        $perPage = $validated['per_page'] ?? 25; // Default to 25 items per page
        $sales = $query->paginate($perPage);

        // --- Return Paginated Resource Collection ---
        return SaleResource::collection($sales);
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
            $query->with(['purchaseItems' => function ($query) {
                $query->where('remaining_quantity', '>', 0)
                    ->select(['id', 'product_id', 'batch_number', 'remaining_quantity', 'expiry_date', 'unit_cost', 'sale_price']) // Select specific batch fields
                    ->orderBy('expiry_date', 'asc')
                    ->orderBy('created_at', 'asc');
            }]);
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
            'sort_by' => ['nullable', 'string', Rule::in(['expiry_date', 'products.name', 'remaining_quantity', 'purchase_items.created_at'])], // Allowed sort fields
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
            ->with(['product:id,name,sku,sellable_unit_name']) // Eager load necessary product details
            ->whereNotNull('expiry_date')                 // Only items with an expiry date
            ->where('remaining_quantity', '>', 0)       // Only items with stock remaining
            ->whereBetween('expiry_date', [$today, $expiryCutoffDate]); // Expiring within the threshold (inclusive)

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

        // --- 1. Get Daily Sales Totals ---
        $dailySalesQuery = Sale::query()
            ->select(
                DB::raw("DATE(COALESCE(sale_date, created_at)) as sale_day"),
                DB::raw('SUM(total_amount) as daily_total_revenue'),
                DB::raw('SUM(paid_amount) as daily_total_paid') // Sum of initial paid amounts on Sale record
            )
            ->whereBetween(DB::raw("DATE(COALESCE(sale_date, created_at))"), [$startDate->toDateString(), $endDate->toDateString()])
            ->whereIn('status', ['completed', 'pending', 'draft']); // Include drafts created today

        // if (!empty($validated['client_id'])) { $dailySalesQuery->where('client_id', $validated['client_id']); }
        // if (!empty($validated['user_id'])) { $dailySalesQuery->where('user_id', $validated['user_id']); }

        $dailySales = $dailySalesQuery->groupBy('sale_day')
            ->orderBy('sale_day', 'asc')
            ->get()
            ->keyBy('sale_day'); // Key by date for easy merging

        // --- 2. Get Daily Payments by Method ---
        // This query sums payments made ON a specific day, regardless of when the sale was made,
        // but linked to sales within the requested month for context OR sales made by a specific user.
        // For a pure revenue report based on SALE DATE, it's better to sum payments linked to sales *made* in that period.
        $dailyPaymentsQuery = Payment::query()
            ->join('sales', 'payments.sale_id', '=', 'sales.id') // Join to filter sales if needed
            ->select(
                DB::raw('DATE(payments.payment_date) as payment_day'), // Could also group by sales.sale_date
                'payments.method',
                DB::raw('SUM(payments.amount) as total_amount_by_method')
            )
            // Option A: Payments made within the month for sales made within the month
            ->whereBetween(DB::raw("DATE(COALESCE(sales.sale_date, sales.created_at))"), [$startDate->toDateString(), $endDate->toDateString()])
            ->whereBetween('payments.payment_date', [$startDate, $endDate]) // Payment also in this month
            // Option B: All payments made within the month, regardless of sale date (cash flow focused)
            // ->whereBetween('payments.payment_date', [$startDate, $endDate])
            ->whereIn('sales.status', ['completed', 'pending', 'draft']);


        // if (!empty($validated['client_id'])) { $dailyPaymentsQuery->where('sales.client_id', $validated['client_id']); }
        // if (!empty($validated['user_id'])) { $dailyPaymentsQuery->where('sales.user_id', $validated['user_id']); }
        // Note: If using payments.user_id, filter by that directly: ->where('payments.user_id', ...)

        $dailyPaymentsByMethod = $dailyPaymentsQuery->groupBy('payment_day', 'payments.method')
            ->orderBy('payment_day', 'asc')
            ->orderBy('payments.method', 'asc')
            ->get()
            ->groupBy('payment_day') // Group by day first
            ->map(function ($paymentsOnDay) {
                // Then map each day's payments to {method: amount}
                return $paymentsOnDay->mapWithKeys(function ($paymentGroup) {
                    return [$paymentGroup->method => (float) $paymentGroup->total_amount_by_method];
                });
            });


        // --- 3. Combine Data for Each Day of the Month ---
        $report = [];
        $currentDay = $startDate->copy();
        $monthSummary = [
            'total_revenue' => 0,
            'total_paid' => 0, // Sum of direct Sale.paid_amount
            'total_payments_by_method' => [], // Sum of all payments by method for the month
        ];

        while ($currentDay->lte($endDate)) {
            $dayStr = $currentDay->toDateString();
            $saleDataForDay = $dailySales->get($dayStr);
            $paymentsForDay = $dailyPaymentsByMethod->get($dayStr) ?? collect([]); // Ensure it's a collection or empty array

            $dailyRevenue = $saleDataForDay ? (float) $saleDataForDay->daily_total_revenue : 0;
            $dailyPaidOnSale = $saleDataForDay ? (float) $saleDataForDay->daily_total_paid : 0; // From Sale record

            // Sum payments for this day from the payments query
            $dailyTotalPaymentsFromPaymentsTable = $paymentsForDay->sum();

            $report[$dayStr] = [
                'date' => $dayStr,
                'day_of_week' => $currentDay->isoFormat('dddd'), // Localized day name (e.g., الأحد)
                'total_revenue' => $dailyRevenue,
                'total_paid_at_sale_creation' => $dailyPaidOnSale, // Reflects initial payments from Sale.paid_amount
                'total_payments_recorded_on_day' => $dailyTotalPaymentsFromPaymentsTable, // Reflects all payments *processed* on this day
                'payments_by_method' => $paymentsForDay->toArray(),
            ];

            $monthSummary['total_revenue'] += $dailyRevenue;
            $monthSummary['total_paid'] += $dailyPaidOnSale; // Or sum dailyTotalPaymentsFromPaymentsTable for a different metric

            foreach ($paymentsForDay as $method => $amount) {
                $monthSummary['total_payments_by_method'][$method] = ($monthSummary['total_payments_by_method'][$method] ?? 0) + $amount;
            }

            $currentDay->addDay();
        }

        return response()->json([
            'data' => [
                'year' => $year,
                'month' => $month,
                'month_name' => $startDate->isoFormat('MMMM YYYY'), // Localized month name
                'daily_breakdown' => array_values($report), // Convert associative array to indexed for easier frontend map
                'month_summary' => $monthSummary,
            ]
        ]);
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
            ->whereIn('sales.status', ['completed', 'pending', 'draft'])
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

        // We sum total_amount from the Sale header as it represents the total billed amount
        $totalRevenue = $revenueQuery->whereIn('status', ['completed', 'pending']) // Include pending or only completed? Depends on accounting practice. Let's include pending for now.
            ->sum('total_amount');


        // --- Calculate Cost of Goods Sold (COGS) ---
        // Sum the (quantity * unit_cost_from_batch) for all SaleItems linked to sales within the period.
        $cogsQuery = SaleItem::query()
            // Join with sales table to filter by sale_date
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            // Join with the *specific* purchase_item (batch) the sale item came from
            // Use leftJoin in case the link is somehow broken (though constraint should prevent)
            ->leftJoin('purchase_items', 'sale_items.purchase_item_id', '=', 'purchase_items.id')
            ->whereBetween('sales.sale_date', [$startDate, $endDate])
            ->whereIn('sales.status', ['completed', 'pending']); // Match revenue status inclusion


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

        // --- Calculate Gross Profit ---
        $grossProfit = $totalRevenue - $totalCOGS;

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
            'revenue' => (float) $totalRevenue,
            'cost_of_goods_sold' => (float) $totalCOGS,
            'gross_profit' => (float) $grossProfit,
            // 'total_purchase_cost' => (float) $totalPurchaseCost, // Optional
        ];

        return response()->json(['data' => $reportData]);
    }
    public function downloadSalesReportPDF(Request $request)
    {
        // 1. Validate Input Parameters
        $validated = $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'client_id' => 'nullable|integer|exists:clients,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'status' => ['nullable', 'string', Rule::in(['completed', 'pending', 'draft', 'cancelled'])],
        ]);

        // 2. Fetch and Filter Sales Data
        $query = Sale::query()->with([
            'client:id,name,phone,email',
            'user:id,name',
            'items.product:id,name,sku',
            'payments'
        ]);
        
        $startDate = isset($validated['start_date']) ? Carbon::parse($validated['start_date'])->startOfDay() : Carbon::today()->startOfDay();
        $endDate = isset($validated['end_date']) ? Carbon::parse($validated['end_date'])->endOfDay() : Carbon::today()->endOfDay();

        if ($startDate) {
            $query->where('sale_date', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('sale_date', '<=', $endDate);
        }
        if (!empty($validated['client_id'])) {
            $query->where('client_id', $validated['client_id']);
        }
        if (!empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $sales = $query->orderBy('sale_date', 'desc')->get();

        // 3. Calculate Summary Statistics
        $summaryStats = $this->calculateSalesSummary($sales);

        // 4. Generate PDF
        $pdf = new MyCustomTCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetTitle('Professional Sales Report');
        $pdf->AddPage();
        $pdf->setRTL(true);

        // PDF Content
        $this->generateProfessionalPDFHeader($pdf, $startDate, $endDate, $validated);
        $this->generateTotalIncomeSection($pdf, $summaryStats);
        $this->generatePaymentsBreakdownSection($pdf, $summaryStats);
        $this->generateSalesTable($pdf, $sales);
        $this->generateFooter($pdf);

        // Output PDF
        $pdfFileName = 'professional_sales_report_' . now()->format('Y-m-d_H-i-s') . '.pdf';
        $pdfContent = $pdf->Output($pdfFileName, 'S');

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "attachment; filename=\"{$pdfFileName}\"");
    }

    private function calculateSalesSummary($sales)
    {
        $totalSales = $sales->count();
        $totalAmount = $sales->sum('total_amount');
        $totalPaid = $sales->sum('paid_amount');
        $totalDue = $totalAmount - $totalPaid;
        
        $statusBreakdown = $sales->groupBy('status')->map->count();
        $completedSales = $statusBreakdown->get('completed', 0);
        $pendingSales = $statusBreakdown->get('pending', 0);
        $draftSales = $statusBreakdown->get('draft', 0);
        $cancelledSales = $statusBreakdown->get('cancelled', 0);
        
        $completionRate = $totalSales > 0 ? ($completedSales / $totalSales) * 100 : 0;
        
        // Payment method breakdown
        $paymentMethods = $sales->flatMap->payments->groupBy('method')->map->sum('amount');
        
        // Top clients
        $topClients = $sales->groupBy('client_id')
            ->map(function ($clientSales) {
                return [
                    'name' => $clientSales->first()->client?->name ?? 'Unknown',
                    'total' => $clientSales->sum('total_amount'),
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
            'completion_rate' => $completionRate,
            'status_breakdown' => [
                'completed' => $completedSales,
                'pending' => $pendingSales,
                'draft' => $draftSales,
                'cancelled' => $cancelledSales
            ],
            'payment_methods' => $paymentMethods,
            'top_clients' => $topClients
        ];
    }

    private function generateProfessionalPDFHeader($pdf, $startDate, $endDate, $filters)
    {
        // Company Information
        $companyName = config('app_settings.company_name', 'Your Company');
        $companyAddress = config('app_settings.company_address', '');
        $companyPhone = config('app_settings.company_phone', '');
        $companyEmail = config('app_settings.company_email', '');

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
            // Calculate discount (if any)
            $discount = 0;
            if (isset($sale->discount_amount) && $sale->discount_amount > 0) {
                $discount = $sale->discount_amount;
            }
            
            // Get sale items names with quantity (optimized for smaller column)
            $itemNames = $sale->items->map(function($item) {
                $productName = $item->product?->name ?? 'Unknown';
                $quantity = $item->quantity ?? 1;
                return $productName . ' (x' . $quantity . ')';
            })->implode(', ');
            
            // Truncate item names for smaller column width
            if (strlen($itemNames) > 30) {
                $itemNames = substr($itemNames, 0, 27) . '...';
            }
            
            // Status color coding with enhanced colors
            $statusColor = $this->getStatusColor($sale->status);
            
            // Format currency with proper alignment
                    $totalAmount = number_format($sale->total_amount, 0);
        $paidAmount = number_format($sale->paid_amount, 0);
        $discountAmount = number_format($discount, 0);
            
            // Format date for smaller column (compact format)
            $saleDate = Carbon::parse($sale->sale_date)->format('M d, H:i');
            
            // Get user name with fallback (truncate if too long)
            $userName = $sale->user?->name ?? 'System';
            if (strlen($userName) > 20) {
                $userName = substr($userName, 0, 17) . '...';
            }
            
            // Compact transaction ID with status
            $transactionId = '#' . $sale->id . ' (' . substr(ucfirst($sale->status), 0, 3) . ')';

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
        // Enhanced summary row with better styling
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 10);
        $pdf->SetFillColor(70, 130, 180); // Steel blue background
        $pdf->SetTextColor(255, 255, 255); // White text
        
        $totalAmount = $sales->sum('total_amount');
        $totalPaid = $sales->sum('paid_amount');
        $totalDiscount = $sales->sum('discount_amount');
        $totalDue = $totalAmount - $totalPaid;

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
        // Additional professional statistics
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 11);
        $pdf->Cell(0, 8, 'Transaction Analysis', 0, 1, 'L');
        $pdf->Ln(3);
        
        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 9);
        
        // Calculate additional statistics
        $totalAmount = $sales->sum('total_amount');
        $totalPaid = $sales->sum('paid_amount');
        $totalDue = $totalAmount - $totalPaid;
        $avgSaleValue = $sales->count() > 0 ? $totalAmount / $sales->count() : 0;
        $paymentRate = $totalAmount > 0 ? ($totalPaid / $totalAmount) * 100 : 0;
        
        // Status breakdown
        $statusBreakdown = $sales->groupBy('status')->map->count();
        $completedCount = $statusBreakdown->get('completed', 0);
        $pendingCount = $statusBreakdown->get('pending', 0);
        
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
     * @return \Illuminate\Http\Response
     */
    public function inventoryPdf(Request $request)
    {
        if ($request->user()->cannot('view-reports')) {
            abort(403, 'You do not have permission to view reports.');
        }

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
        if ($request->user()->cannot('view-reports')) {
            abort(403, 'You do not have permission to view reports.');
        }

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

    // --- Placeholder for other report methods ---
    /*
    public function purchasesReport(Request $request) { ... }
    public function inventoryReport(Request $request) { ... }
    public function profitLossReport(Request $request) { ... }
    */
}
