<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * Paginated list of payments with sale/user info.
     * Filters: shift_id (takes priority) OR start_date/end_date on payment_date.
     * Optional: user_id, per_page.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'shift_id'   => 'nullable|integer|exists:shifts,id',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'user_id'    => 'nullable|integer|exists:users,id',
            'per_page'   => 'nullable|integer|min:1|max:200',
        ]);

        $query = Payment::with([
            'sale:id,sale_date,client_id,discount_amount',
            'sale.client:id,name',
            'sale.items:id,sale_id,quantity,unit_price',
            'sale.payments:id,sale_id,amount',
            'user:id,name',
        ]);

        if (!empty($validated['shift_id'])) {
            $query->where('shift_id', $validated['shift_id']);
        } else {
            if (!empty($validated['start_date'])) {
                $query->whereDate('payment_date', '>=', $validated['start_date']);
            }
            if (!empty($validated['end_date'])) {
                $query->whereDate('payment_date', '<=', $validated['end_date']);
            }
        }

        if (!empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        $perPage = $validated['per_page'] ?? 50;
        $payments = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $payments->map(function ($p) {
                $sale      = $p->sale;
                $saleTotal = $sale ? $sale->items->sum(fn($i) => $i->quantity * $i->unit_price) - (float) ($sale->discount_amount ?? 0) : 0;
                $salePaid  = $sale ? $sale->payments->sum(fn($pay) => (float) $pay->amount) : 0;
                $saleDue   = max(0, $saleTotal - $salePaid);

                return [
                    'id'               => $p->id,
                    'amount'           => (float) $p->amount,
                    'method'           => $p->method ?? 'cash',
                    'payment_date'     => $p->payment_date?->format('Y-m-d'),
                    'reference_number' => $p->reference_number,
                    'notes'            => $p->notes,
                    'user_name'        => $p->user?->name,
                    'sale_id'          => $p->sale_id,
                    'sale_date'        => $sale?->sale_date?->format('Y-m-d'),
                    'client_name'      => $sale?->client?->name,
                    'client_id'        => $sale?->client_id,
                    'sale_total'       => $saleTotal,
                    'sale_paid'        => $salePaid,
                    'sale_due'         => $saleDue,
                ];
            }),
            'current_page' => $payments->currentPage(),
            'last_page'    => $payments->lastPage(),
            'total'        => $payments->total(),
            'per_page'     => $payments->perPage(),
        ]);
    }

    /**
     * Return payment totals broken down by method.
     */
    public function stats(Request $request)
    {
        $validated = $request->validate([
            'shift_id'   => 'nullable|integer|exists:shifts,id',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'user_id'    => 'nullable|integer|exists:users,id',
        ]);

        $query = Payment::query();

        if (!empty($validated['shift_id'])) {
            $query->where('shift_id', $validated['shift_id']);
        } else {
            if (!empty($validated['start_date'])) {
                $query->whereDate('payment_date', '>=', $validated['start_date']);
            }
            if (!empty($validated['end_date'])) {
                $query->whereDate('payment_date', '<=', $validated['end_date']);
            }
        }

        if (!empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        $payments = $query->get(['method', 'amount']);

        $byMethod = [];
        $total = 0.0;

        foreach ($payments as $payment) {
            $method = $payment->method ?? 'cash';
            $amount = (float) $payment->amount;
            $byMethod[$method] = ($byMethod[$method] ?? 0) + $amount;
            $total += $amount;
        }

        return response()->json([
            'total'     => $total,
            'by_method' => $byMethod,
        ]);
    }
}
