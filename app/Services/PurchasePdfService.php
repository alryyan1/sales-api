<?php

namespace App\Services;

use App\Models\Purchase;
use TCPDF;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Professional PDF Report Generator for Purchase Orders
 * 
 * This service generates high-quality, professional PDF documents for purchase orders
 * with support for branding, RTL text, and comprehensive formatting.
 * 
 * @package App\Services
 * @author Sales Management System
 * @version 2.0.0
 */
class PurchasePdfService
{
    /**
     * @var TCPDF PDF instance
     */
    private $pdf;

    // ============================================
    // COLOR SCHEME CONSTANTS
    // ============================================

    /** @var array Primary brand color (Professional Blue) */
    private const COLOR_PRIMARY = [41, 128, 185];

    /** @var array Header background (Dark Blue-Gray) */
    private const COLOR_HEADER_BG = [52, 73, 94];

    /** @var array Light gray for backgrounds */
    private const COLOR_LIGHT_GRAY = [245, 245, 245];

    /** @var array Border color */
    private const COLOR_BORDER = [220, 220, 220];

    /** @var array Success/Received status color */
    private const COLOR_SUCCESS = [212, 237, 218];
    private const COLOR_SUCCESS_TEXT = [21, 87, 36];

    /** @var array Warning/Pending status color */
    private const COLOR_WARNING = [255, 243, 205];
    private const COLOR_WARNING_TEXT = [133, 100, 4];

    /** @var array Info/Ordered status color */
    private const COLOR_INFO = [209, 236, 241];
    private const COLOR_INFO_TEXT = [12, 84, 96];

    // ============================================
    // LAYOUT CONSTANTS
    // ============================================

    /** @var int Page margin (mm) */
    private const MARGIN = 15;

    /** @var int Header height (mm) */
    private const HEADER_HEIGHT = 40;

    /** @var int Footer height (mm) */
    private const FOOTER_HEIGHT = 20;

    /** @var int Logo maximum width (mm) */
    private const LOGO_MAX_WIDTH = 50;

    /** @var int Logo maximum height (mm) */
    private const LOGO_MAX_HEIGHT = 20;

    // ============================================
    // TYPOGRAPHY CONSTANTS
    // ============================================

    /** @var string Main font family */
    private const FONT_FAMILY = 'dejavusans';

    /** @var int Title font size */
    private const FONT_SIZE_TITLE = 20;

    /** @var int Subtitle font size */
    private const FONT_SIZE_SUBTITLE = 14;

    /** @var int Heading font size */
    private const FONT_SIZE_HEADING = 12;

    /** @var int Body font size */
    private const FONT_SIZE_BODY = 10;

    /** @var int Small text font size */
    private const FONT_SIZE_SMALL = 8;

    /**
     * Generate a professional PDF report for a purchase order
     *
     * @param Purchase $purchase The purchase order to generate PDF for
     * @return string PDF content as string
     * @throws Exception If PDF generation fails
     */
    public function generatePurchasePdf(Purchase $purchase): string
    {
        try {
            // Load all required relationships
            $this->loadPurchaseRelationships($purchase);

            // Initialize PDF instance
            $this->initializePdf($purchase);

            // Add first page
            $this->pdf->AddPage();

            // Build the professional report
            $this->buildReport($purchase);

            // Return PDF content
            return $this->pdf->Output('purchase_order_' . $purchase->id . '.pdf', 'S');
        } catch (Exception $e) {
            Log::error('PDF Generation Failed', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new Exception('Failed to generate PDF: ' . $e->getMessage());
        }
    }

    /**
     * Load all required relationships for the purchase
     *
     * @param Purchase $purchase
     * @return void
     */
    private function loadPurchaseRelationships(Purchase $purchase): void
    {
        $purchase->load([
            'supplier',
            'user',
            'warehouse',
            'items' => function ($query) {
                $query->orderBy('id', 'desc'); // Sort items by ID descending (newest first)
            },
            'items.product.category',
            'items.product.stockingUnit',
            'items.product.sellableUnit'
        ]);
    }

    /**
     * Initialize the PDF instance with professional settings
     *
     * @param Purchase $purchase
     * @return void
     */
    private function initializePdf(Purchase $purchase): void
    {
        // Create new PDF instance (Portrait, mm, A4, Unicode support)
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // Set document metadata
        $this->pdf->SetCreator('Sales Management System');
        $this->pdf->SetAuthor($purchase->user?->name ?? 'System');
        $this->pdf->SetTitle('أمر شراء #' . $purchase->id);
        $this->pdf->SetSubject('تفاصيل أمر الشراء');
        $this->pdf->SetKeywords('purchase, order, invoice, ' . $purchase->id);

        // Disable default header and footer
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);

        // Set page margins
        $this->pdf->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $this->pdf->SetAutoPageBreak(true, self::MARGIN + 5);

        // Set default font
        $this->pdf->SetFont('arial', '', self::FONT_SIZE_BODY);

        // Enable RTL (Right-to-Left) for Arabic
        $this->pdf->setRTL(true);
    }

