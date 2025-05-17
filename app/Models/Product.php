<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany; // For relationships

/**
 * 
 *
 * @property int $id
 * @property string $name
 * @property string|null $sku
 * @property string|null $description
 * @property int $stock_quantity
 * @property int|null $stock_alert_level
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read float|null $latest_purchase_cost
 * @property-read float|null $suggested_sale_price
 * @property-read int $total_stock_quantity
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PurchaseItem> $purchaseItems
 * @property-read int|null $purchase_items_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PurchaseItem> $purchaseItemsWithStock
 * @property-read int|null $purchase_items_with_stock_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SaleItem> $saleItems
 * @property-read int|null $sale_items_count
 * @method static \Database\Factories\ProductFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder|Product hasPurchaseItemsWithStock()
 * @method static \Illuminate\Database\Eloquent\Builder|Product newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Product newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Product query()
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereSku($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereStockAlertLevel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereStockQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereUpdatedAt($value)
 * @property int|null $category_id
 * @property-read \App\Models\Category|null $category
 * @property-read int $calculated_total_stock
 * @method static \Illuminate\Database\Eloquent\Builder|Product hasStock()
 * @method static \Illuminate\Database\Eloquent\Builder|Product lowStock()
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereCategoryId($value)
 * @mixin \Eloquent
 */
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
        'stock_quantity',
        
        'stock_alert_level',
        'category_id', // Add if using categories
        'sellable_unit_name', // Add if using units
        'units_per_stocking_unit', // Add if using units
        'stocking_unit_name', // Add if using units
        // 'unit',        // Add if using units
    ];

    /**
     * The attributes that should be cast.
     * Helps ensure data types are handled correctly (e.g., decimals, integers).
     *
     * @var array<string, string>
     */
    protected $casts = [
        'stock_quantity' => 'integer',   // Cast to integer
        'stock_alert_level' => 'integer', // Cast to integer
        'units_per_stocking_unit' => 'integer', // Cast to integer
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
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    // Example: Get the latest purchase cost for this product
    public function getLatestPurchaseCostAttribute(): ?float // Accessor: $product->latest_purchase_cost
    {
        $latestItem = $this->purchaseItems()
            ->latest('created_at') // Or by purchase_date on the Purchase model
            ->first();
        return $latestItem ? (float) $latestItem->unit_cost : null;
    }

    // Example: Suggest a sale price based on latest cost + markup

    public function getSuggestedSalePriceAttribute(?float $markupPercentage = null): ?float
    {
        // Use a default markup if none is provided or if null is passed
        $markupToUse = $markupPercentage ?? 25.0; // Use null coalescing operator

        // Ensure latest_purchase_cost is not null before calculation
        $latestCost = $this->latest_purchase_cost; // Call the accessor
        if ($latestCost !== null) {
            return (float) $latestCost * (1 + ($markupToUse / 100));
        }
        return null;
    }
    public function getTotalStockQuantityAttribute(): int
    {
        // This sums the remaining quantities from all batches of this product
        return (int) $this->purchaseItems()->sum('remaining_quantity');
    }
    public function scopeHasPurchaseItemsWithStock($query)
    {
        return $query->whereHas('purchaseItems', function ($q) {
            $q->where('remaining_quantity', '>', 0);
        });
    }
    public function purchaseItemsWithStock(): HasMany
    {
        return $this->hasMany(PurchaseItem::class)->where('remaining_quantity', '>', 0)->orderBy('expiry_date', 'asc');
    }

    /**
     * If NOT using an Observer, this accessor calculates total stock on demand.
     * This is less efficient for querying/filtering than having a dedicated updated column.
     * If using PurchaseItemObserver, this accessor becomes less critical but can be a fallback.
     * Note: $this->calculated_total_stock
     */
    public function getCalculatedTotalStockAttribute(): int
    {
        return (int) $this->purchaseItems()->sum('remaining_quantity');
    }


    // --- SCOPES for querying ---

    /**
     * Scope a query to only include products that have batches with stock.
     */
    public function scopeHasStock($query)
    {
        return $query->whereHas('purchaseItems', function ($q) {
            $q->where('remaining_quantity', '>', 0);
        });
        // OR if 'stock_quantity' on products table is reliably updated by observer:
        // return $query->where('stock_quantity', '>', 0);
    }

    /**
     * Scope a query to only include products that are low on stock.
     */
    public function scopeLowStock($query)
    {
        return $query->whereNotNull('stock_alert_level')
            ->whereColumn('stock_quantity', '<=', 'stock_alert_level');
        // If stock_quantity is an aggregate, this whereColumn will work if it's updated.
        // Otherwise, you'd need a more complex whereHas with sum.
    }
    // Accessor to get the latest cost PER SELLABLE UNIT
    public function getLatestCostPerSellableUnitAttribute(): ?float
    {
        $latestBatch = $this->purchaseItems()
                           ->orderBy('created_at', 'desc')
                           ->first();
        if ($latestBatch && $this->units_per_stocking_unit > 0) {
            // Assuming latestBatch->unit_cost is the cost of the 'stocking_unit_name'
            return round((float) $latestBatch->unit_cost / $this->units_per_stocking_unit, 2);
        }
        return null;
    }
    // Accessor for a suggested sale price PER SELLABLE UNIT
    public function getSuggestedSalePricePerSellableUnitAttribute(?float $markupPercentage = null): ?float
    {
        $markupToUse = $markupPercentage ?? config('app_settings.default_markup_percentage', 25.0); // Example: get from config
        $latestCostPerSellable = $this->latest_cost_per_sellable_unit;

        if ($latestCostPerSellable !== null) {
            return round((float) $latestCostPerSellable * (1 + ($markupToUse / 100)), 2);
        }
        return null;
    }
  
}
