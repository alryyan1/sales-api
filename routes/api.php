<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\{
  AuthController,
  BackupController,
  ExpenseCategoryController,
  PackageController,
  SystemController,
  ExpenseController,
  CategoryController,
  ClientController,
  DashboardController,
  InventoryCountController,
  PermissionController,
  PdfReportSettingController,
  ReportTemplateController,
  ProductController,
  ProfileController,
  PurchaseController,
  ReportController,
  RoleController,
  SaleController,
  SaleReminderController,
  SaleReturnController,
  SettingController,
  StockAdjustmentController,
  SupplierController,
  SupplierPaymentController,
  UnitController,
  UserController,
  ShiftController,
  WarehouseController,
  StockTransferController,
  WhatsAppCloudApiController,
};
use App\Http\Controllers\UpdateController;

// --- Update System Routes (Public) ---
Route::get('/updates/check', [UpdateController::class, 'checkForUpdates']);
Route::post('/updates/perform', [UpdateController::class, 'performUpdate']);

// --- Public Routes ---
Route::post('/register', [AuthController::class, 'register'])->name('api.register');
Route::post('/login', [AuthController::class, 'login'])->name('api.login');

// --- Health Check Endpoint (Public, lightweight) ---
Route::get('/health', function () {
  return response()->json(['status' => 'ok', 'timestamp' => now()], 200);
})->name('api.health');

// --- Public WhatsApp Cloud Webhook Routes (no auth) ---
Route::get('/whatsapp-cloud/webhook', [WhatsAppCloudApiController::class, 'verifyWebhook']);
Route::post('/whatsapp-cloud/webhook', [WhatsAppCloudApiController::class, 'webhook']);

// --- Public Settings (read-only, no auth required) ---
Route::get('admin/settings', [SettingController::class, 'index'])->name('settings.index.public');

// --- Public PDF Routes (token validated in controller) ---
Route::get('/reports/moved-expired-pdf', [ReportController::class, 'movedExpiredPdf'])->name('api.reports.moved-expired-pdf');

// --- Public Firestore Sync Routes (called by mobile app, no auth required) ---
Route::post('/clients/sync-to-firestore', [ClientController::class, 'syncToFirestore'])->name('api.clients.sync-to-firestore');
Route::post('/suppliers/sync-to-firestore', [SupplierController::class, 'syncToFirestore'])->name('api.suppliers.sync-to-firestore');
Route::post('/products/sync-to-firestore', [ProductController::class, 'syncToFirestore'])->name('api.products.sync-to-firestore');

