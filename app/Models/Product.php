<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany; // Import HasMany

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sku',
        'description',
        'purchase_price',
        'sale_price',
        'stock_quantity',
        'stock_alert_level',
        // 'category_id', // Uncomment if you add categories later
    ];

    // Optional: Cast numeric fields for easier handling
    protected $casts = [
        'purchase_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'stock_alert_level' => 'integer',
    ];

    /**
     * Get the purchase items associated with the product.
     */
    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    /**
     * Get the sale items associated with the product.
     */
    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    // If you add categories:
    // public function category() {
    //     return $this->belongsTo(Category::class);
    // }
}