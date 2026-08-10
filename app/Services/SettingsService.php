<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SettingsService
{
    /** Memoized for the lifetime of the request — avoids re-querying app_settings when getAll() is called more than once per request (common: most PDF services call it independently). Reset by update(). */
    private static ?array $cached = null;

    private const IMAGE_KEYS = [
        'company_logo_url',
        'company_header_url',
        'company_stamp_url',
        'company_signature_url',
    ];

    /**
     * Keys we manage in DB and their expected types.
     * Type can be: string, int, float, bool
     */
    public function managedKeysWithTypes(): array
    {
        return [
            'company_name' => 'string',
            'company_address' => 'string',
            'company_phone' => 'string',
            'company_phone_2' => 'string',
            'company_email' => 'string',
            'company_logo_url' => 'string',
            'currency_symbol' => 'string',
            'global_low_stock_threshold' => 'int',
            'default_profit_rate' => 'float',
            'timezone' => 'string',
            'company_header_url' => 'string',
            'company_stamp_url' => 'string',
            'company_signature_url' => 'string',
            'invoice_branding_type' => 'string', // 'logo' or 'header'
            'logo_position' => 'string', // 'right', 'left', 'both'
            'logo_height' => 'int',
            'logo_width' => 'int',
            'tax_number' => 'string',
            'pdf_font' => 'string',
            'pos_mode' => 'string', // 'shift' or 'days'
            'pos_filter_sales_by_user' => 'bool',
            'product_images_show_in_list' => 'bool',
            'product_images_show_in_pos' => 'bool',
            'product_images_show_in_invoices' => 'bool',
            'product_images_show_in_reports' => 'bool',
            'whatsapp_shift_closure_numbers' => 'string',
            'firebase_collection_name' => 'string',
            'usd_to_sdg_factor' => 'float',
            'product_row_color_highlight' => 'bool',
            'product_scientific_name_visible' => 'bool',
            'product_scientific_name_required' => 'bool',
            'pos_show_expired_products' => 'bool',
            'pos_show_out_of_stock_products' => 'bool',
            'purchase_use_batch_number' => 'bool',
            'purchase_use_expiry_date' => 'bool',
            'default_purchase_currency' => 'string',
            'currency_code' => 'string',
            'usd_conversion_enabled' => 'bool',

            // Sales Behavior
            'sales_allow_zero_stock' => 'bool',
            'sales_allow_negative_stock' => 'bool',
            'sales_require_customer' => 'bool',
            'sales_default_customer_id' => 'int',
            'sales_allow_price_edit' => 'bool',
            'sales_allow_invoice_date_edit' => 'bool',
        ];
    }

    /**
     * Default values (fallback to existing config where available)
     */
    public function defaultValues(): array
    {
        $c = config('app_settings', []);
        return [
            'company_name' => $c['company_name'] ?? '',
            'company_address' => $c['company_address'] ?? '',
            'company_phone' => $c['company_phone'] ?? '',
            'company_phone_2' => $c['company_phone_2'] ?? null,
            'company_email' => $c['company_email'] ?? '',
            'company_logo_url' => $c['company_logo_url'] ?? null,
            'currency_symbol' => $c['currency_symbol'] ?? 'SDG',
            'global_low_stock_threshold' => $c['global_low_stock_threshold'] ?? 10,
            'default_profit_rate' => $c['default_profit_rate'] ?? 20.0,
            'timezone' => config('app.timezone', 'Africa/Khartoum'),
            'company_header_url' => $c['company_header_url'] ?? null,
            'company_stamp_url' => $c['company_stamp_url'] ?? null,
            'company_signature_url' => $c['company_signature_url'] ?? null,
            'invoice_branding_type' => $c['invoice_branding_type'] ?? 'logo',
            'logo_position' => $c['logo_position'] ?? 'right',
            'logo_height' => $c['logo_height'] ?? 60,
            'logo_width' => $c['logo_width'] ?? 60,
            'tax_number' => $c['tax_number'] ?? null,
            'pdf_font' => $c['pdf_font'] ?? 'Amiri',
            'pos_mode' => $c['pos_mode'] ?? 'shift',
            'pos_filter_sales_by_user' => $c['pos_filter_sales_by_user'] ?? false,
            'product_images_show_in_list' => $c['product_images_show_in_list'] ?? true,
            'product_images_show_in_pos' => $c['product_images_show_in_pos'] ?? true,
            'product_images_show_in_invoices' => $c['product_images_show_in_invoices'] ?? false,
            'product_images_show_in_reports' => $c['product_images_show_in_reports'] ?? false,
            'whatsapp_shift_closure_numbers' => $c['whatsapp_shift_closure_numbers'] ?? '',
            'firebase_collection_name' => $c['firebase_collection_name'] ?? 'none',
            'usd_to_sdg_factor' => $c['usd_to_sdg_factor'] ?? 1.0,
            'product_row_color_highlight' => $c['product_row_color_highlight'] ?? false,
            'product_scientific_name_visible' => $c['product_scientific_name_visible'] ?? true,
            'product_scientific_name_required' => $c['product_scientific_name_required'] ?? false,
            'pos_show_expired_products' => $c['pos_show_expired_products'] ?? false,
            'pos_show_out_of_stock_products' => $c['pos_show_out_of_stock_products'] ?? false,
            'purchase_use_batch_number' => $c['purchase_use_batch_number'] ?? true,
            'purchase_use_expiry_date' => $c['purchase_use_expiry_date'] ?? true,
            'default_purchase_currency' => $c['default_purchase_currency'] ?? 'SDG',
            // Drives decimal-place display everywhere money amounts are shown (see useFormatCurrency
            // in sales-ui): SDG=0, OMR=3, USD=2.
            'currency_code' => $c['currency_code'] ?? 'SDG',
            // Master switch for USD→local-currency price conversion (POS/product pricing).
            // When false, the TopAppBar rate widget is hidden and USD-priced products are
            // shown/sold at face value (no multiplication by usd_to_sdg_factor).
            'usd_conversion_enabled' => $c['usd_conversion_enabled'] ?? true,

            // Sales Behavior
            'sales_allow_zero_stock' => $c['sales_allow_zero_stock'] ?? true,
            'sales_allow_negative_stock' => $c['sales_allow_negative_stock'] ?? false,
            'sales_require_customer' => $c['sales_require_customer'] ?? false,
            'sales_default_customer_id' => $c['sales_default_customer_id'] ?? null,
            'sales_allow_price_edit' => $c['sales_allow_price_edit'] ?? true,
            'sales_allow_invoice_date_edit' => $c['sales_allow_invoice_date_edit'] ?? true,
        ];
    }

    public function getAll(): array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $defaults = $this->defaultValues();
        $types = $this->managedKeysWithTypes();
        $stored = AppSetting::query()->pluck('value', 'key')->toArray();

        $result = $defaults;
        foreach ($stored as $key => $raw) {
            if (!array_key_exists($key, $types)) {
                continue;
            }
            $result[$key] = $this->castFromStorage($raw, $types[$key]);
        }

        // Expand relative image paths to full public URLs.
        // Values that are already full URLs (legacy) are left as-is.
        foreach (self::IMAGE_KEYS as $key) {
            $value = $result[$key] ?? null;
            if ($value && !str_starts_with($value, 'http')) {
                $result[$key] = Storage::disk('public')->url($value);
            }
        }

        // There's no UI to set `currency_symbol` independently of `currency_code` — the
        // settings screen only exposes one "system currency" dropdown. Force the display
        // symbol to always match the selected code so PDFs and every screen that reads
        // either value stay consistent (also self-heals any stale symbol left over from
        // before this was enforced).
        $result['currency_symbol'] = $result['currency_code'] ?? 'SDG';

        return self::$cached = $result;
    }

    public function update(array $data): array
    {
        $types = $this->managedKeysWithTypes();
        foreach ($data as $key => $value) {
            if (!array_key_exists($key, $types)) {
                continue; // ignore unknown keys
            }
            $storedValue = $this->castToStorage($value, $types[$key]);
            AppSetting::updateOrCreate(['key' => $key], ['value' => $storedValue]);
        }
        self::$cached = null;
        return $this->getAll();
    }

    public function validationRules(): array
    {
        $rules = [];
        $types = $this->managedKeysWithTypes();
        foreach ($types as $key => $type) {
            switch ($type) {
                case 'int':
                    $rules[$key] = ['nullable', 'integer'];
                    break;
                case 'float':
                    $rules[$key] = ['nullable', 'numeric'];
                    break;
                case 'bool':
                    $rules[$key] = ['nullable', 'boolean'];
                    break;
                default:
                    $rules[$key] = ['nullable', 'string', 'max:255'];
                    break;
            }
        }
        // Specific constraints
        $rules['company_email'] = ['nullable', 'email', 'max:255'];
        $rules['currency_symbol'] = ['nullable', 'string', 'max:5'];
        $rules['pos_mode'] = ['nullable', 'string', Rule::in(['shift', 'days'])];
        $rules['default_purchase_currency'] = ['nullable', 'string', Rule::in(['SDG', 'USD'])];
        $rules['currency_code'] = ['nullable', 'string', Rule::in(['SDG', 'OMR', 'USD'])];
        $rules['sales_default_customer_id'] = ['nullable', 'integer', 'exists:clients,id'];
        return $rules;
    }

    private function castFromStorage(?string $raw, string $type)
    {
        if ($raw === null) return null;
        return match ($type) {
            'int' => (int) $raw,
            'float' => (float) $raw,
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            default => $raw,
        };
    }

    private function castToStorage($value, string $type): ?string
    {
        if ($value === null) return null;
        return match ($type) {
            'int' => (string) ((int) $value),
            'float' => (string) ((float) $value),
            'bool' => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
