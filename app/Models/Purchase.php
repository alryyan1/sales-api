<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Import relationship type
use Illuminate\Database\Eloquent\Relations\HasMany;   // Import relationship type

/**
 * 
 *
 * @property int $id
 * @property int|null $supplier_id
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon $purchase_date
 * @property string|null $reference_number
 * @property string $status
 * @property string $total_amount
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PurchaseItem> $items
 * @property-read int|null $items_count
 * @property-read \App\Models\Supplier|null $supplier
 * @property-read \App\Models\User|null $user
 * @method static \Database\Factories\PurchaseFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder|Purchase newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Purchase newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Purchase query()
 * @method static \Illuminate\Database\Eloquent\Builder|Purchase whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Purchase whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Purchase whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Purchase wherePurchaseDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Purchase whereReferenceNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Purchase whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Purchase whereSupplierId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Purchase whereTotalAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Purchase whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Purchase whereUserId($value)
 * @mixin \Eloquent
 */
class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'user_id', // Added user_id
        'purchase_date',
        'reference_number',
        'status',
        'total_amount', // Although calculated, might be set initially or updated
        'notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',       // Cast to Carbon date object
        'total_amount' => 'decimal:2', // Cast to decimal with 2 places
    ];

    /**
     * Get the supplier associated with the purchase.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the user who recorded the purchase.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the items included in this purchase.
     */
    public function items(): HasMany // Renamed from purchaseItems for clarity if preferred
    {
        return $this->hasMany(PurchaseItem::class);
    }

    /**
     * Update the total amount based on the sum of all items.
     */
    public function updateTotalAmount(): void
    {
        $totalAmount = $this->items()->sum(DB::raw('quantity * unit_cost'));
        $this->update(['total_amount' => $totalAmount]);
    }
}