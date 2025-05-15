<?php // app/Models/Payment.php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 
 *
 * @property int $id
 * @property int $sale_id
 * @property int|null $user_id
 * @property string $method
 * @property string $amount
 * @property \Illuminate\Support\Carbon $payment_date
 * @property string|null $reference_number
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Sale $sale
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder|Payment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Payment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Payment query()
 * @method static \Illuminate\Database\Eloquent\Builder|Payment whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Payment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Payment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Payment whereMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Payment whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Payment wherePaymentDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Payment whereReferenceNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Payment whereSaleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Payment whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Payment whereUserId($value)
 * @mixin \Eloquent
 */
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