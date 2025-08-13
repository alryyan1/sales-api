<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Auth;
use App\Services\SettingsService;

class WhatsAppController extends Controller
{
    use AuthorizesRequests;

    /**
     * Send a text message via WhatsApp API
     */
    public function sendMessage(Request $request)
    {
        $this->checkAuthorization('send-whatsapp-messages');

        $request->validate([
            'number' => 'nullable|string',
            'phoneNumber' => 'nullable|string',
            'chatId' => 'nullable|string',
            'text' => 'nullable|string|max:4096',
            'message' => 'nullable|string|max:4096',
        ]);

        try {
            $settings = (new SettingsService())->getAll();
            
            if (!$settings['whatsapp_enabled']) {
                return response()->json([
                    'success' => false,
                    'message' => 'WhatsApp integration is not enabled'
                ], 400);
            }

            $apiUrl = rtrim($settings['whatsapp_api_url'], '/');
            $apiToken = $settings['whatsapp_api_token'];
            $instanceId = $settings['whatsapp_instance_id'];

            if (empty($apiToken) || empty($instanceId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'WhatsApp API configuration is incomplete'
                ], 400);
            }

            $endpoint = "{$apiUrl}/send";

            // Determine number and message fields from various inputs for backward compatibility
            $rawNumber = $request->input('number')
                ?? $request->input('phoneNumber')
                ?? ($request->input('chatId') ? str_replace('@c.us', '', $request->input('chatId')) : null);
            $number = $this->formatPhoneNumber((string) $rawNumber);
            $message = $request->input('message') ?? $request->input('text');

            if (empty($number) || empty($message)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing number or message'
                ], 422);
            }

            $payload = [
                'number' => $number,
                'type' => 'text',
                'message' => $message,
                'instance_id' => $instanceId,
                'access_token' => $apiToken,
            ];

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post($endpoint, $payload);

            if ($response->successful()) {
                $data = $response->json();
                

                
                Log::info('WhatsApp message sent successfully', [
                    'number' => $number,
                    'response' => $data
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Message sent successfully',
                    'data' => $data['data']['data'] ?? $data['data'] ?? $data
                ]);
            } else {
                Log::error('WhatsApp API error', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                    'number' => $number
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send WhatsApp message',
                    'error' => $response->json()
                ], $response->status());
            }

        } catch (\Exception $e) {
            Log::error('WhatsApp send message exception', [
                'error' => $e->getMessage(),
                'chatId' => $request->chatId ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error while sending WhatsApp message',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test WhatsApp API connection
     */
    public function test(Request $request)
    {
        $this->checkAuthorization('send-whatsapp-messages');

        $request->validate([
            'phoneNumber' => 'required|string',
            'message' => 'nullable|string|max:4096',
        ]);

        try {
            $settings = (new SettingsService())->getAll();
            
            if (!$settings['whatsapp_enabled']) {
                return response()->json([
                    'success' => false,
                    'message' => 'WhatsApp integration is not enabled'
                ], 400);
            }

            $apiUrl = rtrim($settings['whatsapp_api_url'], '/');
            $apiToken = $settings['whatsapp_api_token'];
            $instanceId = $settings['whatsapp_instance_id'];

            if (empty($apiToken) || empty($instanceId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'WhatsApp API configuration is incomplete'
                ], 400);
            }

            // Format phone number for WhatsApp API
            $phoneNumber = $this->formatPhoneNumber($request->phoneNumber);
            $testMessage = $request->message ?? 'This is a test message from your sales system. If you receive this, WhatsApp integration is working correctly!';

            $endpoint = "{$apiUrl}/send";
            $payload = [
                'number' => $phoneNumber,
                'type' => 'text',
                'message' => $testMessage,
                'instance_id' => $instanceId,
                'access_token' => $apiToken,
            ];

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post($endpoint, $payload);

            if ($response->successful()) {
                $data = $response->json();
                

                
                Log::info('WhatsApp test message sent successfully', [
                    'phoneNumber' => $request->phoneNumber,
                    'number' => $phoneNumber,
                    'response' => $data
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Test message sent successfully',
                    'data' => $data['data']['data'] ?? $data['data'] ?? $data
                ]);
            } else {
                Log::error('WhatsApp test API error', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                    'phoneNumber' => $request->phoneNumber
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send test message',
                    'error' => $response->json()
                ], $response->status());
            }

        } catch (\Exception $e) {
            Log::error('WhatsApp test exception', [
                'error' => $e->getMessage(),
                'phoneNumber' => $request->phoneNumber ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error while testing WhatsApp connection',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get WhatsApp instance status
     */
    public function getStatus()
    {
        $this->checkAuthorization('view-whatsapp-status');

        try {
            $settings = (new SettingsService())->getAll();
            
            if (!$settings['whatsapp_enabled']) {
                return response()->json([
                    'success' => false,
                    'message' => 'WhatsApp integration is not enabled'
                ], 400);
            }

            $apiUrl = rtrim($settings['whatsapp_api_url'], '/');
            $apiToken = $settings['whatsapp_api_token'];
            $instanceId = $settings['whatsapp_instance_id'];

            if (empty($apiToken) || empty($instanceId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'WhatsApp API configuration is incomplete'
                ], 400);
            }

            // Use get_groups as a proxy for status/connectivity
            $endpoint = "{$apiUrl}/get_groups?instance_id={$instanceId}&access_token={$apiToken}";
            $response = Http::get($endpoint);

            if ($response->successful()) {
                $data = $response->json();
                
                return response()->json([
                    'success' => true,
                    'data' => $data
                ]);
            } else {
                Log::error('WhatsApp status API error', [
                    'status' => $response->status(),
                    'response' => $response->json()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to get WhatsApp instance status',
                    'error' => $response->json()
                ], $response->status());
            }

        } catch (\Exception $e) {
            Log::error('WhatsApp status exception', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error while getting WhatsApp status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send sale notification to customer
     */
    public function sendSaleNotification(Request $request)
    {
        $this->checkAuthorization('send-whatsapp-messages');

        $request->validate([
            'phoneNumber' => 'required|string',
            'message' => 'required|string|max:4096',
        ]);

        try {
            $settings = (new SettingsService())->getAll();
            
            if (!$settings['whatsapp_enabled']) {
                return response()->json([
                    'success' => false,
                    'message' => 'WhatsApp integration is not enabled'
                ], 400);
            }

            $apiUrl = rtrim($settings['whatsapp_api_url'], '/');
            $apiToken = $settings['whatsapp_api_token'];
            $instanceId = $settings['whatsapp_instance_id'];

            if (empty($apiToken) || empty($instanceId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'WhatsApp API configuration is incomplete'
                ], 400);
            }

            // Format phone number for WhatsApp API
            $phoneNumber = $this->formatPhoneNumber($request->phoneNumber);
            $endpoint = "{$apiUrl}/send";
            $payload = [
                'number' => $phoneNumber,
                'type' => 'text',
                'message' => $request->message,
                'instance_id' => $instanceId,
                'access_token' => $apiToken,
            ];

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post($endpoint, $payload);

            if ($response->successful()) {
                $data = $response->json();
                
                Log::info('WhatsApp sale notification sent successfully', [
                    'phoneNumber' => $request->phoneNumber,
                    'number' => $phoneNumber,
                    'response' => $data
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Sale notification sent successfully',
                    'data' => $data['data']['data'] ?? $data['data'] ?? $data
                ]);
            } else {
                Log::error('WhatsApp sale notification API error', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                    'phoneNumber' => $request->phoneNumber
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send sale notification',
                    'error' => $response->json()
                ], $response->status());
            }

        } catch (\Exception $e) {
            Log::error('WhatsApp sale notification exception', [
                'error' => $e->getMessage(),
                'phoneNumber' => $request->phoneNumber ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error while sending sale notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Format phone number for WhatsApp API
     */
    private function formatPhoneNumber($phoneNumber)
    {
        // Remove all non-numeric characters (including +)
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // Return only the numeric part (no + prefix)
        return $phoneNumber;
    }

    /**
     * Helper to authorize based on permission string
     */
    private function checkAuthorization(string $permission): void
    {
        if (Auth::user() && !Auth::user()->can($permission)) {
            abort(403, 'This action is unauthorized.');
        } elseif (!Auth::user()) {
            abort(401, 'Unauthenticated.');
        }
    }
} 