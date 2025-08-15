<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sale_id
 * @property int $product_id
 * @property int|null $purchase_item_id
 * @property string|null $batch_number_sold
 * @property int $quantity
 * @property string $unit_price
 * @property string $total_price
 * @property string $cost_price_at_sale
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\PurchaseItem|null $purchaseItemBatch
 * @property-read \App\Models\Sale $sale
 * @method static \Database\Factories\SaleItemFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem query()
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereBatchNumberSold($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereCostPriceAtSale($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem wherePurchaseItemId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereSaleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereTotalPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereUnitPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'product_id',
        'purchase_item_id',
        'batch_number_sold',
        'quantity',           // Quantity in sellable_unit_name (e.g., pieces)
        'unit_price',         // Sale price PER sellable_unit_name
        'cost_price_at_sale', // Cost PER sellable_unit_name from batch
        'total_price',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'cost_price_at_sale' => 'decimal:2', // Optional, can be set at point of sale
    ];

    /**
     * Get the parent sale record.
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * Get the product associated with this item.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
    // New relationship to the specific purchase item (batch)
    public function purchaseItemBatch(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class, 'purchase_item_id');
    }
}
