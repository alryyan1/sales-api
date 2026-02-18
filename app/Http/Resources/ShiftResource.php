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
            'stats' => $this->whenLoaded('sales', function () use ($request) {
                $currentUserId = $request->user()?->id;

                // Calculate Sales Breakdown
                $sales = $this->sales->load('payments');
                $salesBreakdown = [
                    'cash' => 0,
                    'bankak' => 0,
                    'fawry' => 0,
                    'ocash' => 0,
                    'total' => 0,
                ];

                foreach ($sales as $sale) {
                    // Filter by logged-in user
                    if ($currentUserId && $sale->user_id !== $currentUserId) {
                        continue;
                    }

                    foreach ($sale->payments as $payment) {
                        $method = $payment->method ?? 'cash';
                        $amount = (float)$payment->amount;
                        if (isset($salesBreakdown[$method])) {
                            $salesBreakdown[$method] += $amount;
                        } else {
                            if (in_array($method, ['visa', 'bank', 'bank_transfer'])) {
                                $salesBreakdown['bankak'] += $amount;
                            }
                        }
                        $salesBreakdown['total'] += $amount;
                    }
                }

                // Calculate Expenses Breakdown
                $expenses = $this->expenses;
                $expensesBreakdown = [
                    'cash' => 0,
                    'bankak' => 0,
                    'fawry' => 0,
                    'ocash' => 0,
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

                        $key = match ($method) {
                            'cash' => 'cash',
                            'bank', 'bank_transfer', 'visa', 'bankak' => 'bankak',
                            'fawry' => 'fawry',
                            'ocash' => 'ocash',
                            default => 'cash'
                        };

                        $expensesBreakdown[$key] += $amount;
                        $expensesBreakdown['total'] += $amount;
                    }
                }

                // Calculate Returns Breakdown
                $returns = $this->saleReturns;
                $returnsBreakdown = [
                    'cash' => 0,
                    'bankak' => 0,
                    'fawry' => 0,
                    'ocash' => 0,
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

                        $key = match ($method) {
                            'cash' => 'cash',
                            'bank', 'bank_transfer', 'visa', 'bankak' => 'bankak',
                            'fawry' => 'fawry',
                            'ocash' => 'ocash',
                            default => 'cash'
                        };

                        $returnsBreakdown[$key] += $amount;
                        $returnsBreakdown['total'] += $amount;
                    }
                }

                return [
                    'sales' => $salesBreakdown,
                    'expenses' => $expensesBreakdown,
                    'returns' => $returnsBreakdown,
                    'net' => [
                        'cash' => $salesBreakdown['cash'] - $expensesBreakdown['cash'] - $returnsBreakdown['cash'],
                        'bankak' => $salesBreakdown['bankak'] - $expensesBreakdown['bankak'] - $returnsBreakdown['bankak'],
                        'fawry' => $salesBreakdown['fawry'] - $expensesBreakdown['fawry'] - $returnsBreakdown['fawry'],
                        'ocash' => $salesBreakdown['ocash'] - $expensesBreakdown['ocash'] - $returnsBreakdown['ocash'],
                        'total' => $salesBreakdown['total'] - $expensesBreakdown['total'] - $returnsBreakdown['total'],
                    ]
                ];
            }),
        ];
    }
}
