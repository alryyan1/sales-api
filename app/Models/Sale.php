<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 
 *
 * @property int $id
 * @property int|null $client_id
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon $sale_date
 * @property string|null $invoice_number
 * @property string $total_amount
 * @property string $paid_amount
 * @property string $status
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Client|null $client
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SaleItem> $items
 * @property-read int|null $items_count
 * @property-read \App\Models\User|null $user
 * @method static \Database\Factories\SaleFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder|Sale newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Sale newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Sale query()
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereClientId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereInvoiceNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale wherePaidAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereSaleDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereTotalAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereUserId($value)
 * @property-read float $calculated_due_amount
 * @property-read float $calculated_paid_amount
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Payment> $payments
 * @property-read int|null $payments_count
 * @mixin \Eloquent
 */
class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'user_id',
        'sale_date',
        'invoice_number',
        'total_amount', // Usually calculated, but make fillable
        'paid_amount',
        'status',
        'notes',
        'subtotal',
        'discount_amount',
        'discount_type',
        'sale_order_number',
    ];

    protected $casts = [
        'sale_date' => 'date',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
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
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($sale) {
            if (empty($sale->sale_order_number)) {
                // Get the next order number for today's date
                $today = $sale->sale_date ?? now()->toDateString();
                $maxOrderNumber = static::whereDate('sale_date', $today)
                    ->max('sale_order_number') ?? 0;
                $sale->sale_order_number = $maxOrderNumber + 1;
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
        return (float) $this->total_amount - $this->getCalculatedPaidAmountAttribute();
    }
}