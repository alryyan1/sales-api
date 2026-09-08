<?php

namespace App\Listeners;

use App\Events\SaleCreated;
use App\Http\Resources\SaleResource;
use App\Services\RealtimeNotifier;

/**
 * Pushes a newly created sale to the realtime relay so it appears in every cashier's
 * shift sales list live. Hooks the SaleCreated *event* (fired by the controller once the
 * sale's items/discount/payments are fully assembled and reloaded) rather than the Sale
 * model's "created" Eloquent event, which fires the instant the bare sale header row is
 * inserted — before its items exist — and would otherwise push an item-less sale.
 */
class PushSaleCreatedToRealtimeServer
{
    public function handle(SaleCreated $event): void
    {
        app(RealtimeNotifier::class)->notify('sale.created', [
            'shift_id' => $event->sale->shift_id,
            'sale' => (new SaleResource($event->sale))->resolve(),
        ]);
    }
}
