<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB; // Use DB facade for aggregates
use App\Models\Sale;
use App\Models\Purchase;
use App\Models\Product;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\Warehouse;
use Carbon\Carbon; // For date manipulation

class DashboardController extends Controller
{
    /**
     * Provide summary statistics for the dashboard.
     */
    public function summary(Request $request)
    {
        // --- Validate Date Parameters ---
        $validated = $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        $startDate = isset($validated['start_date']) ? Carbon::parse($validated['start_date']) : null;
        $endDate = isset($validated['end_date']) ? Carbon::parse($validated['end_date']) : null;

        // --- Define Date Ranges (for backward compatibility when dates not provided) ---
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();
        $startOfWeek = Carbon::now()->startOfWeek();
        $startOfMonth = Carbon::now()->startOfMonth();
        $startOfYear = Carbon::now()->startOfYear();

        // --- Calculate Sales Stats ---
        // If date range is provided, filter by it; otherwise use default ranges
        $salesQuery = Sale::query();
        if ($startDate && $endDate) {
            $salesQuery->whereDate('sale_date', '>=', $startDate)
                ->whereDate('sale_date', '<=', $endDate);
        }

        // Sum of item totals (sale_items.total_price) grouped by sale_date ranges
        $salesToday = Sale::whereDate('sale_date', $today)
            ->join('sale_items', 'sales.id', '=', 'sale_items.sale_id')
            ->sum('sale_items.total_price');
        $salesYesterday = Sale::whereDate('sale_date', $yesterday)
            ->join('sale_items', 'sales.id', '=', 'sale_items.sale_id')
            ->sum('sale_items.total_price');
        $salesThisWeek = Sale::whereDate('sale_date', '>=', $startOfWeek)
            ->join('sale_items', 'sales.id', '=', 'sale_items.sale_id')
            ->sum('sale_items.total_price');
        $salesThisMonth = Sale::whereDate('sale_date', '>=', $startOfMonth)
            ->join('sale_items', 'sales.id', '=', 'sale_items.sale_id')
            ->sum('sale_items.total_price');
        $salesThisYear = Sale::whereDate('sale_date', '>=', $startOfYear)
            ->join('sale_items', 'sales.id', '=', 'sale_items.sale_id')
            ->sum('sale_items.total_price');
        $totalSalesAmount = Sale::join('sale_items', 'sales.id', '=', 'sale_items.sale_id')
            ->sum('sale_items.total_price'); // Overall total

        // Filtered sales amount and count (for date range)
        $filteredSalesAmount = 0;
        $filteredSalesCount = 0;
        if ($startDate && $endDate) {
            $filteredSalesAmount = (float) $salesQuery->clone()
                ->join('sale_items', 'sales.id', '=', 'sale_items.sale_id')
                ->sum('sale_items.total_price');
            $filteredSalesCount = $salesQuery->clone()->count();
        } else {
            // Default to this month when no dates provided
            $filteredSalesAmount = $salesThisMonth;
            $filteredSalesCount = Sale::whereDate('sale_date', '>=', $startOfMonth)->count();
        }

        // Count sales records
        $salesTodayCount = Sale::whereDate('sale_date', $today)->count();
        $salesThisMonthCount = Sale::whereDate('sale_date', '>=', $startOfMonth)->count();


        // --- Calculate Purchase Stats ---
        $purchasesQuery = Purchase::query();
        if ($startDate && $endDate) {
            $purchasesQuery->whereDate('purchase_date', '>=', $startDate)
                ->whereDate('purchase_date', '<=', $endDate);
        }

        $purchasesToday = Purchase::whereDate('purchase_date', $today)->sum('total_amount');
        $purchasesThisMonth = Purchase::whereDate('purchase_date', '>=', $startOfMonth)->sum('total_amount');
        $purchasesThisMonthCount = Purchase::whereDate('purchase_date', '>=', $startOfMonth)->count();

        // Filtered purchases amount and count (for date range)
        $filteredPurchasesAmount = 0;
        $filteredPurchasesCount = 0;
        if ($startDate && $endDate) {
            $filteredPurchasesAmount = (float) $purchasesQuery->clone()->sum('total_amount');
            $filteredPurchasesCount = $purchasesQuery->clone()->count();
        } else {
            // Default to this month when no dates provided
            $filteredPurchasesAmount = $purchasesThisMonth;
            $filteredPurchasesCount = $purchasesThisMonthCount;
        }


        // --- Inventory Stats ---
        $totalProducts = Product::count();

        // Define a subquery for total stock from the SSOT (product_warehouse)
        $totalStockSubquery = function ($query) {
            $query->selectRaw('COALESCE(SUM(quantity), 0)')
                ->from('product_warehouse')
                ->whereColumn('product_id', 'products.id');
        };

        $lowStockProductsCount = Product::whereNotNull('stock_alert_level')
            ->where($totalStockSubquery, '<=', DB::raw('products.stock_alert_level'))
            ->count();

        $outOfStockProductsCount = Product::where($totalStockSubquery, '<=', 0)->count();

        // Optional: Get names of a few low stock products
        $lowStockProductsSample = Product::whereNotNull('stock_alert_level')
            ->select('products.name', 'products.stock_alert_level')
            ->selectSub($totalStockSubquery, 'total_stock')
            ->where($totalStockSubquery, '<=', DB::raw('products.stock_alert_level'))
            ->orderBy('total_stock', 'asc') // Show lowest stock first
            ->limit(5) // Limit sample size
            ->get()
            ->pluck('name', 'total_stock') // Get name and quantity
            ->toArray(); // Convert collection to array


        // --- Profit = Net Revenue - Expenses - Returns ---
        $revenueQuery = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.is_returned', false)
            ->where('sales.is_quote', false);

        $expensesQuery = DB::table('expenses');

        $returnsQuery = DB::table('sale_return_items')
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id');

        if ($startDate && $endDate) {
            $revenueQuery->whereDate('sales.sale_date', '>=', $startDate)
                ->whereDate('sales.sale_date', '<=', $endDate);
            $expensesQuery->whereDate('expense_date', '>=', $startDate)
                ->whereDate('expense_date', '<=', $endDate);
            $returnsQuery->whereDate('sale_returns.created_at', '>=', $startDate)
                ->whereDate('sale_returns.created_at', '<=', $endDate);
        } else {
            $revenueQuery->whereDate('sales.sale_date', '>=', $startOfMonth);
            $expensesQuery->whereDate('expense_date', '>=', $startOfMonth);
            $returnsQuery->whereDate('sale_returns.created_at', '>=', $startOfMonth);
        }

        $filteredNetRevenue = (float) $revenueQuery->sum('sale_items.total_price');
        $filteredExpenses   = (float) $expensesQuery->sum('amount');
        $filteredReturns    = (float) $returnsQuery->selectRaw('SUM(sale_return_items.quantity * sale_return_items.price) as total')->value('total');
        $filteredProfit     = $filteredNetRevenue - $filteredExpenses - $filteredReturns;

        // --- Customer/Supplier Stats ---
        $totalClients = Client::count();
        $totalSuppliers = Supplier::count();
        $totalWarehouses = Warehouse::where('is_active', true)->count();

        // --- Inventory Value ---
        $inventoryValue = (float) DB::table('product_warehouse')
            ->join('products', 'products.id', '=', 'product_warehouse.product_id')
            ->selectRaw('SUM(product_warehouse.quantity * COALESCE(products.cost_price, 0)) as total_value')
            ->value('total_value');

        // --- Purchases Summary by Payment Method (filtered period) ---
        $bankMethods = ['bank_transfer', 'bankak', 'mada', 'visa', 'mastercard', 'fawry', 'ocash'];

        $purchPaymentsQ = DB::table('purchase_payments')
            ->join('purchases', 'purchases.id', '=', 'purchase_payments.purchase_id')
            ->whereNull('purchase_payments.deleted_at');

        if ($startDate && $endDate) {
            $purchPaymentsQ->whereDate('purchases.purchase_date', '>=', $startDate)
                           ->whereDate('purchases.purchase_date', '<=', $endDate);
        } else {
            $purchPaymentsQ->whereDate('purchases.purchase_date', '>=', $startOfMonth);
        }

        $purchCash = (float) (clone $purchPaymentsQ)->where('purchase_payments.method', 'cash')->sum('purchase_payments.amount');
        $purchBank = (float) (clone $purchPaymentsQ)->whereIn('purchase_payments.method', $bankMethods)->sum('purchase_payments.amount');

        // Deferred = total purchases amount − all payments made on those purchases
        $purchTotalQ = DB::table('purchases');
        if ($startDate && $endDate) {
            $purchTotalQ->whereDate('purchase_date', '>=', $startDate)->whereDate('purchase_date', '<=', $endDate);
        } else {
            $purchTotalQ->whereDate('purchase_date', '>=', $startOfMonth);
        }
        $purchTotal    = (float) $purchTotalQ->sum('total_amount');
        $purchAllPaid  = (float) (clone $purchPaymentsQ)->sum('purchase_payments.amount');
        $purchDeferred = max(0, $purchTotal - $purchAllPaid);


        // --- Combine data into response array ---
        $summaryData = [
            'sales' => [
                'today_amount' => (float) $salesToday, // Cast to float
                'yesterday_amount' => (float) $salesYesterday,
                'this_week_amount' => (float) $salesThisWeek,
                'this_month_amount' => (float) $salesThisMonth,
                'this_year_amount' => (float) $salesThisYear,
                'total_amount' => (float) $totalSalesAmount,
                'today_count' => $salesTodayCount,
                'this_month_count' => $salesThisMonthCount,
                // Filtered values for date range (used by frontend)
                'filtered_amount' => $filteredSalesAmount,
                'filtered_count' => $filteredSalesCount,
            ],
            'purchases' => [
                'today_amount' => (float) $purchasesToday,
                'this_month_amount' => (float) $purchasesThisMonth,
                'this_month_count' => $purchasesThisMonthCount,
                // Filtered values for date range (used by frontend)
                'filtered_amount' => $filteredPurchasesAmount,
                'filtered_count' => $filteredPurchasesCount,
            ],
            'inventory' => [
                'total_products'    => $totalProducts,
                'low_stock_count'   => $lowStockProductsCount,
                'out_of_stock_count'=> $outOfStockProductsCount,
                'inventory_value'   => $inventoryValue,
                'low_stock_sample'  => $lowStockProductsSample,
            ],
            'purchases_summary' => [
                'cash'     => $purchCash,
                'bank'     => $purchBank,
                'deferred' => $purchDeferred,
                'total'    => $purchTotal,
            ],
            'entities' => [
                'total_clients' => $totalClients,
                'total_suppliers' => $totalSuppliers,
                'total_warehouses' => $totalWarehouses,
            ],
            'profit' => [
                'filtered_profit'      => $filteredProfit,
                'filtered_net_revenue' => $filteredNetRevenue,
                'filtered_expenses'    => $filteredExpenses,
                'filtered_returns'     => $filteredReturns,
            ],
            // Add recent activities later if needed
            // 'recent_sales' => SaleResource::collection(Sale::with('client:id,name')->latest()->limit(5)->get()),
            // 'recent_purchases' => PurchaseResource::collection(Purchase::with('supplier:id,name')->latest()->limit(5)->get()),
        ];

        return response()->json(['data' => $summaryData]);
    }

