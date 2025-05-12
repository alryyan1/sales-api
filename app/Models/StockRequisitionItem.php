<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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