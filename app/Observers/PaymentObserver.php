<?php // app/Observers/PaymentObserver.php
namespace App\Observers;
use App\Models\Payment;
use App\Models\Sale;

class PaymentObserver
{
    // paid_amount column was removed from sales table; totals are now computed from payments on the fly.
    // Observer kept for future use if needed, but currently does nothing.
    public function created(Payment $payment): void {}
    public function updated(Payment $payment): void {}
    public function deleted(Payment $payment): void {}
    public function restored(Payment $payment): void {}
}