    /**
     * Build the complete report structure
     *
     * @param Purchase $purchase
     * @return void
     */
    private function buildReport(Purchase $purchase): void
    {
        // Professional header with logo and title
        $this->addProfessionalHeader($purchase);

        // Purchase information section
        $this->addPurchaseInformationSection($purchase);

        // Items table with professional styling
        $this->addProfessionalItemsTable($purchase);

        // Financial summary
        $this->addFinancialSummary($purchase);

        // Professional footer with terms
        $this->addProfessionalFooter($purchase);
    }

    /**
     * Add professional header with logo and company info
     *
     * @param Purchase $purchase
     * @return void
     */
    private function addProfessionalHeader(Purchase $purchase): void
    {
        // Add decorative top border
        $this->pdf->SetDrawColor(self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $this->pdf->SetLineWidth(1.5);
        $this->pdf->Line(self::MARGIN, self::MARGIN, $this->pdf->getPageWidth() - self::MARGIN, self::MARGIN);
        $this->pdf->Ln(5);

        // Company name/logo section
        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_TITLE);
        $this->pdf->SetTextColor(self::COLOR_HEADER_BG[0], self::COLOR_HEADER_BG[1], self::COLOR_HEADER_BG[2]);
        $this->pdf->Cell(0, 10, 'نظام إدارة المبيعات', 0, 1, 'C');

        // Document title
        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_SUBTITLE + 2);
        $this->pdf->SetTextColor(self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $this->pdf->Cell(0, 8, 'أمر شراء', 0, 1, 'C');

        // Order number with enhanced styling
        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_SUBTITLE);
        $this->pdf->SetTextColor(self::COLOR_HEADER_BG[0], self::COLOR_HEADER_BG[1], self::COLOR_HEADER_BG[2]);
        $this->pdf->Cell(0, 8, 'رقم الطلب: #' . str_pad($purchase->id, 6, '0', STR_PAD_LEFT), 0, 1, 'C');

        // Status badge with professional styling
        $this->addEnhancedStatusBadge($purchase->status);

