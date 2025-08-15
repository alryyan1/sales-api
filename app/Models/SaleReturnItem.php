<?php // app/Models/SaleReturnItem.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sale_return_id
 * @property int $product_id
 * @property int|null $original_sale_item_id
 * @property int|null $return_to_purchase_item_id
 * @property int $quantity_returned
 * @property string $unit_price
 * @property string $total_returned_value
 * @property string|null $condition
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\SaleItem|null $originalSaleItem
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\PurchaseItem|null $returnToPurchaseItemBatch
 * @property-read \App\Models\SaleReturn $saleReturn
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturnItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturnItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturnItem query()
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturnItem whereCondition($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturnItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturnItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturnItem whereOriginalSaleItemId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturnItem whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturnItem whereQuantityReturned($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturnItem whereReturnToPurchaseItemId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturnItem whereSaleReturnId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturnItem whereTotalReturnedValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturnItem whereUnitPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturnItem whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class SaleReturnItem extends Model
{
    use HasFactory;
    protected $fillable = [
        'sale_return_id',
        'product_id',
        'original_sale_item_id',
        'return_to_purchase_item_id',
        'quantity_returned',
        'unit_price',
        'total_returned_value',
        'condition',
    ];
    protected $casts = [
        'quantity_returned' => 'integer',
        'unit_price' => 'decimal:2',
        'total_returned_value' => 'decimal:2',
    ];
    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
    public function originalSaleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class, 'original_sale_item_id');
    }
    public function returnToPurchaseItemBatch(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class, 'return_to_purchase_item_id');
    }
}
