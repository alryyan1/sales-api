<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Writes a sale as a ready-to-import journal entry document into Firestore,
 * under finance/{collection}/journal_entries/{docId} — the same generic path
 * finance-api's "Firebase" import button already reads from. sales-api never
 * calls finance-api directly; Firestore is the only point of contact.
 *
 * Each account line carries a semantic account_role (e.g. "sales_revenue")
 * instead of any finance-api-specific account id or code — finance-api
 * resolves the role to a real account via its own Settings, so this service
 * never needs to know finance-api's chart of accounts. The customer is
 * likewise referenced only by an opaque "sales-api_client_{id}" party_id,
 * resolved (and auto-created on first sight) on finance-api's side.
 */
class FinanceBridgeService
{
    private const BASE_URL = 'https://firestore.googleapis.com/v1';

    /** Payment methods (see payments.method enum) that count as "paid in cash". */
    private const CASH_METHODS = ['cash'];

    /** Payment methods that count as "paid electronically" (Bankak, Fawry, O-Cash — all settle to a bank account). */
    private const BANK_METHODS = ['bankak', 'fawry', 'ocash'];

    /**
     * Builds the journal entry payload for a sale and writes it to Firestore,
     * plus one additional entry per payment when the sale itself was booked
     * to receivable (see debitRole()) — each payment entry moves its amount
     * from receivable into cash/bank, so partial or later payments are
     * reflected instead of silently sitting unrecorded against the customer.
     * Idempotent by design — every doc id is derived from a stable sale/payment
     * id, so calling this again overwrites rather than duplicates.
     */
    public function exportSale(Sale $sale): void
    {
        $sale->loadMissing(['client', 'items', 'payments']);

        $revenueAmount = $sale->calculated_total_amount;
        $costAmount = $sale->calculated_cost_amount;

        if ($revenueAmount <= 0) {
            throw new \RuntimeException('لا يمكن تصدير فاتورة بدون قيمة.');
        }

        $debitRole = $this->debitRole($sale);

        $lines = [
            [
                'account_role' => $debitRole,
                'party_id' => $sale->client_id ? "sales-api_client_{$sale->client_id}" : null,
                'party_name' => $sale->client?->name,
                'party_phone' => $sale->client?->phone,
                'party_email' => $sale->client?->email,
                'description' => "ذمم/نقدية — فاتورة رقم {$sale->id}",
                'debit' => $revenueAmount,
                'credit' => 0,
            ],
            [
                'account_role' => 'sales_revenue',
                'party_id' => null,
                'description' => "إيراد فاتورة رقم {$sale->id}",
                'debit' => 0,
                'credit' => $revenueAmount,
            ],
        ];

        if ($costAmount > 0) {
            $lines[] = [
                'account_role' => 'sales_cogs',
                'party_id' => null,
                'description' => "تكلفة فاتورة رقم {$sale->id}",
                'debit' => $costAmount,
                'credit' => 0,
            ];
            $lines[] = [
                'account_role' => 'sales_inventory',
                'party_id' => null,
                'description' => "مخزون فاتورة رقم {$sale->id}",
                'debit' => 0,
                'credit' => $costAmount,
            ];
        }

        $this->putDocument("sale_{$sale->id}", [
            'date' => $sale->sale_date->toDateString(),
            'reference' => (string) $sale->id,
            'description' => "قيد مبيعات — فاتورة رقم {$sale->id}",
            'createdAt' => now()->toIso8601String(),
            'lines' => $lines,
        ]);

        // The sale itself already booked the full amount straight to cash/bank
        // when debitRole() picked one of those — recording payments too would
        // double-count. Only when the sale went to receivable do payments need
        // their own entries, to move their amount out of it.
        if ($debitRole === 'sales_receivable') {
            foreach ($sale->payments as $payment) {
                $this->exportPayment($sale, $payment);
            }
        }
    }

