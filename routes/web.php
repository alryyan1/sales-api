<?php

use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\ProductController;
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

Route::get('reports/sales/pdf', [ReportController::class, 'downloadSalesReportPDF'])->name('api.sales.pdf');
Route::get('/sales/{sale}/invoice-pdf', [SaleController::class, 'downloadInvoicePDF'])->name('api.sales.invoice.pdf');
Route::get('/sales/{sale}/thermal-invoice-pdf', [SaleController::class, 'downloadThermalInvoicePDF'])->name('api.sales.thermalInvoice.pdf');

// Products PDF Export Route
Route::get('/products/export/pdf', [ProductController::class, 'exportPdf'])->name('products.exportPdf');

// Products Excel Export Route
Route::get('/products/export/excel', [ProductController::class, 'exportExcel'])->name('products.exportExcel');

Route::get('/test-whatsapp', function () {
    $whatsAppService = new WhatsAppService();
    $response = $whatsAppService->sendMessage('249991961111@c.us', 'Hello, this is a test message!');
    return response()->json(['status' => 'Message sent', 'response' => $response]);
});