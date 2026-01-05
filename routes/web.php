<?php

use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use Illuminate\Support\Facades\Route;
use App\Services\WhatsAppService;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Web routes without authentication - using guest.access middleware to allow both authenticated and unauthenticated users
Route::middleware('guest.access')->group(function () {
    Route::get('reports/sales/pdf', [ReportController::class, 'downloadSalesReportPDF'])->name('api.sales.pdf');
    Route::get('reports/sales/{saleId}/pdf', [ReportController::class, 'downloadSaleDetailPDF'])->name('api.sales.detail.pdf');

    // Products PDF Export Route
    Route::get('/products/export/pdf', [ProductController::class, 'exportPdf']);

    // Products Excel Export Route
    Route::get('/products/export/excel', [ProductController::class, 'exportExcel']);

    // Purchase PDF Export Route (named for inline display in controller)
    Route::get('/purchases/{purchase}/export/pdf', [PurchaseController::class, 'exportPdf'])->name('purchases.exportPdf');

    // Purchase Excel Export Route
    Route::get('/purchases/export/excel', [PurchaseController::class, 'exportExcel']);
});

Route::get('/test-whatsapp', function () {
    $whatsAppService = new WhatsAppService();
    $response = $whatsAppService->sendMessage('249991961111@c.us', 'Hello, this is a test message!');
    return response()->json(['status' => 'Message sent', 'response' => $response]);
});