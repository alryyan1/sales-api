<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;

class InventoryCountItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_count_id',
        'product_id',
        'expected_quantity',
        'actual_quantity',
        'notes',
    ];

    protected $casts = [
        'expected_quantity' => 'decimal:2',
        'actual_quantity' => 'decimal:2',
    ];

    protected $appends = ['difference'];

    /**
     * Get the inventory count that owns the item
     */
    public function inventoryCount(): BelongsTo
    {
        return $this->belongsTo(InventoryCount::class);
    }

    /**
     * Get the product
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the difference between actual and expected quantity
     */
    protected function difference(): Attribute
    {
        return Attribute::make(
            get: fn() => ($this->actual_quantity ?? 0) - $this->expected_quantity,
        );
    }
}
