<?php

namespace App\Services;

use App\Models\Purchase;
use TCPDF;
use Illuminate\Support\Facades\Storage;

class PurchasePdfService
{
    private $pdf;
    private $primaryColor = [41, 128, 185]; // Professional blue
    private $headerBg = [52, 73, 94]; // Dark blue-gray
    private $lightGray = [245, 245, 245];
    private $borderColor = [220, 220, 220];

    /**
     * Generate a PDF report for a specific purchase
     *
     * @param Purchase $purchase
     * @return string PDF content
     */
    public function generatePurchasePdf(Purchase $purchase): string
    {
        // Load the purchase with relationships
        $purchase->load([
            'supplier',
            'user',
            'warehouse',
            'items.product.category',
            'items.product.stockingUnit',
            'items.product.sellableUnit'
        ]);

        // Create PDF instance
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // Set document information
        $this->pdf->SetCreator('Sales Management System');
        $this->pdf->SetAuthor($purchase->user ? $purchase->user->name : 'System');
        $this->pdf->SetTitle('Purchase Order #' . $purchase->id);
        $this->pdf->SetSubject('Purchase Order Details');

        // Remove default header/footer
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);

        // Set margins
        $this->pdf->SetMargins(15, 15, 15);
        $this->pdf->SetAutoPageBreak(true, 15);

        // Set font
        $this->pdf->SetFont('arial', '', 10);

        // Add a page
        $this->pdf->AddPage();

        // Build the report
        $this->addReportHeader($purchase);
        $this->addPurchaseInfo($purchase);
        $this->addItemsTable($purchase);
        $this->addSummary($purchase);
        $this->addFooter($purchase);

