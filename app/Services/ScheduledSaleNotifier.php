<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\ScheduledSale;
use Illuminate\Support\Facades\Log;

/**
 * Sends the WhatsApp invoice notification for a scheduled sale that has already
 * been executed (i.e. `scheduled_sale.sale_id` is set). Sends the same template
 * message to the customer's phone (from the Client record) and to the owner's
 * configured phone (`scheduled_invoice_owner_phone` setting). Never called from
 * inside a DB transaction — this only performs network I/O.
 */
class ScheduledSaleNotifier
{
    public function __construct(
        private readonly WhatsAppCloudApiService $whatsapp,
        private readonly SettingsService $settingsService,
    ) {}

    public function sendInvoice(ScheduledSale $scheduledSale): void
    {
        $scheduledSale->loadMissing(['sale.client', 'sale.items.product']);
        $sale = $scheduledSale->sale;

        if (! $sale) {
            Log::error('ScheduledSaleNotifier: sendInvoice called without a linked sale.', [
                'scheduled_sale_id' => $scheduledSale->id,
            ]);

            return;
        }

        $settings = $this->settingsService->getAll();
        $templateName = $settings['scheduled_invoice_template_name'] ?? '';
        $languageCode = $settings['scheduled_invoice_template_lang'] ?? 'ar';
        $ownerPhone = $settings['scheduled_invoice_owner_phone'] ?? '';

        if (empty($templateName)) {
            $scheduledSale->update([
                'whatsapp_customer_status' => 'skipped_no_template',
                'whatsapp_owner_status' => 'skipped_no_template',
            ]);

            return;
        }

        $components = $this->buildTemplateComponents($sale);

        $this->sendLeg($scheduledSale, 'customer', $sale->client?->phone, $templateName, $languageCode, $components);
        $this->sendLeg($scheduledSale, 'owner', $ownerPhone, $templateName, $languageCode, $components);
    }

    /**
     * Builds the WhatsApp template's `components` payload. This is the one method
     * that needs editing once the real approved template's parameters are known —
     * everything else in the send pipeline is unaffected by that future change.
     */
    private function buildTemplateComponents(Sale $sale): array
    {
        // Order matches the {{1}}..{{5}} placeholders in the Meta-approved
        // template body, in the order they read top-to-bottom: customer name,
        // invoice number, sale date, total amount, due amount.
        return [
            [
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $sale->client?->name ?? 'عميل'],
                    ['type' => 'text', 'text' => (string) ($sale->number ?? $sale->id)],
                    ['type' => 'text', 'text' => $sale->sale_date?->format('Y-m-d') ?? now()->format('Y-m-d')],
                    ['type' => 'text', 'text' => number_format((float) $sale->calculated_total_amount, 2)],
                    ['type' => 'text', 'text' => number_format((float) $sale->calculated_due_amount, 2)],
                ],
            ],
            [
                // Matches the single QUICK_REPLY button on the approved template.
                // The button's visible text is fixed in the template itself — only
                // the payload is set per-send, and it's what our webhook
                // (WhatsAppCloudApiController::handleInvoiceDownloadRequest) reads
                // to know which invoice PDF to generate and send back.
                'type' => 'button',
                'sub_type' => 'quick_reply',
                'index' => '0',
                'parameters' => [
                    ['type' => 'payload', 'payload' => "DOWNLOAD_INVOICE|sale_id:{$sale->id}"],
                ],
            ],
        ];
    }

    private function sendLeg(
        ScheduledSale $scheduledSale,
        string $leg,
        ?string $phone,
        string $templateName,
        string $languageCode,
        array $components
    ): void {
        $statusKey = "whatsapp_{$leg}_status";
        $errorKey = "whatsapp_{$leg}_error";
        $messageIdKey = "whatsapp_{$leg}_message_id";
        $sentAtKey = "whatsapp_{$leg}_sent_at";

        if (empty(trim((string) $phone))) {
            $scheduledSale->update([$statusKey => 'skipped_no_phone']);

            return;
        }

        $to = WhatsAppCloudApiService::formatPhoneNumber($phone);

        try {
            $result = $this->whatsapp->sendTemplateMessage($to, $templateName, $languageCode, $components);

            if ($result['success']) {
                $scheduledSale->update([
                    $statusKey => 'sent',
                    $errorKey => null,
                    $messageIdKey => $result['message_id'] ?? null,
                    $sentAtKey => now(),
                ]);
            } else {
                $scheduledSale->update([
                    $statusKey => 'failed',
                    $errorKey => $result['error'] ?? 'Unknown error',
                ]);
                Log::error("ScheduledSaleNotifier: failed to send {$leg} leg for scheduled sale {$scheduledSale->id}: ".($result['error'] ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            $scheduledSale->update([
                $statusKey => 'failed',
                $errorKey => $e->getMessage(),
            ]);
            Log::error("ScheduledSaleNotifier: exception sending {$leg} leg for scheduled sale {$scheduledSale->id}: ".$e->getMessage());
        }
    }
}
