<?php

namespace App\Support;

/**
 * Single source of truth for payment method values, backed by
 * config/payment_methods.php — see that file for the actual lists.
 */
final class PaymentMethods
{
    /** @return array<int, string> */
    public static function all(): array
    {
        return config('payment_methods.all');
    }

    /** @return array<int, string> */
    public static function bank(): array
    {
        return config('payment_methods.bank');
    }

    public static function isBank(?string $method): bool
    {
        return in_array($method, self::bank(), true);
    }

    /** Comma-joined list for `'in:...'` style validation rules. */
    public static function validationRule(): string
    {
        return 'in:'.implode(',', self::all());
    }
}
