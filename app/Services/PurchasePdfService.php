<?php

namespace App\Services;

use App\Models\Purchase;
use App\Services\Pdf\PdfHeaderRenderer;
use TCPDF;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * PDF Report Generator for Purchase Orders.
 *
 * Styled to match the supplier statement PDFs (SupplierSummaryPdfService /
 * SupplierLedgerPdfService): black & white palette, double-rule header,
 * bordered white-fill tables, and a matching footer.
 */
class PurchasePdfService
{
    private TCPDF $pdf;
    private PdfHeaderRenderer $renderer;

    // ── Palette (black & white only) ─────────────────────────────────────────
    private const BLACK  = [0,   0,   0];
    private const MID    = [120, 120, 120];
    private const WHITE  = [255, 255, 255];
    private const BORDER = [160, 160, 160];
    private const TEXT   = [30,  30,  30];

    // ── Layout ────────────────────────────────────────────────────────────────
    private const M     = 15;  // page margin (mm)
    private const RH    = 7;   // standard table row height (mm)
    private const RH_IMG = 14; // items table row height (mm), taller for product image
    private const F     = 'arial';

    /**
     * Generate a PDF report for a purchase order.
     *
     * @param Purchase $purchase The purchase order to generate PDF for
     * @return string PDF content as string
     * @throws Exception If PDF generation fails
     */
    public function generatePurchasePdf(Purchase $purchase): string
    {
        try {
            $this->loadPurchaseRelationships($purchase);

            $this->renderer = new PdfHeaderRenderer('purchase');
            $this->initPdf($purchase);
            $this->pdf->AddPage();
            $this->renderer->render($this->pdf);

            $this->drawHeader($purchase);
            $this->drawPurchaseBox($purchase);
            $this->drawItemsTable($purchase);
            $this->drawSummary($purchase);
            $this->drawFooter();

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

    // ─────────────────────────────────────────────────────────────────────────
    // Data
    // ─────────────────────────────────────────────────────────────────────────

    private function loadPurchaseRelationships(Purchase $purchase): void
    {
        $purchase->load([
            'supplier',
            'user',
            'warehouse',
            'items' => function ($query) {
                $query->orderBy('id', 'desc');
            },
            'items.product.category',
            'items.product.stockingUnit',
            'items.product.sellableUnit'
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Init
    // ─────────────────────────────────────────────────────────────────────────

    private function initPdf(Purchase $purchase): void
    {
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->setPrintHeader(false);
        $this->pdf->SetCreator('Sales Management System');
        $this->pdf->SetAuthor($purchase->user?->name ?? 'System');
        $this->pdf->SetTitle('فاتورة مشتريات #' . $purchase->id);
        $this->pdf->SetSubject('تفاصيل أمر الشراء');
        $this->pdf->SetKeywords('purchase, order, invoice, ' . $purchase->id);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetMargins(self::M, $this->renderer->getTopMargin(), self::M);
        $this->pdf->SetAutoPageBreak(true, 25);
        $this->pdf->SetFont(self::F, '', 9);
        $this->pdf->setRTL(false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section: Header
    // ─────────────────────────────────────────────────────────────────────────

    private function drawHeader(Purchase $purchase): void
    {
        $W = $this->W();
        $y = max($this->pdf->GetY(), self::M);

        // Heavy top rule
        $this->pdf->SetDrawColor(...self::BLACK);
        $this->pdf->SetLineWidth(1.2);
        $this->pdf->Line(self::M, $y, self::M + $W, $y);

        // Thin rule 2.5mm below
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Line(self::M, $y + 2.5, self::M + $W, $y + 2.5);

        $this->pdf->SetY($y + 7);

        // Main title
        $this->pdf->SetFont(self::F, 'B', 16);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->Cell($W, 10, 'فاتورة مشتريات', 0, 1, 'C');

        // System sub-title
        $this->pdf->SetFont(self::F, '', 9);
        $this->pdf->SetTextColor(...self::MID);
        $this->pdf->Cell($W, 5, 'نظام إدارة المبيعات', 0, 1, 'C');

        $this->pdf->Ln(3);

        // Meta line: order number | issue date
        $this->pdf->SetFont(self::F, '', 8);
        $this->pdf->SetTextColor(...self::TEXT);
        $this->pdf->Cell($W / 2, 5, 'رقم الطلب: #' . str_pad((string) $purchase->id, 6, '0', STR_PAD_LEFT), 0, 0, 'L');
        $this->pdf->Cell($W / 2, 5, 'تاريخ الإصدار: ' . now()->format('Y-m-d'), 0, 1, 'R');

        $this->pdf->Ln(3);

        // Thin rule then heavy rule
        $this->pdf->SetDrawColor(...self::BLACK);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Line(self::M, $this->pdf->GetY(), self::M + $W, $this->pdf->GetY());
        $this->pdf->SetLineWidth(1.0);
        $this->pdf->Line(self::M, $this->pdf->GetY() + 2.5, self::M + $W, $this->pdf->GetY() + 2.5);

        $this->pdf->Ln(8);
        $this->pdf->SetTextColor(...self::TEXT);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section: Purchase info
    // ─────────────────────────────────────────────────────────────────────────

    private function drawPurchaseBox(Purchase $purchase): void
    {
        $W      = $this->W();
        $colW   = $W / 2;
        $labelW = $colW * 0.40;
        $valW   = $colW * 0.60;
        $rowH   = 7;

        // Section heading
        $this->pdf->SetFont(self::F, 'B', 9);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Cell($W, 7, 'بيانات الطلب', 1, 1, 'C', true);

        $rows = [
            ['المستودع', $purchase->warehouse?->name ?? 'المستودع الرئيسي', 'المورد', $purchase->supplier?->name ?? 'غير محدد'],
            ['رقم المرجع', $purchase->reference_number ?: '---', 'تاريخ الشراء', $purchase->purchase_date ?? 'غير محدد'],
            ['تاريخ الإنشاء', $purchase->created_at?->format('Y-m-d H:i') ?? 'غير متوفر', 'تم الإنشاء بواسطة', $purchase->user?->name ?? 'النظام'],
        ];

        $this->pdf->SetFillColor(...self::WHITE);
        foreach ($rows as [$l1, $v1, $l2, $v2]) {
            $this->pdf->SetFont(self::F, 'B', 8.5);
            $this->pdf->SetTextColor(...self::TEXT);
            $this->pdf->Cell($labelW, $rowH, $l1 . ':', 1, 0, 'R', true);

            $this->pdf->SetFont(self::F, '', 8.5);
            $this->pdf->Cell($valW, $rowH, $v1, 1, 0, 'R', true);

            $this->pdf->SetFont(self::F, 'B', 8.5);
            $this->pdf->Cell($labelW, $rowH, $l2 . ':', 1, 0, 'R', true);

            $this->pdf->SetFont(self::F, '', 8.5);
            $this->pdf->Cell($valW, $rowH, $v2, 1, 1, 'R', true);
        }

        if (!empty($purchase->notes)) {
            $this->pdf->SetFont(self::F, 'B', 8.5);
            $this->pdf->SetTextColor(...self::TEXT);
            $this->pdf->Cell($labelW, $rowH, 'ملاحظات:', 1, 0, 'R', true);

            $this->pdf->SetFont(self::F, '', 8.5);
            $this->pdf->MultiCell($valW + $colW, $rowH, $purchase->notes, 1, 'R', true, 1);
        }

        $this->pdf->Ln(6);
        $this->pdf->SetTextColor(...self::TEXT);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section: Items table
    // ─────────────────────────────────────────────────────────────────────────

    private function drawItemsTable(Purchase $purchase): void
    {
        $W = $this->W();

        $w = [
            'no'      => $W * 0.05,
            'img'     => $W * 0.07,
            'product' => $W * 0.21,
            'batch'   => $W * 0.10,
            'qty'     => $W * 0.10,
            'cost'    => $W * 0.12,
            'sale'    => $W * 0.12,
            'expiry'  => $W * 0.10,
            'total'   => $W * 0.13,
        ];

        $this->drawItemsTableHeader($w);

        $items = $purchase->items;

        if ($items->isEmpty()) {
            $this->pdf->SetFont(self::F, '', 9);
            $this->pdf->SetFillColor(...self::WHITE);
            $this->pdf->SetTextColor(...self::MID);
            $this->pdf->SetDrawColor(...self::BORDER);
            $this->pdf->Cell($W, 12, 'لا توجد أصناف', 1, 1, 'C', true);
            return;
        }

        $this->pdf->SetLineWidth(0.2);

        $grandTotal = 0.0;

        foreach ($items as $index => $item) {
            if ($this->pdf->GetY() + self::RH_IMG > $this->pdf->getPageHeight() - 28) {
                $this->pdf->AddPage();
                $this->renderer->render($this->pdf);
                $this->drawItemsTableHeader($w);
            }

            $itemTotal   = $item->quantity * $item->unit_cost;
            $grandTotal += $itemTotal;

            $productName = $item->product?->name ?? 'منتج محذوف';
            $unit        = $item->product?->stockingUnit?->name ?? '';

            $this->pdf->SetFillColor(...self::WHITE);
            $this->pdf->SetDrawColor(...self::BORDER);

            $rowY = $this->pdf->GetY();
            $imgCellX = $this->pdf->GetX() + $w['no'];

            $this->pdf->SetFont(self::F, '', 7.5);
            $this->pdf->SetTextColor(...self::MID);
            $this->pdf->Cell($w['no'], self::RH_IMG, $index + 1, 1, 0, 'C', true);

            // Image cell placeholder (drawn below)
            $this->pdf->Cell($w['img'], self::RH_IMG, '', 1, 0, 'C', true);

            $this->pdf->SetFont(self::F, '', 8.5);
            $this->pdf->SetTextColor(...self::TEXT);
            $this->pdf->Cell($w['product'], self::RH_IMG, $productName, 1, 0, 'R', true);
            $this->pdf->Cell($w['batch'], self::RH_IMG, $item->batch_number ?: '---', 1, 0, 'C', true);
            $this->pdf->Cell($w['qty'], self::RH_IMG, number_format($item->quantity) . ($unit ? " $unit" : ''), 1, 0, 'C', true);
            $this->pdf->Cell($w['cost'], self::RH_IMG, number_format($item->unit_cost, 2), 1, 0, 'C', true);
            $this->pdf->Cell($w['sale'], self::RH_IMG, $item->sale_price ? number_format($item->sale_price, 2) : '---', 1, 0, 'C', true);
            $this->pdf->Cell($w['expiry'], self::RH_IMG, $item->expiry_date ? date('Y-m-d', strtotime($item->expiry_date)) : '---', 1, 0, 'C', true);

            $this->pdf->SetFont(self::F, 'B', 8.5);
            $this->pdf->SetTextColor(...self::BLACK);
            $this->pdf->Cell($w['total'], self::RH_IMG, number_format($itemTotal, 2), 1, 1, 'C', true);

            $imageUrl = $item->product?->image_url ?? null;
            if ($imageUrl) {
                $imgPath = $this->resolveProductImagePath($imageUrl);
                if ($imgPath) {
                    try {
                        $imgSize = self::RH_IMG - 2;
                        $imgXPos = $imgCellX + ($w['img'] - $imgSize) / 2;
                        $imgYPos = $rowY + (self::RH_IMG - $imgSize) / 2;
                        @$this->pdf->Image($imgPath, $imgXPos, $imgYPos, $imgSize, $imgSize, '', '', '', true, 150, '', false, false, 0, 'CM');
                    } catch (\Throwable $e) {
                        // Silently skip if image rendering fails
                    }
                }
            }
        }

        // Totals row — white fill, bold, full border
        $this->pdf->SetFont(self::F, 'B', 8.5);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.4);

        $labelWidth = $w['no'] + $w['img'] + $w['product'] + $w['batch'] + $w['qty'] + $w['cost'] + $w['sale'] + $w['expiry'];
        $this->pdf->Cell($labelWidth, self::RH, 'الإجمالي', 1, 0, 'R', true);
        $this->pdf->Cell($w['total'], self::RH, number_format($grandTotal, 2), 1, 1, 'C', true);

        $this->pdf->SetTextColor(...self::TEXT);
        $this->pdf->Ln(6);
    }

    private function drawItemsTableHeader(array $w): void
    {
        $this->pdf->SetFont(self::F, 'B', 8.5);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.3);

        $this->pdf->Cell($w['no'], 8, '#', 1, 0, 'C', true);
        $this->pdf->Cell($w['img'], 8, 'صورة', 1, 0, 'C', true);
        $this->pdf->Cell($w['product'], 8, 'المنتج', 1, 0, 'C', true);
        $this->pdf->Cell($w['batch'], 8, 'رقم الدفعة', 1, 0, 'C', true);
        $this->pdf->Cell($w['qty'], 8, 'الكمية', 1, 0, 'C', true);
        $this->pdf->Cell($w['cost'], 8, 'سعر الوحدة', 1, 0, 'C', true);
        $this->pdf->Cell($w['sale'], 8, 'سعر البيع', 1, 0, 'C', true);
        $this->pdf->Cell($w['expiry'], 8, 'الصلاحية', 1, 0, 'C', true);
        $this->pdf->Cell($w['total'], 8, 'الإجمالي', 1, 1, 'C', true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section: Financial summary
    // ─────────────────────────────────────────────────────────────────────────

    private function drawSummary(Purchase $purchase): void
    {
        $totalAmount = 0.0;
        $totalQuantity = 0;

        foreach ($purchase->items as $item) {
            $totalAmount += $item->quantity * $item->unit_cost;
            $totalQuantity += $item->quantity;
        }

        $W      = $this->W();
        $colW   = $W / 3;
        $labelW = $colW * 0.55;
        $valW   = $colW * 0.45;
        $rowH   = 8;

        // Section heading
        $this->pdf->SetFont(self::F, 'B', 9);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Cell($W, 7, 'الملخص المالي', 1, 1, 'C', true);

        $pairs = [
            ['عدد الأصناف', number_format($purchase->items->count())],
            ['إجمالي الكمية', number_format($totalQuantity)],
            ['المبلغ الإجمالي', number_format($totalAmount, 2) . ' ' . ($purchase->currency ?? 'SDG')],
        ];

        $this->pdf->SetFillColor(...self::WHITE);
        foreach ($pairs as [$label, $value]) {
            $this->pdf->Cell($labelW, $rowH, $label . ':', 1, 0, 'R', true);
            $this->pdf->Cell($valW, $rowH, $value, 1, 0, 'C', true);
        }
        $this->pdf->Ln($rowH);

        $this->pdf->SetTextColor(...self::TEXT);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section: Footer
    // ─────────────────────────────────────────────────────────────────────────

    private function drawFooter(): void
    {
        $W = $this->W();

        $this->pdf->SetAutoPageBreak(false);
        $this->pdf->SetY(-16);

        $this->pdf->SetDrawColor(...self::BLACK);
        $this->pdf->SetLineWidth(0.8);
        $this->pdf->Line(self::M, $this->pdf->GetY(), self::M + $W, $this->pdf->GetY());
        $this->pdf->SetLineWidth(0.2);
        $this->pdf->Line(self::M, $this->pdf->GetY() + 1.5, self::M + $W, $this->pdf->GetY() + 1.5);
        $this->pdf->Ln(3.5);

        $this->pdf->SetFont(self::F, '', 7.5);
        $this->pdf->SetTextColor(...self::MID);

        $this->pdf->Cell($W / 2, 5, 'نظام إدارة المبيعات  ·  طُبع: ' . now()->format('Y-m-d  H:i'), 0, 0, 'R');
        $this->pdf->Cell($W / 2, 5, 'صفحة ' . $this->pdf->getAliasNumPage() . ' / ' . $this->pdf->getAliasNbPages(), 0, 1, 'L');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function W(): float
    {
        return $this->pdf->getPageWidth() - self::M * 2;
    }

    /**
     * Resolve a product image URL to a local filesystem path for TCPDF rendering.
     *
     * @param string|null $imageUrl
     * @return string|null Local file path, or null if not resolvable
     */
    private function resolveProductImagePath(?string $imageUrl): ?string
    {
        if (!$imageUrl) return null;

        $path = parse_url($imageUrl, PHP_URL_PATH) ?: '';
        if (!$path) return null;

        $storagePos = strpos($path, '/storage/');
        if ($storagePos !== false) {
            $relative  = substr($path, $storagePos + strlen('/storage/'));
            $candidate = public_path('storage/' . ltrim($relative, '/'));
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        $candidate = public_path(ltrim($path, '/'));
        if (file_exists($candidate)) {
            return $candidate;
        }

        return null;
    }
}
