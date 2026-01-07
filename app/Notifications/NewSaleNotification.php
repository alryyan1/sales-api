<?php

namespace App\Notifications;

use App\Models\Sale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class NewSaleNotification extends Notification
{
    use Queueable;

    protected Sale $sale;

    /**
     * Create a new notification instance.
     */
    public function __construct(Sale $sale)
    {
        $this->sale = $sale;
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
        $clientName = $this->sale->client?->name ?? 'عميل غير محدد';
        $totalAmount = number_format((float) $this->sale->total_amount, 2);
        
        return [
            'type' => 'new_sale',
            'title' => 'بيع جديد',
            'message' => "تم إتمام بيع جديد للعميل {$clientName} بمبلغ {$totalAmount} ريال",
            'data' => [
                'sale_id' => $this->sale->id,
                'sale_order_number' => $this->sale->sale_order_number,
                'invoice_number' => $this->sale->invoice_number,
                'client_id' => $this->sale->client_id,
                'client_name' => $clientName,
                'total_amount' => $this->sale->total_amount,
                'user_id' => $this->sale->user_id,
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




