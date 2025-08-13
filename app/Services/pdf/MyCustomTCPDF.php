<?php // app/Services/Pdf/MyCustomTCPDF.php

namespace App\Services\Pdf;

use TCPDF; // Directly use the base TCPDF class

// Define K_PATH_MAIN, K_PATH_URL, K_PATH_FONTS, K_PATH_IMAGES if not already defined globally or by TCPDF config
// This is often needed when using TCPDF outside its standard integration in some frameworks.
// However, Composer's autoloading and TCPDF's internal path resolution might handle some of these.
// Test without first, add if you get "constant not defined" errors.
// if (!defined('K_PATH_IMAGES')) {
//     define('K_PATH_IMAGES', public_path('images/pdf_assets/')); // Example for images
// }

class MyCustomTCPDF extends TCPDF
{
    protected $companyName;
    protected $companyAddress;
    protected $companyLogoUrl;
    protected $defaultFontFamily = 'arial'; // Changed to dejavusans for better Arabic support
    protected $defaultFontSize = 10;
    protected $defaultFontBold = 'arial'; // Bold variant for Arabic support

    public function __construct($orientation = 'P', $unit = 'mm', $format = 'A4', $unicode = true, $encoding = 'UTF-8', $diskcache = false, $pdfa = false)
    {
        parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache, $pdfa);

        $settings = (new \App\Services\SettingsService())->getAll();
        $this->companyName = $settings['company_name'] ?? 'Your Company';
        $this->companyAddress = $settings['company_address'] ?? '';
        $this->companyLogoUrl = $settings['company_logo_url'] ?? null;

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
            // 'w_page' => 'صفحة', // Example for internal TCPDF string localization
        ]);

        // Set default RTL mode for text. You can toggle it off for specific LTR sections.
        $this->setRTL(true);
    }

    // Override Header() method
    public function Header()
    {
        // Try to place logo on the left if available
        $logoPlaced = false;
        if (!empty($this->companyLogoUrl)) {
            try {
                $x = 15;
                $y = 10;
                $w = 24;
                $logoPath = $this->companyLogoUrl;
                if (is_string($logoPath)) {
                    $path = parse_url($logoPath, PHP_URL_PATH) ?: '';
                    if ($path) {
                        $storagePos = strpos($path, '/storage/');
                        if ($storagePos !== false) {
                            $relative = substr($path, $storagePos + strlen('/storage/'));
                            $candidate = public_path('storage/' . ltrim($relative, '/'));
                            if (file_exists($candidate)) {
                                $logoPath = $candidate;
                            }
                        }
                    }
                }
                @$this->Image($logoPath, $x +20, $y, $w, 0, '', '', 'T', false, 300, '', false, false, 0, false, false, false);
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
        // $this->Line($this->GetX(), $this->GetY() + 2, $this->getPageWidth() - $this->GetX(), $this->GetY() + 2);
        $this->Ln(5);
    }

    // Override Footer() method
    public function Footer()
    {
        $this->SetY(-15);
        // Footer font already set by setFooterFont in constructor
        // $this->SetFont($this->defaultFontFamily, 'I', 8);
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
            
            $x = $this->GetX();
            $y = $this->GetY();
            
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

    // --- Optional: Override methods to use default font if not specified ---
    // This ensures your default font is used by Cell, MultiCell, Write, etc.,
    // unless a different font is explicitly set right before calling them.

    public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '', $stretch = 0, $ignore_min_height = false, $calign = 'T', $valign = 'M')
    {
        // If current font family is not set or different from default, set default.
        // This is a bit aggressive; usually, SetFont in constructor is enough.
        // if ($this->FontFamily != $this->defaultFontFamily) {
        //    $this->SetFont($this->defaultFontFamily, $this->FontStyle, $this->FontSizePt);
        // }
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
        // Or a simple sans-serif: $this->SetFont('dejavusans', '', 8);

        $this->setRTL(true); // For Arabic content
    }
}
