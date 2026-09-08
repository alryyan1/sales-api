<?php

namespace App\Observers;

use App\Models\Sale;
use App\Services\RealtimeNotifier;

/**
 * Sale *creation* is pushed via the SaleCreated event/listener instead (see
 * App\Listeners\PushSaleCreatedToRealtimeServer) — that fires once items/discount/payments
 * are fully assembled, whereas this model's "created" event fires the instant the bare sale
 * row is inserted (before its items exist). Deletion has no such timing concern: the sale is
 * already fully formed, so hooking Eloquent's "deleted" event directly here is fine.
 */
class SaleObserver
{
    public function deleted(Sale $sale): void
    {
        app(RealtimeNotifier::class)->notify('sale.deleted', [
            'id' => $sale->id,
            'shift_id' => $sale->shift_id,
        ]);
    }
}
