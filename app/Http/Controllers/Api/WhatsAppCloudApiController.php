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
                                    $collection = 'altamayoz';
                                    Log::info('getting data from altamayoz ' . $recipientPhoneNumberId);
                                    if (($message['type'] ?? '') === 'text' && isset($message['text']['body'])) {
                                        $from = $message['from'];
                                        $body = $message['text']['body'];

                                        $msg = <<<MSG
استعلام جديد للنتائج
 التميز الفرع الجديد 
من الرقم: $from
المحتوى: $body
MSG;
                                        $this->sendTextToUser('249991961111', $msg, $recipientPhoneNumberId);
                                    } else {
                                        $msg = "  استعلام جديد    لمختبر التميز  من الرقم  " . ($message['from'] ?? '');
                                        $this->sendTextToUser('249991961111', $msg, $recipientPhoneNumberId);
                                        $this->sendTextToUser('249122867272', $msg, $recipientPhoneNumberId);
                                    }

                                    $this->handleIncomingMessage($message, $value, $collection, $recipientPhoneNumberId);
                                } elseif ($recipientPhoneNumberId === '982254518296345') {
                                    $collection = 'alroomy-shaglaban';
                                    Log::info('getting data from alryyan ' . $recipientPhoneNumberId);
                                    if (($message['type'] ?? '') === 'text' && isset($message['text']['body'])) {
                                        $from = $message['from'];
                                        $body = $message['text']['body'];

                                        $msg = <<<MSG
استعلام جديد للنتائج
الرومي شقلبان 
من الرقم: $from
المحتوى: $body
MSG;
                                        $this->sendTextToUser('249991961111', $msg, $recipientPhoneNumberId);
                                    }

                                    $this->handleIncomingMessage($message, $value, $collection, $recipientPhoneNumberId);
                                } elseif ($recipientPhoneNumberId === '1010322575491077') {
                                    $collection = 'altamayoz_branch_one';
                                    Log::info('getting data from alryyan ' . $recipientPhoneNumberId);
                                    if (($message['type'] ?? '') === 'text' && isset($message['text']['body'])) {
                                        $from = $message['from'];
                                        $body = $message['text']['body'];

                                        $msg = <<<MSG
استعلام جديد للنتائج
التميز الفرع الاول
من الرقم: $from
المحتوى: $body
MSG;
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

        Log::info('WhatsApp Cloud API: Incoming message received.', [
            'message_id' => $messageId,
            'from' => $from,
            'type' => $type,
            'timestamp' => $timestamp,
        ]);

        if (($type ?? '') === 'text' && isset($message['text']['body'])) {
            \App\Events\WhatsAppMessageReceived::dispatch([
                'phone_number_id' => $phoneNumberId,
                'waba_id' => null,
                'from' => $from,
                'to' => $phoneNumberId,
                'type' => 'text',
                'body' => $message['text']['body'],
                'status' => 'received',
                'message_id' => $messageId ?? null,
                'direction' => 'incoming',
                'raw_payload' => $message,
            ]);
        }

        $isButtonMessage = false;
        $buttonData = null;

        if ($type === 'interactive' && isset($message['interactive'])) {
            $interactive = $message['interactive'];
            $interactiveType = $interactive['type'] ?? null;

            Log::info('WhatsApp Cloud API: Interactive message received.', [
                'interactive_type' => $interactiveType,
                'from' => $from,
                'interactive_data' => $interactive,
            ]);

            if ($interactiveType === 'button_reply' && isset($interactive['button_reply'])) {
                $isButtonMessage = true;
                $buttonData = $interactive['button_reply'];

                Log::info('WhatsApp Cloud API: Button reply received (interactive format).', [
                    'button_id' => $buttonData['id'] ?? null,
                    'button_title' => $buttonData['title'] ?? null,
                    'from' => $from,
                ]);
            }
        } elseif ($type === 'button') {
            if (isset($message['button'])) {
                $isButtonMessage = true;
                $buttonData = $message['button'];
            } elseif (isset($message['interactive']['button_reply'])) {
                $isButtonMessage = true;
                $buttonData = $message['interactive']['button_reply'];
            }

            if ($isButtonMessage) {
                Log::info('WhatsApp Cloud API: Button message received (button type).', [
                    'button_id' => $buttonData['id'] ?? null,
                    'button_text' => $buttonData['text'] ?? $buttonData['title'] ?? null,
                    'from' => $from,
                    'full_message' => $message,
                ]);
            }
        }

        if ($isButtonMessage && $buttonData !== null) {
            $pdfUrl = $this->getResultUrlFromFirestoreByPhone($from, $collection);
            Log::info('PDF URL: ' . $pdfUrl);

            if ($pdfUrl) {
                $this->sendTextToUser($from, "سيتم إرسال النتيجة إليكم خلال لحظات", $phoneNumberId);
                $this->sendDocumentToUser($from, $pdfUrl, null, $phoneNumberId);
            } else {
                $this->sendTextToUser($from, "عذراً، لم يتم العثور على النتيجة لرقم الهاتف: {$from}", $phoneNumberId);
            }
        } elseif ($type === 'text' && isset($message['text']['body'])) {
            $messageText = trim($message['text']['body']);

            if (is_numeric($messageText)) {
                $code = $messageText;

                Log::info('WhatsApp Cloud API: Numeric visit ID received.', [
                    'code' => $code,
                    'from' => $from,
                    'message_text' => $messageText,
                ]);

                $this->sendTextToUser($from, "سيتم إرسال النتيجة إليكم خلال لحظات", $phoneNumberId);

                $pdfUrl = $this->getResultUrlFromFirestore($code, $collection);

                if ($pdfUrl) {
                    $this->sendDocumentToUser($from, $pdfUrl, $code, $phoneNumberId);
                } else {
                    $this->sendTextToUser($from, "عذراً، لم يتم العثور على النتيجة للرقم: {$code}", $phoneNumberId);
                }
            } else {
                Log::info('WhatsApp Cloud API: Non-numeric text message ignored.', [
                    'message_text' => $messageText,
                    'from' => $from,
                ]);
            }
        }
    }

    /**
     * Get result URL from Firestore using visit ID/code.
     */
    protected function getResultUrlFromFirestore(string $visitId, ?string $collection = null): ?string
    {
        try {
            $projectId = config('firebase.project_id');
            if (!$projectId) {
                Log::warning('Firebase project ID not configured for Firestore read');
                return null;
            }

            $accessToken = FirebaseService::getAccessToken();
            if (!$accessToken) {
                Log::warning('FCM access token unavailable for Firestore read');
                return null;
            }

            $documentId = (string) $visitId;
            $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/{$collection}/{$documentId}";

            $response = Http::withToken($accessToken)->get($url);

            if ($response->successful()) {
                $document = $response->json();
                $fields = $document['fields'] ?? [];

                if (isset($fields['result_url']['stringValue'])) {
                    $resultUrl = $fields['result_url']['stringValue'];
                    Log::info("Retrieved result URL from Firestore", [
                        'collection' => $collection,
                        'document_id' => $documentId,
                        'result_url' => $resultUrl,
                    ]);
                    return $resultUrl;
                }

                Log::warning("Result URL not found in Firestore document", [
                    'collection' => $collection,
                    'document_id' => $documentId,
                    'available_fields' => array_keys($fields),
                ]);
                return null;
            }

            if ($response->status() === 404) {
                Log::warning("Firestore document not found", [
                    'collection' => $collection,
                    'document_id' => $documentId,
                ]);
                return null;
            }

            Log::warning("Failed to get Firestore document", [
                'collection' => $collection,
                'document_id' => $documentId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error("Failed to get result URL from Firestore", [
                'visit_id' => $visitId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Get the most recent result URL from Firestore by searching for patient phone number.
     */
    protected function getResultUrlFromFirestoreByPhone(string $phoneNumber, ?string $collection): ?string
    {
        try {
            $projectId = config('firebase.project_id');
            if (!$projectId) {
                Log::warning('Firebase project ID not configured for Firestore query');
                return null;
            }

            $accessToken = FirebaseService::getAccessToken();
            if (!$accessToken) {
                Log::warning('FCM access token unavailable for Firestore query');
                return null;
            }

            $normalizedPhone = preg_replace('/[^0-9]/', '', $phoneNumber);

            $phoneVariants = [$phoneNumber];

            if ($phoneNumber !== $normalizedPhone) {
                $phoneVariants[] = $normalizedPhone;
            }

            $countryCodes = ['249', '968', '966', '971', '974', '965', '973', '961', '962', '964', '963', '961'];

            foreach ($countryCodes as $code) {
                if (strlen($normalizedPhone) > strlen($code) && substr($normalizedPhone, 0, strlen($code)) === $code) {
                    $phoneWithoutCountryCode = substr($normalizedPhone, strlen($code));
                    if (strlen($phoneWithoutCountryCode) >= 8) {
                        $phoneVariants[] = $phoneWithoutCountryCode;
                    }
                }
            }

            $phoneVariants = array_values(array_unique($phoneVariants));

            Log::info("Searching Firestore by phone number", [
                'collection' => $collection,
                'original_phone' => $phoneNumber,
                'normalized_phone' => $normalizedPhone,
                'variants_to_try' => $phoneVariants,
            ]);

            $parent = "projects/{$projectId}/databases/(default)";
            $url = "https://firestore.googleapis.com/v1/{$parent}/documents:runQuery";

            $foundDocument = null;
            $phoneVariantUsed = null;

            foreach ($phoneVariants as $phoneToSearch) {
                $query = [
                    'parent' => $parent,
                    'structuredQuery' => [
                        'from' => [
                            ['collectionId' => $collection],
                        ],
                        'where' => [
                            'fieldFilter' => [
                                'field' => [
                                    'fieldPath' => 'patient_phone',
                                ],
                                'op' => 'EQUAL',
                                'value' => [
                                    'stringValue' => $phoneToSearch,
                                ],
                            ],
                        ],
                        'limit' => 10,
                    ],
                ];

                Log::debug("Querying Firestore", [
                    'collection' => $collection,
                    'phone_variant' => $phoneToSearch,
                    'query_url' => $url,
                ]);

                $response = Http::withToken($accessToken)
                    ->withBody(json_encode($query), 'application/json')
                    ->post($url);

                if ($response->successful()) {
                    $results = $response->json();

                    Log::debug("Firestore query response", [
                        'collection' => $collection,
                        'phone_variant' => $phoneToSearch,
                        'response_status' => $response->status(),
                        'results_count' => is_array($results) ? count($results) : 0,
                        'results' => $results,
                    ]);

                    if (is_array($results) && !empty($results)) {
                        $documents = array_filter($results, function ($result) {
                            return isset($result['document']);
                        });

                        if (!empty($documents)) {
                            usort($documents, function ($a, $b) {
                                $aTime = $a['document']['fields']['updated_at']['timestampValue'] ?? '';
                                $bTime = $b['document']['fields']['updated_at']['timestampValue'] ?? '';
                                return strcmp($bTime, $aTime);
                            });

                            $foundDocument = $documents[0]['document'];
                            $phoneVariantUsed = $phoneToSearch;
                            Log::info("Found matching document in Firestore", [
                                'collection' => $collection,
                                'phone_variant_used' => $phoneVariantUsed,
                                'document_name' => $foundDocument['name'] ?? 'unknown',
                                'total_matches' => count($documents),
                            ]);
                            break;
                        }
                    } else {
                        Log::debug("No documents found for phone variant", [
                            'collection' => $collection,
                            'phone_variant' => $phoneToSearch,
                            'results' => $results,
                        ]);
                    }
                } else {
                    Log::warning("Failed to query Firestore by phone variant", [
                        'collection' => $collection,
                        'phone_variant' => $phoneToSearch,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            }

            if ($foundDocument === null) {
                Log::info("No Firestore documents found for phone number after trying all variants", [
                    'collection' => $collection,
                    'phone_number' => $phoneNumber,
                    'normalized_phone' => $normalizedPhone,
                    'variants_tried' => $phoneVariants,
                ]);
                return null;
            }

            $document = $foundDocument;
            $fields = $document['fields'] ?? [];

            if (isset($fields['result_url']['stringValue'])) {
                $resultUrl = $fields['result_url']['stringValue'];
                $documentId = $document['name'] ?? 'unknown';

                Log::info("Retrieved result URL from Firestore by phone", [
                    'collection' => $collection,
                    'phone_number' => $phoneNumber,
                    'phone_variant_used' => $phoneVariantUsed,
                    'document_id' => $documentId,
                    'result_url' => $resultUrl,
                ]);
                return $resultUrl;
            }

            Log::warning("Result URL not found in Firestore document", [
                'collection' => $collection,
                'phone_number' => $phoneNumber,
                'phone_variant_used' => $phoneVariantUsed,
                'available_fields' => array_keys($fields),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error("Failed to get result URL from Firestore by phone", [
                'phone_number' => $phoneNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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
            broadcast(new \App\Events\WhatsAppStatusUpdated($messageId, $currentStatus, $errorInfo));
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast WhatsApp status update: ' . $e->getMessage());
        }
    }

    /**
     * Test method to get result URL from Firestore by phone number.
     */
    public function testGetResultUrlByPhone(Request $request, ?string $phoneNumber = null): JsonResponse
    {
        $phoneNumber = $phoneNumber ?? $request->query('phone', '');
        $collection = $request->query('collection');

        $resultUrl = $this->getResultUrlFromFirestoreByPhone($phoneNumber, $collection);

        return response()->json([
            'success' => $resultUrl !== null,
            'phone_number' => $phoneNumber,
            'collection' => $collection,
            'result_url' => $resultUrl,
        ]);
    }
}

