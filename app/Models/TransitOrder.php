<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransitOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id',
        'supplier_id',
        'order_date',
        'eta_date',
        'notes',
        'status',
        'user_id',
    ];

    protected $casts = [
        'order_date' => 'date',
        'eta_date' => 'date',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (TransitOrder $order) {
            if (empty($order->order_number)) {
                $maxOrderNumber = static::lockForUpdate()->max('order_number') ?? 0;
                $order->order_number = $maxOrderNumber + 1;
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransitOrderItem::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
