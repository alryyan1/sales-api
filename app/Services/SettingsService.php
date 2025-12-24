<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    private const CACHE_KEY = 'app_settings_cache_v1';
    private const CACHE_TTL_SECONDS = 60; // adjust as needed

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
            'company_email' => 'string',
            'company_logo_url' => 'string',
            'currency_symbol' => 'string',
            'date_format' => 'string',
            'global_low_stock_threshold' => 'int',
            'invoice_prefix' => 'string',
            'purchase_order_prefix' => 'string',
            'default_profit_rate' => 'float',
            'timezone' => 'string',
            'whatsapp_enabled' => 'bool',
            'whatsapp_api_url' => 'string',
            'whatsapp_api_token' => 'string',
            'whatsapp_instance_id' => 'string',
            'whatsapp_default_phone' => 'string',
            'company_header_url' => 'string',
            'invoice_branding_type' => 'string', // 'logo' or 'header'
            'logo_position' => 'string', // 'right', 'left', 'both'
            'logo_height' => 'int',
            'logo_width' => 'int',
            'tax_number' => 'string',
        ];
    }

    /**
     * Default values (fallback to existing config where available)
     */
    public function defaultValues(): array
    {
        $c = config('app_settings', []);
        return [
            'company_name' => $c['company_name'] ?? 'My Awesome Company',
            'company_address' => $c['company_address'] ?? '123 Main St, Anytown, USA',
            'company_phone' => $c['company_phone'] ?? '+1-555-123-4567',
            'company_email' => $c['company_email'] ?? 'contact@example.com',
            'company_logo_url' => $c['company_logo_url'] ?? null,
            'currency_symbol' => $c['currency_symbol'] ?? 'SDG',
            'date_format' => $c['date_format'] ?? 'YYYY-MM-DD',
            'global_low_stock_threshold' => $c['global_low_stock_threshold'] ?? 10,
            'invoice_prefix' => $c['invoice_prefix'] ?? 'INV-',
            'purchase_order_prefix' => $c['purchase_order_prefix'] ?? 'PO-',
            'default_profit_rate' => $c['default_profit_rate'] ?? 20.0,
            'timezone' => config('app.timezone', 'Africa/Khartoum'),
            'whatsapp_enabled' => $c['whatsapp_enabled'] ?? false,
            // Default to WaClient API values as requested
            'whatsapp_api_url' => 'https://waclient.com/api',
            'whatsapp_api_token' => '68968ae964aac',
            'whatsapp_instance_id' => '68968AFE5FF3D',
            'whatsapp_default_phone' => $c['whatsapp_default_phone'] ?? '',
            'company_header_url' => $c['company_header_url'] ?? null,
            'invoice_branding_type' => $c['invoice_branding_type'] ?? 'logo',
            'logo_position' => $c['logo_position'] ?? 'right',
            'logo_height' => $c['logo_height'] ?? 60,
            'logo_width' => $c['logo_width'] ?? 60,
            'tax_number' => $c['tax_number'] ?? null,
        ];
    }

    public function getAll(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
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
            return $result;
        });
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
        Cache::forget(self::CACHE_KEY);
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