    /**
     * Provide a focused profit summary: paid sales, expenses, cost of sales and net profit
     * for a date range. Defaults to the start of the current month through its end.
     */
    public function profitSummary(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        $startDate = isset($validated['start_date'])
            ? Carbon::parse($validated['start_date'])
            : Carbon::now()->startOfMonth();
        $endDate = isset($validated['end_date'])
            ? Carbon::parse($validated['end_date'])
            : Carbon::now()->endOfMonth();

        // Total sales amount: full invoiced value of non-quote sales made in the period
        // (includes unpaid/deferred amounts — not the same as paid_sales_amount below).
        $totalSalesAmount = (float) DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.is_quote', false)
            ->whereDate('sales.sale_date', '>=', $startDate)
            ->whereDate('sales.sale_date', '<=', $endDate)
            ->sum('sale_items.total_price');

        // Paid sales amount: payments actually collected against non-quote sales made in the period.
        $paidSalesAmount = (float) DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->where('sales.is_quote', false)
            ->whereDate('sales.sale_date', '>=', $startDate)
            ->whereDate('sales.sale_date', '<=', $endDate)
            ->sum('payments.amount');

        // Cost of sales (COGS): cost price at time of sale, for non-returned non-quote sales in the period.
        $costOfSalesAmount = (float) DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.is_quote', false)
            ->where('sales.is_returned', false)
            ->whereDate('sales.sale_date', '>=', $startDate)
            ->whereDate('sales.sale_date', '<=', $endDate)
            ->selectRaw('COALESCE(SUM(sale_items.cost_price_at_sale * sale_items.quantity), 0) as total')
            ->value('total');

        // Expenses recorded in the period.
        $expensesAmount = (float) DB::table('expenses')
            ->whereDate('expense_date', '>=', $startDate)
            ->whereDate('expense_date', '<=', $endDate)
            ->sum('amount');

        $profit = $paidSalesAmount - $expensesAmount - $costOfSalesAmount;

        return response()->json(['data' => [
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'total_sales_amount' => $totalSalesAmount,
            'paid_sales_amount' => $paidSalesAmount,
            'expenses_amount' => $expensesAmount,
            'cost_of_sales_amount' => $costOfSalesAmount,
            'profit' => $profit,
        ]]);
    }

