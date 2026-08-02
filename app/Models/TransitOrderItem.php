<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransitOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'transit_order_id',
        'product_id',
        'quantity',
        'is_received',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'is_received' => 'boolean',
    ];

    public function transitOrder(): BelongsTo
    {
        return $this->belongsTo(TransitOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