    /**
     * Records money actually received against a sale that was booked to
     * receivable: debits the payment's own cash/bank account, credits
     * receivable for the same amount, so the customer's outstanding balance
     * reflects what's really still owed.
     */
    private function exportPayment(Sale $sale, Payment $payment): void
    {
        $amount = (float) $payment->amount;
        if ($amount <= 0) {
            return;
        }

        $this->putDocument("sale_{$sale->id}_payment_{$payment->id}", [
            'date' => $payment->payment_date->toDateString(),
            'reference' => (string) $sale->id,
            'description' => "دفعة على فاتورة رقم {$sale->id}",
            'createdAt' => now()->toIso8601String(),
            'lines' => [
                [
                    'account_role' => $this->roleForMethod($payment->method),
                    'party_id' => null,
                    'description' => "استلام دفعة — فاتورة رقم {$sale->id}",
                    'debit' => $amount,
                    'credit' => 0,
                ],
                [
                    'account_role' => 'sales_receivable',
                    'party_id' => $sale->client_id ? "sales-api_client_{$sale->client_id}" : null,
                    'party_name' => $sale->client?->name,
                    'party_phone' => $sale->client?->phone,
                    'party_email' => $sale->client?->email,
                    'description' => "تخفيض ذمم — فاتورة رقم {$sale->id}",
                    'debit' => 0,
                    'credit' => $amount,
                ],
            ],
        ]);
    }

    /**
     * "sales_cash"/"sales_bank" when the sale is fully paid through a single
     * group of payment methods; "sales_receivable" otherwise (still has a due
     * balance, has no payments yet, or was paid through a mix of methods) —
     * the safe default that books the full amount to the customer's account.
     */
    private function debitRole(Sale $sale): string
    {
        if ($sale->calculated_due_amount > 0.0) {
            return 'sales_receivable';
        }

        $methods = $sale->payments->pluck('method')->unique();
        if ($methods->count() !== 1) {
            return 'sales_receivable';
        }

        return $this->roleForMethod($methods->first());
    }

    /** Maps a payments.method value to the account role that should receive it. */
    private function roleForMethod(?string $method): string
    {
        if (in_array($method, self::CASH_METHODS, true)) {
            return 'sales_cash';
        }

        if (in_array($method, self::BANK_METHODS, true)) {
            return 'sales_bank';
        }

        return 'sales_receivable';
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function putDocument(string $docId, array $fields): void
    {
        $token = FirebaseService::getAccessToken();
        if (! $token) {
            throw new \RuntimeException('تعذّر الاتصال بـ Firebase — تحقق من إعدادات الاعتماد.');
        }

        $projectId = config('firebase.project_id');
        $collection = config('firebase.finance_bridge_collection');
        $url = self::BASE_URL."/projects/{$projectId}/databases/(default)/documents/finance/{$collection}/journal_entries/{$docId}";

        $response = Http::withToken($token)->patch($url, ['fields' => $this->encodeFields($fields)]);

        if (! $response->successful()) {
            Log::error('FinanceBridgeService: failed to write journal entry to Firestore', [
                'doc_id' => $docId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('فشل إرسال القيد إلى النظام المالي.');
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, array<string, mixed>>
     */
    private function encodeFields(array $fields): array
    {
        return array_map(fn (mixed $value) => $this->encodeValue($value), $fields);
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeValue(mixed $value): array
    {
        return match (true) {
            is_null($value) => ['nullValue' => null],
            is_bool($value) => ['booleanValue' => $value],
            is_int($value) => ['integerValue' => (string) $value],
            is_float($value) => ['doubleValue' => $value],
            is_array($value) && array_is_list($value) => [
                'arrayValue' => ['values' => array_map(fn (mixed $v) => $this->encodeValue($v), $value)],
            ],
            is_array($value) => ['mapValue' => ['fields' => $this->encodeFields($value)]],
            default => ['stringValue' => (string) $value],
        };
    }
}