    /**
     * Provide a lightweight summary for the sales terminal (e.g., today's sales for current user).
     */
    public function salesTerminalSummary(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $today = Carbon::today();

        // Sales for the current authenticated user for today
        $salesQuery = Sale::where('user_id', $user->id)
            ->whereDate('sale_date', $today); // Sales CREATED today

        $salesTodayAmount = $salesQuery->clone()
            ->join('sale_items', 'sales.id', '=', 'sale_items.sale_id')
            ->sum('sale_items.total_price');
        $salesTodayCount = $salesQuery->clone()->count();

        // --- New: Payments by Method for Today ---
        // Fetches payments RECORDED today, associated with sales made by the current user
        // (This assumes payment_date reflects when the payment was taken)
        $paymentsTodayByMethod = Payment::where('user_id', $user->id) // Payment recorded by this user
            ->whereDate('payment_date', $today) // Payment made today
            // Optionally, further filter by sales that are also from today,
            // or sales made by this user if sales can have payments recorded by different users
            // For simplicity, let's assume payments are tied to the user who recorded them
            // and we are interested in payments taken today by this user.
            ->select('method', DB::raw('SUM(amount) as total_amount_by_method'))
            ->groupBy('method')
            ->orderBy('method') // Consistent ordering
            ->get()
            ->mapWithKeys(function ($paymentGroup) {
                return [$paymentGroup->method => (float) $paymentGroup->total_amount_by_method];
            }); // Converts to an associative array: ['cash' => 150.00, 'visa' => 200.50]


        return response()->json([
            'data' => [
                'total_sales_amount_today' => (float) $salesTodayAmount,
                'sales_count_today' => (int) $salesTodayCount,
                'payments_today_by_method' => $paymentsTodayByMethod, // <-- Add new data
            ]
        ]);
    }

