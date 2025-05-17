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
        // --- Define Date Ranges ---
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();
        $startOfWeek = Carbon::now()->startOfWeek();
        $startOfMonth = Carbon::now()->startOfMonth();
        $startOfYear = Carbon::now()->startOfYear();

        // --- Calculate Sales Stats ---
        // Using query builder with sum for performance
        $salesToday = Sale::whereDate('sale_date', $today)->sum('total_amount');
        $salesYesterday = Sale::whereDate('sale_date', $yesterday)->sum('total_amount');
        $salesThisWeek = Sale::whereDate('sale_date', '>=', $startOfWeek)->sum('total_amount');
        $salesThisMonth = Sale::whereDate('sale_date', '>=', $startOfMonth)->sum('total_amount');
        $salesThisYear = Sale::whereDate('sale_date', '>=', $startOfYear)->sum('total_amount');
        $totalSalesAmount = Sale::sum('total_amount'); // Overall total

        // Count sales records
        $salesTodayCount = Sale::whereDate('sale_date', $today)->count();
        $salesThisMonthCount = Sale::whereDate('sale_date', '>=', $startOfMonth)->count();


        // --- Calculate Purchase Stats ---
        $purchasesToday = Purchase::whereDate('purchase_date', $today)->sum('total_amount');
        $purchasesThisMonth = Purchase::whereDate('purchase_date', '>=', $startOfMonth)->sum('total_amount');
        $purchasesThisMonthCount = Purchase::whereDate('purchase_date', '>=', $startOfMonth)->count();


        // --- Inventory Stats ---
        $totalProducts = Product::count();
        $lowStockProductsCount = Product::whereNotNull('stock_alert_level') // Only consider products with an alert level set
            ->whereColumn('stock_quantity', '<=', 'stock_alert_level') // Compare quantity to alert level column
            ->count();
        $outOfStockProductsCount = Product::where('stock_quantity', '<=', 0)->count();

        // Optional: Get names of a few low stock products
        $lowStockProductsSample = Product::whereNotNull('stock_alert_level')
            ->whereColumn('stock_quantity', '<=', 'stock_alert_level')
            ->orderBy('stock_quantity', 'asc') // Show lowest stock first
            ->limit(5) // Limit sample size
            ->pluck('name', 'stock_quantity') // Get name and quantity
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
            ],
            'purchases' => [
                'today_amount' => (float) $purchasesToday,
                'this_month_amount' => (float) $purchasesThisMonth,
                'this_month_count' => $purchasesThisMonthCount,
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
            ->whereDate('sale_date', $today) // Sales CREATED today
            ->whereIn('status', ['completed', 'pending']);

        $salesTodayAmount = $salesQuery->clone()->sum('total_amount');
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
