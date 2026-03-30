<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseItem;
use App\Services\Pdf\PdfHeaderRenderer;
use TCPDF;

class PriceListPdfService
{
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

        $renderer = new PdfHeaderRenderer('pricelist');
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetTitle('قائمة الأسعار');
        $pdf->SetMargins(15, $renderer->getTopMargin(), 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();
        $renderer->render($pdf);

        $this->generateContent($pdf, $sorted, $uncategorized);

        return $pdf->Output('pricelist.pdf', 'S');
    }

    private function generateContent(TCPDF $pdf, $grouped, $uncategorized): void
    {
        // Title
        $pdf->SetFont('arial', 'B', 16);
        $pdf->Cell(0, 10, 'قائمة الأسعار', 0, 1, 'C');

        // Date
        $pdf->SetFont('arial', '', 9);
        $pdf->Cell(0, 6, 'تاريخ: ' . now()->format('Y-m-d'), 0, 1, 'R');
        $pdf->Ln(3);

        // Column widths for portrait A4 (~180mm usable)
        $colWidths = [10, 100, 35, 35];

        $rowNum = 1;

        foreach ($grouped as $categoryName => $products) {
            $this->renderCategorySection($pdf, $categoryName, $products, $colWidths, $rowNum);
        }

        if ($uncategorized && $uncategorized->isNotEmpty()) {
            $this->renderCategorySection($pdf, 'بدون تصنيف', $uncategorized, $colWidths, $rowNum);
        }
    }

    private function renderCategorySection(TCPDF $pdf, string $categoryName, $products, array $colWidths, int &$rowNum): void
    {
        $totalWidth = array_sum($colWidths);

        // Category header row
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(21, 101, 192);   // blue
        $pdf->SetTextColor(255, 255, 255);   // white
        $pdf->Cell($totalWidth, 8, $categoryName, 1, 1, 'C', true);
        $pdf->SetTextColor(0, 0, 0);

        // Column headers
        $pdf->SetFont('arial', 'B', 9);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell($colWidths[0], 7, '#',       1, 0, 'C', true);
        $pdf->Cell($colWidths[1], 7, 'الاسم',   1, 0, 'C', true);
        $pdf->Cell($colWidths[2], 7, 'الوحدة', 1, 0, 'C', true);
        $pdf->Cell($colWidths[3], 7, 'السعر',   1, 1, 'C', true);

        // Data rows
        $pdf->SetFont('arial', '', 8);
        $fill = false;
        foreach ($products as $product) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);

            $price = $product->last_sale_price_per_sellable_unit !== null
                ? number_format($product->last_sale_price_per_sellable_unit, 2)
                : '-';

            $pdf->Cell($colWidths[0], 6, $rowNum,                                          1, 0, 'C', true);
            $pdf->Cell($colWidths[1], 6, $this->truncate($product->name, 55),              1, 0, 'C', true);
            $pdf->Cell($colWidths[2], 6, $product->sellableUnit?->name ?? '-',             1, 0, 'C', true);
            $pdf->Cell($colWidths[3], 6, $price,                                           1, 1, 'C', true);

            $rowNum++;
            $fill = !$fill;
        }

        $pdf->Ln(3);
    }

    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) > $maxLength) {
            return mb_substr($text, 0, $maxLength - 2) . '..';
        }
        return $text;
    }
}
