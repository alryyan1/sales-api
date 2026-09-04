<?php

namespace App\Services\Pdf;

use Illuminate\Support\Facades\Log;

/**
 * Makes sure the TCPDF font definition files exist, generating them on demand
 * from a bundled TTF the first time they are needed.
 *
 * Why this exists: TCPDF only ships a handful of pre-built font definitions
 * (helvetica, dejavusans, aefurat, ...). The reports here render with `arial`,
 * whose definition files live in `vendor/tecnickcom/tcpdf/fonts/` and are wiped
 * every time `composer install` runs. When they are missing TCPDF aborts with:
 *
 *     TCPDF ERROR: Could not include font definition file: arial
 *
 * `ensure()` is idempotent and costs a single `is_file()` once the font is
 * installed, so it is safe to call before every PDF render.
 */
class TcpdfFontInstaller
{
    /**
     * Guarantee that `$family` is usable with TCPDF's SetFont(), installing it
     * from `public/<family>.ttf` if the definition files are absent.
     *
     * @param  string  $family    Font family / key (lowercase, no extension).
     * @param  string  $fallback  Font to return if installation is impossible
     *                             (must be one TCPDF already ships).
     * @return string  The font family to hand to SetFont().
     */
    public static function ensure(string $family = 'arial', string $fallback = 'dejavusans'): string
    {
        $fontsDir = self::fontsDir();

        if ($fontsDir !== null && is_file($fontsDir . $family . '.php')) {
            return $family;
        }

        if (!class_exists(\TCPDF_FONTS::class)) {
            return $fallback;
        }

        // K_PATH_FONTS is only defined once the TCPDF class has been loaded.
        if ($fontsDir === null) {
            class_exists(\TCPDF::class);
            $fontsDir = self::fontsDir();
        }

        if ($fontsDir !== null && is_file($fontsDir . $family . '.php')) {
            return $family;
        }

        $ttf = self::locateTtf($family);
        if ($ttf === null) {
            Log::warning("TcpdfFontInstaller: no source TTF for '{$family}' in public/ — falling back to '{$fallback}'.");
            return $fallback;
        }

        try {
            $installed = \TCPDF_FONTS::addTTFfont($ttf, 'TrueTypeUnicode', '', 32);
            if (is_string($installed) && $installed !== ''
                && $fontsDir !== null && is_file($fontsDir . $installed . '.php')) {
                Log::info("TcpdfFontInstaller: installed TCPDF font '{$installed}' from {$ttf}.");
                return $installed;
            }
            Log::error("TcpdfFontInstaller: addTTFfont() did not produce a definition for '{$family}'.");
        } catch (\Throwable $e) {
            Log::error("TcpdfFontInstaller: failed to install '{$family}': {$e->getMessage()}");
        }

        return $fallback;
    }

    /**
     * Resolve TCPDF's fonts directory without forcing TCPDF to load.
     */
    private static function fontsDir(): ?string
    {
        if (defined('K_PATH_FONTS')) {
            return K_PATH_FONTS;
        }

        $vendorFonts = base_path('vendor/tecnickcom/tcpdf/fonts/');

        return is_dir($vendorFonts) ? $vendorFonts : null;
    }

    /**
     * Find a source font file in public/ (tolerating common casings/extensions).
     */
    private static function locateTtf(string $family): ?string
    {
        $candidates = [
            $family . '.ttf',
            $family . '.TTF',
            $family . '.otf',
            ucfirst($family) . '.ttf',
            ucfirst($family) . '.TTF',
            strtoupper($family) . '.ttf',
            strtoupper($family) . '.TTF',
        ];

        foreach ($candidates as $name) {
            $path = public_path($name);
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
