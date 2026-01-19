<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCount extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id',
        'user_id',
        'count_date',
        'status',
        'notes',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'count_date' => 'date',
        'approved_at' => 'datetime',
    ];

    /**
     * Get the warehouse that owns the inventory count
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Get the user who created the count
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who approved the count
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the items for the inventory count
     */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryCountItem::class);
    }

    /**
     * Scope a query to only include counts for a specific warehouse
     */
    public function scopeByWarehouse($query, $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    /**
     * Scope a query to only include counts with a specific status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Calculate total difference across all items
     */
    public function calculateTotalDifference(): float
    {
        return $this->items->sum(function ($item) {
            return ($item->actual_quantity ?? 0) - $item->expected_quantity;
        });
    }

    /**
     * Approve the inventory count and adjust inventory
     */
    public function approve(int $approvedById): bool
    {
        if ($this->status !== 'completed') {
            return false;
        }

        $this->update([
            'status' => 'approved',
            'approved_by' => $approvedById,
            'approved_at' => now(),
        ]);

        // Adjust inventory quantities
        foreach ($this->items as $item) {
            if ($item->actual_quantity !== null) {
                $difference = $item->actual_quantity - $item->expected_quantity;

                if ($difference != 0) {
                    // Update product warehouse quantity
                    $product = $item->product;
                    $pivot = $product->warehouses()->where('warehouse_id', $this->warehouse_id)->first();

                    if ($pivot) {
                        $newQuantity = max(0, $pivot->pivot->quantity + $difference);
                        $product->warehouses()->updateExistingPivot($this->warehouse_id, [
                            'quantity' => $newQuantity
                        ]);
                    }
                }
            }
        }

        return true;
    }

    /**
     * Reject the inventory count
     */
    public function reject(int $rejectedById): bool
    {
        if ($this->status !== 'completed') {
            return false;
        }

        $this->update([
            'status' => 'rejected',
            'approved_by' => $rejectedById,
            'approved_at' => now(),
        ]);

        return true;
    }
}
