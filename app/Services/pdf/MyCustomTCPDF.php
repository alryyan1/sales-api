<?php // app/Services/Pdf/MyCustomTCPDF.php

namespace App\Services\Pdf;

use TCPDF; // Directly use the base TCPDF class

class MyCustomTCPDF extends TCPDF
{
    protected $companyName;
    protected $companyAddress;
    protected $companyLogoUrl;
    protected $companyHeaderUrl;
    protected $defaultFontFamily = 'arial'; // Changed to dejavusans for better Arabic support
    protected $defaultFontSize = 10;
    protected $defaultFontBold = 'arial'; // Bold variant for Arabic support

    // Global branding settings
    protected $globalBrandingType  = 'logo'; // 'logo' | 'header' | 'none'
    protected $globalLogoPosition  = 'right';
    protected $globalLogoHeight    = 24;
    protected $globalLogoWidth     = 24;

    // Per-report overrides (loaded via loadReportSettings)
    protected $reportSettings = null;

    public function __construct($orientation = 'P', $unit = 'mm', $format = 'A4', $unicode = true, $encoding = 'UTF-8', $diskcache = false, $pdfa = false)
    {
        parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache, $pdfa);

        $settings = (new \App\Services\SettingsService())->getAll();
        $this->companyName      = $settings['company_name']       ?? 'Your Company';
        $this->companyAddress   = $settings['company_address']    ?? '';
        $this->companyLogoUrl   = $settings['company_logo_url']   ?? null;
        $this->companyHeaderUrl = $settings['company_header_url'] ?? null;

        $this->globalBrandingType = $settings['invoice_branding_type'] ?? 'logo';
        $this->globalLogoPosition = $settings['logo_position']         ?? 'right';
        $this->globalLogoHeight   = (int) ($settings['logo_height']    ?? 24);
        $this->globalLogoWidth    = (int) ($settings['logo_width']     ?? 24);

        $this->SetCreator('Sales Management System');
        $this->SetAuthor($this->companyName);
        $this->SetSubject('Professional Business Report');
        $this->SetKeywords('sales, report, business, management');

        // Set header and footer fonts using the default
        $this->setHeaderFont([$this->defaultFontFamily, '', ($this->defaultFontSize + 2)]); // Slightly larger for header
        $this->setFooterFont([$this->defaultFontFamily, 'I', ($this->defaultFontSize - 2)]); // Italic, smaller for footer

        $this->SetDefaultMonospacedFont('dejavusansmono'); // A monospaced variant of DejaVu

        $this->SetMargins(15, 35, 15); // Increased top margin for custom header
        $this->SetHeaderMargin(5);
        $this->SetFooterMargin(10);

        $this->SetAutoPageBreak(TRUE, 25);
        $this->setImageScale(1.25);
        $this->setFontSubsetting(true);

        // --- SET THE DEFAULT FONT FOR THE DOCUMENT BODY ---
        $this->SetFont($this->defaultFontFamily, '', $this->defaultFontSize);

        // Set language array for better RTL support and metadata
        $this->setLanguageArray([
            'a_meta_charset' => 'UTF-8',
            'a_meta_dir' => 'rtl', // Default direction for document elements
            'a_meta_language' => 'ar', // Document language
        ]);

