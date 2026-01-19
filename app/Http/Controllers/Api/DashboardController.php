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


        // --- Customer/Supplier Stats ---
        $totalClients = Client::count();
        $totalSuppliers = Supplier::count();


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
                'total_products' => $totalProducts,
                'low_stock_count' => $lowStockProductsCount,
                'out_of_stock_count' => $outOfStockProductsCount,
                'low_stock_sample' => $lowStockProductsSample, // Array of [quantity => name]
            ],
            'entities' => [
                'total_clients' => $totalClients,
                'total_suppliers' => $totalSuppliers,
            ],
            // Add recent activities later if needed
            // 'recent_sales' => SaleResource::collection(Sale::with('client:id,name')->latest()->limit(5)->get()),
            // 'recent_purchases' => PurchaseResource::collection(Purchase::with('supplier:id,name')->latest()->limit(5)->get()),
        ];

        return response()->json(['data' => $summaryData]);
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

    // --- Potential Future Methods ---
    /*
    public function salesChartData(Request $request) {
        // Logic to get sales data grouped by day/week/month
    }

    public function lowStockList(Request $request) {
        // Logic to get a paginated list of all low stock products
    }
    */
}
