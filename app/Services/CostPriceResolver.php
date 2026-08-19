<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseItem;

class CostPriceResolver
{
    /**
     * Resolve the per-unit cost of a sale item, in local currency:
     * - If the item's own stored cost is usable, it's returned as-is.
     * - For sales in the current month only, an unusable stored cost is re-resolved live:
     *   - No stored cost at all (cost_price_at_sale = 0): the product's cost is resolved from
     *     its latest purchase, converted using today's exchange rate.
     *   - Stored cost orders of magnitude smaller than the sale price: sale price and cost
     *     should sit on the same currency scale, so a huge price-to-cost ratio means the cost
     *     was frozen as a raw, unconverted USD figure (before the fix in commit f19ccd5) while
     *     the price was already in local currency — convert it now.
     * Older months keep their stored figures as-is, since re-pricing them with today's
     * much-higher rate would misrepresent what they actually cost back then.
     *
     * Takes primitives (not a SaleItem model) so it can be reused both against loaded
     * Eloquent models (SaleResource) and against raw query-builder rows (sales summary totals).
     */
    public static function resolveSaleItemCost(int $productId, float $storedCost, float $unitPrice, bool $isCurrentMonth): float
    {
        if ($isCurrentMonth && $storedCost <= 0) {
            $product = Product::find($productId);

            return $product ? self::resolveCostPrice($product) : 0;
        }

        if ($isCurrentMonth && $storedCost > 0 && $unitPrice > 0 && ($unitPrice / $storedCost) > 100) {
            return self::convertCostToLocalCurrency($storedCost, 'USD');
        }

        return $storedCost;
    }

    /**
     * Resolve cost price for a product from the latest purchase invoice.
     * Falls back to product->cost_price if no purchase item exists.
     */
    public static function resolveCostPrice(Product $product): float
    {
        $lastItem = PurchaseItem::where('purchase_items.product_id', $product->id)
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->orderBy('purchases.purchase_date', 'desc')
            ->orderBy('purchase_items.created_at', 'desc')
            ->select('purchase_items.*', 'purchases.currency as purchase_currency')
            ->first();

        if ($lastItem) {
            $cost = (float) ($lastItem->cost_per_sellable_unit > 0
                ? $lastItem->cost_per_sellable_unit
                : ($lastItem->unit_cost ?? 0));

            return self::convertCostToLocalCurrency($cost, $lastItem->purchase_currency);
        }

        return self::convertCostToLocalCurrency((float) ($product->cost_price ?? 0), $product->preferred_currency);
    }

    /**
     * Cost prices are frozen onto sale_items in whatever currency the source purchase was
     * recorded in. Dashboard/report totals sum cost_price_at_sale as one currency, so a USD
     * cost must be converted to the local currency using the exchange rate in effect at the
     * moment of sale (usd_to_sdg_factor), matching how POS already prices USD-sourced products.
     */
    public static function convertCostToLocalCurrency(float $cost, ?string $currency): float
    {
        if ($currency !== 'USD') {
            return $cost;
        }

        $settings = (new SettingsService)->getAll();
        if (! ($settings['usd_conversion_enabled'] ?? true)) {
            return $cost;
        }

        $factor = (float) ($settings['usd_to_sdg_factor'] ?? 1);

        return $cost * $factor;
    }
}
