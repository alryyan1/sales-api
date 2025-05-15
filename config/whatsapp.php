<?php // config/whatsapp.php

return [
    'enabled' => env('WHATSAPP_API_ENABLED', false), // Default to false if not set in .env
    'api_url' => env('WHATSAPP_API_URL'),
    'api_token' => env('WHATSAPP_API_TOKEN'),
    'notification_number' => env('WHATSAPP_NOTIFICATION_NUMBER'), // Admin/Notification recipient
];