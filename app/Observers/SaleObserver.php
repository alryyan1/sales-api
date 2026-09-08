<?php

namespace App\Observers;

use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Services\RealtimeNotifier;

class SaleObserver
{
    // Pushes every newly created sale to the realtime relay so it appears in every
    // cashier's shift sales list live, without a page reload.
    public function created(Sale $sale): void
    {
        $sale->load([
            'client:id,name',
            'user:id,name',
            'warehouse:id,name',
            'items.product:id,name,sku,scientific_name,image_url,is_service',
            'payments.user:id,name,username',
            'returns:id,sale_id',
            'returns.items:id,sale_return_id,product_id,quantity,price',
        ]);

        app(RealtimeNotifier::class)->notify('sale.created', [
            'shift_id' => $sale->shift_id,
            'sale' => (new SaleResource($sale))->resolve(),
        ]);
    }
}
