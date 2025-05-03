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
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SupplierController; // Uncomment when created
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
    // This provides:
    // GET     /api/clients          (index)   -> name: clients.index
    // POST    /api/clients          (store)   -> name: clients.store
    // GET     /api/clients/{client} (show)    -> name: clients.show
    // PUT/PATCH /api/clients/{client} (update)  -> name: clients.update
    // DELETE  /api/clients/{client} (destroy) -> name: clients.destroy

    // -- Suppliers Management (Example - Uncomment and create Controller/etc. later) --
    Route::apiResource('suppliers', SupplierController::class);

    // -- Products Management (Example - Uncomment and create Controller/etc. later) --
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