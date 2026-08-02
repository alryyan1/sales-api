<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn() => $this->user?->name),
            'closed_by_user_id' => $this->closed_by_user_id,
            'closed_by_user_name' => $this->whenLoaded('closedByUser', fn() => $this->closedByUser?->name),
            'opened_at' => $this->opened_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'is_open' => $this->is_open,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'stats' => $this->whenLoaded('payments', function () use ($request) {
                $currentUserId = $request->user()?->id;

                // Calculate Sales Breakdown — iterate payments directly via shift_id
                $salesBreakdown = [
                    'cash' => 0,
                    'bank_transfer' => 0,
                    'visa' => 0,
                    'total' => 0,
                ];

                foreach ($this->payments as $payment) {
                    if ($currentUserId && $payment->user_id !== $currentUserId) {
                        continue;
                    }

                    $method = $payment->method ?? 'cash';
                    $amount = (float)$payment->amount;
                    if (isset($salesBreakdown[$method])) {
                        $salesBreakdown[$method] += $amount;
                    }
                    $salesBreakdown['total'] += $amount;
                }

                // Calculate Expenses Breakdown
                $expenses = $this->expenses;
                $expensesBreakdown = [
                    'cash' => 0,
                    'bank_transfer' => 0,
                    'visa' => 0,
                    'total' => 0,
                ];
                if ($expenses) {
                    foreach ($expenses as $expense) {
                        // Filter by logged-in user
                        if ($currentUserId && $expense->user_id !== $currentUserId) {
                            continue;
                        }

                        $method = $expense->payment_method ?? 'cash';
                        $amount = (float)$expense->amount;

                        if (isset($expensesBreakdown[$method])) {
                            $expensesBreakdown[$method] += $amount;
                        }
                        $expensesBreakdown['total'] += $amount;
                    }
                }

                // Calculate Returns Breakdown
                $returns = $this->saleReturns;
                $returnsBreakdown = [
                    'cash' => 0,
                    'bank_transfer' => 0,
                    'visa' => 0,
                    'total' => 0,
                ];

                if ($returns) {
                    foreach ($returns as $ret) {
                        // Filter by logged-in user
                        if ($currentUserId && $ret->user_id !== $currentUserId) {
                            continue;
                        }

                        $amount = $ret->items->sum(function ($item) {
                            return $item->quantity * $item->price;
                        });

                        $method = $ret->returned_payment_method ?? 'cash';

                        if (isset($returnsBreakdown[$method])) {
                            $returnsBreakdown[$method] += $amount;
                        }
                        $returnsBreakdown['total'] += $amount;
                    }
                }

                return [
                    'sales' => $salesBreakdown,
                    'expenses' => $expensesBreakdown,
                    'returns' => $returnsBreakdown,
                    'net' => [
                        'cash' => $salesBreakdown['cash'] - $expensesBreakdown['cash'] - $returnsBreakdown['cash'],
                        'bank_transfer' => $salesBreakdown['bank_transfer'] - $expensesBreakdown['bank_transfer'] - $returnsBreakdown['bank_transfer'],
                        'visa' => $salesBreakdown['visa'] - $expensesBreakdown['visa'] - $returnsBreakdown['visa'],
                        'total' => $salesBreakdown['total'] - $expensesBreakdown['total'] - $returnsBreakdown['total'],
                    ]
                ];
            }),
        ];
    }
}
