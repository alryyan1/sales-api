<?php // app/Models/Payment.php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model {
    use HasFactory; // Add factory later
    protected $fillable = [
        'sale_id', 'user_id', 'method', 'amount', 'payment_date', 'reference_number', 'notes'
    ];
    protected $casts = ['amount' => 'decimal:2', 'payment_date' => 'date'];
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    // public function paymentMethod(): BelongsTo { return $this->belongsTo(PaymentMethod::class); } // If using separate table
}