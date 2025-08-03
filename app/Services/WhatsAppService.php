<?php

namespace App\Services;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config; // To access config values
use App\Models\Product;
use Illuminate\Support\ServiceProvider;
class WhatsAppService extends ServiceProvider
{
    protected GuzzleClient $client;
    protected ?string $apiUrl;
    protected ?string $apiToken;
    protected bool $isEnabled;

    public function __construct()
    {
        $this->client = new GuzzleClient([
            'timeout' => 10.0, // Set a timeout for requests
        ]);
        $this->isEnabled = Config::get('whatsapp.enabled', false);
        $this->apiUrl = Config::get('whatsapp.api_url');
        $this->apiToken = Config::get('whatsapp.api_token');
    }

    /**
     * Send a WhatsApp message.
     *
     * @param string $chatId The recipient's WhatsApp ID (e.g., "249991961111@c.us")
     * @param string $message The message content.
     * @return bool True on success, false on failure.
     */
    public function sendMessage(string $chatId, string $message): bool
    {
        if (!$this->isEnabled) {
            Log::info("WhatsAppService: Sending disabled. Message not sent to {$chatId}: {$message}");
            return true; // Or false if you want to indicate a "failure" due to being disabled
        }

        if (empty($this->apiUrl) || empty($this->apiToken)) {
            Log::error("WhatsAppService: API URL or Token is not configured.");
            return false;
        }

        if (empty($chatId)) {
            Log::error("WhatsAppService: Chat ID is empty. Cannot send message.");
            return false;
        }

        try {
            Log::info("WhatsAppService: Attempting to send message to {$chatId}");
            $response = $this->client->request('POST', $this->apiUrl, [
                'json' => [ // Use 'json' for Guzzle to automatically set Content-Type and encode body
                    'chatId' => $chatId,
                    'message' => $message,
                ],
                'headers' => [
                    'accept' => 'application/json',
                    'authorization' => 'Bearer '.$this->apiToken,
                    'content-type' => 'application/json', // Guzzle sets this with 'json' option
                ],
                'http_errors' => true, // Ensure Guzzle throws exceptions for 4xx/5xx responses
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = json_decode($response->getBody()->getContents(), true);

            if ($statusCode >= 200 && $statusCode < 300) {
                // Check for success indicators in the response body if the API provides them
                // Example: if (isset($responseBody['status']) && $responseBody['status'] === 'success')
                Log::info("WhatsAppService: Message sent successfully to {$chatId}. Status: {$statusCode}", ['response' => $responseBody]);
                return true;
            } else {
                Log::error("WhatsAppService: Failed to send message to {$chatId}. Status: {$statusCode}", ['response' => $responseBody]);
                return false;
            }
        } catch (RequestException $e) {
            $errorMessage = $e->getMessage();
            if ($e->hasResponse()) {
                $responseBody = $e->getResponse()->getBody()->getContents();
                $errorMessage .= " | Response: " . $responseBody;
            }
            Log::error("WhatsAppService: RequestException sending message to {$chatId}: {$errorMessage}");
            return false;
        } catch (\Exception $e) {
            Log::error("WhatsAppService: Generic Exception sending message to {$chatId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a low stock alert notification to the admin/configured number.
     * @param Product $product The product that is low on stock.
     * @return bool
     */
    public function sendLowStockAlert(Product $product): bool
    {
        $notificationNumber = Config::get('whatsapp.notification_number');
        if (!$notificationNumber) {
            Log::warning("WhatsAppService: WHATSAPP_NOTIFICATION_NUMBER is not set. Cannot send low stock alert.");
            return false;
        }

        $sku = $product->sku ?? 'N/A';
        $message = "⚠️ Low Stock Alert! ⚠️\n\n";
        $message .= "Product: {$product->name} (SKU: {$sku})\n";
        $message .= "Current Stock: {$product->stock_quantity}\n";
        $message .= "Alert Level: {$product->stock_alert_level}\n\n";
        $message .= "Please reorder soon.\n";
        $message .= "System: " . Config::get('app.name');

        return $this->sendMessage($notificationNumber, $message);
    }
}