    public function branchesComparison(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        $startDate = $validated['start_date'] ?? null;
        $endDate   = $validated['end_date'] ?? null;

        $warehouses = Warehouse::where('is_active', true)->get();

        $branches = $warehouses->map(function ($warehouse) use ($startDate, $endDate) {
            // Net revenue per branch
            $salesQ = DB::table('sale_items')
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->where('sales.warehouse_id', $warehouse->id)
                ->where('sales.is_returned', false)
                ->where('sales.is_quote', false);

            // Invoices count
            $invQ = DB::table('sales')
                ->where('warehouse_id', $warehouse->id)
                ->where('is_returned', false)
                ->where('is_quote', false);

            // Returns per branch (via sale_id -> sales.warehouse_id)
            $returnsQ = DB::table('sale_return_items')
                ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
                ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
                ->where('sales.warehouse_id', $warehouse->id);

            if ($startDate && $endDate) {
                $salesQ->whereBetween(DB::raw('DATE(sales.sale_date)'), [$startDate, $endDate]);
                $invQ->whereBetween(DB::raw('DATE(sale_date)'), [$startDate, $endDate]);
                $returnsQ->whereBetween(DB::raw('DATE(sale_returns.created_at)'), [$startDate, $endDate]);
            }

            $totalSales   = (float) $salesQ->sum('sale_items.total_price');
            $invoicesCount = (int) $invQ->count();
            $totalReturns = (float) ($returnsQ->selectRaw('SUM(sale_return_items.quantity * sale_return_items.price) as total')->value('total') ?? 0);
            $totalProfit  = $totalSales - $totalReturns;

            return [
                'id'             => $warehouse->id,
                'name'           => $warehouse->name,
                'total_sales'    => $totalSales,
                'total_returns'  => $totalReturns,
                'total_profit'   => $totalProfit,
                'invoices_count' => $invoicesCount,
                'contribution_percentage' => 0,
            ];
        });

        $grandTotalSales = $branches->sum('total_sales');

        $branches = $branches->map(function ($b) use ($grandTotalSales) {
            $b['contribution_percentage'] = $grandTotalSales > 0
                ? round(($b['total_sales'] / $grandTotalSales) * 100, 1)
                : 0;
            return $b;
        })->sortByDesc('total_sales')->values();

        return response()->json([
            'data' => [
                'branches'     => $branches,
                'best_branch'  => $branches->first(),
                'worst_branch' => $branches->count() > 1 ? $branches->last() : null,
                'total_sales'  => $grandTotalSales,
            ]
        ]);
    }

