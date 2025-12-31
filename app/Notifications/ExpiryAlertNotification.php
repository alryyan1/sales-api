<?php

namespace App\Notifications;

use App\Models\PurchaseItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class ExpiryAlertNotification extends Notification
{
    use Queueable;

    protected PurchaseItem $batch;
    protected int $daysUntilExpiry;

    /**
     * Create a new notification instance.
     */
    public function __construct(PurchaseItem $batch, int $daysUntilExpiry)
    {
        $this->batch = $batch;
        $this->daysUntilExpiry = $daysUntilExpiry;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $productName = $this->batch->product?->name ?? 'منتج غير محدد';
        $batchNumber = $this->batch->batch_number ?? 'بدون رقم دفعة';
        $expiryDate = $this->batch->expiry_date ? date('Y-m-d', strtotime($this->batch->expiry_date)) : 'غير محدد';
        
        $message = $this->daysUntilExpiry <= 0 
            ? "انتهت صلاحية دفعة {$batchNumber} للمنتج {$productName}"
            : "ستنتهي صلاحية دفعة {$batchNumber} للمنتج {$productName} خلال {$this->daysUntilExpiry} يوم";
        
        return [
            'type' => 'expiry_alert',
            'title' => 'تنبيه انتهاء صلاحية',
            'message' => $message,
            'data' => [
                'batch_id' => $this->batch->id,
                'product_id' => $this->batch->product_id,
                'product_name' => $productName,
                'batch_number' => $batchNumber,
                'expiry_date' => $expiryDate,
                'days_until_expiry' => $this->daysUntilExpiry,
                'remaining_quantity' => $this->batch->remaining_quantity,
            ],
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}

