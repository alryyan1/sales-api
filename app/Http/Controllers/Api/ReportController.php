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
        $products->getCollection()->each->append(['suggested_sale_price_per_sellable_unit', 'latest_cost_per_sellable_unit']);


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
                DB::raw('DATE(sale_date) as sale_day'),
                DB::raw('SUM(total_amount) as daily_total_revenue'),
                DB::raw('SUM(paid_amount) as daily_total_paid') // Sum of initial paid amounts on Sale record
            )
            ->whereBetween('sale_date', [$startDate, $endDate])
            ->whereIn('status', ['completed', 'pending']); // Consider which statuses count as revenue

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
            ->whereBetween('sales.sale_date', [$startDate, $endDate])
            ->whereBetween('payments.payment_date', [$startDate, $endDate]) // Payment also in this month
            // Option B: All payments made within the month, regardless of sale date (cash flow focused)
            // ->whereBetween('payments.payment_date', [$startDate, $endDate])
            ->whereIn('sales.status', ['completed', 'pending']);


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
            'status' => ['nullable', 'string', Rule::in(['completed', 'pending', 'draft', 'cancelled'])],
        ]);

        // 2. Fetch and Filter Sales Data
        $query = Sale::query()->with(['client:id,name', 'user:id,name']);
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
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $sales = $query->orderBy('sale_date', 'desc')->get();

        // 3. Generate PDF
        $pdf = new MyCustomTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetTitle('Sales Report');
        // $pdf->SetSubject("Sales Report from {$startDate->format('Y-m-d') ?? 'All Time'} to {$endDate->format('Y-m-d') ?? 'All Time'}");
        $pdf->AddPage();
        $pdf->setRTL(true);

        // PDF Header
        $this->generatePDFHeader($pdf, $startDate, $endDate);

        // PDF Table
        $this->generatePDFTable($pdf, $sales);

        // Output PDF
        $pdfFileName = 'sales_report_' . now()->format('Ymd_His') . '.pdf';
        $pdfContent = $pdf->Output($pdfFileName, 'S');

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "attachment; filename=\"{$pdfFileName}\"");
    }

    private function generatePDFHeader($pdf, $startDate, $endDate)
    {
        $formattedStartDate = $startDate ? $startDate->format('Y-m-d') : 'All Time';
        $formattedEndDate = $endDate ? $endDate->format('Y-m-d') : 'All Time';

        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 16);
        $pdf->Cell(0, 12, 'Sales Report', 0, 1, 'C');
        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 10);
        $pdf->Cell(0, 8, "Period: {$formattedStartDate} to {$formattedEndDate}", 0, 1, 'C');
        $pdf->Ln(6);
    }

    private function generatePDFTable($pdf, $sales)
    {
        $headers = ['Due', 'Paid', 'Total', 'Client', 'Invoice No.', 'Date'];
        $columnWidths = [25, 25, 30, 55, 30, 25];
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 9);
        $pdf->SetFillColor(230, 230, 230);

        foreach ($headers as $i => $header) {
            $pdf->Cell($columnWidths[$i], 8, $header, 1, 0, 'C', true);
        }
        $pdf->Ln();

        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 8);
        $pdf->SetFillColor(245, 245, 245);
        $fill = false;

        $totals = ['grandTotal' => 0, 'paid' => 0, 'due' => 0];

        if ($sales->isEmpty()) {
            $pdf->Cell(array_sum($columnWidths), 10, 'No sales data available for the selected period.', 1, 1, 'C');
        } else {
            foreach ($sales as $sale) {
                $due = (float)$sale->total_amount - (float)$sale->paid_amount;

                $totals['grandTotal'] += $sale->total_amount;
                $totals['paid'] += $sale->paid_amount;
                $totals['due'] += $due;

                $rowData = [
                    Carbon::parse($sale->sale_date)->format('Y-m-d'),
                    $sale->invoice_number ?? '---',
                    $sale->client?->name ?? 'Not Specified',
                    number_format($sale->total_amount, 2),
                    number_format($sale->paid_amount, 2),
                    number_format($due, 2),
                ];

                foreach (array_reverse($rowData) as $i => $cellData) {
                    $pdf->Cell($columnWidths[$i], 6, $cellData, 'LRB', 0, 'R', $fill);
                }
                $pdf->Ln();
                $fill = !$fill;
            }
        }

        // Summary Totals
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 9);
        $pdf->Cell(25, 7, number_format($totals['due'], 2), 1, 0, 'R', true);
        $pdf->Cell(25, 7, number_format($totals['paid'], 2), 1, 0, 'R', true);
        $pdf->Cell(30, 7, number_format($totals['grandTotal'], 2), 1, 0, 'R', true);
        $pdf->Cell(110, 7, 'Totals:', 1, 1, 'R', true);
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

        // Validate date parameter
        $validated = $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        $date = $validated['date'] ?? null;

        // Generate PDF using the service
        $pdfService = new DailySalesPdfService();
        $pdfContent = $pdfService->generateDailySalesPdf($date);

        // Return PDF response
        $filename = 'daily_sales_report_' . ($date ?? now()->format('Y-m-d')) . '.pdf';
        
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
