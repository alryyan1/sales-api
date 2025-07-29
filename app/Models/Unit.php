<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get products that use this unit as stocking unit
     */
    public function stockingProducts()
    {
        return $this->hasMany(Product::class, 'stocking_unit_id');
    }

    /**
     * Get products that use this unit as sellable unit
     */
    public function sellableProducts()
    {
        return $this->hasMany(Product::class, 'sellable_unit_id');
    }

    /**
     * Scope to get only stocking units
     */
    public function scopeStocking($query)
    {
        return $query->where('type', 'stocking');
    }

    /**
     * Scope to get only sellable units
     */
    public function scopeSellable($query)
    {
        return $query->where('type', 'sellable');
    }

    /**
     * Scope to get only active units
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
