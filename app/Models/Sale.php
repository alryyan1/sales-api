<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $sale_order_number
 * @property int|null $client_id
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon $sale_date
 * @property string|null $invoice_number
 * @property string|null $discount_amount
 * @property string|null $discount_type
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Client|null $client
 * @property-read float $calculated_due_amount
 * @property-read float $calculated_paid_amount
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SaleItem> $items
 * @property-read int|null $items_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Payment> $payments
 * @property-read int|null $payments_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SaleReturn> $saleReturns
 * @property-read int|null $sale_returns_count
 * @property-read \App\Models\User|null $user
 * @method static \Database\Factories\SaleFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder|Sale newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Sale newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Sale query()
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereClientId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereDiscountAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereDiscountType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereInvoiceNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale wherePaidAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereSaleDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereSaleOrderNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereSubtotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereTotalAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereUserId($value)
 * @mixin \Eloquent
 */
class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'user_id',
        'shift_id',
        'sale_date',
        'invoice_number',
        'notes',
        'discount_amount',
        'discount_type',
        'sale_order_number',
    ];

    protected $casts = [
        'sale_date' => 'date',
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

    /**
     * Get the items included in this sale.
     */
    public function items(): HasMany // Renamed from saleItems for consistency
    {
        return $this->hasMany(SaleItem::class);
    }
    
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    
    /**
     * Get the sale returns associated with this sale.
     */
    public function saleReturns(): HasMany
    {
        return $this->hasMany(SaleReturn::class, 'original_sale_id');
    }
    
    /**
     * Check if this sale has any returns.
     */
    public function hasReturns(): bool
    {
        return $this->saleReturns()->exists();
    }

    /**
     * Boot method to auto-generate sale_order_number
     * Order numbers are unique per shift (shift_id + sale_order_number)
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($sale) {
            if (empty($sale->sale_order_number)) {
                // Get the next order number for the current shift
                // If no shift_id, fall back to date-based numbering for backward compatibility
                if ($sale->shift_id) {
                    $maxOrderNumber = static::where('shift_id', $sale->shift_id)
                        ->max('sale_order_number') ?? 0;
                    $sale->sale_order_number = $maxOrderNumber + 1;
                } else {
                    // Fallback: use date-based numbering if no shift_id
                $today = $sale->sale_date ?? now()->toDateString();
                $maxOrderNumber = static::whereDate('sale_date', $today)
                        ->whereNull('shift_id')
                    ->max('sale_order_number') ?? 0;
                $sale->sale_order_number = $maxOrderNumber + 1;
                }
            }
        });
    }

    // Accessor to always get the up-to-date paid amount
    public function getCalculatedPaidAmountAttribute(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    // Accessor for due amount
    public function getCalculatedDueAmountAttribute(): float
    {
        // Gross total is now derived from items
        $itemsTotal = (float) $this->items()->sum('total_price');
        $discount = (float) ($this->discount_amount ?? 0);
        $paid = $this->getCalculatedPaidAmountAttribute();
        return (float) max(0, $itemsTotal - $discount - $paid);
    }
}