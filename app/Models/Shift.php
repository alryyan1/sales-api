<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'closed_by_user_id',
        'opened_at',
        'closed_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('closed_at');
    }

    public function getIsOpenAttribute(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * Sales recorded during this shift.
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function saleReturns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    /**
     * Calculate shift financial stats (sales, returns, expenses, net).
     * Assumes sales.payments, saleReturns.items, and expenses are already loaded.
     *
     * @return array{salesCash: float, salesBank: float, totalSales: float,
     *               returnsCash: float, returnsBank: float, totalReturns: float,
     *               expensesCash: float, expensesBank: float,
     *               netCash: float, netBank: float}
     */
    public function calculateStats(): array
    {
        // 1. Sales
        $salesCash = 0.0;
        $salesBank = 0.0;
        foreach ($this->sales as $sale) {
            foreach ($sale->payments as $payment) {
                $method = $payment->method ?? 'cash';
                if ($method === 'cash') {
                    $salesCash += $payment->amount;
                } else {
                    $salesBank += $payment->amount;
                }
            }
        }

        // 2. Returns
        $returnsCash = 0.0;
        $returnsBank = 0.0;
        foreach ($this->saleReturns as $return) {
            $returnVal = 0.0;
            foreach ($return->items as $item) {
                $returnVal += ($item->quantity * $item->price);
            }
            $method = $return->returned_payment_method ?? 'cash';
            if ($method === 'cash') {
                $returnsCash += $returnVal;
            } else {
                $returnsBank += $returnVal;
            }
        }

        // 3. Expenses
        $expensesCash = 0.0;
        $expensesBank = 0.0;
        foreach ($this->expenses as $expense) {
            $method = $expense->payment_method ?? 'cash';
            if ($method === 'cash') {
                $expensesCash += $expense->amount;
            } else {
                $expensesBank += $expense->amount;
            }
        }

        // 4. Net
        return [
            'salesCash'    => $salesCash,
            'salesBank'    => $salesBank,
            'totalSales'   => $salesCash + $salesBank,
            'returnsCash'  => $returnsCash,
            'returnsBank'  => $returnsBank,
            'totalReturns' => $returnsCash + $returnsBank,
            'expensesCash' => $expensesCash,
            'expensesBank' => $expensesBank,
            'netCash'      => $salesCash - $returnsCash - $expensesCash,
            'netBank'      => $salesBank - $returnsBank - $expensesBank,
        ];
    }
}
