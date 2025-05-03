<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Import relationship type

class PurchaseItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_id',
        'product_id',
        'quantity',
        'unit_cost',
        'total_cost', // Often calculated, but fillable if set directly
    ];

    protected $casts = [
        'quantity' => 'integer',   // Cast to integer
        'unit_cost' => 'decimal:2', // Cast to decimal
        'total_cost' => 'decimal:2', // Cast to decimal
    ];

    /**
     * Get the parent purchase record.
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * Get the product associated with this item.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}