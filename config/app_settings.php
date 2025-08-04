<?php // config/app_settings.php

return [
    'company_name' => env('APP_SETTINGS_COMPANY_NAME', 'My Awesome Company'),
    'company_address' => env('APP_SETTINGS_COMPANY_ADDRESS', '123 Main St, Anytown, USA'),
    'company_phone' => env('APP_SETTINGS_COMPANY_PHONE', '+1-555-123-4567'),
    'company_email' => env('APP_SETTINGS_COMPANY_EMAIL', 'contact@example.com'),
    'company_logo_url' => env('APP_SETTINGS_COMPANY_LOGO_URL', null), // URL to a logo image

    'currency_symbol' => env('APP_SETTINGS_CURRENCY_SYMBOL', 'SDG'),
    'date_format' => env('APP_SETTINGS_DATE_FORMAT', 'YYYY-MM-DD'), // Example: 'MM/DD/YYYY', 'DD.MM.YYYY'
    'global_low_stock_threshold' => (int) env('APP_SETTINGS_LOW_STOCK_THRESHOLD', 10),

    'invoice_prefix' => env('APP_SETTINGS_INVOICE_PREFIX', 'INV-'),
    'purchase_order_prefix' => env('APP_SETTINGS_PO_PREFIX', 'PO-'),
    'default_profit_rate' => (float) env('APP_SETTINGS_DEFAULT_PROFIT_RATE', 20.0), // Default profit rate percentage

    // WhatsApp API Configuration
    'whatsapp_enabled' => env('APP_SETTINGS_WHATSAPP_ENABLED', false),
    'whatsapp_api_url' => env('APP_SETTINGS_WHATSAPP_API_URL', 'https://waapi.app/api/v1'),
    'whatsapp_api_token' => env('APP_SETTINGS_WHATSAPP_API_TOKEN', ''),
    'whatsapp_instance_id' => env('APP_SETTINGS_WHATSAPP_INSTANCE_ID', ''),
    'whatsapp_default_phone' => env('APP_SETTINGS_WHATSAPP_DEFAULT_PHONE', ''),

    // Add more settings as needed
    // 'timezone' => env('APP_TIMEZONE', 'UTC'),
     'payment_methods_ar' => [
        'cash' => 'نقدي',
        'visa' => 'فيزا',
        'mastercard' => 'ماستركارد',
        'bank_transfer' => 'تحويل بنكي',
        'mada' => 'مدى',
        'store_credit' => 'رصيد متجر',
        'other' => 'أخرى',
    ],
    'invoice_thermal_footer' => 'شكراً لزيارتكم!زورونا مرة أخرى!',

];