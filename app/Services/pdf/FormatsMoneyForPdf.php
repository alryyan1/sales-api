<?php

namespace App\Services\Pdf;

use App\Services\SettingsService;

/**
 * Shared money formatting for PDF services — keeps decimal-place display in sync with
 * the "currency_code" app setting (SDG=0, OMR=3, USD=2), matching CURRENCY_DECIMALS in
 * sales-ui's src/constants.ts. `use` this trait in any PDF controller/service regardless
 * of what it already extends.
 */
trait FormatsMoneyForPdf
{
    private ?int $pdfMoneyDecimals = null;

    /**
     * Decimal places to use for money amounts, resolved once per instance from the
     * currency_code setting (SettingsService::getAll() is itself cached, so this is cheap).
     */
    protected function moneyDecimals(): int
    {
        if ($this->pdfMoneyDecimals === null) {
            $code = (new SettingsService())->getAll()['currency_code'] ?? 'SDG';
            $this->pdfMoneyDecimals = match ($code) {
                'OMR' => 3,
                'USD' => 2,
                default => 0,
            };
        }

        return $this->pdfMoneyDecimals;
    }

    /**
     * Formats a money amount using the app's configured currency decimal places.
     * Pass $decimals explicitly only when a specific cell must diverge from that (rare).
     */
    protected function formatMoney(float|int|string|null $value, ?int $decimals = null): string
    {
        return number_format((float) ($value ?? 0), $decimals ?? $this->moneyDecimals());
    }
}
