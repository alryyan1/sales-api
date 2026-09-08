<?php

namespace App\Observers;

use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\RealtimeNotifier;

/**
 * The actual POS flow (PosBlankPage.tsx) creates a sale empty (see createEmptySale())
 * and adds items to it one at a time afterward (addSaleItem/updateSaleItem/deleteSaleItem/
 * addMultipleSaleItems) — none of those go through the SaleCreated event. Without this,
 * other cashiers watching the shift would see a new invoice appear with 0 items and never
 * update as items get added. Push the sale's fresh state on every item add/update/delete.
 */
class SaleItemObserver
{
    public function created(SaleItem $item): void
    {
        $this->notifySaleUpdated($item);
    }

    public function updated(SaleItem $item): void
    {
        $this->notifySaleUpdated($item);
    }

    public function deleted(SaleItem $item): void
    {
        $this->notifySaleUpdated($item);
    }

    private function notifySaleUpdated(SaleItem $item): void
    {
        $sale = Sale::with([
            'client:id,name',
            'user:id,name',
            'warehouse:id,name',
            'items.product:id,name,sku,scientific_name,image_url,is_service',
            'payments.user:id,name,username',
            'returns:id,sale_id',
            'returns.items:id,sale_return_id,product_id,quantity,price',
        ])->find($item->sale_id);

        if (! $sale) {
            return;
        }

        app(RealtimeNotifier::class)->notify('sale.updated', [
            'sale' => (new SaleResource($sale))->resolve(),
        ]);
    }
}
