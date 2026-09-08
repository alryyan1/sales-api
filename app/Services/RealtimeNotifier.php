<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort push to the standalone Node/Socket.IO relay server (see
 * sales-api/realtime-server). Never throws — a down or unconfigured relay must
 * not break the sale/payment request that triggered the notification.
 */
class RealtimeNotifier
{
    public function notify(string $event, array $payload): void
    {
        $url = config('services.realtime.url');
        if (empty($url)) {
            return;
        }

        try {
            Http::timeout(1)
                ->withHeaders(['X-Internal-Secret' => (string) config('services.realtime.secret')])
                ->post(rtrim($url, '/').'/notify', [
                    'event' => $event,
                    'payload' => $payload,
                ]);
        } catch (\Throwable $e) {
            Log::debug('RealtimeNotifier: failed to reach realtime server', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
