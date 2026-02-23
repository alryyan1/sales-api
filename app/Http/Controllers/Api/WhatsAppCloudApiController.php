<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatsAppCloudApiService;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class WhatsAppCloudApiController extends Controller
{
    protected WhatsAppCloudApiService $whatsappService;

    public function __construct(WhatsAppCloudApiService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    /**
     * Send a text message via WhatsApp Cloud API.
     */
    public function sendTextMessage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'to' => 'required|string|max:20',
            'text' => 'required|string|max:4096',
            'access_token' => 'nullable|string',
            'phone_number_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $to = $request->input('to');
        $text = $request->input('text');
        $accessToken = $request->input('access_token') ?? $this->whatsappService->getAccessToken();
        $phoneNumberId = $request->input('phone_number_id') ?? $this->whatsappService->getPhoneNumberId();

        // Format phone number to international format
        $to = WhatsAppCloudApiService::formatPhoneNumber($to);
        if (!$to) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid phone number format',
            ], 400);
        }

        $result = $this->whatsappService->sendTextMessage($to, $text, $accessToken, $phoneNumberId);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Send a template message via WhatsApp Cloud API.
     */
    public function sendTemplateMessage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'to' => 'required|string|max:20',
            'template_name' => 'required|string|max:255',
            'language_code' => 'nullable|string|max:10',
            'components' => 'nullable|array',
            'access_token' => 'nullable|string',
            'phone_number_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $to = $request->input('to');
        $templateName = $request->input('template_name');
        $languageCode = $request->input('language_code', 'en_US');
        $components = $request->input('components', []);
        $accessToken = $request->input('access_token') ?? $this->whatsappService->getAccessToken();
        $phoneNumberId = $request->input('phone_number_id') ?? $this->whatsappService->getPhoneNumberId();

        // Format phone number to international format
        $to = WhatsAppCloudApiService::formatPhoneNumber($to);
        if (!$to) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid phone number format',
            ], 400);
        }

        $result = $this->whatsappService->sendTemplateMessage(
            $to,
            $templateName,
            $languageCode,
            $components,
            $accessToken,
            $phoneNumberId
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Send a document via WhatsApp Cloud API.
     */
    public function sendDocument(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'to' => 'required|string|max:20',
            'document_url' => 'required|url',
            'filename' => 'nullable|string|max:255',
            'caption' => 'nullable|string|max:1024',
            'access_token' => 'nullable|string',
            'phone_number_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $to = $request->input('to');
        $documentUrl = $request->input('document_url');
        $filename = $request->input('filename');
        $caption = $request->input('caption');
        $accessToken = $request->input('access_token') ?? $this->whatsappService->getAccessToken();
        $phoneNumberId = $request->input('phone_number_id') ?? $this->whatsappService->getPhoneNumberId();

        // Format phone number to international format
        $to = WhatsAppCloudApiService::formatPhoneNumber($to);
        if (!$to) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid phone number format',
            ], 400);
        }

        $result = $this->whatsappService->sendDocument(
            $to,
            $documentUrl,
            $filename,
            $caption,
            $accessToken,
            $phoneNumberId
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Send an image via WhatsApp Cloud API.
     */
    public function sendImage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'to' => 'required|string|max:20',
            'image_url' => 'required|url',
            'caption' => 'nullable|string|max:1024',
            'access_token' => 'nullable|string',
            'phone_number_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $to = $request->input('to');
        $imageUrl = $request->input('image_url');
        $caption = $request->input('caption');
        $accessToken = $request->input('access_token') ?? $this->whatsappService->getAccessToken();
        $phoneNumberId = $request->input('phone_number_id') ?? $this->whatsappService->getPhoneNumberId();

        // Format phone number to international format
        $to = WhatsAppCloudApiService::formatPhoneNumber($to);
        if (!$to) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid phone number format',
            ], 400);
        }

        $result = $this->whatsappService->sendImage(
            $to,
            $imageUrl,
            $caption,
            $accessToken,
            $phoneNumberId
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Send an audio message via WhatsApp Cloud API.
     */
    public function sendAudio(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'to' => 'required|string|max:20',
            'audio_url' => 'required|url',
            'access_token' => 'nullable|string',
            'phone_number_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $to = $request->input('to');
        $audioUrl = $request->input('audio_url');
        $accessToken = $request->input('access_token') ?? $this->whatsappService->getAccessToken();
        $phoneNumberId = $request->input('phone_number_id') ?? $this->whatsappService->getPhoneNumberId();

        $to = WhatsAppCloudApiService::formatPhoneNumber($to);
        if (!$to) {
            return response()->json(['success' => false, 'error' => 'Invalid phone number format'], 400);
        }

        $result = $this->whatsappService->sendAudio($to, $audioUrl, $accessToken, $phoneNumberId);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Send a video message via WhatsApp Cloud API.
     */
    public function sendVideo(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'to' => 'required|string|max:20',
            'video_url' => 'required|url',
            'caption' => 'nullable|string|max:1024',
            'access_token' => 'nullable|string',
            'phone_number_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $to = $request->input('to');
        $videoUrl = $request->input('video_url');
        $caption = $request->input('caption');
        $accessToken = $request->input('access_token') ?? $this->whatsappService->getAccessToken();
        $phoneNumberId = $request->input('phone_number_id') ?? $this->whatsappService->getPhoneNumberId();

        $to = WhatsAppCloudApiService::formatPhoneNumber($to);
        if (!$to) {
            return response()->json(['success' => false, 'error' => 'Invalid phone number format'], 400);
        }

        $result = $this->whatsappService->sendVideo($to, $videoUrl, $caption, $accessToken, $phoneNumberId);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Send a location via WhatsApp Cloud API.
     */
    public function sendLocation(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'to' => 'required|string|max:20',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'access_token' => 'nullable|string',
            'phone_number_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $to = $request->input('to');
        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');
        $name = $request->input('name');
        $address = $request->input('address');
        $accessToken = $request->input('access_token') ?? $this->whatsappService->getAccessToken();
        $phoneNumberId = $request->input('phone_number_id') ?? $this->whatsappService->getPhoneNumberId();

        $to = WhatsAppCloudApiService::formatPhoneNumber($to);
        if (!$to) {
            return response()->json(['success' => false, 'error' => 'Invalid phone number format'], 400);
        }

        $result = $this->whatsappService->sendLocation(
            $to,
            $latitude,
            $longitude,
            $name,
            $address,
            $accessToken,
            $phoneNumberId
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Get templates for a WABA.
     */
    public function getTemplates(Request $request): JsonResponse
    {
        $wabaId = $request->input('waba_id') ?? $this->whatsappService->getWabaId();
        $accessToken = $request->input('access_token') ?? $this->whatsappService->getAccessToken();

        $result = $this->whatsappService->getTemplates($wabaId, $accessToken);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Get phone numbers for a WABA.
     */
    public function getPhoneNumbers(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'waba_id' => 'nullable|string',
            'access_token' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $wabaId = $request->input('waba_id') ?? $this->whatsappService->getWabaId();
        $accessToken = $request->input('access_token') ?? $this->whatsappService->getAccessToken();

        $result = $this->whatsappService->getPhoneNumbers($wabaId, $accessToken);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Check if service is configured.
     */
    public function isConfigured(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'configured' => $this->whatsappService->isConfigured(),
            'phone_number_id' => $this->whatsappService->getPhoneNumberId(),
            'waba_id' => $this->whatsappService->getWabaId(),
        ]);
    }

    /**
     * Webhook verification endpoint for WhatsApp Cloud API.
     * This is called by Meta when setting up webhooks.
     */
    public function verifyWebhook(Request $request)
    {
        // PHP converts hub.mode to hub_mode, hub.verify_token to hub_verify_token, etc.
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        // TODO: ideally load from config or settings table
        $verifyToken = config('services.whatsapp_cloud.verify_token', 'alryyan');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            Log::info('WhatsApp Cloud API: Webhook verified successfully.', [
                'mode' => $mode,
                'challenge' => $challenge,
            ]);

            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('WhatsApp Cloud API: Webhook verification failed.', [
            'mode' => $mode,
            'token_received' => $token,
        ]);

        return response()->json(['error' => 'Forbidden'], 403);
    }

    /**
     * Webhook callback endpoint for receiving WhatsApp event notifications.
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $data = $request->all();

        // Validate the webhook signature (X-Hub-Signature-256)
        if (!$this->validateWebhookSignature($request, $payload)) {
            Log::warning('WhatsApp Cloud API: Webhook signature validation failed.', [
                'signature_header' => $request->header('X-Hub-Signature-256'),
                'ip' => $request->ip(),
            ]);

            // Still return 200 OK to prevent retries, but log the issue
            return response()->json(['success' => false, 'error' => 'Invalid signature'], 200);
        }

        Log::info('WhatsApp Cloud API: Webhook received and validated.', [
            'object' => $data['object'] ?? null,
            'entry_count' => isset($data['entry']) ? count($data['entry']) : 0,
        ]);

        if (isset($data['entry'])) {
            foreach ($data['entry'] as $entry) {
                if (isset($entry['changes'])) {
                    foreach ($entry['changes'] as $change) {
                        $value = $change['value'] ?? [];
                        $recipientPhoneNumberId = $value['metadata']['phone_number_id'] ?? null;

                        Log::info('WhatsApp Cloud API: Recipient phone number ID: ' . $recipientPhoneNumberId);

                        // Handle incoming messages
                        if (isset($value['messages'])) {
                            foreach ($value['messages'] as $message) {
                                if ($recipientPhoneNumberId === '953041111231804') {
                                    $collection = 'one_care';
                                    if (($message['type'] ?? '') === 'text' && isset($message['text']['body'])) {
                                        $from = $message['from'];
                                        $body = $message['text']['body'];
                                    } else {
                                        $msg = "  استعلام جديد    لصيدليه ون كير من الرقم  " . ($message['from'] ?? '');
                                        $this->sendTextToUser('249991961111', $msg, $recipientPhoneNumberId);
                                    }

                                    $this->handleIncomingMessage($message, $value, $collection, $recipientPhoneNumberId);
                                }
                            }
                        }

                        // Handle message status updates
                        if (isset($value['statuses'])) {
                            foreach ($value['statuses'] as $status) {
                                $this->handleMessageStatus($status);
                            }
                        }
                    }
                }
            }
        }

        return response()->json(['success' => true], 200);
    }

    /**
     * Validate the webhook signature using SHA256 and App Secret.
     */
    protected function validateWebhookSignature(Request $request, string $payload): bool
    {
        $signatureHeader = $request->header('X-Hub-Signature-256');

        if (!$signatureHeader) {
            Log::warning('WhatsApp Cloud API: Missing X-Hub-Signature-256 header.');
            return false;
        }

        $signature = str_replace('sha256=', '', $signatureHeader);

        if (empty($signature)) {
            return false;
        }

        $appSecret = config('services.whatsapp_cloud.app_secret');

        if (!$appSecret) {
            Log::warning('WhatsApp Cloud API: App Secret not configured. Skipping signature validation.');
            return true;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $appSecret);

        $isValid = hash_equals($expectedSignature, $signature);

        if (!$isValid) {
            Log::error('WhatsApp Cloud API: Signature mismatch.', [
                'expected' => substr($expectedSignature, 0, 10) . '...',
                'received' => substr($signature, 0, 10) . '...',
            ]);
        }

        return $isValid;
    }

    /**
     * Handle incoming WhatsApp messages.
     */
    protected function handleIncomingMessage(array $message, array $value, $collection, $phoneNumberId = null): void
    {
        $messageId = $message['id'] ?? null;
        $from = $message['from'] ?? null;
        $type = $message['type'] ?? null;
        $timestamp = $message['timestamp'] ?? null;


        if (($type ?? '') === 'text' && isset($message['text']['body'])) {
            // \App\Events\WhatsAppMessageReceived::dispatch([
            //     'phone_number_id' => $phoneNumberId,
            //     'waba_id' => null,
            //     'from' => $from,
            //     'to' => $phoneNumberId,
            //     'type' => 'text',
            //     'body' => $message['text']['body'],
            //     'status' => 'received',
            //     'message_id' => $messageId ?? null,
            //     'direction' => 'incoming',
            //     'raw_payload' => $message,
            // ]);
        }

        $isButtonMessage = false;
        $buttonData = null;

        if ($type === 'interactive' && isset($message['interactive'])) {
            $interactive = $message['interactive'];
            $interactiveType = $interactive['type'] ?? null;

            if ($interactiveType === 'button_reply' && isset($interactive['button_reply'])) {
                $isButtonMessage = true;
                $buttonData = $interactive['button_reply'];
            }
        } elseif ($type === 'button') {
            if (isset($message['button'])) {
                $isButtonMessage = true;
                $buttonData = $message['button'];
            } elseif (isset($message['interactive']['button_reply'])) {
                $isButtonMessage = true;
                $buttonData = $message['interactive']['button_reply'];
            }
        }

        if ($isButtonMessage && $buttonData !== null) {
            // Determine which button was pressed by its title or payload
            $buttonTitle = strtolower(trim($buttonData['title'] ?? $buttonData['text'] ?? $buttonData['id'] ?? ''));
            $buttonPayload = strtolower(trim($buttonData['payload'] ?? $buttonData['id'] ?? ''));

            // Identify the report type from the button
            $isSalesReport   = str_contains($buttonTitle, 'مبيعات')   && !str_contains($buttonTitle, 'مردود') && !str_contains($buttonTitle, 'اصناف');
            $isSoldItems     = str_contains($buttonTitle, 'اصناف')    || str_contains($buttonPayload, 'sold');
            $isReturns       = str_contains($buttonTitle, 'مردود')    || str_contains($buttonPayload, 'return');

            // Fallback: try button payload keywords
            if (!$isSalesReport && !$isSoldItems && !$isReturns) {
                $isSalesReport = str_contains($buttonPayload, 'sales') || str_contains($buttonPayload, 'report');
            }

            // We need to know the shift_id. It may be embedded in the button payload, e.g. "sales_71" or just "71".
            // Try to extract a numeric shift_id from payload, otherwise look up last shift document.
            $shiftId = null;
            if (preg_match('/(\d+)/', $buttonPayload, $m)) {
                $shiftId = $m[1];
            } elseif (preg_match('/(\d+)/', $buttonTitle, $m)) {
                $shiftId = $m[1];
            }

            if ($shiftId) {
                $shiftPdfs = $this->getShiftPdfUrlsFromFirestore($shiftId, $collection);
            } else {
                $shiftPdfs = null;
            }

            if ($shiftPdfs) {
                if ($isSoldItems) {
                    $pdfUrl  = $shiftPdfs['sold_items_pdf_url'] ?? null;
                    $label   = 'تقرير الأصناف المباعة';
                } elseif ($isReturns) {
                    $pdfUrl  = $shiftPdfs['returns_pdf_url'] ?? null;
                    $label   = 'تقرير مردودات المبيعات';
                } else {
                    // Default: main sales report
                    $pdfUrl  = $shiftPdfs['pdf_url'] ?? null;
                    $label   = 'تقرير المبيعات';
                }

                if ($pdfUrl) {
                    $this->sendTextToUser($from, "سيتم إرسال {$label} خلال لحظات", $phoneNumberId);
                    $this->sendDocumentToUser($from, $pdfUrl, "shift_{$shiftId}", $phoneNumberId);
                } else {
                    $this->sendTextToUser($from, "عذراً، {$label} غير متاح لهذه الوردية.", $phoneNumberId);
                }
            } else {
                // Fallback to old phone-based lookup

                $this->sendTextToUser($from, "عذراً، لم يتم العثور على التقرير لهذا الرقم: {$from}", $phoneNumberId);
            }
        } 
    }

    /**
     * Fetch all PDF URLs for a shift from Firestore.
     * Firestore path: one_care → shifts (subcollection) → {shift_id}
     * Returns array with keys: pdf_url, cost_pdf_url, sold_items_pdf_url, returns_pdf_url
     */
    protected function getShiftPdfUrlsFromFirestore(string $shiftId, ?string $collection): ?array
    {
        try {
            $projectId = config('firebase.project_id');
            if (!$projectId) {
                Log::warning('Firebase project ID not configured');
                return null;
            }

            $accessToken = \App\Services\FirebaseService::getAccessToken();
            if (!$accessToken) {
                Log::warning('FCM access token unavailable');
                return null;
            }

            // Path: one_care/shifts/shifts/{shift_id}
            $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/one_care/shifts/shifts/{$shiftId}";

            $response = \Illuminate\Support\Facades\Http::withToken($accessToken)->get($url);

            if (!$response->successful()) {
                Log::warning("Shift Firestore document not found", ['shift_id' => $shiftId, 'status' => $response->status()]);
                return null;
            }

            $fields = $response->json()['fields'] ?? [];

            return [
                'pdf_url'            => $fields['pdf_url']['stringValue'] ?? null,
                'cost_pdf_url'       => $fields['cost_pdf_url']['stringValue'] ?? null,
                'sold_items_pdf_url' => $fields['sold_items_pdf_url']['stringValue'] ?? null,
                'returns_pdf_url'    => $fields['returns_pdf_url']['stringValue'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error("Failed to fetch shift PDF URLs from Firestore", ['shift_id' => $shiftId, 'error' => $e->getMessage()]);
            return null;
        }
    }

 
    /**
     * Send a document to a user via WhatsApp Cloud API.
     */
    protected function sendDocumentToUser(string $to, string $documentUrl, ?string $code = null, $phoneNumberId = null): void
    {
        try {
            $filename = $code ? "result_{$code}.pdf" : 'result.pdf';
            Log::info('WhatsApp Cloud API: Sending document to user.', [
                'to' => $to,
                'document_url' => $documentUrl,
                'filename' => $filename,
                'phoneNumberId' => $phoneNumberId,
            ]);

            $accessToken = $this->whatsappService->getAccessToken();

            $result = $this->whatsappService->sendDocument(
                $to,
                $documentUrl,
                $filename,
                'نتيجة المختبر - Lab Result',
                $accessToken,
                $phoneNumberId
            );

            if ($result['success']) {
                Log::info('WhatsApp Cloud API: Document sent successfully to user.', [
                    'to' => $to,
                    'document_url' => $documentUrl,
                    'message_id' => $result['message_id'] ?? null,
                ]);
            } else {
                Log::error('WhatsApp Cloud API: Failed to send document to user.', [
                    'to' => $to,
                    'document_url' => $documentUrl,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('WhatsApp Cloud API: Exception while sending document to user.', [
                'to' => $to,
                'document_url' => $documentUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send a text message to a user via WhatsApp Cloud API.
     */
    protected function sendTextToUser(string $to, string $text, $phoneNumberId = null): void
    {
        try {
            $result = $this->whatsappService->sendTextMessage($to, $text, null, $phoneNumberId);

            if ($result['success']) {
                Log::info('WhatsApp Cloud API: Text message sent successfully to user.', [
                    'to' => $to,
                    'message_id' => $result['message_id'] ?? null,
                ]);
            } else {
                Log::error('WhatsApp Cloud API: Failed to send text message to user.', [
                    'to' => $to,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('WhatsApp Cloud API: Exception while sending text message to user.', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle message status updates.
     */
    protected function handleMessageStatus(array $status): void
    {
        $messageId = $status['id'] ?? null;
        $currentStatus = $status['status'] ?? null;
        $errorInfo = null;

        if ($currentStatus === 'failed' && isset($status['errors'])) {
            $errorInfo = $status['errors'][0] ?? null;
            foreach ($status['errors'] as $error) {
                Log::error("WhatsApp Delivery Failure [ID: {$messageId}]: ", [
                    'error_code' => $error['code'] ?? 'N/A',
                    'error_message' => $error['message'] ?? 'N/A',
                    'error_data' => $error['error_data'] ?? 'N/A',
                ]);
            }
        }

        Log::info("WhatsApp Message Status: [ID: {$messageId}] is now {$currentStatus}");

        try {
            // broadcast(new \App\Events\WhatsAppStatusUpdated($messageId, $currentStatus, $errorInfo));
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast WhatsApp status update: ' . $e->getMessage());
        }
    }


}