        // Return PDF content
        return $this->pdf->Output('purchase_order_' . $purchase->id . '.pdf', 'S');
    }

    /**
     * Add report header with title
     */
    private function addReportHeader(Purchase $purchase): void
    {
        // Company/System name (if you have settings, load from there)
        $this->pdf->SetFont('arial', 'B', 18);
        $this->pdf->SetTextColor(52, 73, 94);
        $this->pdf->Cell(0, 10, 'أمر شراء', 0, 1, 'C');

        // Purchase Order Number
        $this->pdf->SetFont('arial', 'B', 14);
        $this->pdf->SetTextColor(41, 128, 185);
        $this->pdf->Cell(0, 8, 'رقم الطلب: #' . $purchase->id, 0, 1, 'C');

        // Status badge
        $this->addStatusBadge($purchase->status);

        $this->pdf->Ln(5);
    }

    /**
     * Add status badge
     */
    private function addStatusBadge(string $status): void
    {
        $statusText = $this->getStatusText($status);
        $statusColors = $this->getStatusColors($status);

        $this->pdf->SetFont('arial', 'B', 11);
        $this->pdf->SetFillColor($statusColors['bg'][0], $statusColors['bg'][1], $statusColors['bg'][2]);
        $this->pdf->SetTextColor($statusColors['text'][0], $statusColors['text'][1], $statusColors['text'][2]);

        // Calculate width for centered badge
        $badgeWidth = 40;
        $x = ($this->pdf->getPageWidth() - $badgeWidth) / 2;
        $this->pdf->SetX($x);
        $this->pdf->Cell($badgeWidth, 7, $statusText, 0, 1, 'C', true);

        // Reset colors
        $this->pdf->SetTextColor(0, 0, 0);
    }

    /**
     * Add purchase information section
     */
    private function addPurchaseInfo(Purchase $purchase): void
    {
        // Section header
        $this->pdf->SetFont('arial', 'B', 12);
        $this->pdf->SetFillColor($this->headerBg[0], $this->headerBg[1], $this->headerBg[2]);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->Cell(0, 8, 'معلومات الطلب', 0, 1, 'R', true);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->Ln(2);

        // Info grid - 2 columns
        $colWidth = ($this->pdf->getPageWidth() - 30) / 2;
        $rowHeight = 7;

        $this->pdf->SetFont('arial', '', 10);

        // Row 1: Supplier | Purchase Date
        $this->addInfoRow(
            'المورد',
            $purchase->supplier ? $purchase->supplier->name : 'غير محدد',
            'تاريخ الشراء',
            $purchase->purchase_date,
            $colWidth,
            $rowHeight
        );

        // Row 2: Warehouse | Reference Number
        $this->addInfoRow(
            'المستودع',
            $purchase->warehouse ? $purchase->warehouse->name : 'المستودع الرئيسي',
            'رقم المرجع',
            $purchase->reference_number ?: '---',
            $colWidth,
            $rowHeight
        );

        // Row 3: Created By | Created At
        $this->addInfoRow(
            'تم الإنشاء بواسطة',
            $purchase->user ? $purchase->user->name : 'النظام',
            'تاريخ الإنشاء',
            $purchase->created_at->format('Y-m-d H:i'),
            $colWidth,
            $rowHeight
        );

        // Notes (if exists) - full width
        if ($purchase->notes) {
            $this->pdf->Ln(1);
            $this->pdf->SetFont('arial', 'B', 9);
            $this->pdf->SetFillColor($this->lightGray[0], $this->lightGray[1], $this->lightGray[2]);
            $this->pdf->Cell(30, 6, 'ملاحظات:', 1, 0, 'R', true);
            $this->pdf->SetFont('arial', '', 9);
            $this->pdf->MultiCell(0, 6, $purchase->notes, 1, 'R', false, 1);
        }

        $this->pdf->Ln(5);
    }

    /**
     * Add info row with 2 columns
     */
    private function addInfoRow(string $label1, string $value1, string $label2, string $value2, float $colWidth, float $height): void
    {
        $labelWidth = $colWidth * 0.35;
        $valueWidth = $colWidth * 0.65;

        // Left column (label)
        $this->pdf->SetFont('arial', 'B', 9);
        $this->pdf->SetFillColor($this->lightGray[0], $this->lightGray[1], $this->lightGray[2]);
        $this->pdf->Cell($labelWidth, $height, $label1 . ':', 1, 0, 'R', true);

        // Left column (value)
        $this->pdf->SetFont('arial', '', 9);
        $this->pdf->Cell($valueWidth, $height, $value1, 1, 0, 'R');

        // Right column (label)
        $this->pdf->SetFont('arial', 'B', 9);
        $this->pdf->SetFillColor($this->lightGray[0], $this->lightGray[1], $this->lightGray[2]);
        $this->pdf->Cell($labelWidth, $height, $label2 . ':', 1, 0, 'R', true);

        // Right column (value)
        $this->pdf->SetFont('arial', '', 9);
        $this->pdf->Cell($valueWidth, $height, $value2, 1, 1, 'R');
    }

    /**
     * Add items table
     */
    private function addItemsTable(Purchase $purchase): void
    {
        // Section header
        $this->pdf->SetFont('arial', 'B', 12);
        $this->pdf->SetFillColor($this->headerBg[0], $this->headerBg[1], $this->headerBg[2]);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->Cell(0, 8, 'الأصناف', 0, 1, 'R', true);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->Ln(2);

        // Table header
        $this->pdf->SetFont('arial', 'B', 9);
        $this->pdf->SetFillColor($this->primaryColor[0], $this->primaryColor[1], $this->primaryColor[2]);
        $this->pdf->SetTextColor(255, 255, 255);

        // Column widths
        $pageWidth = $this->pdf->getPageWidth() - 30;
        $widths = [
            'no' => $pageWidth * 0.06,      // #
            'product' => $pageWidth * 0.24, // Product
            'batch' => $pageWidth * 0.12,   // Batch
            'qty' => $pageWidth * 0.12,     // Quantity
            'cost' => $pageWidth * 0.12,    // Unit Cost
            'total' => $pageWidth * 0.14,   // Total
            'sale' => $pageWidth * 0.12,    // Sale Price
            'expiry' => $pageWidth * 0.08,  // Expiry
        ];

        $this->pdf->Cell($widths['no'], 7, '#', 1, 0, 'C', true);
        $this->pdf->Cell($widths['product'], 7, 'المنتج', 1, 0, 'C', true);
        $this->pdf->Cell($widths['batch'], 7, 'رقم الدفعة', 1, 0, 'C', true);
        $this->pdf->Cell($widths['qty'], 7, 'الكمية', 1, 0, 'C', true);
        $this->pdf->Cell($widths['cost'], 7, 'سعر الوحدة', 1, 0, 'C', true);
        $this->pdf->Cell($widths['total'], 7, 'الإجمالي', 1, 0, 'C', true);
        $this->pdf->Cell($widths['sale'], 7, 'سعر البيع', 1, 0, 'C', true);
        $this->pdf->Cell($widths['expiry'], 7, 'الصلاحية', 1, 1, 'C', true);

        // Reset colors for table body
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('arial', '', 8);

        // Table rows
        $totalAmount = 0;
        $totalQuantity = 0;
        $fill = false;

        foreach ($purchase->items as $index => $item) {
            $itemTotal = $item->quantity * $item->unit_cost;
            $totalAmount += $itemTotal;
            $totalQuantity += $item->quantity;

            // Alternate row colors
            if ($fill) {
                $this->pdf->SetFillColor(250, 250, 250);
            } else {
                $this->pdf->SetFillColor(255, 255, 255);
            }

            // Product name with unit
            $productName = $item->product ? $item->product->name : 'منتج محذوف';
            $unit = '';
            if ($item->product && $item->product->stockingUnit) {
                $unit = ' (' . $item->product->stockingUnit->name . ')';
            }

            $this->pdf->Cell($widths['no'], 6, ($index + 1), 1, 0, 'C', true);
            $this->pdf->Cell($widths['product'], 6, $productName, 1, 0, 'C', true);
            $this->pdf->Cell($widths['batch'], 6, $item->batch_number ?: '---', 1, 0, 'C', true);
            $this->pdf->Cell($widths['qty'], 6, number_format($item->quantity) . $unit, 1, 0, 'C', true);
            $this->pdf->Cell($widths['cost'], 6, number_format($item->unit_cost, 2), 1, 0, 'C', true);
            $this->pdf->Cell($widths['total'], 6, number_format($itemTotal, 2), 1, 0, 'C', true);
            $this->pdf->Cell($widths['sale'], 6, $item->sale_price ? number_format($item->sale_price, 2) : '---', 1, 0, 'C', true);
            $this->pdf->Cell($widths['expiry'], 6, $item->expiry_date ? date('Y-m-d', strtotime($item->expiry_date)) : '---', 1, 1, 'C', true);

            $fill = !$fill;
        }

        $this->pdf->Ln(3);
    }

    /**
     * Add summary section
     */
    private function addSummary(Purchase $purchase): void
    {
        $totalAmount = 0;
        $totalItems = $purchase->items->count();
        $totalQuantity = 0;

        foreach ($purchase->items as $item) {
            $totalAmount += $item->quantity * $item->unit_cost;
            $totalQuantity += $item->quantity;
        }

        // Summary box
        $boxWidth = 80;
        $x = $this->pdf->getPageWidth() - 15 - $boxWidth;
        $this->pdf->SetX($x);

        // Header
        $this->pdf->SetFont('arial', 'B', 11);
        $this->pdf->SetFillColor($this->headerBg[0], $this->headerBg[1], $this->headerBg[2]);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->Cell($boxWidth, 7, 'الملخص', 1, 1, 'C', true);
        $this->pdf->SetTextColor(0, 0, 0);

        // Summary rows
        $this->pdf->SetFont('arial', '', 10);
        $labelWidth = $boxWidth * 0.5;
        $valueWidth = $boxWidth * 0.5;

        // Total Items
        $this->pdf->SetX($x);
        $this->pdf->SetFillColor($this->lightGray[0], $this->lightGray[1], $this->lightGray[2]);
        $this->pdf->Cell($labelWidth, 6, 'عدد الأصناف:', 1, 0, 'R', true);
        $this->pdf->SetFont('arial', 'B', 10);
        $this->pdf->Cell($valueWidth, 6, number_format($totalItems), 1, 1, 'C');

        // Total Quantity
        $this->pdf->SetX($x);
        $this->pdf->SetFont('arial', '', 10);
        $this->pdf->Cell($labelWidth, 6, 'إجمالي الكمية:', 1, 0, 'R', true);
        $this->pdf->SetFont('arial', 'B', 10);
        $this->pdf->Cell($valueWidth, 6, number_format($totalQuantity), 1, 1, 'C');

        // Total Amount
        $this->pdf->SetX($x);
        $this->pdf->SetFont('arial', '', 10);
        $this->pdf->SetFillColor($this->primaryColor[0], $this->primaryColor[1], $this->primaryColor[2]);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->Cell($labelWidth, 7, 'المبلغ الإجمالي:', 1, 0, 'R', true);
        $this->pdf->SetFont('arial', 'B', 11);
        $this->pdf->Cell($valueWidth, 7, number_format($totalAmount, 2), 1, 1, 'C', true);
        $this->pdf->SetTextColor(0, 0, 0);
    }

    /**
     * Add footer with page number and generation info
     */
    private function addFooter(Purchase $purchase): void
    {
        // Position at bottom
        $this->pdf->SetY(-20);

        // Separator line
        $this->pdf->SetDrawColor($this->borderColor[0], $this->borderColor[1], $this->borderColor[2]);
        $this->pdf->Line(15, $this->pdf->GetY(), $this->pdf->getPageWidth() - 15, $this->pdf->GetY());
        $this->pdf->Ln(3);

        // Footer text
        $this->pdf->SetFont('arial', 'I', 8);
        $this->pdf->SetTextColor(128, 128, 128);

        $footerText = 'تم الإنشاء بواسطة نظام إدارة المبيعات - ' . date('Y-m-d H:i:s');
        $this->pdf->Cell(0, 5, $footerText, 0, 1, 'C');

        // Page number
        $pageNum = 'صفحة ' . $this->pdf->getAliasNumPage() . ' من ' . $this->pdf->getAliasNbPages();
        $this->pdf->Cell(0, 5, $pageNum, 0, 1, 'C');
    }

    /**
     * Get status text in Arabic
     */
    private function getStatusText(string $status): string
    {
        return match ($status) {
            'received' => 'مستلم',
            'pending' => 'قيد الانتظار',
            'ordered' => 'مطلوب',
            default => $status,
        };
    }

    /**
     * Get status colors
     */
    private function getStatusColors(string $status): array
    {
        return match ($status) {
            'received' => [
                'bg' => [212, 237, 218],   // Light green
                'text' => [21, 87, 36],     // Dark green
            ],
            'pending' => [
                'bg' => [255, 243, 205],    // Light yellow
                'text' => [133, 100, 4],    // Dark yellow
            ],
            'ordered' => [
                'bg' => [209, 236, 241],    // Light blue
                'text' => [12, 84, 96],     // Dark blue
            ],
            default => [
                'bg' => [245, 245, 245],    // Light gray
                'text' => [0, 0, 0],        // Black
            ],
        };
    }
}
