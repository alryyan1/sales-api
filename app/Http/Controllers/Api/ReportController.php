<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use Illuminate\Http\Request;
use App\Models\Sale; // Import the Sale model
use App\Http\Resources\SaleResource; // Reuse SaleResource for formatting
use App\Models\Product;
use App\Models\SaleItem;
use App\Services\Pdf\MyCustomTCPDF;
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

    // --- Placeholder for other report methods ---
    /*
    public function purchasesReport(Request $request) { ... }
    public function inventoryReport(Request $request) { ... }
    public function profitLossReport(Request $request) { ... }
    */
}
