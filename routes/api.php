<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\{
  AuthController,
  CategoryController,
  ClientController,
  DashboardController,
  PermissionController,
  ProductController,
  ProfileController,
  PurchaseController,
  ReportController,
  RoleController,
  SaleController,
  SaleReturnController,
  SettingController,
  StockAdjustmentController,
  StockRequisitionController,
  SupplierController,
  UserController
};

// --- Public Routes ---
Route::post('/register', [AuthController::class, 'register'])->name('api.register');
Route::post('/login', [AuthController::class, 'login'])->name('api.login');

// --- Protected Routes ---
Route::middleware('auth:sanctum')->group(function () {

  // -- Authentication --
  Route::get('/user', [AuthController::class, 'user'])->name('api.user');
  Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');

  // -- Autocomplete Routes --
  Route::get('/products/autocomplete', [ProductController::class, 'autocomplete'])->name('api.products.autocomplete');
  Route::get('/clients/autocomplete', [ClientController::class, 'autocomplete'])->name('api.clients.autocomplete');
  Route::apiResource('stock-requisitions', StockRequisitionController::class)->except(['update']);
  Route::post('/stock-requisitions/{stock_requisition}/process', [StockRequisitionController::class, 'processRequisition'])->name('process');
  // -- Clients Management --
  Route::apiResource('clients', ClientController::class);
  Route::get('products/{product}/available-batches', [ProductController::class, 'getAvailableBatches']);

  // -- User Profile Routes --
  Route::prefix('profile')->name('api.profile.')->group(function () {
    Route::get('/', [ProfileController::class, 'show'])->name('show');
    Route::put('/', [ProfileController::class, 'update'])->name('update');
    Route::put('/password', [ProfileController::class, 'updatePassword'])->name('updatePassword');
  });

  // -- Stock Adjustments --
  Route::middleware(['permission:adjust-stock|view-stock-adjustments'])->group(function () {
    Route::get('/stock-adjustments', [StockAdjustmentController::class, 'index'])->name('api.stock-adjustments.index')->middleware('permission:view-stock-adjustments');
    Route::post('/stock-adjustments', [StockAdjustmentController::class, 'store'])->name('api.stock-adjustments.store')->middleware('permission:adjust-stock');
  });

  // -- Reporting Routes --
  Route::prefix('reports')->name('api.reports.')->group(function () {
    Route::get('/sales', [ReportController::class, 'salesReport'])->name('sales');
    Route::get('/purchases', [ReportController::class, 'purchasesReport'])->name('purchases');
    Route::get('/inventory', [ReportController::class, 'inventoryReport'])->name('inventory');
    Route::get('/profit-loss', [ReportController::class, 'profitLossReport'])->name('profit-loss');
  });

  // -- Admin Only Routes --
  Route::middleware(['role:admin'])->prefix('admin')->name('api.admin.')->group(function () {
    Route::apiResource('users', UserController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('roles', RoleController::class);
    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');
    Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index');
  });

  // -- Suppliers Management --
  Route::apiResource('suppliers', SupplierController::class);

  // -- Sale Returns --
  Route::apiResource('sale-returns', SaleReturnController::class)->except(['update', 'destroy']);
  Route::get('/sales/{sale}/returnable-items', [SaleController::class, 'getReturnableItems'])->name('api.sales.returnableItems');

  // -- Products Management --
  Route::get('/product/by-ids', [ProductController::class, 'getByIds']);
  Route::apiResource('products', ProductController::class);

  // -- Purchases Management --
  Route::apiResource('purchases', PurchaseController::class);

  // -- Sales Management --
  Route::apiResource('sales', SaleController::class)->except(['update']);
  Route::post('/sales/{sale}/payments', [SaleController::class, 'addPayment'])->name('api.sales.addPayment');

  // -- Dashboard Data --
  Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->name('api.dashboard.summary');
  Route::get('/reports/inventory-log', [App\Http\Controllers\Api\InventoryLogController::class, 'index'])->name('api.reports.inventory-log');
});
