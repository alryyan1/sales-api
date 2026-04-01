<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleReminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SaleReminderController extends Controller
{
    /**
     * Create or update the reminder for a sale.
     * POST /sales/{sale}/reminder
     */
    public function upsert(Request $request, Sale $sale): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'required|integer|min:1|max:365',
        ]);

        $remindAt = now()->addDays($validated['days'])->format('Y-m-d');

        $reminder = SaleReminder::updateOrCreate(
            ['sale_id' => $sale->id],
            ['remind_at' => $remindAt, 'is_dismissed' => false]
        );

        return response()->json($reminder, 200);
    }

    /**
     * Get the active reminder for a sale.
     * GET /sales/{sale}/reminder
     */
    public function show(Sale $sale): JsonResponse
    {
        $reminder = SaleReminder::where('sale_id', $sale->id)
            ->where('is_dismissed', false)
            ->first();

        return response()->json($reminder);
    }

    /**
     * Delete the reminder for a sale.
     * DELETE /sales/{sale}/reminder
     */
    public function destroy(Sale $sale): Response
    {
        SaleReminder::where('sale_id', $sale->id)->delete();

        return response()->noContent();
    }

    /**
     * Get all due reminders (remind_at <= today, not dismissed, sale still has due amount).
     * GET /sale-reminders/due
     */
    public function due(): JsonResponse
    {
        $reminders = SaleReminder::with(['sale.client', 'sale.payments', 'sale.items'])
            ->where('remind_at', '<=', today())
            ->where('is_dismissed', false)
            ->get()
            ->filter(fn($r) => $r->sale && (float) $r->sale->calculated_due_amount > 0)
            ->map(fn($r) => [
                'id'         => $r->id,
                'sale_id'    => $r->sale_id,
                'remind_at'  => $r->remind_at->format('Y-m-d'),
                'sale'       => [
                    'id'          => $r->sale->id,
                    'client_name' => $r->sale->client?->name,
                    'due_amount'  => (float) $r->sale->calculated_due_amount,
                    'client'      => $r->sale->client ? [
                        'name'  => $r->sale->client->name,
                        'phone' => $r->sale->client->phone,
                    ] : null,
                ],
            ])
            ->values();

        return response()->json($reminders);
    }

    /**
     * Dismiss a reminder.
     * PATCH /sale-reminders/{reminder}/dismiss
     */
    public function dismiss(SaleReminder $reminder): Response
    {
        $reminder->update(['is_dismissed' => true]);

        return response()->noContent();
    }
}
