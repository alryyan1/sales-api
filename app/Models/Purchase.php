<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Import relationship type
use Illuminate\Database\Eloquent\Relations\HasMany;   // Import relationship type

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
}