<?php // app/Models/StockAdjustment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustment extends Model
{
    use HasFactory; // Add factory later if needed for testing

    protected $fillable = [
        'product_id',
        'purchase_item_id', // If adjusting a specific batch
        'user_id',
        'quantity_change',
        'quantity_before',
        'quantity_after',
        'reason',
        'notes',
    ];

    protected $casts = [
        'quantity_change' => 'integer',
        'quantity_before' => 'integer',
        'quantity_after' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseItemBatch(): BelongsTo // Renamed for clarity
    {
        return $this->belongsTo(PurchaseItem::class, 'purchase_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}