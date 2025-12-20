<?php // app/Models/StockAdjustment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property int|null $purchase_item_id
 * @property int|null $user_id
 * @property int $quantity_change
 * @property int $quantity_before
 * @property int $quantity_after
 * @property string|null $reason
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\PurchaseItem|null $purchaseItemBatch
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder|StockAdjustment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|StockAdjustment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|StockAdjustment query()
 * @method static \Illuminate\Database\Eloquent\Builder|StockAdjustment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockAdjustment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockAdjustment whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockAdjustment whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockAdjustment wherePurchaseItemId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockAdjustment whereQuantityAfter($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockAdjustment whereQuantityBefore($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockAdjustment whereQuantityChange($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockAdjustment whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockAdjustment whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockAdjustment whereUserId($value)
 * @mixin \Eloquent
 */
class StockAdjustment extends Model
{
    use HasFactory; // Add factory later if needed for testing

    protected $fillable = [
        'warehouse_id',
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

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
