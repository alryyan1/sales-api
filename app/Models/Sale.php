<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $number
 * @property int|null $client_id
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon $sale_date
 * @property bool $is_returned
 * @property float|string|null $discount_amount
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Client|null $client
 * @property-read float $calculated_due_amount
 * @property-read float $calculated_paid_amount
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SaleItem> $items
 * @property-read int|null $items_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Payment> $payments
 * @property-read int|null $payments_count
 * @property-read \App\Models\User|null $user
 * @method static \Database\Factories\SaleFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder|Sale newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Sale newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Sale query()
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereClientId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereSaleDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereSubtotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereIsReturned($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereUserId($value)
 * @mixin \Eloquent
 */
class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id',
        'client_id',
        'user_id',
        'shift_id',
        'sale_date',
        'number',
        'is_returned',
        'total_cost',
        'discount_amount',
    ];

    protected $casts = [
        'sale_date' => 'date',
        'is_returned' => 'boolean',
        'discount_amount' => 'decimal:2',
    ];

    /**
     * Get the client associated with the sale.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the user (salesperson) who made the sale.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the shift associated with this sale.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Get the items included in this sale.
     */
    public function items(): HasMany // Renamed from saleItems for consistency
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Boot method to auto-generate number (unique per shift).
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($sale) {
            if (empty($sale->number)) {
                if ($sale->shift_id) {
                    Shift::where('id', $sale->shift_id)->lockForUpdate()->first();
                    $maxOrderNumber = static::where('shift_id', $sale->shift_id)->max('number') ?? 0;
                    $sale->number = $maxOrderNumber + 1;
                } else {
                    $saleDate = $sale->sale_date ?? now()->toDateString();
                    $maxOrderNumber = static::whereDate('sale_date', $saleDate)
                        ->whereNull('shift_id')
                        ->max('number') ?? 0;
                    $sale->number = $maxOrderNumber + 1;
                }
            }
        });
    }

    // Accessor to always get the up-to-date paid amount
    public function getCalculatedPaidAmountAttribute(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    // Accessor for due amount (subtotal - discount_amount - paid)
    public function getCalculatedDueAmountAttribute(): float
    {
        $itemsTotal = (float) $this->items()->sum('total_price');
        $discount = (float) ($this->discount_amount ?? 0);
        $paid = $this->getCalculatedPaidAmountAttribute();
        return (float) max(0, $itemsTotal - $discount - $paid);
    }
}
