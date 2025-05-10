<?php // app/Models/SaleReturn.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
