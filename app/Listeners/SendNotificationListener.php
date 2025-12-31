<?php

namespace App\Listeners;

use App\Events\ProductStockLow;
use App\Events\ProductOutOfStock;
use App\Events\SaleCreated;
use App\Events\PurchaseReceived;
use App\Events\StockRequisitionCreated;
use App\Notifications\LowStockNotification;
use App\Notifications\OutOfStockNotification;
use App\Notifications\NewSaleNotification;
use App\Notifications\PurchaseReceivedNotification;
use App\Notifications\StockRequisitionNotification;
use App\Models\User;
use App\Models\NotificationPreference;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendNotificationListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Send notification to users who have it enabled.
     */
    private function sendToUsers(string $notificationType, $notification, $eventInfo = null): void
    {
        // Get all users
        $users = User::all();
        
        foreach ($users as $user) {
            // Check if user has this notification type enabled
            if (NotificationPreference::isEnabled($user, $notificationType)) {
                $user->notify($notification);
            }
        }
        
        Log::info("Notification '{$notificationType}' sent to enabled users", $eventInfo ?? []);
    }

    /**
     * Handle the ProductStockLow event.
     */
    public function handleProductStockLow(ProductStockLow $event): void
    {
        try {
            $this->sendToUsers(
                'low_stock',
                new LowStockNotification($event->product),
                ['product_id' => $event->product->id]
            );
        } catch (\Exception $e) {
            Log::error("Failed to send low stock notification: " . $e->getMessage());
        }
    }

    /**
     * Handle the ProductOutOfStock event.
     */
    public function handleProductOutOfStock(ProductOutOfStock $event): void
    {
        try {
            $this->sendToUsers(
                'out_of_stock',
                new OutOfStockNotification($event->product),
                ['product_id' => $event->product->id]
            );
        } catch (\Exception $e) {
            Log::error("Failed to send out of stock notification: " . $e->getMessage());
        }
    }

    /**
     * Handle the SaleCreated event.
     */
    public function handleSaleCreated(SaleCreated $event): void
    {
        try {
            $this->sendToUsers(
                'new_sale',
                new NewSaleNotification($event->sale),
                ['sale_id' => $event->sale->id]
            );
        } catch (\Exception $e) {
            Log::error("Failed to send new sale notification: " . $e->getMessage());
        }
    }

    /**
     * Handle the PurchaseReceived event.
     */
    public function handlePurchaseReceived(PurchaseReceived $event): void
    {
        try {
            $this->sendToUsers(
                'purchase_received',
                new PurchaseReceivedNotification($event->purchase),
                ['purchase_id' => $event->purchase->id]
            );
        } catch (\Exception $e) {
            Log::error("Failed to send purchase received notification: " . $e->getMessage());
        }
    }

    /**
     * Handle the StockRequisitionCreated event.
     */
    public function handleStockRequisitionCreated(StockRequisitionCreated $event): void
    {
        try {
            // Get all users who have stock_requisition notifications enabled
            $users = User::all()->filter(function ($user) {
                return NotificationPreference::isEnabled($user, 'stock_requisition');
            });
            
            foreach ($users as $user) {
                $user->notify(new StockRequisitionNotification($event->requisition, $event->action));
            }
            
            // Always notify the requester if they have it enabled
            if ($event->requisition->requester_user_id) {
                $requester = User::find($event->requisition->requester_user_id);
                if ($requester && NotificationPreference::isEnabled($requester, 'stock_requisition')) {
                    $requester->notify(new StockRequisitionNotification($event->requisition, $event->action));
                }
            }
            
            Log::info("Stock requisition notification sent for requisition {$event->requisition->id}");
        } catch (\Exception $e) {
            Log::error("Failed to send stock requisition notification: " . $e->getMessage());
        }
    }
}

