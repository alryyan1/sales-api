<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 
 *
 * @property int $id
 * @property int $stock_requisition_id
 * @property int $product_id
 * @property int $requested_quantity
 * @property int $issued_quantity
 * @property int|null $issued_from_purchase_item_id
 * @property string|null $issued_batch_number
 * @property string $status
 * @property string|null $item_notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\PurchaseItem|null $issuedFromPurchaseItemBatch
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\StockRequisition $stockRequisition
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisitionItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisitionItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisitionItem query()
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisitionItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisitionItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisitionItem whereIssuedBatchNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisitionItem whereIssuedFromPurchaseItemId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisitionItem whereIssuedQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisitionItem whereItemNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisitionItem whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisitionItem whereRequestedQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisitionItem whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisitionItem whereStockRequisitionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockRequisitionItem whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class StockRequisitionItem extends Model
{
    use HasFactory; // Remember to create StockRequisitionItemFactory

    protected $fillable = [
        'stock_requisition_id',
        'product_id',
        'requested_quantity',
        'issued_quantity',
        'issued_from_purchase_item_id',
        'issued_batch_number',
        'status',
        'item_notes',
    ];

    protected $casts = [
        'requested_quantity' => 'integer',
        'issued_quantity' => 'integer',
    ];

    public function stockRequisition(): BelongsTo
    {
        return $this->belongsTo(StockRequisition::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function issuedFromPurchaseItemBatch(): BelongsTo // Renamed relation
    {
        return $this->belongsTo(PurchaseItem::class, 'issued_from_purchase_item_id');
    }
}