<?php // app/Models/SaleReturnItem.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