        // Set default RTL mode for text. You can toggle it off for specific LTR sections.
        $this->setRTL(false);
    }

    /**
     * Load per-report branding settings from the database.
     * Call this right after instantiation in each PDF service.
     */
    public function loadReportSettings(string $reportKey): void
    {
        $this->reportSettings = \App\Models\PdfReportSetting::where('report_key', $reportKey)->first();
    }

    /**
     * Resolve the effective branding type (per-report override or global).
     */
    protected function effectiveBrandingType(): string
    {
        if ($this->reportSettings && $this->reportSettings->branding_type !== null) {
            return $this->reportSettings->branding_type;
        }
        return $this->globalBrandingType;
    }

    /**
     * Resolve the effective logo position.
     */
    protected function effectiveLogoPosition(): string
    {
        if ($this->reportSettings && $this->reportSettings->logo_position !== null) {
            return $this->reportSettings->logo_position;
        }
        return $this->globalLogoPosition;
    }

    /**
     * Resolve the effective logo height (in mm for TCPDF).
     */
    protected function effectiveLogoHeight(): int
    {
        if ($this->reportSettings && $this->reportSettings->logo_height !== null) {
            return $this->reportSettings->logo_height;
        }
        return $this->globalLogoHeight;
    }

    /**
     * Resolve the effective logo width (in mm for TCPDF).
     */
    protected function effectiveLogoWidth(): int
    {
        if ($this->reportSettings && $this->reportSettings->logo_width !== null) {
            return $this->reportSettings->logo_width;
        }
        return $this->globalLogoWidth;
    }

    /**
     * Resolve a filesystem path from a URL (storage URL → absolute path).
     */
    protected function resolveImagePath(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        if (!$path) return null;

        $storagePos = strpos($path, '/storage/');
        if ($storagePos !== false) {
            $relative  = substr($path, $storagePos + strlen('/storage/'));
            $candidate = public_path('storage/' . ltrim($relative, '/'));
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    // Override Header() method
    public function Header()
    {
        $brandingType = $this->effectiveBrandingType();
        $pageWidth    = $this->getPageWidth();

        if ($brandingType === 'header' && !empty($this->companyHeaderUrl)) {
            // --- Full-width header image ---
            try {
                $headerPath = $this->resolveImagePath($this->companyHeaderUrl) ?? $this->companyHeaderUrl;
                $headerHeight = 30; // mm
                @$this->Image($headerPath, 0, 0, $pageWidth, $headerHeight, '', '', 'T', false, 300, '', false, false, 0, false, false, false);
            } catch (\Throwable $e) {
                // Fallback to text header on error
                $this->renderTextHeader();
            }
        } elseif ($brandingType === 'logo') {
            // --- Logo + text header ---
            $this->renderLogoAndTextHeader();
        } else {
            // --- Text only (branding_type = 'none' or no image available) ---
            $this->renderTextHeader();
        }

        // Watermark (drawn on every page)
        if ($this->reportSettings && $this->reportSettings->show_watermark) {
            $this->addWatermark();
        }
    }

    /**
     * Render company name + address as text (no image).
     */
    protected function renderTextHeader(): void
    {
        $this->SetY(10);
        $this->SetFont($this->defaultFontBold ?: $this->defaultFontFamily, 'B', 12);
        $this->Cell(0, 10, $this->companyName, 0, 1, 'C', 0, '', 0, false, 'M', 'M');
        $this->SetFont($this->defaultFontFamily, '', 9);
        $this->Cell(0, 8, $this->companyAddress, 0, 1, 'C', 0, '', 0, false, 'M', 'M');
        $this->Ln(5);
    }

    /**
     * Render logo on the appropriate side + company text centered.
     */
    protected function renderLogoAndTextHeader(): void
    {
        $logoPlaced = false;

        if (!empty($this->companyLogoUrl)) {
            try {
                $logoPath = $this->resolveImagePath($this->companyLogoUrl) ?? $this->companyLogoUrl;
                $w        = $this->effectiveLogoWidth();
                $position = $this->effectiveLogoPosition();
                $pageWidth = $this->getPageWidth();
                $margins   = $this->getMargins();

                // Image() uses absolute page coordinates (RTL mode does NOT flip Image x)
                if ($position === 'left') {
                    $x = $margins['left'];
                } elseif ($position === 'center') {
                    $x = ($pageWidth - $w) / 2;
                } else { // right (default)
                    $x = $pageWidth - $margins['right'] - $w;
                }

                @$this->Image($logoPath, $x, 8, $w, 0, '', '', 'T', false, 300, '', false, false, 0, false, false, false);
                $logoPlaced = true;
            } catch (\Throwable $e) {
                // ignore logo errors
            }
        }

        // Title & address centered
        $this->SetY(10);
        $this->SetFont($this->defaultFontBold ?: $this->defaultFontFamily, 'B', 12);
        $this->Cell(0, 10, $this->companyName, 0, 1, 'C', 0, '', 0, false, 'M', 'M');
        $this->SetFont($this->defaultFontFamily, '', 9);
        $this->Cell(0, 8, $this->companyAddress, 0, 1, 'C', 0, '', 0, false, 'M', 'M');
        $this->Ln(5);
    }

    /**
     * Draw the logo as a semi-transparent watermark centered on the page.
     */
    protected function addWatermark(): void
    {
        if (empty($this->companyLogoUrl)) return;

        try {
            $logoPath  = $this->resolveImagePath($this->companyLogoUrl) ?? $this->companyLogoUrl;
            $pageWidth  = $this->getPageWidth();
            $pageHeight = $this->getPageHeight();
            $wmSize     = min($pageWidth, $pageHeight) * 0.5; // 50% of the smaller dimension
            $x = ($pageWidth  - $wmSize) / 2;
            $y = ($pageHeight - $wmSize) / 2;

            // Save current alpha and set low opacity
            $this->setAlpha(0.08);
            @$this->Image($logoPath, $x, $y, $wmSize, $wmSize, '', '', '', false, 72, '', false, false, 0, false, false, false);
            // Restore full opacity
            $this->setAlpha(1);
        } catch (\Throwable $e) {
            // ignore watermark errors
        }
    }

    // Override Footer() method
    public function Footer()
    {
        $this->SetY(-15);
        $this->Cell(0, 10, 'صفحة ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
    }

    // --- Professional styling methods ---

    /**
     * Create a professional section header
     */
    public function addSectionHeader($title, $fontSize = 14)
    {
        $this->SetFont($this->defaultFontFamily, 'B', $fontSize);
        $this->SetFillColor(240, 240, 240);
        $this->Cell(0, 8, $title, 1, 1, 'C', true);
        $this->Ln(2);
    }

    /**
     * Create a professional table header
     */
    public function addTableHeader($headers, $columnWidths, $fontSize = 9)
    {
        $this->SetFont($this->defaultFontFamily, 'B', $fontSize);
        $this->SetFillColor(70, 130, 180); // Professional blue background
        $this->SetTextColor(255, 255, 255); // White text

        foreach ($headers as $i => $header) {
            $this->Cell($columnWidths[$i], 8, $header, 1, 0, 'C', true);
        }
        $this->Ln();
        $this->SetTextColor(0, 0, 0); // Reset text color
        $this->SetFillColor(255, 255, 255); // Reset fill color
    }

    /**
     * Create a professional table row
     */
    public function addTableRow($data, $columnWidths, $rowHeight = 8, $fill = false, $fillColor = [245, 245, 245])
    {
        $this->SetFont($this->defaultFontFamily, '', 8);
        $this->SetFillColor($fillColor[0], $fillColor[1], $fillColor[2]);

        // Calculate the maximum height needed for this row
        $maxHeight = $rowHeight;

        // Check if any cell needs more height due to text wrapping
        foreach ($data as $i => $cellData) {
            $cellHeight = $this->calculateStringHeight($columnWidths[$i], $cellData);
            $maxHeight = max($maxHeight, $cellHeight);
        }

        // Draw cells with proper height
        $startY = $this->GetY();
        foreach ($data as $i => $cellData) {
            $x = $this->GetX();
            $y = $startY;

            // Use MultiCell for text wrapping
            $this->SetXY($x, $y);
            $this->MultiCell($columnWidths[$i], $maxHeight, $cellData, 'LRB', 'C', $fill, 0);

            $this->SetXY($x + $columnWidths[$i], $y);
        }

        $this->SetY($startY + $maxHeight);
    }

    /**
     * Calculate the height needed for a string in a given width
     */
    private function calculateStringHeight($width, $text)
    {
        $lines = $this->getStringLines($width, $text);
        return count($lines) * 4; // 4mm per line
    }

    /**
     * Get the number of lines a string will take in a given width
     */
    private function getStringLines($width, $text)
    {
        $words = explode(' ', $text);
        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            $testLine = $currentLine . ($currentLine ? ' ' : '') . $word;
            if ($this->GetStringWidth($testLine) <= $width) {
                $currentLine = $testLine;
            } else {
                if ($currentLine) {
                    $lines[] = $currentLine;
                    $currentLine = $word;
                } else {
                    // Word is too long for the cell, truncate it
                    $lines[] = substr($word, 0, floor($width / $this->GetStringWidth('a')));
                    $currentLine = '';
                }
            }
        }

        if ($currentLine) {
            $lines[] = $currentLine;
        }

        return $lines;
    }

    /**
     * Create a professional summary box
     */
    public function addSummaryBox($title, $data, $columns = 2)
    {
        $this->SetFont($this->defaultFontFamily, 'B', 12);
        $this->SetFillColor(245, 245, 245);
        $this->Cell(0, 8, $title, 0, 1, 'L');
        $this->Ln(2);

        $this->SetFont($this->defaultFontFamily, '', 10);
        $colWidth = 190 / $columns; // 190mm available width divided by columns

        $i = 0;
        foreach ($data as $label => $value) {
            if ($i % $columns == 0 && $i > 0) {
                $this->Ln(6);
            }

            $this->Cell($colWidth, 6, $label . ': ' . $value, 0, 0, 'L');

            if ($i % $columns == $columns - 1) {
                $this->Ln(6);
            }
            $i++;
        }

        if ($i % $columns != 0) {
            $this->Ln(6);
        }
        $this->Ln(3);
    }

    /**
     * Create a professional chart placeholder (for future chart integration)
     */
    public function addChartPlaceholder($title, $width = 180, $height = 80)
    {
        $this->SetFont($this->defaultFontFamily, 'B', 12);
        $this->Cell(0, 8, $title, 0, 1, 'L');
        $this->Ln(2);

        $this->SetFillColor(248, 248, 248);
        $this->SetDrawColor(200, 200, 200);
        $this->Rect($this->GetX(), $this->GetY(), $width, $height, 'DF');

        $this->SetFont($this->defaultFontFamily, 'I', 10);
        $this->SetTextColor(128);
        $this->SetXY($this->GetX(), $this->GetY() + $height/2 - 5);
        $this->Cell($width, 10, 'Chart visualization would appear here', 0, 1, 'C');

        $this->SetY($this->GetY() + $height + 5);
    }

    // --- Override base methods to preserve font defaults ---

    public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '', $stretch = 0, $ignore_min_height = false, $calign = 'T', $valign = 'M')
    {
        parent::Cell($w, $h, $txt, $border, $ln, $align, $fill, $link, $stretch, $ignore_min_height, $calign, $valign);
    }

    public function MultiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false, $ln = 1, $x = '', $y = '', $reseth = true, $stretch = 0, $ishtml = false, $autopadding = true, $maxh = 0, $valign = 'T', $fitcell = false)
    {
        parent::MultiCell($w, $h, $txt, $border, $align, $fill, $ln, $x, $y, $reseth, $stretch, $ishtml, $autopadding, $maxh, $valign, $fitcell);
    }

    public function Write($h, $txt, $link = '', $fill = false, $align = '', $ln = false, $stretch = 0, $firstline = false, $firstblock = false, $maxh = 0, $wadj = 0, $margin = '')
    {
        parent::Write($h, $txt, $link, $fill, $align, $ln, $stretch, $firstline, $firstblock, $maxh, $wadj, $margin);
    }

    public function getDefaultFontFamily()
    {
        return $this->defaultFontFamily;
    }

    public function setThermalDefaults(float $width = 80, float $height = 200): void
    {
        // Page format: custom width, height in mm
        $pageLayout = [$width, $height];
        $this->AddPage($this->CurOrientation, $pageLayout); // 'P' for portrait

        $this->setPrintHeader(false); // Often no header on thermal receipts
        $this->setPrintFooter(false); // Often no footer

        // Minimal margins
        $this->SetMargins(3, 3, 3); // Left, Top, Right
        $this->SetAutoPageBreak(TRUE, 5); // Small bottom margin

        // Use a simple, clear font
        $this->SetFont('dejavusansmono', '', 8); // Monospaced font, small size

        $this->setRTL(false); // For Arabic content
    }
}
