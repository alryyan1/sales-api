<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Import BelongsTo
use Illuminate\Database\Eloquent\Relations\HasMany;   // Import HasMany

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'user_id', // Added this field
        'sale_date',
        'invoice_number',
        'total_amount',
        'paid_amount',
        'status',
        'notes',
    ];

    protected $casts = [
        'sale_date' => 'date',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    /**
     * Get the client associated with the sale.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the user (salesperson) associated with the sale.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the items included in the sale.
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }
}