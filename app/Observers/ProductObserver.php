<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\WhatsAppService; // Import the service
use App\Events\ProductStockLow;
use App\Events\ProductOutOfStock;
use Illuminate\Support\Facades\Log;

class ProductObserver
{
    protected WhatsAppService $whatsAppService;

    public function __construct(WhatsAppService $whatsAppService)
    {
        $this->whatsAppService = $whatsAppService;
    }

    /**
     * Handle the Product "updated" event.
     *
     * @param  \App\Models\Product  $product
     * @return void
     */
    public function updated(Product $product): void
    {
        // Only check if stock_alert_level changed (stock_quantity column dropped)
        // Stock changes happen via warehouse updates which have their own alert logic
        if ($product->isDirty('stock_alert_level') && $product->stock_alert_level !== null) {
            $currentStock = $product->total_stock;
            $alertLevel = $product->stock_alert_level;

            // Check if we're now below the new alert level
            $originalAlert = $product->getOriginal('stock_alert_level');

            // If stock is low against new alert level, and it wasn't low before
            if ($currentStock <= $alertLevel && ($originalAlert === null || $currentStock > $originalAlert)) {
                Log::info("ProductObserver: Alert level changed - Product ID {$product->id} now low. Current: {$currentStock}, Alert: {$alertLevel}.");
                $this->whatsAppService->sendLowStockAlert($product);

                if ($currentStock > 0) {
                    event(new ProductStockLow($product));
                } else {
                    event(new ProductOutOfStock($product));
                }
            }
        }
    }

    public function created(Product $product): void
    {
        // Check if created with low stock
        if ($product->stock_alert_level !== null && $product->stock_quantity <= $product->stock_alert_level) {
            Log::info("ProductObserver: Low stock on creation for Product ID {$product->id}. Current: {$product->stock_quantity}, Alert: {$product->stock_alert_level}.");
            $this->whatsAppService->sendLowStockAlert($product);

            if ($product->stock_quantity > 0) {
                event(new ProductStockLow($product));
            } else {
                event(new ProductOutOfStock($product));
            }
            event(new ProductOutOfStock($product));
        }
    }
}
