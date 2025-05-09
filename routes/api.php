<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// --- Import Controllers ---
// It's good practice to group API controllers, e.g., under App\Http\Controllers\Api
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\StockAdjustmentController;
use App\Http\Controllers\Api\SupplierController; // Uncomment when created
use App\Http\Controllers\Api\UserController;

// use App\Http\Controllers\Api\ProductController;  // Uncomment when created
// use App\Http\Controllers\Api\PurchaseController; // Uncomment when created
// use App\Http\Controllers\Api\SaleController;     // Uncomment when created
// use App\Http\Controllers\Api\DashboardController; // Example for dashboard data

// --- Public Routes ---
// Routes accessible without authentication
Route::post('/register', [AuthController::class, 'register'])->name('api.register'); // Naming is optional but good practice
Route::post('/login', [AuthController::class, 'login'])->name('api.login');

// --- Protected Routes ---
// Routes requiring authentication (using Sanctum Bearer Tokens)
Route::middleware('auth:sanctum')->group(function () {

  // -- Authentication --
  Route::get('/user', [AuthController::class, 'user'])->name('api.user');       // Get authenticated user details
  Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout'); // Invalidate the current token
  Route::get('/products/autocomplete', [App\Http\Controllers\Api\ProductController::class, 'autocomplete'])->name('api.products.autocomplete');
  Route::get('/clients/autocomplete', [App\Http\Controllers\Api\ClientController::class, 'autocomplete'])->name('api.clients.autocomplete');
  // -- Clients Management --
  Route::apiResource('clients', ClientController::class);
  Route::get('products/{product}/available-batches', [ProductController::class, 'getAvailableBatches']);
  // --- User Profile Routes ---
  Route::prefix('profile')->name('api.profile.')->group(function () {
    Route::get('/', [ProfileController::class, 'show'])->name('show');
    Route::put('/', [ProfileController::class, 'update'])->name('update'); // Use PUT for replacing profile data
    Route::put('/password', [ProfileController::class, 'updatePassword'])->name('updatePassword'); // Separate endpoint for password

  });



  // --- Stock Adjustments ---
  // Protect with specific permission middleware or rely on controller authorization
  Route::middleware(['permission:adjust-stock|view-stock-adjustments'])->group(function () { // Example group middleware
    Route::get('/stock-adjustments', [StockAdjustmentController::class, 'index'])->name('api.stock-adjustments.index')->middleware('permission:view-stock-adjustments'); // View history
    Route::post('/stock-adjustments', [StockAdjustmentController::class, 'store'])->name('api.stock-adjustments.store')->middleware('permission:adjust-stock'); // Create adjustment
  });

  // --- Reporting Routes ---
  Route::prefix('reports')->name('api.reports.')->group(function () { // Group report routes
    Route::get('/sales', [ReportController::class, 'salesReport'])->name('sales');
    Route::get('/purchases', [ReportController::class, 'purchasesReport'])->name('purchases'); // Add later
    Route::get('/inventory', [ReportController::class, 'inventoryReport'])->name('inventory'); // <-- Add this line
    Route::get('/profit-loss', [ReportController::class, 'profitLossReport'])->name('profit-loss'); // <-- Add this line

    // Route::get('/inventory', [ReportController::class, 'inventoryReport'])->name('inventory'); // Add later
  });
  // --- Admin Only Routes Example ---
  // --- Admin Only Routes Group ---
  Route::middleware(['role:admin'])->prefix('admin')->name('api.admin.')->group(function () {
    // User Management
    Route::apiResource('users', UserController::class);
    Route::apiResource('categories', CategoryController::class);

    // Role Management
    Route::apiResource('roles', RoleController::class); // Full resource for roles
    // --- Settings Routes ---
    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');
    // Permission Listing
    Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index'); // Route to list permissions
  });


  // -- Suppliers Management (Example - Uncomment and create Controller/etc. later) --
  Route::apiResource('suppliers', SupplierController::class);

  // -- Products Management (Example - Uncomment and create Controller/etc. later) --
  Route::get('/product/by-ids', [ProductController::class, 'getByIds']);
  Route::apiResource('products', ProductController::class);
  // You might add custom product routes, e.g., for stock adjustment
  // Route::post('/products/{product}/adjust-stock', [ProductController::class, 'adjustStock'])->name('api.products.adjustStock');
  // --- Purchase Routes ---
  // Exclude 'update' as purchases typically aren't modified after creation
  Route::apiResource('purchases', PurchaseController::class)->except(['update']); // <-- Add this line

  // -- Sales Management (Example - Uncomment and create Controller/etc. later) --
  Route::apiResource('sales', SaleController::class);

  // -- Dashboard Data (Example - Uncomment and create Controller/etc. later) --
  Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->name('api.dashboard.summary');

  // --- Add other protected resource or custom action routes here ---

});

// --- Fallback Route (Optional) ---
// Handles any route not matched above within the /api prefix
// Route::fallback(function(){
//     return response()->json(['message' => 'API Endpoint Not Found'], 404);
// });