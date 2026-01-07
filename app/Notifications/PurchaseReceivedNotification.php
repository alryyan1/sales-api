<?php

namespace App\Notifications;

use App\Models\Purchase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class PurchaseReceivedNotification extends Notification
{
    use Queueable;

    protected Purchase $purchase;

    /**
     * Create a new notification instance.
     */
    public function __construct(Purchase $purchase)
    {
        $this->purchase = $purchase;
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
        $supplierName = $this->purchase->supplier?->name ?? 'مورد غير محدد';
        $totalCost = number_format((float) $this->purchase->total_cost, 2);
        
        return [
            'type' => 'purchase_received',
            'title' => 'استلام مشتريات',
            'message' => "تم استلام مشتريات من {$supplierName} بقيمة {$totalCost} ريال",
            'data' => [
                'purchase_id' => $this->purchase->id,
                'reference_number' => $this->purchase->reference_number,
                'supplier_id' => $this->purchase->supplier_id,
                'supplier_name' => $supplierName,
                'total_cost' => $this->purchase->total_cost,
                'warehouse_id' => $this->purchase->warehouse_id,
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




