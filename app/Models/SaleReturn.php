<?php // app/Models/SaleReturn.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $original_sale_id
 * @property int|null $client_id
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon $return_date
 * @property string|null $return_reason
 * @property string|null $notes
 * @property string $total_returned_amount
 * @property string $status
 * @property string $credit_action
 * @property string $refunded_amount
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Client|null $client
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SaleReturnItem> $items
 * @property-read int|null $items_count
 * @property-read \App\Models\Sale $originalSale
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturn newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturn newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturn query()
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturn whereClientId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturn whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturn whereCreditAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturn whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturn whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturn whereOriginalSaleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturn whereRefundedAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturn whereReturnDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturn whereReturnReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturn whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturn whereTotalReturnedAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturn whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleReturn whereUserId($value)
 * @mixin \Eloquent
 */
class SaleReturn extends Model
{
    use HasFactory;
    protected $fillable = [
        'original_sale_id',
        'client_id',
        'user_id',
        'return_date',
        'return_reason',
        'notes',
        'total_returned_amount',
        'status',
        'credit_action',
        'refunded_amount',
    ];
    protected $casts = [
        'return_date' => 'date',
        'total_returned_amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
    ];
    public function originalSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'original_sale_id');
    }
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }
}