// --- Protected Routes ---
Route::middleware('auth:sanctum')->group(function () {

  // -- Authentication --
  Route::get('/user', [AuthController::class, 'user'])->name('api.user');
  Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');

  // -- Shifts (POS) --
  Route::get('/shifts', [ShiftController::class, 'index'])->name('api.shifts.index');
  Route::get('/shifts/by-month', [ShiftController::class, 'byMonth'])->name('api.shifts.by-month');
  Route::get('/shifts/current', [ShiftController::class, 'current'])->name('api.shifts.current');
  Route::get('/shifts/{shift}', [ShiftController::class, 'show'])->name('api.shifts.show');
  Route::post('/shifts/open', [ShiftController::class, 'open'])->name('api.shifts.open');
  Route::post('/shifts/close', [ShiftController::class, 'close'])->name('api.shifts.close');
  Route::post('/shifts/{shift}/notify', [ShiftController::class, 'notify'])->name('api.shifts.notify');

  // -- Autocomplete Routes --
  Route::get('/products/autocomplete', [ProductController::class, 'autocomplete'])->name('api.products.autocomplete');
  Route::get('/clients/autocomplete', [ClientController::class, 'autocomplete'])->name('api.clients.autocomplete');
  // -- Clients Management --
  Route::apiResource('clients', ClientController::class);
  Route::get('/clients/{client}/ledger', [\App\Http\Controllers\Api\ClientLedgerController::class, 'getLedger']);
  Route::get('/clients/{client}/ledger/pdf', [\App\Http\Controllers\Api\ClientLedgerController::class, 'downloadLedgerPDF']);
  Route::post('/clients/{client}/settle-debt', [\App\Http\Controllers\Api\ClientLedgerController::class, 'settleDebt']);
  Route::get('/clients/{client}/payments', [\App\Http\Controllers\Api\ClientLedgerController::class, 'getPayments']);
  Route::get('products/{product}/available-batches', [ProductController::class, 'getAvailableBatches']);
  Route::get('products/{product}/barcode-label-pdf', [ProductController::class, 'barcodeLabelPdf'])->name('api.products.barcodeLabelPdf');

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

  Route::put('admin/settings', [SettingController::class, 'update'])->name('settings.update');
  Route::post('admin/settings/logo', [SettingController::class, 'uploadLogo'])->name('settings.uploadLogo');
  Route::post('admin/settings/header', [SettingController::class, 'uploadHeader'])->name('settings.uploadHeader');
  Route::post('admin/settings/stamp', [SettingController::class, 'uploadStamp'])->name('settings.uploadStamp');
  Route::post('admin/settings/signature', [SettingController::class, 'uploadSignature'])->name('settings.uploadSignature');

  // -- PDF Report Branding Settings --
  Route::get('admin/pdf-report-settings', [PdfReportSettingController::class, 'index'])->name('pdf-report-settings.index');
  Route::put('admin/pdf-report-settings/{reportKey}', [PdfReportSettingController::class, 'update'])->name('pdf-report-settings.update');

  // -- Database Backup Management --
  Route::prefix('admin')->name('api.admin.')->group(function () {
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
    Route::get('/near-expiry', [ReportController::class, 'nearExpiryReport'])->name('near-expiry');
    Route::get('/expired-products', [ReportController::class, 'expiredProductsReport'])->name('expired-products');
    Route::post('/expired-products/{id}/move', [ReportController::class, 'moveExpiredProduct'])->name('expired-products.move');
    Route::get('/moved-expired', [ReportController::class, 'movedExpiredProductsReport'])->name('moved-expired');
    Route::get('/expiry-counts', [ReportController::class, 'expiryCountsSummary'])->name('expiry-counts');
    Route::get('/monthly-revenue', [ReportController::class, 'monthlyRevenueReport'])->name('monthly-revenue');
    Route::get('/monthly-revenue-excel', [ReportController::class, 'monthlyRevenueExcel'])->name('monthly-revenue-excel');
    Route::get('/monthly-purchases', [ReportController::class, 'monthlyPurchasesReport'])->name('monthly-purchases');
    Route::get('/top-products', [ReportController::class, 'topSellingProducts'])->name('top-products');

    // Statistical Dashboard Routes
    Route::get('/stats/best-selling', [ReportController::class, 'bestSelling'])->name('stats.best-selling');
    Route::get('/stats/stagnant', [ReportController::class, 'stagnant'])->name('stats.stagnant');
    Route::get('/stats/expiring', [ReportController::class, 'expiring'])->name('stats.expiring');

    Route::get('/daily-sales-pdf', [ReportController::class, 'dailySalesPdf'])->name('daily-sales-pdf');
    Route::get('/sales-pdf', [ReportController::class, 'downloadSalesReportPDF'])->name('sales-pdf');
    Route::get('/shift-cost-pdf', [ReportController::class, 'shiftCostPdf'])->name('shift-cost-pdf');
    Route::apiResource('templates', ReportTemplateController::class)->except(['create', 'edit']);
    Route::get('/templates/{template}/pdf', [ReportTemplateController::class, 'pdf'])->name('templates.pdf');
    Route::get('/shift-returns-pdf', [ReportController::class, 'shiftReturnsPdf'])->name('shift-returns-pdf');
    Route::get('/shift-sold-items-pdf', [ReportController::class, 'shiftSoldItemsPdf'])->name('shift-sold-items-pdf');
    Route::get('/shift-inventory-effects-pdf', [ReportController::class, 'shiftInventoryEffectsPdf'])->name('shift-inventory-effects-pdf');
    Route::get('/inventory-pdf', [ReportController::class, 'inventoryPdf'])->name('inventory-pdf');
    Route::get('/inventory-log', [App\Http\Controllers\Api\InventoryLogController::class, 'index'])->name('inventory-log');
    Route::get('/inventory-log/pdf', [App\Http\Controllers\Api\InventoryLogController::class, 'generatePdf'])->name('inventory-log.pdf');
    Route::get('/expenses-summary', [ReportController::class, 'expensesSummary'])->name('expenses-summary');
    Route::get('/monthly-expenses', [ReportController::class, 'monthlyExpenses'])->name('monthly-expenses');
    Route::get('/monthly-expenses-excel', [ReportController::class, 'monthlyExpensesExcel'])->name('monthly-expenses-excel');
    Route::get('/inventory-audit-pdf', [ReportController::class, 'inventoryAuditPdf'])->name('inventory-audit-pdf');
    Route::get('/warehouse-products-pdf', [ReportController::class, 'warehouseProductsPdf'])->name('warehouse-products-pdf');
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

    // -- WhatsApp Cloud API Routes --
    Route::prefix('whatsapp-cloud')->group(function () {
      Route::post('/send-text', [WhatsAppCloudApiController::class, 'sendTextMessage']);
      Route::post('/send-template', [WhatsAppCloudApiController::class, 'sendTemplateMessage']);
      Route::post('/send-document', [WhatsAppCloudApiController::class, 'sendDocument']);
      Route::post('/send-image', [WhatsAppCloudApiController::class, 'sendImage']);
      Route::post('/send-audio', [WhatsAppCloudApiController::class, 'sendAudio']);
      Route::post('/send-video', [WhatsAppCloudApiController::class, 'sendVideo']);
      Route::post('/send-location', [WhatsAppCloudApiController::class, 'sendLocation']);
      Route::get('/templates', [WhatsAppCloudApiController::class, 'getTemplates']);
      Route::get('/phone-numbers', [WhatsAppCloudApiController::class, 'getPhoneNumbers']);
      Route::get('/is-configured', [WhatsAppCloudApiController::class, 'isConfigured']);
      Route::get('/test-get-result-by-phone/{phone?}', [WhatsAppCloudApiController::class, 'testGetResultUrlByPhone']);
      Route::get('/test-shift-firestore/{shift_id?}', [WhatsAppCloudApiController::class, 'testShiftFirestore']);
    });
  });

  // -- Suppliers Management --
  Route::get('/suppliers/summary', [SupplierController::class, 'summary'])->name('api.suppliers.summary');
  Route::apiResource('suppliers', SupplierController::class);

  // -- Supplier Payments & Ledger --
  Route::prefix('suppliers/{supplier}')->group(function () {
    Route::get('/ledger', [SupplierPaymentController::class, 'getLedger']);
    Route::post('/payments', [SupplierPaymentController::class, 'store']);
    Route::post('/settle-debt', [SupplierPaymentController::class, 'settleDebt']);
  });
  Route::apiResource('supplier-payments', SupplierPaymentController::class)->except(['index', 'show']);
  Route::get('/payment-methods', [SupplierPaymentController::class, 'getPaymentMethods']);
  Route::get('/payment-types', [SupplierPaymentController::class, 'getPaymentTypes']);

  // -- Sale Returns --
  Route::get('/sale-returns', [SaleReturnController::class, 'index'])->name('api.sale-returns.index');
  Route::post('/sale-returns', [SaleReturnController::class, 'store'])->name('api.sale-returns.store');

  // -- Products Management --
  Route::post('/product/by-ids', [ProductController::class, 'getByIds']);
  Route::post('/products/import', [ProductController::class, 'importExcel']);
  Route::post('/products/preview-import', [ProductController::class, 'previewImport']);
  Route::post('/products/process-import', [ProductController::class, 'processImport']);
  Route::get('/products/{product}/purchase-history', [ProductController::class, 'purchaseHistory']);
  Route::get('/products/{product}/sales-history', [ProductController::class, 'salesHistory']);
  Route::post('/products/{product}/image', [ProductController::class, 'uploadImage'])->name('api.products.upload-image');
  Route::post('/products/bulk-update-units', [ProductController::class, 'bulkUpdateUnits']);
  Route::post('/products/bulk-update-sale-price', [ProductController::class, 'bulkUpdateSalePrice']);
  Route::post('/products/{product}/clear-sale-price', [ProductController::class, 'clearSalePrice']);
  Route::apiResource('products', ProductController::class);
  Route::apiResource('packages', PackageController::class);

  // -- Warehouses Management --
  Route::apiResource('warehouses', WarehouseController::class);
  Route::post('warehouses/{warehouse}/import-missing-products', [WarehouseController::class, 'importMissingProducts']);
  Route::apiResource('stock-transfers', StockTransferController::class)->only(['index', 'store']);

  // -- Inventory Counts --
  Route::apiResource('inventory-counts', InventoryCountController::class);
  Route::post('/inventory-counts/{inventoryCount}/items', [InventoryCountController::class, 'addItem'])->name('api.inventory-counts.addItem');
  Route::put('/inventory-counts/{inventoryCount}/items/{item}', [InventoryCountController::class, 'updateItem'])->name('api.inventory-counts.updateItem');
  Route::delete('/inventory-counts/{inventoryCount}/items/{item}', [InventoryCountController::class, 'deleteItem'])->name('api.inventory-counts.deleteItem');
  Route::post('/inventory-counts/{inventoryCount}/approve', [InventoryCountController::class, 'approve'])->name('api.inventory-counts.approve');
  Route::post('/inventory-counts/{inventoryCount}/reject', [InventoryCountController::class, 'reject'])->name('api.inventory-counts.reject');
  Route::post('/inventory-counts/{inventoryCount}/import-all-products', [InventoryCountController::class, 'importAllProducts'])->name('api.inventory-counts.importAllProducts');


  // -- Units Management --
  Route::get('/units/all', [UnitController::class, 'all'])->name('api.units.all');
  Route::apiResource('units', UnitController::class);

  // -- Purchases Management --
  Route::apiResource('purchases', PurchaseController::class);
  Route::post('/purchases/import-items', [PurchaseController::class, 'importPurchaseItems']);
  Route::post('/purchases/preview-import-items', [PurchaseController::class, 'previewImportPurchaseItems']);
  Route::post('/purchases/process-import-items', [PurchaseController::class, 'processImportPurchaseItems']);
  Route::get('/purchases/{purchase}/items', [PurchaseController::class, 'getItems'])->name('api.purchases.getItems');
  Route::get('/purchases/{purchase}/payments', [PurchaseController::class, 'getPayments'])->name('api.purchases.getPayments');
  Route::post('/purchases/{purchase}/payments', [PurchaseController::class, 'addPayment'])->name('api.purchases.addPayment');
  Route::post('/purchases/{purchase}/items', [PurchaseController::class, 'addPurchaseItem'])->name('api.purchases.addPurchaseItem');
  Route::put('/purchases/{purchase}/items/{purchaseItem}', [PurchaseController::class, 'updatePurchaseItem'])->name('api.purchases.updatePurchaseItem');
  Route::put('/purchases/{purchase}/items/{purchaseItem}', [PurchaseController::class, 'updatePurchaseItem'])->name('api.purchases.updatePurchaseItem');
  Route::delete('/purchases/{purchase}/items/{purchaseItem}', [PurchaseController::class, 'deletePurchaseItem'])->name('api.purchases.deletePurchaseItem');
  Route::get('/purchases/{purchase}/export-tax-pdf', [PurchaseController::class, 'exportTaxPdf'])->name('api.purchases.exportTaxPdf');
  Route::delete('/purchases/{purchase}/items-zero-quantity', [PurchaseController::class, 'deleteZeroQuantityItems'])->name('api.purchases.deleteZeroQuantityItems');
  Route::post('/purchases/{purchase}/add-all-missing-products', [PurchaseController::class, 'addAllMissingProducts'])->name('api.purchases.addAllMissingProducts');
  Route::get('/sales/calculator', [SaleController::class, 'calculator'])->name('api.sales.calculator'); // <-- Calculator Route
  Route::get('/sales/today-by-created-at', [SaleController::class, 'getTodaySalesByCreatedAt'])->name('api.sales.todayByCreatedAt');
  Route::get('/payments', [\App\Http\Controllers\Api\PaymentController::class, 'index'])->name('api.payments.index');
  Route::get('/payments/stats', [\App\Http\Controllers\Api\PaymentController::class, 'stats'])->name('api.payments.stats');
  Route::get('/sales/list-all', [SaleController::class, 'listAll'])->name('api.sales.listAll');
  // -- Sales Management --
  Route::apiResource('sales', SaleController::class);
  Route::post('/sales/create-empty', [SaleController::class, 'createEmptySale'])->name('api.sales.createEmpty');
  Route::put('/sales/{sale}/remove-client', [SaleController::class, 'removeClient'])->name('api.sales.removeClient');
  Route::post('/sales/{sale}/payments', [SaleController::class, 'addPayment'])->name('api.sales.addPayment');
  Route::delete('/sales/{sale}/payments', [SaleController::class, 'deletePayments'])->name('api.sales.deletePayments');
  Route::post('/sales/{sale}/payments/single', [SaleController::class, 'addSinglePayment'])->name('api.sales.addSinglePayment');
  Route::put('/sales/{sale}/discount', [SaleController::class, 'updateDiscount'])->name('api.sales.updateDiscount');
  Route::delete('/sales/{sale}/payments/{payment}', [SaleController::class, 'deleteSinglePayment'])->name('api.sales.deleteSinglePayment');
  Route::post('/sales/{sale}/items', [SaleController::class, 'addSaleItem'])->name('api.sales.addSaleItem');
  Route::post('/sales/{sale}/items/multiple', [SaleController::class, 'addMultipleSaleItems'])->name('api.sales.addMultipleSaleItems');
  Route::put('/sales/{sale}/items/{saleItem}', [SaleController::class, 'updateSaleItem'])->name('api.sales.updateSaleItem');
  Route::delete('/sales/{sale}/items/{saleItem}', [SaleController::class, 'deleteSaleItem'])->name('api.sales.deleteSaleItem');
  Route::post('/sales/{sale}/toggle-quote', [SaleController::class, 'toggleQuote'])->name('api.sales.toggleQuote');
  Route::get('/sales/{sale}/thermal-invoice-pdf', [SaleController::class, 'downloadThermalInvoicePDF'])->name('api.sales.thermalInvoice.pdf'); // <-- New Route
  Route::get('/sales/{sale}/invoice-pdf', [SaleController::class, 'downloadInvoicePDF'])->name('api.sales.invoice.pdf'); // <-- Invoice PDF Route
  Route::get('/sales/{sale}/a4-invoice-pdf', [SaleController::class, 'downloadA4InvoicePdf'])->name('api.sales.a4Invoice.pdf'); // <-- A4 English Invoice (download)
  Route::get('/sales/{sale}/a4-invoice-pdf/view', [SaleController::class, 'viewA4InvoicePdf'])->name('api.sales.a4Invoice.view'); // <-- A4 English Invoice (view)
  Route::get('/sales-print/last-completed-id', [SaleController::class, 'getLastCompletedSaleId'])->name('api.sales.lastCompletedId');
  // -- Sale Reminders --
  Route::get('/sale-reminders/due', [SaleReminderController::class, 'due'])->name('api.sale-reminders.due');
  Route::patch('/sale-reminders/{reminder}/dismiss', [SaleReminderController::class, 'dismiss'])->name('api.sale-reminders.dismiss');
  Route::get('/sales/{sale}/reminder', [SaleReminderController::class, 'show'])->name('api.sales.reminder.show');
  Route::post('/sales/{sale}/reminder', [SaleReminderController::class, 'upsert'])->name('api.sales.reminder.upsert');
  Route::delete('/sales/{sale}/reminder', [SaleReminderController::class, 'destroy'])->name('api.sales.reminder.destroy');

  // ... existing Sale routes ...



  //reports/sales/pdf
  // -- Dashboard Data --
  Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->name('api.dashboard.summary');
  Route::get('/dashboard/sales-terminal-summary', [DashboardController::class, 'salesTerminalSummary'])->name('api.dashboard.salesTerminalSummary'); //

  // -- Public Users Route for Filters --
  Route::get('/users/list', [UserController::class, 'listForFilters'])->name('api.users.list');
  Route::get('/users/navigation-items', [UserController::class, 'getNavigationItems'])->name('api.users.navigation-items');

  // -- System Management Routes --
  Route::prefix('system')->name('system.')->group(function () {
    Route::get('/version', [SystemController::class, 'getVersion'])->name('version');
    Route::get('/check-updates', [SystemController::class, 'checkForUpdates'])->name('check-updates');
    Route::post('/update-backend', [SystemController::class, 'updateBackend'])->name('update-backend');
    Route::post('/update-frontend', [SystemController::class, 'updateFrontend'])->name('update-frontend');
    Route::post('/update-both', [SystemController::class, 'updateBoth'])->name('update-both');
    Route::get('/frontend-instructions', [SystemController::class, 'getFrontendUpdateInstructions'])->name('frontend-instructions');
  });
});
