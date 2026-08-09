<?php

namespace App\Services\Pdf;

class PdfLocale
{
    /**
     * Detect which language a generated report should use.
     *
     * Checks the `lang` query parameter first (needed for report links that are
     * opened via a raw browser navigation, e.g. `window.open`, which can't set
     * custom request headers), then falls back to the `Accept-Language` header
     * that the frontend's axios client sets on every request to reflect the
     * currently selected UI language (see src/lib/axios.ts).
     */
    public static function detect(): string
    {
        $request = request();
        $lang = $request?->query('lang') ?: $request?->header('Accept-Language');
        $lang = strtolower(substr((string) $lang, 0, 2));

        return $lang === 'en' ? 'en' : 'ar';
    }
}
