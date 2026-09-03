<?php

namespace App\Services;

use App\Models\ScheduledSale;
use App\Models\Shift;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Executes a single due scheduled sale: creates the real Sale (fully unpaid, using
 * the last shift found), then sends the WhatsApp invoice notification. The caller
 * (the artisan command) is responsible for atomically claiming the row (flipping it
 * to 'processing') before calling execute() — this class assumes that's already done.
 */
class ScheduledSaleExecutor
{
    public function __construct(
        private readonly SaleCreationService $saleCreationService,
        private readonly ScheduledSaleNotifier $notifier,
    ) {}

    public function execute(ScheduledSale $scheduledSale): void
    {
        $scheduledSale->loadMissing('items');

        $shift = Shift::orderBy('id', 'desc')->first();

        try {
            $sale = $this->saleCreationService->createSaleWithItems(
                [
                    'client_id' => $scheduledSale->client_id,
                    'user_id' => $scheduledSale->user_id,
                    'warehouse_id' => $scheduledSale->warehouse_id,
                    'shift_id' => $shift?->id,
                    'sale_date' => now(),
                    'source' => 'scheduled',
                    'discount_amount' => $scheduledSale->discount_amount,
                    'discount_type' => $scheduledSale->discount_type,
                ],
                $scheduledSale->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                ])->all()
            );
        } catch (ValidationException $e) {
            $this->fail($scheduledSale, collect($e->errors())->flatten()->implode(' '));

            return;
        } catch (\Throwable $e) {
            Log::error("ScheduledSaleExecutor: failed to create sale for scheduled sale {$scheduledSale->id}: ".$e->getMessage());
            $this->fail($scheduledSale, $e->getMessage());

            return;
        }

        $scheduledSale->update(['sale_id' => $sale->id]);

        $this->notifier->sendInvoice($scheduledSale);

        $scheduledSale->refresh();

        // Only the customer-facing leg can fail the row — the owner copy is an
        // internal courtesy copy, not the deliverable.
        if ($scheduledSale->whatsapp_customer_status === 'failed') {
            $scheduledSale->update([
                'status' => ScheduledSale::STATUS_FAILED,
                'error_message' => $scheduledSale->whatsapp_customer_error,
                'executed_at' => now(),
            ]);

            return;
        }

        $scheduledSale->update([
            'status' => ScheduledSale::STATUS_COMPLETED,
            'error_message' => null,
            'executed_at' => now(),
        ]);
    }

    private function fail(ScheduledSale $scheduledSale, string $message): void
    {
        $scheduledSale->update([
            'status' => ScheduledSale::STATUS_FAILED,
            'error_message' => $message,
            'executed_at' => now(),
        ]);
    }
}
