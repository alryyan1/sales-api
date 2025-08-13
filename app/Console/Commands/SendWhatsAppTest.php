<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Services\SettingsService;

class SendWhatsAppTest extends Command
{
    protected $signature = 'whatsapp:send-test {number} {--message=Test message from Sales System}';

    protected $description = 'Send a test WhatsApp text message via WaClient API to the given number';

    public function handle(): int
    {
        $number = preg_replace('/[^0-9]/', '', (string) $this->argument('number'));
        $message = (string) $this->option('message');

        if (!$number) {
            $this->error('Invalid number');
            return 1;
        }

        $settings = app(SettingsService::class)->getAll();
        $apiUrl = rtrim($settings['whatsapp_api_url'] ?? 'https://waclient.com/api', '/');
        $apiToken = $settings['whatsapp_api_token'] ?? null;
        $instanceId = $settings['whatsapp_instance_id'] ?? null;

        if (!$apiToken || !$instanceId) {
            $this->error('Missing API token or instance id');
            return 1;
        }

        $endpoint = "$apiUrl/send";
        $payload = [
            'number' => $number,
            'type' => 'text',
            'message' => $message,
            'instance_id' => $instanceId,
            'access_token' => $apiToken,
        ];

        $this->info('Sending request to: ' . $endpoint);
        $this->line('Payload: ' . json_encode($payload));

        try {
            $response = Http::asJson()->post($endpoint, $payload);
            $this->line('Status: ' . $response->status());
            $this->line('Response: ' . $response->body());
            return $response->successful() ? 0 : 1;
        } catch (\Throwable $e) {
            $this->error('Exception: ' . $e->getMessage());
            return 1;
        }
    }
}


