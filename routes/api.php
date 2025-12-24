<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\{
  AuthController,
  BackupController,
  ExpenseCategoryController,
  ExpenseController,
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
  SupplierPaymentController,
  SystemController,
  UnitController,
  UserController,
  WhatsAppController,
  WhatsAppSchedulerController,
  ShiftController,
  WarehouseController,
  StockTransferController
};
use App\Http\Controllers\UpdateController;

// --- Update System Routes (Public) ---
Route::get('/updates/check', [UpdateController::class, 'checkForUpdates']);
Route::post('/updates/perform', [UpdateController::class, 'performUpdate']);

// --- Public Routes ---
Route::post('/register', [AuthController::class, 'register'])->name('api.register');
Route::post('/login', [AuthController::class, 'login'])->name('api.login');

// --- Protected Routes ---
Route::middleware('auth:sanctum')->group(function () {

  // -- Authentication --
  Route::get('/user', [AuthController::class, 'user'])->name('api.user');
  Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');

  // -- Shifts (POS) --
  Route::get('/shifts/current', [ShiftController::class, 'current'])->name('api.shifts.current');
  Route::post('/shifts/open', [ShiftController::class, 'open'])->name('api.shifts.open');
  Route::post('/shifts/close', [ShiftController::class, 'close'])->name('api.shifts.close');

  // -- Autocomplete Routes --
  Route::get('/products/autocomplete', [ProductController::class, 'autocomplete'])->name('api.products.autocomplete');
  Route::get('/clients/autocomplete', [ClientController::class, 'autocomplete'])->name('api.clients.autocomplete');
  Route::apiResource('stock-requisitions', StockRequisitionController::class)->except(['update']);
  Route::post('/stock-requisitions/{stock_requisition}/process', [StockRequisitionController::class, 'processRequisition'])->name('api.stock-requisitions.process');
  // -- Clients Management --
  Route::apiResource('clients', ClientController::class);
  Route::get('/clients/{client}/ledger', [\App\Http\Controllers\Api\ClientLedgerController::class, 'getLedger']);
  Route::get('/clients/{client}/ledger/pdf', [\App\Http\Controllers\Api\ClientLedgerController::class, 'downloadLedgerPDF']);
  Route::post('/clients/{client}/settle-debt', [\App\Http\Controllers\Api\ClientLedgerController::class, 'settleDebt']);
  Route::get('products/{product}/available-batches', [ProductController::class, 'getAvailableBatches']);

  // -- User Profile Routes --
  Route::prefix('profile')->name('api.profile.')->group(function () {
    Route::get('/', [ProfileController::class, 'show'])->name('show');
    Route::put('/', [ProfileController::class, 'update'])->name('update');
    Route::put('/password', [ProfileController::class, 'updatePassword'])->name('updatePassword');
  });

  // -- Stock Adjustments --
  Route::get('/stock-adjustments', [StockAdjustmentController::class, 'index'])->name('api.stock-adjustments.index');
  Route::post('/stock-adjustments', [StockAdjustmentController::class, 'store'])->name('api.stock-adjustments.store');
  // -- Settings Management --

  Route::get('admin/settings', [SettingController::class, 'index'])->name('settings.index');
  Route::put('admin/settings', [SettingController::class, 'update'])->name('settings.update');
  Route::post('admin/settings/logo', [SettingController::class, 'uploadLogo'])->name('settings.uploadLogo');

  // -- Database Backup Management --
  Route::middleware(['role:admin'])->prefix('admin')->name('api.admin.')->group(function () {
    Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
    Route::post('/backups', [BackupController::class, 'store'])->name('backups.store');
    Route::get('/backups/statistics', [BackupController::class, 'statistics'])->name('backups.statistics');
    Route::get('/backups/{filename}/download', [BackupController::class, 'download'])->name('backups.download');
    Route::delete('/backups/{filename}', [BackupController::class, 'destroy'])->name('backups.destroy');
  });
  // -- Reporting Routes --
  Route::prefix('reports')->name('api.reports.')->group(function () {
    Route::get('/sales', [ReportController::class, 'salesReport'])->name('sales');
    Route::get('/purchases', [ReportController::class, 'purchasesReport'])->name('purchases');
    Route::get('/inventory', [ReportController::class, 'inventoryReport'])->name('inventory');
    Route::get('/profit-loss', [ReportController::class, 'profitLossReport'])->name('profit-loss');
    Route::get('/near-expiry', [ReportController::class, 'nearExpiryReport'])->name('near-expiry'); // <-- Add this line
    Route::get('/monthly-revenue', [ReportController::class, 'monthlyRevenueReport'])->name('monthly-revenue');
    Route::get('/monthly-purchases', [ReportController::class, 'monthlyPurchasesReport'])->name('monthly-purchases');
    Route::get('/top-products', [ReportController::class, 'topSellingProducts'])->name('top-products');
    Route::get('/daily-sales-pdf', [ReportController::class, 'dailySalesPdf'])->name('daily-sales-pdf');
    Route::get('/sales-pdf', [ReportController::class, 'downloadSalesReportPDF'])->name('sales-pdf');
    Route::get('/inventory-pdf', [ReportController::class, 'inventoryPdf'])->name('inventory-pdf');
    Route::get('/inventory-log', [App\Http\Controllers\Api\InventoryLogController::class, 'index'])->name('inventory-log');
    Route::get('/inventory-log/pdf', [App\Http\Controllers\Api\InventoryLogController::class, 'generatePdf'])->name('inventory-log.pdf');
  });

  // -- Admin Only Routes --
  Route::middleware(['role:admin|ادمن'])->prefix('admin')->name('api.admin.')->group(function () {
    Route::apiResource('users', UserController::class);
    Route::apiResource('categories', CategoryController::class);
    // Expenses Management
    Route::apiResource('expense-categories', ExpenseCategoryController::class);
    Route::apiResource('expenses', ExpenseController::class);
    Route::apiResource('roles', RoleController::class);
    Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index');

    // -- System Management Routes --
    Route::prefix('system')->name('system.')->group(function () {
      Route::get('/version', [SystemController::class, 'getVersion'])->name('version');
      Route::get('/check-updates', [SystemController::class, 'checkForUpdates'])->name('check-updates');
      Route::post('/update-backend', [SystemController::class, 'updateBackend'])->name('update-backend');
      Route::post('/update-frontend', [SystemController::class, 'updateFrontend'])->name('update-frontend');
      Route::post('/update-both', [SystemController::class, 'updateBoth'])->name('update-both');
      Route::get('/frontend-instructions', [SystemController::class, 'getFrontendUpdateInstructions'])->name('frontend-instructions');
    });

    // -- WhatsApp Scheduler Routes --
    Route::get('/whatsapp-schedulers/options', [WhatsAppSchedulerController::class, 'options'])->name('whatsapp-schedulers.options');
    Route::apiResource('whatsapp-schedulers', WhatsAppSchedulerController::class);
    Route::patch('/whatsapp-schedulers/{whatsapp_scheduler}/toggle', [WhatsAppSchedulerController::class, 'toggle'])->name('whatsapp-schedulers.toggle');

    // -- WhatsApp API Routes --
    Route::prefix('whatsapp')->group(function () {
      Route::post('/send-message', [WhatsAppController::class, 'sendMessage']);
      Route::post('/test', [WhatsAppController::class, 'test']);
      Route::get('/status', [WhatsAppController::class, 'getStatus']);
      Route::post('/send-sale-notification', [WhatsAppController::class, 'sendSaleNotification']);
    });
  });

  // -- Suppliers Management --
  Route::apiResource('suppliers', SupplierController::class);

  // -- Supplier Payments & Ledger --
  Route::prefix('suppliers/{supplier}')->group(function () {
    Route::get('/ledger', [SupplierPaymentController::class, 'getLedger']);
    Route::post('/payments', [SupplierPaymentController::class, 'store']);
  });
  Route::apiResource('supplier-payments', SupplierPaymentController::class)->except(['index', 'show']);
  Route::get('/payment-methods', [SupplierPaymentController::class, 'getPaymentMethods']);
  Route::get('/payment-types', [SupplierPaymentController::class, 'getPaymentTypes']);

  // -- Sale Returns --
  Route::get('/sale-returns/total-amount', [SaleReturnController::class, 'getTotalReturnedAmount'])->name('api.sale-returns.total-amount');
  Route::apiResource('sale-returns', SaleReturnController::class)->except(['update', 'destroy']);
  Route::get('/sales/{sale}/returnable-items', [SaleController::class, 'getReturnableItems'])->name('api.sales.returnableItems');

  // -- Products Management --
  Route::post('/product/by-ids', [ProductController::class, 'getByIds']);
  Route::post('/products/import', [ProductController::class, 'importExcel']);
  Route::post('/products/preview-import', [ProductController::class, 'previewImport']);
  Route::post('/products/process-import', [ProductController::class, 'processImport']);
  Route::get('/products/{product}/purchase-history', [ProductController::class, 'purchaseHistory']);
  Route::get('/products/{product}/sales-history', [ProductController::class, 'salesHistory']);
  Route::apiResource('products', ProductController::class);

  // -- Warehouses Management --
  Route::apiResource('warehouses', WarehouseController::class);
  Route::post('warehouses/{warehouse}/import-missing-products', [WarehouseController::class, 'importMissingProducts']);
  Route::apiResource('stock-transfers', StockTransferController::class)->only(['index', 'store']);

  // -- Units Management --
  Route::get('/units/stocking', [UnitController::class, 'stocking'])->name('api.units.stocking');
  Route::get('/units/sellable', [UnitController::class, 'sellable'])->name('api.units.sellable');
  Route::apiResource('units', UnitController::class);

  // -- Purchases Management --
  Route::apiResource('purchases', PurchaseController::class);
  Route::post('/purchases/import-items', [PurchaseController::class, 'importPurchaseItems']);
  Route::post('/purchases/preview-import-items', [PurchaseController::class, 'previewImportPurchaseItems']);
  Route::post('/purchases/process-import-items', [PurchaseController::class, 'processImportPurchaseItems']);
  Route::post('/purchases/{purchase}/items', [PurchaseController::class, 'addPurchaseItem'])->name('api.purchases.addPurchaseItem');
  Route::put('/purchases/{purchase}/items/{purchaseItem}', [PurchaseController::class, 'updatePurchaseItem'])->name('api.purchases.updatePurchaseItem');
  Route::delete('/purchases/{purchase}/items/{purchaseItem}', [PurchaseController::class, 'deletePurchaseItem'])->name('api.purchases.deletePurchaseItem');
  Route::get('/sales/calculator', [SaleController::class, 'calculator'])->name('api.sales.calculator'); // <-- Calculator Route
  Route::get('/sales/today-by-created-at', [SaleController::class, 'getTodaySalesByCreatedAt'])->name('api.sales.todayByCreatedAt');
  // -- Sales Management --
  Route::apiResource('sales', SaleController::class);
  Route::post('/sales/create-empty', [SaleController::class, 'createEmptySale'])->name('api.sales.createEmpty');
  Route::post('/sales/{sale}/payments', [SaleController::class, 'addPayment'])->name('api.sales.addPayment');
  Route::delete('/sales/{sale}/payments', [SaleController::class, 'deletePayments'])->name('api.sales.deletePayments');
  Route::post('/sales/{sale}/payments/single', [SaleController::class, 'addSinglePayment'])->name('api.sales.addSinglePayment');
  Route::put('/sales/{sale}/discount', [SaleController::class, 'updateDiscount'])->name('api.sales.updateDiscount');
  Route::delete('/sales/{sale}/payments/{payment}', [SaleController::class, 'deleteSinglePayment'])->name('api.sales.deleteSinglePayment');
  Route::post('/sales/{sale}/items', [SaleController::class, 'addSaleItem'])->name('api.sales.addSaleItem');
  Route::post('/sales/{sale}/items/multiple', [SaleController::class, 'addMultipleSaleItems'])->name('api.sales.addMultipleSaleItems');
  Route::put('/sales/{sale}/items/{saleItem}', [SaleController::class, 'updateSaleItem'])->name('api.sales.updateSaleItem');
  Route::delete('/sales/{sale}/items/{saleItem}', [SaleController::class, 'deleteSaleItem'])->name('api.sales.deleteSaleItem');
  Route::get('/sales/{sale}/thermal-invoice-pdf', [SaleController::class, 'downloadThermalInvoicePDF'])->name('api.sales.thermalInvoice.pdf'); // <-- New Route
  Route::get('/sales/{sale}/invoice-pdf', [SaleController::class, 'downloadInvoicePDF'])->name('api.sales.invoice.pdf'); // <-- Invoice PDF Route

  // ... existing Sale routes ...
  Route::get('/sales-print/last-completed-id', [SaleController::class, 'getLastCompletedSaleId'])->name('api.sales.lastCompletedId'); // <-- New Route


  //reports/sales/pdf
  // -- Dashboard Data --
  Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->name('api.dashboard.summary');
  Route::get('/dashboard/sales-terminal-summary', [DashboardController::class, 'salesTerminalSummary'])->name('api.dashboard.salesTerminalSummary'); //

  // -- Public Users Route for Filters --
  Route::get('/users/list', [UserController::class, 'listForFilters'])->name('api.users.list');
});