    public function salesTimeseries(Request $request)
    {
        $period = $request->input('period', 'monthly');

        $baseQuery = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.is_returned', false)
            ->where('sales.is_quote', false);

        if ($period === 'daily') {
            $start = now()->subDays(29)->toDateString();
            $rows = (clone $baseQuery)
                ->whereDate('sales.sale_date', '>=', $start)
                ->selectRaw("DATE(sales.sale_date) as period_key, SUM(sale_items.total_price) as total_sales, COUNT(DISTINCT sales.id) as invoices_count")
                ->groupByRaw("DATE(sales.sale_date)")
                ->orderByRaw("DATE(sales.sale_date)")
                ->get();

            $data = $rows->map(fn($r) => [
                'label'          => Carbon::parse($r->period_key)->format('d/m'),
                'total_sales'    => (float) $r->total_sales,
                'invoices_count' => (int)   $r->invoices_count,
            ]);

        } elseif ($period === 'weekly') {
            $start = now()->subWeeks(11)->startOfWeek()->toDateString();
            $rows = (clone $baseQuery)
                ->whereDate('sales.sale_date', '>=', $start)
                ->selectRaw("YEARWEEK(sales.sale_date, 1) as yw, MIN(DATE(sales.sale_date)) as week_start, SUM(sale_items.total_price) as total_sales, COUNT(DISTINCT sales.id) as invoices_count")
                ->groupByRaw("YEARWEEK(sales.sale_date, 1)")
                ->orderByRaw("YEARWEEK(sales.sale_date, 1)")
                ->get();

            $data = $rows->map(fn($r) => [
                'label'          => Carbon::parse($r->week_start)->format('d/m'),
                'total_sales'    => (float) $r->total_sales,
                'invoices_count' => (int)   $r->invoices_count,
            ]);

        } else {
            $start = now()->subMonths(11)->startOfMonth()->toDateString();
            $rows = (clone $baseQuery)
                ->whereDate('sales.sale_date', '>=', $start)
                ->selectRaw("DATE_FORMAT(sales.sale_date, '%Y-%m') as period_key, SUM(sale_items.total_price) as total_sales, COUNT(DISTINCT sales.id) as invoices_count")
                ->groupByRaw("DATE_FORMAT(sales.sale_date, '%Y-%m')")
                ->orderByRaw("DATE_FORMAT(sales.sale_date, '%Y-%m')")
                ->get();

            $data = $rows->map(fn($r) => [
                'label'          => $r->period_key,
                'total_sales'    => (float) $r->total_sales,
                'invoices_count' => (int)   $r->invoices_count,
            ]);
        }

        return response()->json(['data' => $data]);
    }

