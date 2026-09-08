<?php // app/Observers/PaymentObserver.php
namespace App\Observers;
use App\Models\Payment;
use App\Models\Sale;
use App\Http\Resources\SaleResource;
use App\Services\RealtimeNotifier;

class PaymentObserver
{
    // paid_amount column was removed from sales table; totals are now computed from payments on the fly.
    public function created(Payment $payment): void
    {
        $this->notifySaleUpdated($payment);
    }

    public function updated(Payment $payment): void {}

    public function deleted(Payment $payment): void
    {
        $this->notifySaleUpdated($payment);
    }

    public function restored(Payment $payment): void {}

    // Pushes the sale's fresh state to the realtime relay so every cashier watching this
    // shift sees the paid/due status update live, whoever recorded or cancelled the payment.
    private function notifySaleUpdated(Payment $payment): void
    {
        $sale = Sale::with([
            'client:id,name',
            'user:id,name',
            'warehouse:id,name',
            'items.product:id,name,sku,scientific_name,image_url,is_service',
            'payments.user:id,name,username',
            'returns:id,sale_id',
            'returns.items:id,sale_return_id,product_id,quantity,price',
        ])->find($payment->sale_id);

        if (! $sale) {
            return;
        }

        app(RealtimeNotifier::class)->notify('sale.updated', [
            'sale' => (new SaleResource($sale))->resolve(),
        ]);
    }
}
