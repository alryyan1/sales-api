<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'whatsapp_cloud' => [
        'token'           => env('WHATSAPP_CLOUD_API_TOKEN'),
        'phone_number_id' => env('WHATSAPP_CLOUD_API_PHONE_NUMBER_ID'),
        'waba_id'         => env('WHATSAPP_CLOUD_WABA_ID'),
        'api_version'     => env('WHATSAPP_CLOUD_API_VERSION', 'v22.0'),
        'app_secret'      => env('WHATSAPP_CLOUD_APP_SECRET'),
        'verify_token'    => env('WHATSAPP_CLOUD_VERIFY_TOKEN', 'alryyan'),
    ],

    'airtel_sms' => [
        'api_key'  => env('AIRTEL_SMS_API_KEY', '683e2c68-a020-4423-bc7f-2d9c53e873c6'),
        'sender'   => env('AIRTEL_SMS_SENDER', 'INFO'),
        'endpoint' => env('AIRTEL_SMS_ENDPOINT', 'https://www.airtel.sd/api/rest_send_sms/'),
    ],

];
