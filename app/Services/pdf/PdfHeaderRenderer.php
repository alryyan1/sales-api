<?php

namespace App\Services\Pdf;

use App\Models\PdfReportSetting;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Storage;
use TCPDF;

class PdfHeaderRenderer
{
    private string $companyName;
    private string $companyAddress;
    private string $companyPhone;
    private ?string $logoPath;
    private ?string $headerImagePath;

    private string $brandingType;
    private string $logoPosition;
    private int $logoWidth;
    private int $logoHeight;
    private bool $showWatermark;

    public function __construct(string $reportKey)
    {
        $settings = app(SettingsService::class)->getAll();

        $this->companyName    = $settings['company_name']    ?? '';
        $this->companyAddress = $settings['company_address'] ?? '';
        $this->companyPhone   = $settings['company_phone']   ?? '';
        $this->logoPath       = $this->resolveImagePath($settings['company_logo_url']   ?? null);
        $this->headerImagePath = $this->resolveImagePath($settings['company_header_url'] ?? null);

        // Global defaults
        $globalBrandingType = $settings['invoice_branding_type'] ?? 'logo';
        $globalLogoPosition = $settings['logo_position']         ?? 'right';
        $globalLogoWidth    = (int) ($settings['logo_width']     ?? 24);
        $globalLogoHeight   = (int) ($settings['logo_height']    ?? 24);

        // Per-report overrides
        $reportSettings = PdfReportSetting::where('report_key', $reportKey)->first();

        $this->brandingType  = $reportSettings?->branding_type  ?? $globalBrandingType;
        $this->logoPosition  = $reportSettings?->logo_position  ?? $globalLogoPosition;
        $this->logoWidth     = $reportSettings?->logo_width     ?? $globalLogoWidth;
        $this->logoHeight    = $reportSettings?->logo_height    ?? $globalLogoHeight;
        $this->showWatermark = $reportSettings?->show_watermark ?? false;
    }

    /**
     * Draw the branding header on the current page.
     * Call this right after AddPage() (or at the start of each page if multi-page).
     */
    public function render(TCPDF $pdf): void
    {
        if ($this->brandingType === 'header' && $this->headerImagePath) {
            $this->renderHeaderImage($pdf);
        } elseif ($this->brandingType === 'logo') {
            $this->renderLogoAndText($pdf);
        } else {
            $this->renderTextOnly($pdf);
        }

        if ($this->showWatermark) {
            $this->renderWatermark($pdf);
        }
    }

    /**
     * Draw the watermark on the current page.
     * For multi-page reports call this again after each AddPage().
     */
    public function renderWatermark(TCPDF $pdf): void
    {
        if (!$this->logoPath) return;

        try {
            $pageWidth  = $pdf->getPageWidth();
            $pageHeight = $pdf->getPageHeight();
            $wmSize     = min($pageWidth, $pageHeight) * 0.5;
            $x          = ($pageWidth  - $wmSize) / 2;
            $y          = ($pageHeight - $wmSize) / 2;

            $pdf->setAlpha(0.08);
            @$pdf->Image($this->logoPath, $x, $y, $wmSize, $wmSize, '', '', '', false, 72, '', false, false, 0, false, false, false);
            $pdf->setAlpha(1);
        } catch (\Throwable $e) {
            // silently ignore watermark errors
        }
    }

    /**
     * Returns the recommended top margin (mm) to pass to SetMargins() before AddPage().
     * Add extra padding on top of this for body content if needed.
     */
    public function getTopMargin(): int
    {
        return match ($this->brandingType) {
            'header' => 35,
            'logo'   => 30,
            default  => 25,
        };
    }

    // ── Private rendering methods ───────────────────────────────────────────

    private function renderHeaderImage(TCPDF $pdf): void
    {
        try {
            $pageWidth    = $pdf->getPageWidth();
            $headerHeight = 30; // mm
            @$pdf->Image($this->headerImagePath, 0, 0, $pageWidth, $headerHeight, '', '', 'T', false, 300, '', false, false, 0, false, false, false);
            $pdf->SetY($headerHeight + 2);
        } catch (\Throwable $e) {
            $this->renderTextOnly($pdf);
        }
    }

    private function renderLogoAndText(TCPDF $pdf): void
    {
        if ($this->logoPath) {
            try {
                $pageWidth  = $pdf->getPageWidth();
                $margins    = $pdf->getMargins();
                $w          = $this->logoWidth;

                $x = match ($this->logoPosition) {
                    'left'   => $margins['left'],
                    'center' => ($pageWidth - $w) / 2,
                    default  => $pageWidth - $margins['right'] - $w, // right
                };

                @$pdf->Image(
                    $this->logoPath,
                    $x, 5, $w,
                    $this->logoHeight > 0 ? $this->logoHeight : 0,
                    '', '', 'T', false, 300, '', false, false, 0, false, false, false
                );
            } catch (\Throwable $e) {
                // logo error — fall through to text
            }
        }

        $this->renderCompanyText($pdf);
    }

    private function renderTextOnly(TCPDF $pdf): void
    {
        $this->renderCompanyText($pdf);
    }

    private function renderCompanyText(TCPDF $pdf): void
    {
        $pdf->SetY(8);
        $pdf->SetFont('arial', 'B', 12);
        $pdf->Cell(0, 10, $this->companyName, 0, 1, 'C');

        $pdf->SetFont('arial', '', 9);
        if ($this->companyAddress) {
            $pdf->Cell(0, 6, $this->companyAddress, 0, 1, 'C');
        }
        if ($this->companyPhone) {
            $pdf->Cell(0, 6, 'هاتف: ' . $this->companyPhone, 0, 1, 'C');
        }

        $pdf->Ln(3);
    }

    private function resolveImagePath(?string $urlOrPath): ?string
    {
        if (!$urlOrPath) return null;

        // Relative storage path (new format: e.g. "logos/abc.jpg")
        if (!str_starts_with($urlOrPath, 'http')) {
            $candidate = Storage::disk('public')->path($urlOrPath);
            return file_exists($candidate) ? $candidate : null;
        }

        // Full URL (legacy format or constructed by SettingsService::getAll())
        $path = parse_url($urlOrPath, PHP_URL_PATH) ?: '';
        $storagePos = strpos($path, '/storage/');
        if ($storagePos !== false) {
            $relative  = substr($path, $storagePos + 9); // 9 = strlen('/storage/')
            $candidate = public_path('storage/' . ltrim($relative, '/'));
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
