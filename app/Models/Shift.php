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

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Calculate shift financial stats (payments, returns, expenses, net).
     * Assumes payments, saleReturns.items, and expenses are already loaded.
     * Mirrors the breakdown logic in ShiftResource.
     *
     * @return array{salesCash: float, salesBank: float, totalSales: float,
     *               returnsCash: float, returnsBank: float, totalReturns: float,
     *               expensesCash: float, expensesBank: float,
     *               netCash: float, netBank: float}
     */
    public function calculateStats(): array
    {
        $bankMethods = ['bank', 'bank_transfer', 'visa', 'bankak'];

        // 1. Sales — iterate payments directly (mirrors ShiftResource)
        $salesCash = 0.0;
        $salesBank = 0.0;
        foreach ($this->payments as $payment) {
            $method = $payment->method ?? 'cash';
            $amount = (float) $payment->amount;
            if ($method === 'cash') {
                $salesCash += $amount;
            } else {
                $salesBank += $amount;
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
                $expensesCash += (float) $expense->amount;
            } else {
                $expensesBank += (float) $expense->amount;
            }
        }

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
