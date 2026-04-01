<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReminder extends Model
{
    protected $fillable = [
        'sale_id',
        'remind_at',
        'is_dismissed',
    ];

    protected $casts = [
        'remind_at'    => 'date',
        'is_dismissed' => 'boolean',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
