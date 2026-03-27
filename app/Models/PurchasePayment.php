<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchasePayment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'purchase_id',
        'supplier_id',
        'user_id',
        'amount',
        'method',
        'reference_number',
        'payment_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    /**
     * Relationship: The purchase this payment belongs to (optional).
     */
    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * Relationship: The supplier this payment belongs to.
     */
    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Relationship: The user who recorded the payment.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