        // Separator line
        $this->pdf->Ln(3);
        $this->pdf->SetDrawColor(self::COLOR_BORDER[0], self::COLOR_BORDER[1], self::COLOR_BORDER[2]);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Line(self::MARGIN, $this->pdf->GetY(), $this->pdf->getPageWidth() - self::MARGIN, $this->pdf->GetY());
        $this->pdf->Ln(5);
    }

    /**
     * Add enhanced status badge with icon-like appearance
     *
     * @param string $status
     * @return void
     */
    private function addEnhancedStatusBadge(string $status): void
    {
        $statusText = $this->getStatusText($status);
        $statusColors = $this->getStatusColors($status);

        $this->pdf->SetFont('arial', 'B', 12);
        $this->pdf->SetFillColor($statusColors['bg'][0], $statusColors['bg'][1], $statusColors['bg'][2]);
        $this->pdf->SetTextColor($statusColors['text'][0], $statusColors['text'][1], $statusColors['text'][2]);

        // Rounded badge appearance
        $badgeWidth = 50;
        $x = ($this->pdf->getPageWidth() - $badgeWidth) / 2;
        $this->pdf->SetX($x);
        $this->pdf->Cell($badgeWidth, 8, $statusText, 'LRTB', 1, 'C', true, '', 1, false, 'T', 'M');

        // Reset text color
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->Ln(2);
    }

    /**
     * Add purchase information section with professional grid layout
     *
     * @param Purchase $purchase
     * @return void
     */
    private function addPurchaseInformationSection(Purchase $purchase): void
    {
        // Section header with icon-like decoration
        $this->addSectionHeader('معلومات الطلب');

        // Information grid
        $pageWidth = $this->pdf->getPageWidth() - (self::MARGIN * 2);
        $colWidth = $pageWidth / 2;
        $rowHeight = 8;

        $this->pdf->SetFont('arial', '', self::FONT_SIZE_BODY);

        // Row 1: Supplier | Warehouse
        $this->addInfoGridRow(
            'المورد',
            $purchase->supplier?->name ?? 'غير محدد',
            'المستودع',
            $purchase->warehouse?->name ?? 'المستودع الرئيسي',
            $colWidth,
            $rowHeight
        );

        // Row 2: Purchase Date | Reference
        $this->addInfoGridRow(
            'تاريخ الشراء',
            $purchase->purchase_date ?? 'غير محدد',
            'رقم المرجع',
            $purchase->reference_number ?: '---',
            $colWidth,
            $rowHeight
        );

        // Row 3: Created By | Created At
        $this->addInfoGridRow(
            'تم الإنشاء بواسطة',
            $purchase->user?->name ?? 'النظام',
            'تاريخ الإنشاء',
            $purchase->created_at?->format('Y-m-d H:i') ?? 'غير متوفر',
            $colWidth,
            $rowHeight
        );

        // Notes section (if exists)
        if (!empty($purchase->notes)) {
            $this->pdf->Ln(2);
            $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_BODY);
            $this->pdf->SetFillColor(self::COLOR_LIGHT_GRAY[0], self::COLOR_LIGHT_GRAY[1], self::COLOR_LIGHT_GRAY[2]);
            $this->pdf->Cell(35, 7, 'ملاحظات:', 1, 0, 'C', true);

            $this->pdf->SetFont('arial', '', self::FONT_SIZE_BODY - 1);
            $this->pdf->MultiCell(0, 7, $purchase->notes, 1, 'R', false, 1);
        }

        $this->pdf->Ln(8);
    }

    /**
     * Add section header with professional styling
     *
     * @param string $title
     * @return void
     */
    private function addSectionHeader(string $title): void
    {
        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_HEADING);
        $this->pdf->SetFillColor(self::COLOR_HEADER_BG[0], self::COLOR_HEADER_BG[1], self::COLOR_HEADER_BG[2]);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->Cell(0, 10, $title, 0, 1, 'R', true);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->Ln(3);
    }

    /**
     * Add information grid row (2 columns)
     *
     * @param string $label1
     * @param string $value1
     * @param string $label2
     * @param string $value2
     * @param float $colWidth
     * @param float $height
     * @return void
     */
    private function addInfoGridRow(
        string $label1,
        string $value1,
        string $label2,
        string $value2,
        float $colWidth,
        float $height
    ): void {
        $labelWidth = $colWidth * 0.4;
        $valueWidth = $colWidth * 0.6;

        // Column 1 - Label
        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_SMALL + 1);
        $this->pdf->SetFillColor(self::COLOR_LIGHT_GRAY[0], self::COLOR_LIGHT_GRAY[1], self::COLOR_LIGHT_GRAY[2]);
        $this->pdf->Cell($labelWidth, $height, $label1 . ':', 1, 0, 'C', true);

        // Column 1 - Value
        $this->pdf->SetFont('arial', '', self::FONT_SIZE_BODY);
        $this->pdf->Cell($valueWidth, $height, $value1, 1, 0, 'R');

        // Column 2 - Label
        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_SMALL + 1);
        $this->pdf->SetFillColor(self::COLOR_LIGHT_GRAY[0], self::COLOR_LIGHT_GRAY[1], self::COLOR_LIGHT_GRAY[2]);
        $this->pdf->Cell($labelWidth, $height, $label2 . ':', 1, 0, 'C', true);

        // Column 2 - Value
        $this->pdf->SetFont('arial', '', self::FONT_SIZE_BODY);
        $this->pdf->Cell($valueWidth, $height, $value2, 1, 1, 'R');
    }

    /**
     * Add professional items table with enhanced styling
     *
     * @param Purchase $purchase
     * @return void
     */
    private function addProfessionalItemsTable(Purchase $purchase): void
    {
        // Section header
        $this->addSectionHeader('تفاصيل الأصناف');

        // Table header with gradient-like effect
        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_SMALL + 1);
        $this->pdf->SetFillColor(self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->SetDrawColor(self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $this->pdf->SetLineWidth(0.5);

        // Calculate column widths
        $pageWidth = $this->pdf->getPageWidth() - (self::MARGIN * 2);
        $widths = [
            'no' => $pageWidth * 0.05,      // #
            'product' => $pageWidth * 0.26, // Product
            'batch' => $pageWidth * 0.11,   // Batch
            'qty' => $pageWidth * 0.11,     // Quantity
            'cost' => $pageWidth * 0.12,    // Unit Cost
            'sale' => $pageWidth * 0.12,    // Sale Price
            'expiry' => $pageWidth * 0.10,  // Expiry
            'total' => $pageWidth * 0.13,   // Total
        ];

        // Table headers
        $this->pdf->Cell($widths['no'], 8, '#', 1, 0, 'C', true);
        $this->pdf->Cell($widths['product'], 8, 'المنتج', 1, 0, 'C', true);
        $this->pdf->Cell($widths['batch'], 8, 'رقم الدفعة', 1, 0, 'C', true);
        $this->pdf->Cell($widths['qty'], 8, 'الكمية', 1, 0, 'C', true);
        $this->pdf->Cell($widths['cost'], 8, 'سعر الوحدة', 1, 0, 'C', true);
        $this->pdf->Cell($widths['sale'], 8, 'سعر البيع', 1, 0, 'C', true);
        $this->pdf->Cell($widths['expiry'], 8, 'الصلاحية', 1, 0, 'C', true);
        $this->pdf->Cell($widths['total'], 8, 'الإجمالي', 1, 1, 'C', true);

        // Reset colors for body
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('arial', '', self::FONT_SIZE_SMALL);
        $this->pdf->SetDrawColor(self::COLOR_BORDER[0], self::COLOR_BORDER[1], self::COLOR_BORDER[2]);
        $this->pdf->SetLineWidth(0.2);

        // Table rows with alternating colors
        $fill = false;
        foreach ($purchase->items as $index => $item) {
            // Alternate row background
            $fillColor = $fill ? [252, 252, 252] : [255, 255, 255];
            $this->pdf->SetFillColor($fillColor[0], $fillColor[1], $fillColor[2]);

            $itemTotal = $item->quantity * $item->unit_cost;
            $productName = $item->product?->name ?? 'منتج محذوف';
            $unit = $item->product?->stockingUnit?->name ?? '';

            // Row data
            $this->pdf->Cell($widths['no'], 7, ($index + 1), 1, 0, 'C', true);
            $this->pdf->Cell($widths['product'], 7, $productName, 1, 0, 'R', true);
            $this->pdf->Cell($widths['batch'], 7, $item->batch_number ?: '---', 1, 0, 'C', true);
            $this->pdf->Cell($widths['qty'], 7, number_format($item->quantity) . ($unit ? " $unit" : ''), 1, 0, 'C', true);
            $this->pdf->Cell($widths['cost'], 7, number_format($item->unit_cost, 2), 1, 0, 'C', true);
            $this->pdf->Cell($widths['sale'], 7, $item->sale_price ? number_format($item->sale_price, 2) : '---', 1, 0, 'C', true);
            $this->pdf->Cell($widths['expiry'], 7, $item->expiry_date ? date('Y-m-d', strtotime($item->expiry_date)) : '---', 1, 0, 'C', true);

            // Total with bold font
            $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_SMALL);
            $this->pdf->Cell($widths['total'], 7, number_format($itemTotal, 2), 1, 1, 'C', true);
            $this->pdf->SetFont('arial', '', self::FONT_SIZE_SMALL);

            $fill = !$fill;
        }

        $this->pdf->Ln(5);
    }

    /**
     * Add financial summary with professional card-like layout
     *
     * @param Purchase $purchase
     * @return void
     */
    private function addFinancialSummary(Purchase $purchase): void
    {
        // Calculate totals
        $totalAmount = 0;
        $totalQuantity = 0;
        $totalItems = $purchase->items->count();

        foreach ($purchase->items as $item) {
            $totalAmount += $item->quantity * $item->unit_cost;
            $totalQuantity += $item->quantity;
        }

        // Summary card positioned on the right
        $cardWidth = 90;
        $x = $this->pdf->getPageWidth() - self::MARGIN - $cardWidth;

        // Card header
        $this->pdf->SetX($x);
        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_HEADING);
        $this->pdf->SetFillColor(self::COLOR_HEADER_BG[0], self::COLOR_HEADER_BG[1], self::COLOR_HEADER_BG[2]);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->Cell($cardWidth, 9, 'الملخص المالي', 1, 1, 'C', true);
        $this->pdf->SetTextColor(0, 0, 0);

        // Summary rows
        $labelWidth = $cardWidth * 0.5;
        $valueWidth = $cardWidth * 0.5;

        // Items count
        $this->pdf->SetX($x);
        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_BODY - 1);
        $this->pdf->SetFillColor(self::COLOR_LIGHT_GRAY[0], self::COLOR_LIGHT_GRAY[1], self::COLOR_LIGHT_GRAY[2]);
        $this->pdf->Cell($labelWidth, 7, 'عدد الأصناف:', 1, 0, 'R', true);
        $this->pdf->SetFont('arial', '', self::FONT_SIZE_BODY);
        $this->pdf->Cell($valueWidth, 7, number_format($totalItems), 1, 1, 'C');

        // Total quantity
        $this->pdf->SetX($x);
        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_BODY - 1);
        $this->pdf->Cell($labelWidth, 7, 'إجمالي الكمية:', 1, 0, 'R', true);
        $this->pdf->SetFont('arial', '', self::FONT_SIZE_BODY);
        $this->pdf->Cell($valueWidth, 7, number_format($totalQuantity), 1, 1, 'C');

        // Grand total with emphasis
        $this->pdf->SetX($x);
        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_BODY);
        $this->pdf->SetFillColor(self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->Cell($labelWidth, 9, 'المبلغ الإجمالي:', 1, 0, 'R', true);
        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_HEADING - 1);
        $this->pdf->Cell($valueWidth, 9, number_format($totalAmount, 2) . ' ج.س', 1, 1, 'C', true);
        $this->pdf->SetTextColor(0, 0, 0);
    }

    /**
     * Add professional footer with terms and page numbers
     *
     * @param Purchase $purchase
     * @return void
     */
    private function addProfessionalFooter(Purchase $purchase): void
    {
        // Position footer at bottom
        $this->pdf->SetY(-25);

        // Separator line
        $this->pdf->SetDrawColor(self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $this->pdf->SetLineWidth(0.8);
        $this->pdf->Line(self::MARGIN, $this->pdf->GetY(), $this->pdf->getPageWidth() - self::MARGIN, $this->pdf->GetY());
        $this->pdf->Ln(4);

        // Footer information
        $this->pdf->SetFont('arial', 'I', self::FONT_SIZE_SMALL);
        $this->pdf->SetTextColor(100, 100, 100);

        $footerText = 'تم الإنشاء بواسطة نظام إدارة المبيعات المتطور';
        $this->pdf->Cell(0, 4, $footerText, 0, 1, 'C');

        $dateText = 'تاريخ الطباعة: ' . date('Y-m-d H:i:s');
        $this->pdf->Cell(0, 4, $dateText, 0, 1, 'C');

        // Page number
        $pageNum = 'صفحة ' . $this->pdf->getAliasNumPage() . ' من ' . $this->pdf->getAliasNbPages();
        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_SMALL);
        $this->pdf->Cell(0, 4, $pageNum, 0, 1, 'C');
    }

    /**
     * Get localized status text in Arabic
     *
     * @param string $status
     * @return string
     */
    private function getStatusText(string $status): string
    {
        return match ($status) {
            'received' => 'مستلم ✓',
            'pending' => 'قيد الانتظار',
            'ordered' => 'مطلوب',
            'cancelled' => 'ملغي',
            default => ucfirst($status),
        };
    }

    /**
     * Get status-specific colors for badges
     *
     * @param string $status
     * @return array{bg: array<int>, text: array<int>}
     */
    private function getStatusColors(string $status): array
    {
        return match ($status) {
            'received' => [
                'bg' => self::COLOR_SUCCESS,
                'text' => self::COLOR_SUCCESS_TEXT,
            ],
            'pending' => [
                'bg' => self::COLOR_WARNING,
                'text' => self::COLOR_WARNING_TEXT,
            ],
            'ordered' => [
                'bg' => self::COLOR_INFO,
                'text' => self::COLOR_INFO_TEXT,
            ],
            'cancelled' => [
                'bg' => [248, 215, 218],
                'text' => [114, 28, 36],
            ],
            default => [
                'bg' => self::COLOR_LIGHT_GRAY,
                'text' => [0, 0, 0],
            ],
        };
    }
}
