<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Import relationship type

/**
 * 
 *
 * @property int $id
 * @property int $purchase_id
 * @property int $product_id
 * @property string|null $batch_number
 * @property int $quantity
 * @property int $remaining_quantity
 * @property string $unit_cost
 * @property string $total_cost
 * @property string|null $sale_price
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\Purchase $purchase
 * @method static \Database\Factories\PurchaseItemFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseItem query()
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseItem whereBatchNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseItem whereExpiryDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseItem whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseItem wherePurchaseId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseItem whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseItem whereRemainingQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseItem whereSalePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseItem whereTotalCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseItem whereUnitCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseItem whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class PurchaseItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_id',
        'product_id',
        'quantity',
        'unit_cost',
        'batch_number', // New
        'remaining_quantity', // New
        'total_cost', // Often calculated, but fillable if set directly
        'sale_price',
        'expiry_date', // Optional, can be set at point of sale
        // 'batch_number', // Optional, can be set at point of sale
        // 'unit', // Optional, can be set at point of sale
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'sale_price' => 'decimal:2', // New
        'expiry_date' => 'date',     // New
        'remaining_quantity' => 'integer'
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