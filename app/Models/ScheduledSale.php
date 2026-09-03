<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduledSale extends Model
{
    use HasFactory;

    const STATUS_PENDING = 'pending';

    const STATUS_PROCESSING = 'processing';

    const STATUS_COMPLETED = 'completed';

    const STATUS_FAILED = 'failed';

    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'client_id',
        'warehouse_id',
        'user_id',
        'scheduled_at',
        'status',
        'discount_amount',
        'discount_type',
        'notes',
        'sale_id',
        'error_message',
        'executed_at',
        'whatsapp_customer_status',
        'whatsapp_customer_error',
        'whatsapp_customer_message_id',
        'whatsapp_customer_sent_at',
        'whatsapp_owner_status',
        'whatsapp_owner_error',
        'whatsapp_owner_message_id',
        'whatsapp_owner_sent_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'executed_at' => 'datetime',
        'whatsapp_customer_sent_at' => 'datetime',
        'whatsapp_owner_sent_at' => 'datetime',
        'discount_amount' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ScheduledSaleItem::class);
    }
}
