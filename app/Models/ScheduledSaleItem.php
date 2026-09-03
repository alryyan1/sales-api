<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledSaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'scheduled_sale_id',
        'product_id',
        'quantity',
        'unit_price',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
    ];

    public function scheduledSale(): BelongsTo
    {
        return $this->belongsTo(ScheduledSale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
