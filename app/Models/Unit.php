<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $type
 * @property string|null $description
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $sellableProducts
 * @property-read int|null $sellable_products_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $stockingProducts
 * @property-read int|null $stocking_products_count
 * @method static \Illuminate\Database\Eloquent\Builder|Unit active()
 * @method static \Illuminate\Database\Eloquent\Builder|Unit newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Unit newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Unit query()
 * @method static \Illuminate\Database\Eloquent\Builder|Unit sellable()
 * @method static \Illuminate\Database\Eloquent\Builder|Unit stocking()
 * @method static \Illuminate\Database\Eloquent\Builder|Unit whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Unit whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Unit whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Unit whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Unit whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Unit whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Unit whereUpdatedAt($value)
 * @mixin \Eloquent
 */
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
