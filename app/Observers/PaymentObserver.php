<?php // app/Observers/PaymentObserver.php
namespace App\Observers;
use App\Models\Payment;
use App\Models\Sale;

class PaymentObserver
{
    protected function updateSalePaidAmount(Payment $payment)
    {
        if ($payment->sale_id) {
            $sale = Sale::find($payment->sale_id);
            if ($sale) {
                $sale->paid_amount = $sale->payments()->sum('amount');
                $sale->saveQuietly(); // Avoid triggering other events
            }
        }
    }
    public function created(Payment $payment): void { $this->updateSalePaidAmount($payment); }
    public function updated(Payment $payment): void { $this->updateSalePaidAmount($payment); }
    public function deleted(Payment $payment): void { $this->updateSalePaidAmount($payment); }
    public function restored(Payment $payment): void { $this->updateSalePaidAmount($payment); }
}