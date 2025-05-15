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
    protected $defaultFontFamily = 'arial'; // <-- DEFINE YOUR DEFAULT FONT HERE
    protected $defaultFontSize = 10;
    protected $defaultFontBold = 'dejavusansb'; // Example if you have a specific bold variant defined

    public function __construct($orientation = 'P', $unit = 'mm', $format = 'A4', $unicode = true, $encoding = 'UTF-8', $diskcache = false, $pdfa = false)
    {
        parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache, $pdfa);

        $this->companyName = config('app_settings.company_name', 'Your Company');
        $this->companyAddress = config('app_settings.company_address', '');

        $this->SetCreator('Your App Name');
        $this->SetAuthor($this->companyName);
        $this->SetSubject('Application Report');
        // $this->AddFont()
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
        // Logo example
        // $image_file = storage_path('app/public/logo.png'); // Example path
        // if (file_exists($image_file)) {
        //     $this->Image($image_file, 180, 6, 20, '', 'PNG', '', 'T', false, 300, 'R', false, false, 0);
        // }

        $this->SetY(10);
        // Use default font for header, perhaps bold
        $this->SetFont($this->defaultFontBold ?: $this->defaultFontFamily, 'B', 12);
        $this->Cell(0, 10, $this->companyName, 0, 1, 'C', 0, '', 0, false, 'M', 'M');

        $this->SetFont($this->defaultFontFamily, '', 9);
        $this->Cell(0, 8, $this->companyAddress, 0, 1, 'C', 0, '', 0, false, 'M', 'M');

        $this->Line($this->GetX(), $this->GetY() + 2, $this->getPageWidth() - $this->GetX(), $this->GetY() + 2);
        $this->Ln(5); // Space after header line
    }

    // Override Footer() method
    public function Footer()
    {
        $this->SetY(-15);
        // Footer font already set by setFooterFont in constructor
        // $this->SetFont($this->defaultFontFamily, 'I', 8);
        $this->Cell(0, 10, 'صفحة ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
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
