<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AirtelSmsService
{
    protected string $apiKey;
    protected string $sender;
    protected string $endpoint;

    public function __construct()
    {
        $this->apiKey   = config('services.airtel_sms.api_key', '');
        $this->sender   = config('services.airtel_sms.sender', 'INFO');
        $this->endpoint = config('services.airtel_sms.endpoint', 'https://www.airtel.sd/api/rest_send_sms/');
    }

    /**
     * Send an SMS to a single number.
     *
     * @param  string  $to       International format, e.g. 249912345678
     * @param  string  $message  Message text
     * @param  string|null $msgId  Optional tracking ID (digits only)
     * @return array{success: bool, status: string, apiMsgId: int|null, error: string|null}
     */
    public function send(string $to, string $message, ?string $msgId = null): array
    {
        return $this->sendBatch([['to' => $to, 'message' => $message, 'MSGID' => $msgId]])[0] ?? [
            'success' => false,
            'status'  => 'failed',
            'apiMsgId' => null,
            'error'   => 'Empty batch result',
        ];
    }

    /**
     * Send the same message to multiple numbers.
     *
     * @param  string[]  $numbers  Array of phone numbers
     * @param  string    $message  Message text
     * @return array  Raw API response
     */
    public function sendToMany(array $numbers, string $message): array
    {
        $messages = array_map(fn($n, $i) => [
            'to'      => $this->normalizeNumber($n),
            'message' => $message,
            'MSGID'   => (string)(time() + $i),
        ], $numbers, array_keys($numbers));

        return $this->sendBatch($messages);
    }

    /**
     * Low-level batch send.
     *
     * @param  array  $messages  Array of ['to', 'message', 'MSGID'?] items
     * @return array  Normalized results per message
     */
    public function sendBatch(array $messages): array
    {
        if (empty($this->apiKey)) {
            Log::warning('AirtelSmsService: API key not configured.');
            return [['success' => false, 'status' => 'failed', 'apiMsgId' => null, 'error' => 'SMS API key not configured']];
        }

        // Normalize numbers
        foreach ($messages as &$msg) {
            $msg['to'] = $this->normalizeNumber($msg['to'] ?? '');
            if (isset($msg['MSGID']) && $msg['MSGID'] === null) {
                unset($msg['MSGID']);
            }
        }
        unset($msg);

        try {
            $response = Http::timeout(15)
                ->withHeaders(['X-API-KEY' => $this->apiKey])
                ->post($this->endpoint, [
                    'sender'   => $this->sender,
                    'messages' => $messages,
                ]);

            $body = $response->json();
            Log::info('AirtelSmsService: response', ['status' => $response->status(), 'body' => $body]);

            $results = $body['results'] ?? [];

            return array_map(function ($r) {
                return [
                    'success'  => ($r['status'] ?? '') === 'sent',
                    'status'   => $r['status'] ?? 'unknown',
                    'apiMsgId' => $r['apiMsgId'] ?? null,
                    'to'       => $r['to'] ?? null,
                    'error'    => $r['reason'] ?? null,
                ];
            }, $results);
        } catch (\Throwable $e) {
            Log::error('AirtelSmsService: HTTP error', ['error' => $e->getMessage()]);
            return [['success' => false, 'status' => 'failed', 'apiMsgId' => null, 'error' => $e->getMessage()]];
        }
    }

    /**
     * Get remaining SMS balance.
     */
    public function getBalance(): int|null
    {
        try {
            $balanceEndpoint = str_replace('rest_send_sms', 'rest_get_balance', $this->endpoint);
            $response = Http::timeout(10)
                ->withHeaders(['X-API-KEY' => $this->apiKey])
                ->post($balanceEndpoint, []);

            return $response->json('balance') ?? null;
        } catch (\Throwable $e) {
            Log::error('AirtelSmsService: getBalance error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Normalize a Sudanese phone number to international format (249XXXXXXXXX).
     */
    protected function normalizeNumber(string $number): string
    {
        $number = preg_replace('/\D/', '', $number); // strip non-digits

        if (str_starts_with($number, '249') && strlen($number) === 12) {
            return $number; // already correct
        }
        if (str_starts_with($number, '0') && strlen($number) === 10) {
            return '249' . ltrim($number, '0');
        }
        if (strlen($number) === 9) {
            return '249' . $number;
        }

        return $number; // return as-is and let API validate
    }
}
