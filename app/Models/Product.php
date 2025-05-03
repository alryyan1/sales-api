<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany; // For relationships

class Product extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     * Ensure all fields you want to create/update via form are here.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'sku',
        'description',
        'purchase_price',
        'sale_price',
        'stock_quantity',
        'stock_alert_level',
        // 'category_id', // Add if using categories
        // 'unit',        // Add if using units
    ];

    /**
     * The attributes that should be cast.
     * Helps ensure data types are handled correctly (e.g., decimals, integers).
     *
     * @var array<string, string>
     */
    protected $casts = [
        'purchase_price' => 'decimal:2', // Cast to decimal with 2 places
        'sale_price' => 'decimal:2',     // Cast to decimal with 2 places
        'stock_quantity' => 'integer',   // Cast to integer
        'stock_alert_level' => 'integer',// Cast to integer
    ];

    /**
     * Get the purchase items associated with the product.
     * (Relationship to PurchaseItem model - define PurchaseItem later)
     */
    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }


    /**
     * Get the sale items associated with the product.
     * (Relationship to SaleItem model - define SaleItem later)
     */
    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    // Optional: Relationship to Category
    // public function category(): BelongsTo
    // {
    //     return $this->belongsTo(Category::class);
    // }
}