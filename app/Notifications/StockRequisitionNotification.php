<?php

namespace App\Notifications;

use App\Models\StockRequisition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class StockRequisitionNotification extends Notification
{
    use Queueable;

    protected StockRequisition $requisition;
    protected string $action;

    /**
     * Create a new notification instance.
     */
    public function __construct(StockRequisition $requisition, string $action = 'created')
    {
        $this->requisition = $requisition;
        $this->action = $action;
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
        $messages = [
            'created' => 'تم إنشاء طلب مخزون جديد',
            'approved' => 'تم الموافقة على طلب المخزون',
            'rejected' => 'تم رفض طلب المخزون',
            'fulfilled' => 'تم تنفيذ طلب المخزون',
        ];

        $message = $messages[$this->action] ?? 'تم تحديث طلب المخزون';
        
        return [
            'type' => 'stock_requisition',
            'title' => 'طلب مخزون',
            'message' => "{$message} - رقم الطلب: {$this->requisition->id}",
            'data' => [
                'requisition_id' => $this->requisition->id,
                'status' => $this->requisition->status,
                'action' => $this->action,
                'requester_user_id' => $this->requisition->requester_user_id,
                'department_or_reason' => $this->requisition->department_or_reason,
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