    public function topProducts(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d',
            'limit'      => 'nullable|integer|min:1|max:20',
        ]);

        $startDate = $validated['start_date'] ?? now()->subDays(29)->toDateString();
        $endDate   = $validated['end_date']   ?? now()->toDateString();
        $limit     = (int) ($validated['limit'] ?? 8);

        $data = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.is_returned', false)
            ->where('sales.is_quote', false)
            ->whereDate('sales.sale_date', '>=', $startDate)
            ->whereDate('sales.sale_date', '<=', $endDate)
            ->selectRaw("products.name as product_name, SUM(sale_items.quantity) as total_qty, SUM(sale_items.total_price) as total_revenue")
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_revenue')
            ->limit($limit)
            ->get()
            ->map(fn($r) => [
                'name'          => $r->product_name,
                'total_qty'     => (float) $r->total_qty,
                'total_revenue' => (float) $r->total_revenue,
            ]);

        return response()->json(['data' => $data]);
    }

    public function alerts(Request $request)
    {
        $since30 = now()->subDays(29)->toDateString();
        $since7  = now()->subDays(7)->toDateString();

        // 1. Low stock products
        $totalStockSub = function ($q) {
            $q->selectRaw('COALESCE(SUM(quantity),0)')
              ->from('product_warehouse')
              ->whereColumn('product_id', 'products.id');
        };

        $lowStock = Product::whereNotNull('stock_alert_level')
            ->select('products.id', 'products.name', 'products.stock_alert_level')
            ->selectSub($totalStockSub, 'total_stock')
            ->where($totalStockSub, '<=', DB::raw('products.stock_alert_level'))
            ->where($totalStockSub, '>=', 0)
            ->orderBy('total_stock', 'asc')
            ->limit(5)
            ->get()
            ->map(fn($p) => [
                'id'          => $p->id,
                'name'        => $p->name,
                'stock'       => (float) $p->total_stock,
                'alert_level' => (float) $p->stock_alert_level,
            ]);

        // 2. Overdue invoices (unpaid balance, older than 7 days)
        $itemSub = DB::table('sale_items')
            ->select('sale_id', DB::raw('SUM(total_price) as total'))
            ->groupBy('sale_id');

        $paymentSub = DB::table('payments')
            ->select('sale_id', DB::raw('SUM(amount) as total'))
            ->groupBy('sale_id');

        $overdueInvoices = DB::table('sales')
            ->leftJoin('clients', 'clients.id', '=', 'sales.client_id')
            ->leftJoinSub($itemSub,     'it', 'it.sale_id',  '=', 'sales.id')
            ->leftJoinSub($paymentSub,  'pt', 'pt.sale_id',  '=', 'sales.id')
            ->where('sales.is_returned', false)
            ->where('sales.is_quote', false)
            ->whereDate('sales.sale_date', '<', $since7)
            ->selectRaw("
                sales.id, sales.number, sales.sale_date,
                COALESCE(clients.name,'—') as client_name,
                COALESCE(it.total,0) - COALESCE(sales.discount_amount,0) - COALESCE(pt.total,0) as due_amount
            ")
            ->havingRaw('due_amount > 0')
            ->orderByDesc('due_amount')
            ->limit(5)
            ->get()
            ->map(fn($r) => [
                'id'          => $r->id,
                'number'      => $r->number,
                'sale_date'   => $r->sale_date,
                'client_name' => $r->client_name,
                'due_amount'  => (float) $r->due_amount,
            ]);

        // 3. High returns branches (return rate > 10% in last 30 days)
        $branchSalesRaw = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.is_returned', false)->where('sales.is_quote', false)
            ->whereDate('sales.sale_date', '>=', $since30)
            ->select('sales.warehouse_id', DB::raw('SUM(sale_items.total_price) as total_sales'))
            ->groupBy('sales.warehouse_id');

        $branchReturnsRaw = DB::table('sale_return_items')
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->whereDate('sale_returns.created_at', '>=', $since30)
            ->select('sales.warehouse_id', DB::raw('SUM(sale_return_items.quantity * sale_return_items.price) as total_returns'))
            ->groupBy('sales.warehouse_id');

        $highReturns = DB::table('warehouses')
            ->where('warehouses.is_active', true)
            ->leftJoinSub($branchSalesRaw,    'bs', 'bs.warehouse_id',  '=', 'warehouses.id')
            ->leftJoinSub($branchReturnsRaw,  'br', 'br.warehouse_id',  '=', 'warehouses.id')
            ->selectRaw("warehouses.id, warehouses.name, COALESCE(bs.total_sales,0) as total_sales, COALESCE(br.total_returns,0) as total_returns")
            ->get()
            ->filter(fn($r) => $r->total_sales > 0 && ($r->total_returns / $r->total_sales) > 0.10)
            ->map(fn($r) => [
                'id'           => $r->id,
                'name'         => $r->name,
                'total_sales'  => (float) $r->total_sales,
                'total_returns'=> (float) $r->total_returns,
                'return_rate'  => round(($r->total_returns / $r->total_sales) * 100, 1),
            ])
            ->values();

        // 4. Low performing branches (below 70% of average in last 30 days)
        $allSales = DB::table('warehouses')
            ->where('warehouses.is_active', true)
            ->leftJoinSub($branchSalesRaw, 'bs', 'bs.warehouse_id', '=', 'warehouses.id')
            ->selectRaw("warehouses.id, warehouses.name, COALESCE(bs.total_sales,0) as total_sales")
            ->get();

        $avgSales = $allSales->avg('total_sales');

        $lowPerforming = $allSales
            ->filter(fn($b) => $avgSales > 0 && $b->total_sales < $avgSales * 0.7)
            ->map(fn($b) => [
                'id'              => $b->id,
                'name'            => $b->name,
                'total_sales'     => (float) $b->total_sales,
                'performance_pct' => round(($b->total_sales / $avgSales) * 100, 1),
            ])
            ->values();

        return response()->json([
            'data' => [
                'low_stock'              => $lowStock,
                'overdue_invoices'       => $overdueInvoices,
                'high_returns_branches'  => $highReturns,
                'low_performing_branches'=> $lowPerforming,
            ]
        ]);
    }

    public function branchDetails(Request $request, $warehouseId)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        $startDate = $validated['start_date'] ?? null;
        $endDate   = $validated['end_date'] ?? null;

        $warehouse = Warehouse::findOrFail($warehouseId);

        // ── Base sale queries ──────────────────────────────────────────────────
        $salesQ = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.warehouse_id', $warehouseId)
            ->where('sales.is_returned', false)
            ->where('sales.is_quote', false);

        $invQ = DB::table('sales')
            ->where('warehouse_id', $warehouseId)
            ->where('is_returned', false)
            ->where('is_quote', false);

        $returnsQ = DB::table('sale_return_items')
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->where('sales.warehouse_id', $warehouseId);

        if ($startDate && $endDate) {
            $salesQ->whereBetween(DB::raw('DATE(sales.sale_date)'), [$startDate, $endDate]);
            $invQ->whereBetween(DB::raw('DATE(sale_date)'), [$startDate, $endDate]);
            $returnsQ->whereBetween(DB::raw('DATE(sale_returns.created_at)'), [$startDate, $endDate]);
        }

        $totalSales    = (float) $salesQ->sum('sale_items.total_price');
        $invoicesCount = (int)   $invQ->count();
        $totalReturns  = (float) ((clone $returnsQ)
            ->selectRaw('SUM(sale_return_items.quantity * sale_return_items.price) as total')
            ->value('total') ?? 0);
        $totalProfit   = $totalSales - $totalReturns;
        $avgInvoice    = $invoicesCount > 0 ? round($totalSales / $invoicesCount, 2) : 0;

        // ── Employees performance ──────────────────────────────────────────────
        $empInvQ = DB::table('sales')
            ->where('warehouse_id', $warehouseId)
            ->where('is_returned', false)
            ->where('is_quote', false);

        $empSalesQ = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.warehouse_id', $warehouseId)
            ->where('sales.is_returned', false)
            ->where('sales.is_quote', false);

        if ($startDate && $endDate) {
            $empInvQ->whereBetween(DB::raw('DATE(sale_date)'), [$startDate, $endDate]);
            $empSalesQ->whereBetween(DB::raw('DATE(sales.sale_date)'), [$startDate, $endDate]);
        }

        $empInvSub   = (clone $empInvQ)->select('user_id', DB::raw('COUNT(id) as inv_count'))->groupBy('user_id');
        $empSalesSub = (clone $empSalesQ)->select('sales.user_id', DB::raw('SUM(sale_items.total_price) as emp_sales'))->groupBy('sales.user_id');

        $employees = DB::table('users')
            ->where('users.warehouse_id', $warehouseId)
            ->leftJoinSub($empInvSub,   'ei', 'ei.user_id', '=', 'users.id')
            ->leftJoinSub($empSalesSub, 'es', 'es.user_id', '=', 'users.id')
            ->select(
                'users.id', 'users.name',
                DB::raw('COALESCE(es.emp_sales, 0) as total_sales'),
                DB::raw('COALESCE(ei.inv_count, 0) as invoices_count')
            )
            ->orderByDesc('total_sales')
            ->get()
            ->map(fn($e) => [
                'id'             => $e->id,
                'name'           => $e->name,
                'total_sales'    => (float) $e->total_sales,
                'invoices_count' => (int)   $e->invoices_count,
            ]);

        $employeesCount = $employees->count();

        // ── Customers ─────────────────────────────────────────────────────────
        $branchClientIds = DB::table('sales')
            ->where('warehouse_id', $warehouseId)
            ->where('is_returned', false)
            ->where('is_quote', false)
            ->when($startDate && $endDate, fn($q) => $q->whereBetween(DB::raw('DATE(sale_date)'), [$startDate, $endDate]))
            ->whereNotNull('client_id')
            ->distinct()
            ->pluck('client_id');

        $totalClientsCount = $branchClientIds->count();

        // New clients: whose first EVER sale was within the date range
        $newClientsCount = 0;
        if ($startDate && $endDate && $branchClientIds->isNotEmpty()) {
            $firstSaleQ = DB::table('sales')
                ->whereIn('client_id', $branchClientIds)
                ->where('is_returned', false)
                ->where('is_quote', false)
                ->whereNotNull('client_id')
                ->select('client_id', DB::raw('MIN(DATE(sale_date)) as first_sale'))
                ->groupBy('client_id');

            $newClientsCount = (int) DB::table(DB::raw("({$firstSaleQ->toSql()}) as fsd"))
                ->mergeBindings($firstSaleQ)
                ->whereBetween('fsd.first_sale', [$startDate, $endDate])
                ->count();
        }

        // Outstanding debt for this branch (all time)
        $debtItemQ = DB::table('sale_items')->select('sale_id', DB::raw('SUM(total_price) as items_total'))->groupBy('sale_id');
        $debtPaidQ = DB::table('payments')->select('sale_id', DB::raw('SUM(amount) as paid_total'))->groupBy('sale_id');

        $outstandingDebt = (float) (DB::table('sales')
            ->where('warehouse_id', $warehouseId)
            ->where('is_returned', false)
            ->where('is_quote', false)
            ->leftJoinSub($debtItemQ, 'it', 'it.sale_id', '=', 'sales.id')
            ->leftJoinSub($debtPaidQ, 'pt', 'pt.sale_id', '=', 'sales.id')
            ->selectRaw('SUM(GREATEST(0, COALESCE(it.items_total,0) - COALESCE(sales.discount_amount,0) - COALESCE(pt.paid_total,0))) as debt')
            ->value('debt') ?? 0);

        // ── Purchases for this branch ─────────────────────────────────────────
        $bankMethods = ['bank_transfer', 'bankak', 'mada', 'visa', 'mastercard', 'fawry', 'ocash'];

        $purchTotalQ = DB::table('purchases')->where('warehouse_id', $warehouseId);
        $purchPaymentsQ = DB::table('purchase_payments')
            ->join('purchases', 'purchases.id', '=', 'purchase_payments.purchase_id')
            ->where('purchases.warehouse_id', $warehouseId)
            ->whereNull('purchase_payments.deleted_at');

        if ($startDate && $endDate) {
            $purchTotalQ->whereBetween(DB::raw('DATE(purchase_date)'), [$startDate, $endDate]);
            $purchPaymentsQ->whereBetween(DB::raw('DATE(purchases.purchase_date)'), [$startDate, $endDate]);
        }

        $purchTotal    = (float) $purchTotalQ->sum('total_amount');
        $purchCash     = (float) (clone $purchPaymentsQ)->where('purchase_payments.method', 'cash')->sum('purchase_payments.amount');
        $purchBank     = (float) (clone $purchPaymentsQ)->whereIn('purchase_payments.method', $bankMethods)->sum('purchase_payments.amount');
        $purchAllPaid  = (float) (clone $purchPaymentsQ)->sum('purchase_payments.amount');
        $purchDeferred = max(0, $purchTotal - $purchAllPaid);

        // ── Recent invoices ────────────────────────────────────────────────────
        $recItemQ = DB::table('sale_items')->select('sale_id', DB::raw('SUM(total_price) as items_total'))->groupBy('sale_id');
        $recPaidQ = DB::table('payments')->select('sale_id', DB::raw('SUM(amount) as paid_total'))->groupBy('sale_id');

        $recentInvoices = DB::table('sales')
            ->where('sales.warehouse_id', $warehouseId)
            ->where('sales.is_returned', false)
            ->where('sales.is_quote', false)
            ->leftJoin('clients', 'clients.id', '=', 'sales.client_id')
            ->leftJoinSub($recItemQ, 'it', 'it.sale_id', '=', 'sales.id')
            ->leftJoinSub($recPaidQ, 'pt', 'pt.sale_id', '=', 'sales.id')
            ->select(
                'sales.id', 'sales.number', 'sales.sale_date',
                DB::raw("COALESCE(clients.name,'—') as client_name"),
                DB::raw('COALESCE(it.items_total,0) - COALESCE(sales.discount_amount,0) as total_amount'),
                DB::raw('COALESCE(pt.paid_total,0) as paid_amount')
            )
            ->orderByDesc('sales.sale_date')
            ->orderByDesc('sales.id')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'id'           => $r->id,
                'number'       => $r->number,
                'sale_date'    => $r->sale_date,
                'client_name'  => $r->client_name,
                'total_amount' => (float) $r->total_amount,
                'paid_amount'  => (float) $r->paid_amount,
            ]);

        return response()->json([
            'data' => [
                'branch' => [
                    'id'              => $warehouse->id,
                    'name'            => $warehouse->name,
                    'address'         => $warehouse->address,
                    'contact_info'    => $warehouse->contact_info,
                    'is_active'       => $warehouse->is_active,
                    'employees_count' => $employeesCount,
                ],
                'summary' => [
                    'total_sales'    => $totalSales,
                    'total_returns'  => $totalReturns,
                    'total_profit'   => $totalProfit,
                    'invoices_count' => $invoicesCount,
                    'avg_invoice'    => $avgInvoice,
                ],
                'customers' => [
                    'total_clients'     => $totalClientsCount,
                    'new_clients_count' => $newClientsCount,
                    'outstanding_debt'  => $outstandingDebt,
                ],
                'purchases' => [
                    'total'    => $purchTotal,
                    'cash'     => $purchCash,
                    'bank'     => $purchBank,
                    'deferred' => $purchDeferred,
                ],
                'employees'       => $employees,
                'recent_invoices' => $recentInvoices,
            ]
        ]);
    }
}
