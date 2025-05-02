<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Import BelongsTo
use Illuminate\Database\Eloquent\Relations\HasMany;   // Import HasMany

class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'purchase_date',
        'reference_number',
        'total_amount',
        'status',
        'notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'total_amount' => 'decimal:2',
    ];

    /**
     * Get the supplier that owns the purchase.
     */
    public function supplier(): BelongsTo // Define the relationship
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the items included in the purchase.
     */
    public function items(): HasMany // Define the relationship
    {
        return $this->hasMany(PurchaseItem::class);
    }
}