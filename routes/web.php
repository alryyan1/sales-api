<?php

use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\SupplierPaymentController;
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

    // Price List PDF Report
    Route::get('/products/pricelist/pdf', [ProductController::class, 'priceListPdf']);

    // Products Excel Export Route
    Route::get('/products/export/excel', [ProductController::class, 'exportExcel']);

    // Purchase PDF Export Route (named for inline display in controller)
    Route::get('/purchases/{purchase}/export/pdf', [PurchaseController::class, 'exportPdf'])->name('purchases.exportPdf');

    // Purchase Excel Export Route
    Route::get('/purchases/export/excel', [PurchaseController::class, 'exportExcel']);

    // Supplier Ledger PDF Export Route
    Route::get('/suppliers/{supplier}/ledger/pdf', [SupplierPaymentController::class, 'exportLedgerPdf']);
});

Route::get('/test-whatsapp', function () {
    $whatsAppService = new WhatsAppService();
    $response = $whatsAppService->sendMessage('249991961111@c.us', 'Hello, this is a test message!');
    return response()->json(['status' => 'Message sent', 'response' => $response]);
});

// Test Airtel SMS — visit /test-sms?to=249912345678
Route::get('/test-sms', function (\Illuminate\Http\Request $request) {
    $to      = $request->query('to', '249991961111');
    $message = $request->query('message', 'اختبار SMS من النظام - ' . now()->format('H:i:s'));

    $sms     = new \App\Services\AirtelSmsService();
    $results = $sms->send($to, $message, (string)time());

    $balance = $sms->getBalance();

    return response()->json([
        'sent_to'   => $to,
        'message'   => $message,
        'result'    => $results,
        'balance'   => $balance,
        'status'    => ($results['success'] ?? false) ? '✅ SMS sent' : '❌ SMS failed',
    ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
});

// ---------------------------------------------------------------------------
// Webhook diagnostic – visit /webhook-check to confirm the webhook is alive
// ---------------------------------------------------------------------------
Route::get('/webhook-check', function (\Illuminate\Http\Request $request) {
    $verifyToken  = config('services.whatsapp_cloud.verify_token', 'alryyan');
    $webhookBase  = url('/api/whatsapp-cloud/webhook');
    $challenge    = 'test_challenge_' . now()->timestamp;

    // Simulate what Meta sends during webhook setup (GET verification)
    $verifyUrl = $webhookBase . '?' . http_build_query([
        'hub_mode'         => 'subscribe',
        'hub_verify_token' => $verifyToken,
        'hub_challenge'    => $challenge,
    ]);

    $verifyResult = null;
    $verifyStatus = null;
    $verifyOk     = false;

    try {
        $http = \Illuminate\Support\Facades\Http::timeout(5)->get($verifyUrl);
        $verifyStatus = $http->status();
        $verifyResult = $http->body();
        $verifyOk     = ($verifyStatus === 200 && trim($verifyResult) === $challenge);
    } catch (\Exception $e) {
        $verifyResult = 'ERROR: ' . $e->getMessage();
    }

    // Simulate what Meta sends for an incoming message (POST)
    $postResult = null;
    $postStatus = null;
    $postOk     = false;
    $samplePayload = [
        'object' => 'whatsapp_business_account',
        'entry'  => [[
            'id'      => 'TEST',
            'changes' => [[
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata'          => ['display_phone_number' => '0000', 'phone_number_id' => 'TEST'],
                    'messages'          => [[
                        'from'      => '000000000',
                        'id'        => 'wamid.test',
                        'timestamp' => now()->timestamp,
                        'text'      => ['body' => 'webhook-check ping'],
                        'type'      => 'text',
                    ]],
                ],
                'field' => 'messages',
            ]],
        ]],
    ];

    try {
        $http = \Illuminate\Support\Facades\Http::timeout(5)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($webhookBase, $samplePayload);
        $postStatus = $http->status();
        $postResult = $http->body();
        $postOk     = ($postStatus >= 200 && $postStatus < 300);
    } catch (\Exception $e) {
        $postResult = 'ERROR: ' . $e->getMessage();
    }

    return response()->json([
        'webhook_base_url' => $webhookBase,
        'verify_token'     => $verifyToken,
        'get_verification' => [
            'url'       => $verifyUrl,
            'http_code' => $verifyStatus,
            'body'      => $verifyResult,
            'passed'    => $verifyOk,
        ],
        'post_webhook'     => [
            'url'       => $webhookBase,
            'http_code' => $postStatus,
            'body'      => $postResult,
            'passed'    => $postOk,
        ],
        'overall_status'   => $verifyOk && $postOk ? '✅ Webhook is working' : '❌ Webhook has issues',
    ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
});
