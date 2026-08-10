<?php // config/app_settings.php

return [
    'company_name' => env('APP_SETTINGS_COMPANY_NAME', 'My Awesome Company'),
    'company_address' => env('APP_SETTINGS_COMPANY_ADDRESS', '123 Main St, Anytown, USA'),
    'company_phone' => env('APP_SETTINGS_COMPANY_PHONE', '+1-555-123-4567'),
    'company_email' => env('APP_SETTINGS_COMPANY_EMAIL', 'contact@example.com'),
    'company_logo_url' => env('APP_SETTINGS_COMPANY_LOGO_URL', null), // URL to a logo image

    'currency_symbol' => env('APP_SETTINGS_CURRENCY_SYMBOL', 'SDG'),
    'global_low_stock_threshold' => (int) env('APP_SETTINGS_LOW_STOCK_THRESHOLD', 10),

    'default_profit_rate' => (float) env('APP_SETTINGS_DEFAULT_PROFIT_RATE', 20.0), // Default profit rate percentage
    'pdf_font' => env('APP_SETTINGS_PDF_FONT', 'Amiri'),

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

    // Sales Behavior
    'sales_allow_zero_stock' => env('APP_SETTINGS_SALES_ALLOW_ZERO_STOCK', true),
    'sales_allow_negative_stock' => env('APP_SETTINGS_SALES_ALLOW_NEGATIVE_STOCK', false),
    'sales_require_customer' => env('APP_SETTINGS_SALES_REQUIRE_CUSTOMER', false),
    'sales_default_customer_id' => env('APP_SETTINGS_SALES_DEFAULT_CUSTOMER_ID', null),
    'sales_allow_price_edit' => env('APP_SETTINGS_SALES_ALLOW_PRICE_EDIT', true),
    'sales_allow_invoice_date_edit' => env('APP_SETTINGS_SALES_ALLOW_INVOICE_DATE_EDIT', true),

];
