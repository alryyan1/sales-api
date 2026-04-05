<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShiftResource;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @OA\Tag(
 *     name="Shifts",
 *     description="User shift management for POS"
 * )
 */
class ShiftController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/shifts",
     *     summary="Get all shifts",
     *     description="Retrieve a list of all shifts for filtering purposes.",
     *     operationId="getShifts",
     *     tags={"Shifts"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of shifts",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="opened_at", type="string", format="date-time"),
     *                     @OA\Property(property="closed_at", type="string", format="date-time", nullable=true)
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        $shifts = Shift::with('user')->orderBy('id', 'desc')
            ->get(['id', 'opened_at', 'closed_at', 'user_id']);

        return response()->json([
            'data' => $shifts->map(function ($shift) {
                return [
                    'id' => $shift->id,
                    'name' => "الوردية #{$shift->id} - " . ($shift->user->name ?? 'Unknown'),
                    'user_name' => $shift->user->name ?? 'Unknown',
                    'shift_date' => $shift->opened_at ? date('Y-m-d', strtotime($shift->opened_at)) : null,
                ];
            }),
        ]);
    }

    /**
     * Get all shifts for a specific month, grouped by day.
     * Accepts ?year=YYYY&month=MM (defaults to current month).
     */
    public function byMonth(Request $request)
    {
        $year  = (int) $request->input('year',  now()->year);
        $month = (int) $request->input('month', now()->month);

        $start = \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $end   = \Carbon\Carbon::createFromDate($year, $month, 1)->endOfMonth();

        $shifts = Shift::with('user')
            ->whereBetween('opened_at', [$start, $end])
            ->orderBy('opened_at', 'asc')
            ->get(['id', 'opened_at', 'closed_at', 'user_id']);

        // Build a map: date-string -> [ shift summaries ]
        $grouped = [];
        foreach ($shifts as $shift) {
            $day = $shift->opened_at ? $shift->opened_at->format('Y-m-d') : 'unknown';
            $grouped[$day][] = [
                'id'        => $shift->id,
                'user_name' => $shift->user?->name ?? '—',
                'opened_at' => $shift->opened_at?->toISOString(),
                'closed_at' => $shift->closed_at?->toISOString(),
                'is_open'   => is_null($shift->closed_at),
            ];
        }

        return response()->json(['data' => $grouped, 'year' => $year, 'month' => $month]);
    }

    /**
     * @OA\Get(
     *     path="/api/shifts/current",
     *     summary="Get current shift",
     *     description="Retrieve the current active shift for the authenticated user.",
     *     operationId="getCurrentShift",
     *     tags={"Shifts"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Current shift details",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="user_id", type="integer"),
     *                 @OA\Property(property="opened_at", type="string", format="date-time"),
     *                 @OA\Property(property="closed_at", type="string", format="date-time", nullable=true),
     *                 @OA\Property(property="is_open", type="boolean")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="No active shift found"
     *     )
     * )
     */
    public function current(Request $request)
    {
        $shift = Shift::with([
            'user',
            'closedByUser',
            'payments',
            'expenses',
            'saleReturns.items'
        ])
            ->orderBy('id', 'desc')
            ->first();

        return $shift
            ? new ShiftResource($shift)
            : response()->json(null, 204);
    }

    /**
     * @OA\Get(
     *     path="/api/shifts/{id}",
     *     summary="Get a specific shift",
     *     description="Retrieve details and stats of a specific shift by its ID.",
     *     operationId="getShiftById",
     *     tags={"Shifts"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Shift ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Shift details",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Shift not found"
     *     )
     * )
     */
    public function show(Request $request, $id)
    {
        $shift = Shift::with([
            'user',
            'closedByUser',
            'payments',
            'expenses',
            'saleReturns.items'
        ])->findOrFail($id);

        return new ShiftResource($shift);
    }

    /**
     * @OA\Post(
     *     path="/api/shifts/open",
     *     summary="Open new shift",
     *     description="Open a new shift for the authenticated user if one isn't already open.",
     *     operationId="openShift",
     *     tags={"Shifts"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=201,
     *         description="Shift opened",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Shift already open"
     *     )
     * )
     */
    public function open(Request $request)
    {
        if (!Auth::user()->can('فتح ورديه')) {
            abort(403, 'This action is unauthorized.');
        }

        $user = $request->user();

        // Prevent opening multiple shifts
        $existing = Shift::open()->first();
        if ($existing) {
            return response()->json([
                'message' => 'هناك وردية مفتوحة بالفعل لهذا المستخدم.',
            ], 422);
        }

        $shift = Shift::create([
            'user_id' => $user->id,
            'opened_at' => now(),
        ]);

        $shift->load([
            'user',
            'closedByUser',
            'payments',
            'expenses',
            'saleReturns.items'
        ]);

        return new ShiftResource($shift);
    }

    /**
     * @OA\Post(
     *     path="/api/shifts/close",
     *     summary="Close current shift",
     *     description="Close the currently active shift for the authenticated user.",
     *     operationId="closeShift",
     *     tags={"Shifts"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Shift closed",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="No open shift to close"
     *     )
     * )
     */
    public function close(Request $request, \App\Services\WhatsAppCloudApiService $whatsapp, \App\Services\AirtelSmsService $sms)
    {
        if (!Auth::user()->can('اغلاق ورديه')) {
            abort(403, 'This action is unauthorized.');
        }

        $user = $request->user();

        $shift = Shift::with(['payments', 'saleReturns.items', 'expenses'])
            ->whereNull('closed_at')
            ->orderBy('id', 'desc')
            ->first();

        if (!$shift) {
            return response()->json([
                'message' => 'لا توجد وردية مفتوحة لإغلاقها.',
            ], 422);
        }

        // Delegate stats calculation to the model
        $stats        = $shift->calculateStats();
        $salesCash    = $stats['salesCash'];
        $salesBank    = $stats['salesBank'];
        $totalSales   = $stats['totalSales'];
        $returnsCash  = $stats['returnsCash'];
        $returnsBank  = $stats['returnsBank'];
        $totalReturns = $stats['totalReturns'];
        $expensesCash = $stats['expensesCash'];
        $expensesBank = $stats['expensesBank'];
        $netCash      = $stats['netCash'];
        $netBank      = $stats['netBank'];


        $shift->update([
            'closed_at' => now(),
            'closed_by_user_id' => $user->id,
        ]);

        $shift->load([
            'user',
            'closedByUser',
            'payments',
            'expenses',
            'saleReturns.items'
        ]);

        return new ShiftResource($shift);
    }

    /**
     * @OA\Post(
     *     path="/api/shifts/{id}/notify",
     *     summary="Send shift closure notifications",
     *     description="Send WhatsApp and SMS notifications for a closed shift. Should be called after PDFs are uploaded.",
     *     operationId="notifyShiftClosure",
     *     tags={"Shifts"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Shift ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Notifications sent",
     *         @OA\JsonContent(
     *             @OA\Property(property="whatsapp_status", type="string"),
     *             @OA\Property(property="whatsapp_message", type="string")
     *         )
     *     )
     * )
     */
    public function notify(Request $request, $id, \App\Services\WhatsAppCloudApiService $whatsapp, \App\Services\AirtelSmsService $sms)
    {
        $shift = Shift::with(['payments', 'saleReturns.items', 'expenses', 'user'])->findOrFail($id);

        // Delegate stats calculation to the model
        $stats        = $shift->calculateStats();
        $salesCash    = $stats['salesCash'];
        $salesBank    = $stats['salesBank'];
        $totalSales   = $stats['totalSales'];
        $returnsCash  = $stats['returnsCash'];
        $returnsBank  = $stats['returnsBank'];
        $totalReturns = $stats['totalReturns'];
        $expensesCash = $stats['expensesCash'];
        $expensesBank = $stats['expensesBank'];
        $netCash      = $stats['netCash'];
        $netBank      = $stats['netBank'];

        // Total sales value from items (excludes quotes)
        $totalSalesAmount = $shift->calculateTotalSales();

        // Send WhatsApp Notification
        $whatsappStatus = 'skipped';
        $whatsappMessage = '';

        try {
            $settingsService = new \App\Services\SettingsService();
            $settings = $settingsService->getAll();
            $numbersString = $settings['whatsapp_shift_closure_numbers'] ?? '';
            $adminNumbers = array_filter(array_map('trim', explode(',', $numbersString)));

            if (empty($adminNumbers)) {
                $whatsappStatus = 'skipped';
                $whatsappMessage = 'No WhatsApp numbers configured for shift closures.';
            } else {
                $templateName = 'shift_closed_ar';
                $languageCode = 'ar';

                $components = [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => (string)$shift->id],                          // 1 - shift number
                            ['type' => 'text', 'text' => $shift->opened_at?->format('Y-m-d') ?? now()->format('Y-m-d')], // 2 - date
                            ['type' => 'text', 'text' => $shift->user?->name ?? '—'],                  // 3 - user name
                            ['type' => 'text', 'text' => number_format($totalSalesAmount, 2)],         // 4 - total sales (items value)
                            ['type' => 'text', 'text' => number_format($totalSales, 2)],               // 5 - total revenues (payments collected)
                            ['type' => 'text', 'text' => number_format($totalReturns, 2)],             // 6 - total returns
                            ['type' => 'text', 'text' => number_format($netCash, 2)],                  // 7 - net cash
                            ['type' => 'text', 'text' => number_format($netBank, 2)],                  // 8 - net bank
                        ]
                    ],
                    // Embed shift_id in each button payload so the webhook can extract it
                    [
                        'type'       => 'button',
                        'sub_type'   => 'quick_reply',
                        'index'      => '0',
                        'parameters' => [
                            ['type' => 'payload', 'payload' => 'sales_' . $shift->id],
                        ],
                    ],
                    [
                        'type'       => 'button',
                        'sub_type'   => 'quick_reply',
                        'index'      => '1',
                        'parameters' => [
                            ['type' => 'payload', 'payload' => 'sold_items_' . $shift->id],
                        ],
                    ],
                    [
                        'type'       => 'button',
                        'sub_type'   => 'quick_reply',
                        'index'      => '2',
                        'parameters' => [
                            ['type' => 'payload', 'payload' => 'returns_' . $shift->id],
                        ],
                    ],
                ];

                $successCount = 0;
                $errors = [];

                foreach ($adminNumbers as $number) {
                    $result = $whatsapp->sendTemplateMessage(
                        $number,
                        $templateName,
                        $languageCode,
                        $components
                    );

                    if ($result['success']) {
                        $successCount++;
                        \Illuminate\Support\Facades\Log::info("Shift {$shift->id} closure notification sent to {$number}");
                    } else {
                        $errors[] = "Failed for {$number}: " . ($result['error'] ?? 'Unknown error');
                        \Illuminate\Support\Facades\Log::error("Failed to send shift closure notification to {$number}: " . ($result['error'] ?? 'Unknown error'));
                    }
                }

                if ($successCount > 0) {
                    $whatsappStatus = 'success';
                    $whatsappMessage = "Sent to {$successCount} configure number(s).";
                    if (!empty($errors)) {
                        $whatsappMessage .= " Errors: " . implode(' | ', $errors);
                    }
                } else {
                    $whatsappStatus = 'failed';
                    $whatsappMessage = implode(' | ', $errors);
                }
            }
        } catch (\Exception $e) {
            $whatsappStatus = 'failed';
            $whatsappMessage = $e->getMessage();
            \Illuminate\Support\Facades\Log::error("Exception sending shift closure notification: " . $e->getMessage());
        }

        // --- SMS Notification (Airtel) ---
        try {
            $settingsServiceSms = new \App\Services\SettingsService();
            $settingsSms = $settingsServiceSms->getAll();
            $smsNumbersString = $settingsSms['whatsapp_shift_closure_numbers'] ?? '';
            $smsNumbers = array_filter(array_map('trim', explode(',', $smsNumbersString)));

            if (!empty($smsNumbers)) {
                $smsText = "تقرير الوردية #{$shift->id}\n"
                    . "المبيعات: " . number_format($totalSales, 2) . "\n"
                    . "المردودات: " . number_format($totalReturns, 2) . "\n"
                    . "صافي كاش: " . number_format($netCash, 2) . "\n"
                    . "صافي بنك: " . number_format($netBank, 2);

                $smsResults = $sms->sendToMany(array_values($smsNumbers), $smsText);
                \Illuminate\Support\Facades\Log::info("Shift {$shift->id} SMS results", $smsResults);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Shift {$shift->id} SMS send failed: " . $e->getMessage());
        }

        return response()->json([
            'whatsapp_status' => $whatsappStatus,
            'whatsapp_message' => $whatsappMessage,
        ]);
    }
}
