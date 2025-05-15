<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\WhatsAppService; // Import the service
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
        // Check if stock_quantity was changed and is now at or below alert level
        if ($product->isDirty('stock_quantity') && $product->stock_alert_level !== null) {
            $originalStock = $product->getOriginal('stock_quantity'); // Stock before update
            $currentStock = $product->stock_quantity;
            $alertLevel = $product->stock_alert_level;

            // Send alert if stock just dropped to or below the alert level
            // And if it was previously above the alert level (to avoid spamming on every save if already low)
            if ($currentStock <= $alertLevel && ($originalStock > $alertLevel || is_null($originalStock)) ) {
                Log::info("ProductObserver: Low stock detected for Product ID {$product->id}. Current: {$currentStock}, Alert: {$alertLevel}. Original: {$originalStock}");
                $this->whatsAppService->sendLowStockAlert($product);
            }
        }
    }

    // You might also want to check on "created" if initial stock can be low
    public function created(Product $product): void
    {
        if ($product->stock_alert_level !== null && $product->stock_quantity <= $product->stock_alert_level) {
             Log::info("ProductObserver: Low stock on creation for Product ID {$product->id}. Current: {$product->stock_quantity}, Alert: {$product->stock_alert_level}.");
             $this->whatsAppService->sendLowStockAlert($product);
        }
    }
}