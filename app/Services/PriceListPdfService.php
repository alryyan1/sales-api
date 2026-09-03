<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseItem;
use App\Services\Pdf\PdfHeaderRenderer;
use TCPDF;

class PriceListPdfService
{
    private const COLOR_BRAND       = [45,  55,  72];
    private const COLOR_TITLE_BAND  = [240, 244, 255];
    private const COLOR_HEADER_BG   = [235, 237, 241];
    private const COLOR_ROW_ALT     = [247, 248, 250];
    private const COLOR_ROW_NORMAL  = [255, 255, 255];
    private const COLOR_BORDER      = [222, 226, 231];
    private const COLOR_TEXT        = [51,  58,  69];
    private const COLOR_MUTED       = [110, 118, 129];
    private const COLOR_USD_BADGE   = [22, 128,  74];

    private const MARGIN = 15;
    private const ROW_H  = 6.5;

    private TCPDF $pdf;
    private PdfHeaderRenderer $renderer;

    /**
     * Generate a price list PDF grouped by category
     *
     * @return string PDF content
     */
    public function generatePriceListPdf(): string
    {
        $products = Product::query()
            ->addSelect([
                'last_sale_price_raw' => PurchaseItem::select('sale_price')
                    ->whereColumn('product_id', 'products.id')
                    ->whereNotNull('sale_price')
                    ->latest('created_at')
                    ->limit(1),
            ])
            ->with(['category', 'sellableUnit'])
            ->orderBy('name')
            ->get();

        // Group by category name (null category → "بدون تصنيف")
        $grouped = $products->groupBy(fn($p) => $p->category?->name ?? '__uncategorized__');

        // Sort: named categories alphabetically, uncategorized last
        $sorted = $grouped->sortKeys();
        $uncategorized = $sorted->pull('__uncategorized__');

        $this->renderer = new PdfHeaderRenderer('pricelist');
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf = $this->pdf;
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetTitle('قائمة الأسعار');
        $pdf->SetMargins(self::MARGIN, $this->renderer->getTopMargin(), self::MARGIN);
        $pdf->SetAutoPageBreak(false, self::MARGIN);
        $pdf->AddPage();
        $this->renderer->render($pdf);

        $this->drawTitle($products->count());

        $colWidths = [10, 100, 35, 35]; // sum = 180 = 210 - 2×15 (portrait A4 usable width)

        $rowNum = 1;
        foreach ($sorted as $categoryName => $categoryProducts) {
            $this->renderCategorySection($categoryName, $categoryProducts, $colWidths, $rowNum);
        }
        if ($uncategorized && $uncategorized->isNotEmpty()) {
            $this->renderCategorySection('بدون تصنيف', $uncategorized, $colWidths, $rowNum);
        }

        return $pdf->Output('pricelist.pdf', 'S');
    }

    private function drawTitle(int $totalCount): void
    {
        $pdf = $this->pdf;
        $pageW = $pdf->getPageWidth() - self::MARGIN * 2;

        $bandY = $pdf->GetY();
        $pdf->SetFillColor(self::COLOR_TITLE_BAND[0], self::COLOR_TITLE_BAND[1], self::COLOR_TITLE_BAND[2]);
        $pdf->Rect(self::MARGIN, $bandY, $pageW, 11, 'F');

        $pdf->SetFont('arial', 'B', 16);
        $pdf->SetTextColor(self::COLOR_BRAND[0], self::COLOR_BRAND[1], self::COLOR_BRAND[2]);
        $pdf->SetY($bandY + 1.5);
        $pdf->Cell(0, 8, 'قائمة الأسعار', 0, 1, 'C');

        $pdf->SetFillColor(self::COLOR_BRAND[0], self::COLOR_BRAND[1], self::COLOR_BRAND[2]);
        $pdf->Rect(self::MARGIN, $bandY + 11, $pageW, 0.8, 'F');
        $pdf->Ln(3.5);

        $pdf->SetFont('arial', '', 8);
        $pdf->SetTextColor(self::COLOR_MUTED[0], self::COLOR_MUTED[1], self::COLOR_MUTED[2]);
        $pdf->Cell($pageW / 2, 5, 'تاريخ: ' . now()->format('Y-m-d'), 0, 0, 'R');
        $pdf->Cell($pageW / 2, 5, 'عدد الأصناف: ' . number_format($totalCount), 0, 1, 'L');
        $pdf->Ln(2);
        $pdf->SetTextColor(self::COLOR_TEXT[0], self::COLOR_TEXT[1], self::COLOR_TEXT[2]);
    }

