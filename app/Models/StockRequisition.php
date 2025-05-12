<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockRequisition extends Model
{
    use HasFactory; // Remember to create StockRequisitionFactory

    protected $fillable = [
        'requester_user_id',
        'approved_by_user_id',
        'department_or_reason',
        'notes',
        'status',
        'request_date',
        'issue_date',
    ];

    protected $casts = [
        'request_date' => 'date',
        'issue_date' => 'date',
    ];

    public function requesterUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockRequisitionItem::class);
    }
}