    /**
     * Make sure at least $neededHeight mm remain on the current page; if not, start a new
     * one and redraw the branding header. Returns true if a new page was started, so the
     * caller can decide whether to repeat section-level chrome (category bar/column header).
     */
    private function ensureSpace(float $neededHeight): bool
    {
        $pdf = $this->pdf;
        if ($pdf->GetY() + $neededHeight <= $pdf->getPageHeight() - self::MARGIN) {
            return false;
        }

        $pdf->AddPage();
        $pdf->SetTextColor(self::COLOR_TEXT[0], self::COLOR_TEXT[1], self::COLOR_TEXT[2]);
        $this->renderer->render($pdf);

        return true;
    }

    private function renderCategorySection(string $categoryName, $products, array $colWidths, int &$rowNum): void
    {
        $pdf = $this->pdf;
        $totalWidth = array_sum($colWidths);

        // Keep the category bar + column header + at least one data row together, so a
        // section never opens as an orphan heading at the very bottom of a page.
        $this->ensureSpace(8 + 7 + self::ROW_H);
        $this->drawSectionHeader($categoryName, $colWidths, $totalWidth);

        $pdf->SetFont('arial', '', 8);
        $fill = false;
        foreach ($products as $product) {
            if ($this->ensureSpace(self::ROW_H)) {
                $this->drawSectionHeader($categoryName . '  (تابع)', $colWidths, $totalWidth);
                $pdf->SetFont('arial', '', 8);
            }

            [$r, $g, $b] = $fill ? self::COLOR_ROW_ALT : self::COLOR_ROW_NORMAL;
            $pdf->SetFillColor($r, $g, $b);

            $isUsd = $product->preferred_currency === 'USD';
            $rawPrice = $product->last_sale_price_per_sellable_unit;
            $price = $rawPrice !== null
                ? number_format($rawPrice, 2) . ($isUsd ? ' $' : '')
                : '-';

            $pdf->SetTextColor(self::COLOR_TEXT[0], self::COLOR_TEXT[1], self::COLOR_TEXT[2]);
            $pdf->Cell($colWidths[0], self::ROW_H, (string) $rowNum, 'B', 0, 'C', true);
            $pdf->Cell($colWidths[1], self::ROW_H, $this->truncate($product->name, 55), 'B', 0, 'C', true);
            $pdf->Cell($colWidths[2], self::ROW_H, $product->sellableUnit?->name ?? '-', 'B', 0, 'C', true);
            if ($isUsd) {
                $pdf->SetTextColor(self::COLOR_USD_BADGE[0], self::COLOR_USD_BADGE[1], self::COLOR_USD_BADGE[2]);
                $pdf->SetFont('arial', 'B', 8);
            }
            $pdf->Cell($colWidths[3], self::ROW_H, $price, 'B', 1, 'C', true);
            if ($isUsd) {
                $pdf->SetFont('arial', '', 8);
            }

            $rowNum++;
            $fill = !$fill;
        }

        $pdf->Ln(3);
    }

    private function drawSectionHeader(string $categoryName, array $colWidths, float $totalWidth): void
    {
        $pdf = $this->pdf;

        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(self::COLOR_BRAND[0], self::COLOR_BRAND[1], self::COLOR_BRAND[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell($totalWidth, 8, $categoryName, 0, 1, 'C', true);

        $pdf->SetFont('arial', 'B', 9);
        $pdf->SetFillColor(self::COLOR_HEADER_BG[0], self::COLOR_HEADER_BG[1], self::COLOR_HEADER_BG[2]);
        $pdf->SetTextColor(self::COLOR_TEXT[0], self::COLOR_TEXT[1], self::COLOR_TEXT[2]);
        $pdf->Cell($colWidths[0], 7, '#', 0, 0, 'C', true);
        $pdf->Cell($colWidths[1], 7, 'الاسم', 0, 0, 'C', true);
        $pdf->Cell($colWidths[2], 7, 'الوحدة', 0, 0, 'C', true);
        $pdf->Cell($colWidths[3], 7, 'السعر', 0, 1, 'C', true);
    }

    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) > $maxLength) {
            return mb_substr($text, 0, $maxLength - 2) . '..';
        }
        return $text;
    }
}
