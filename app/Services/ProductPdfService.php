<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseItem;
use App\Services\Pdf\PdfHeaderRenderer;

class ProductPdfService
{
    private TCPDF $pdf;
    private PdfHeaderRenderer $renderer;

    // ── Colors ────────────────────────────────────────────────────────────────
    private const COLOR_HEADER_BG   = [45,  55,  72];
    private const COLOR_HEADER_TEXT = [255, 255, 255];
    private const COLOR_ROW_ALT     = [247, 248, 250];
    private const COLOR_ROW_NORMAL  = [255, 255, 255];
    private const COLOR_LOW_STOCK   = [255, 249, 219];
    private const COLOR_OUT_STOCK   = [255, 240, 240];
    private const COLOR_BORDER      = [210, 215, 220];
    private const COLOR_TOTAL_BG    = [45,  55,  72];

    // ── Layout ────────────────────────────────────────────────────────────────
    private const MARGIN   = 12;
    private const ROW_H    = 6;
    private const HEADER_H = 7;

    // ── Typography ────────────────────────────────────────────────────────────
    private const F_TITLE   = 14;
    private const F_SECTION = 9;
    private const F_HEADER  = 7;
    private const F_BODY    = 7;
    private const F_SMALL   = 6;

    // ── Column widths (sum = 273 = 297 - 2×12) ───────────────────────────────
    //    #    Name  Sci   SKU   Cat   Qty   Unit  Cost  Sale  Alert Status
    private const COLS = [7, 48, 30, 24, 28, 16, 22, 24, 24, 16, 24];

    // ─────────────────────────────────────────────────────────────────────────

    public function generateProductsPdf(array $filters = []): string
    {
        $products = $this->buildQuery($filters)->get();

        $this->renderer = new PdfHeaderRenderer('product');
        $this->initPdf();
        $this->pdf->AddPage();
        $this->renderer->render($this->pdf);

        $this->drawTitle($filters);
        $this->drawSummaryBar($products);
        $this->drawTableHeader();
        $this->drawRows($products);
        $this->drawTotalsFooter($products);

        return $this->pdf->Output('products_report.pdf', 'S');
    }

    // ── Init ─────────────────────────────────────────────────────────────────

    private function initPdf(): void
    {
        $this->pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetTitle('تقرير المنتجات');
        $this->pdf->SetMargins(self::MARGIN, $this->renderer->getTopMargin(), self::MARGIN);
        $this->pdf->SetAutoPageBreak(true, self::MARGIN);
    }

    private function buildQuery(array $filters)
    {
        $query = Product::query()
            ->select('products.*')
            ->addSelect([
                'latest_purchase_cost_raw' => PurchaseItem::select('unit_cost')
                    ->whereColumn('product_id', 'products.id')
                    ->latest('created_at')
                    ->limit(1),
                'last_sale_price_raw' => PurchaseItem::select('sale_price')
                    ->whereColumn('product_id', 'products.id')
                    ->whereNotNull('sale_price')
                    ->latest('created_at')
                    ->limit(1),
            ])
            ->with(['category', 'stockingUnit', 'sellableUnit']);

        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(fn($q) => $q
                ->where('name', 'like', "%$s%")
                ->orWhere('sku', 'like', "%$s%")
                ->orWhere('scientific_name', 'like', "%$s%"));
        }
        if (!empty($filters['category_id']))   $query->where('category_id', $filters['category_id']);
        if (!empty($filters['in_stock_only']))  $query->hasStock();
        if (!empty($filters['low_stock_only'])) $query->lowStock();

        return $query->orderBy('name');
    }

    // ── Title block ──────────────────────────────────────────────────────────

    private function drawTitle(array $filters): void
    {
        $pdf   = $this->pdf;
        $pageW = $pdf->getPageWidth() - self::MARGIN * 2;

        $pdf->SetFont('arial', 'B', self::F_TITLE);
        $pdf->SetTextColor(self::COLOR_HEADER_BG[0], self::COLOR_HEADER_BG[1], self::COLOR_HEADER_BG[2]);
        $pdf->Cell(0, 8, 'تقرير المنتجات', 0, 1, 'C');

        $pdf->SetDrawColor(self::COLOR_BORDER[0], self::COLOR_BORDER[1], self::COLOR_BORDER[2]);
        $pdf->SetLineWidth(0.3);
        $pdf->Line(self::MARGIN, $pdf->GetY(), self::MARGIN + $pageW, $pdf->GetY());
        $pdf->Ln(2.5);

        // Filters / date line
        $notes = [];
        if (!empty($filters['search']))        $notes[] = 'بحث: ' . $filters['search'];
        if (!empty($filters['in_stock_only']))  $notes[] = 'المتوفر فقط';
        if (!empty($filters['low_stock_only'])) $notes[] = 'المخزون المنخفض';
        if (!empty($filters['category_id'])) {
            $cat = \App\Models\Category::find($filters['category_id']);
            if ($cat) $notes[] = 'الفئة: ' . $cat->name;
        }

        $pdf->SetFont('arial', '', self::F_SMALL);
        $pdf->SetTextColor(100, 110, 120);
        $pdf->Cell(0, 4, 'تاريخ الطباعة: ' . now()->format('Y-m-d  H:i'), 0, 0, 'R');
        if ($notes) {
            $pdf->SetXY(self::MARGIN, $pdf->GetY());
            $pdf->Cell(0, 4, implode('  |  ', $notes), 0, 0, 'L');
        }
        $pdf->Ln(6);
        $pdf->SetTextColor(0, 0, 0);
    }

    // ── Summary bar ──────────────────────────────────────────────────────────

    private function drawSummaryBar($products): void
    {
        $pdf   = $this->pdf;
        $total      = $products->count();
        $inStock    = $products->where('stock_quantity', '>', 0)->count();
        $outOfStock = $total - $inStock;
        $lowStock   = $products->filter(fn($p) =>
            $p->stock_alert_level && $p->stock_quantity > 0 && $p->stock_quantity <= $p->stock_alert_level
        )->count();

        $pageW = $pdf->getPageWidth() - self::MARGIN * 2;
        $boxW  = $pageW / 4;
        $boxH  = 13;
        $y     = $pdf->GetY();

        $stats = [
            ['إجمالي المنتجات', $total,      [240, 244, 255], [45,  55,  72]],
            ['متوفر',           $inStock,     [237, 252, 244], [22,  163,  74]],
            ['غير متوفر',       $outOfStock,  [255, 240, 240], [220,  38,  38]],
            ['مخزون منخفض',     $lowStock,    [255, 249, 219], [160, 100,   0]],
        ];

        foreach ($stats as $i => [$label, $val, $bg, $fg]) {
            $x = self::MARGIN + $i * $boxW;
            $pdf->SetFillColor($bg[0], $bg[1], $bg[2]);
            $pdf->SetDrawColor(self::COLOR_BORDER[0], self::COLOR_BORDER[1], self::COLOR_BORDER[2]);
            $pdf->SetLineWidth(0.2);
            $pdf->Rect($x, $y, $boxW - 1, $boxH, 'FD');

            $pdf->SetFont('arial', 'B', 11);
            $pdf->SetTextColor($fg[0], $fg[1], $fg[2]);
            $pdf->SetXY($x, $y + 1.5);
            $pdf->Cell($boxW - 1, 6, (string) $val, 0, 0, 'C');

            $pdf->SetFont('arial', '', self::F_SMALL);
            $pdf->SetTextColor(80, 90, 100);
            $pdf->SetXY($x, $y + 7.5);
            $pdf->Cell($boxW - 1, 4, $label, 0, 0, 'C');
        }

        $pdf->SetXY(self::MARGIN, $y + $boxH + 3);
        $pdf->SetTextColor(0, 0, 0);
    }

    // ── Table header ─────────────────────────────────────────────────────────

    private function drawTableHeader(): void
    {
        $pdf = $this->pdf;
        [$r, $g, $b] = self::COLOR_HEADER_BG;
        $pdf->SetFillColor($r, $g, $b);
        $pdf->SetTextColor(self::COLOR_HEADER_TEXT[0], self::COLOR_HEADER_TEXT[1], self::COLOR_HEADER_TEXT[2]);
        $pdf->SetFont('arial', 'B', self::F_HEADER);
        $pdf->SetDrawColor(self::COLOR_BORDER[0], self::COLOR_BORDER[1], self::COLOR_BORDER[2]);
        $pdf->SetLineWidth(0.1);

        $labels = ['#', 'الاسم', 'الاسم العلمي', 'الكود', 'الفئة', 'المخزون', 'الوحدة', 'آخر تكلفة', 'سعر البيع', 'حد التنبيه', 'الحالة'];
        $last   = count($labels) - 1;
        foreach ($labels as $i => $lbl) {
            $pdf->Cell(self::COLS[$i], self::HEADER_H, $lbl, 1, ($i === $last ? 1 : 0), 'C', true);
        }
        $pdf->SetTextColor(0, 0, 0);
    }

    // ── Table rows ───────────────────────────────────────────────────────────

    private function drawRows($products): void
    {
        $pdf = $this->pdf;
        $pdf->SetFont('arial', '', self::F_BODY);
        $pdf->SetLineWidth(0.1);
        $pdf->SetDrawColor(self::COLOR_BORDER[0], self::COLOR_BORDER[1], self::COLOR_BORDER[2]);

        foreach ($products as $i => $product) {
            if ($pdf->GetY() + self::ROW_H > $pdf->getPageHeight() - self::MARGIN) {
                $pdf->AddPage();
                $this->renderer->render($pdf);
                $this->drawTableHeader();
                $pdf->SetFont('arial', '', self::F_BODY);
                $pdf->SetLineWidth(0.1);
                $pdf->SetDrawColor(self::COLOR_BORDER[0], self::COLOR_BORDER[1], self::COLOR_BORDER[2]);
            }

            [$r, $g, $b] = $this->rowColor($product, $i);
            $pdf->SetFillColor($r, $g, $b);

            $cost  = $product->latest_cost_per_sellable_unit
                ? number_format((float) $product->latest_cost_per_sellable_unit, 2) : '-';
            $sale  = $product->last_sale_price_per_sellable_unit
                ? number_format((float) $product->last_sale_price_per_sellable_unit, 2) : '-';
            $alert = $product->stock_alert_level
                ? number_format((int) $product->stock_alert_level) : '-';

            $cells = [
                $i + 1,
                $this->cut($product->name, 30),
                $this->cut($product->scientific_name ?: '-', 20),
                $this->cut($product->sku ?: '-', 14),
                $this->cut($product->category?->name ?: '-', 17),
                number_format((int) $product->stock_quantity),
                $this->cut($product->sellableUnit?->name ?: '-', 11),
                $cost,
                $sale,
                $alert,
                $this->statusLabel($product),
            ];

            $last = count($cells) - 1;
            foreach ($cells as $j => $val) {
                $pdf->Cell(self::COLS[$j], self::ROW_H, (string) $val, 1, ($j === $last ? 1 : 0), 'C', true);
            }
        }
    }

    // ── Totals footer ────────────────────────────────────────────────────────

    private function drawTotalsFooter($products): void
    {
        $pdf   = $this->pdf;
        $pageW = $pdf->getPageWidth() - self::MARGIN * 2;

        $totalCost = $products->sum(fn($p) =>
            ((float) ($p->latest_cost_per_sellable_unit ?? 0)) * ((int) $p->stock_quantity)
        );

        $pdf->Ln(2);

        // Total cost row
        [$r, $g, $b] = self::COLOR_TOTAL_BG;
        $pdf->SetFillColor($r, $g, $b);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('arial', 'B', self::F_SECTION);

        $labelW = 55;
        $valueW = 40;
        $spacer = $pageW - $labelW - $valueW;

        $pdf->Cell($spacer, self::ROW_H, '', 0, 0);
        $pdf->Cell($labelW, self::ROW_H, 'إجمالي تكلفة المخزون:', 1, 0, 'R', true);
        $pdf->Cell($valueW, self::ROW_H, number_format($totalCost, 2), 1, 1, 'C', true);

        // Footer rule + page info
        $pdf->Ln(3);
        $pdf->SetFont('arial', '', self::F_SMALL);
        $pdf->SetTextColor(140, 150, 160);
        $pdf->SetDrawColor(self::COLOR_BORDER[0], self::COLOR_BORDER[1], self::COLOR_BORDER[2]);
        $pdf->SetLineWidth(0.2);
        $pdf->Line(self::MARGIN, $pdf->GetY(), self::MARGIN + $pageW, $pdf->GetY());
        $pdf->Ln(1.5);
        $pdf->Cell(0, 4, 'صفحة ' . $pdf->getAliasNumPage() . ' من ' . $pdf->getAliasNbPages(), 0, 0, 'L');
        $pdf->Cell(0, 4, 'تم إنشاؤه بواسطة النظام  —  ' . now()->format('Y-m-d H:i'), 0, 1, 'R');
        $pdf->SetTextColor(0, 0, 0);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function rowColor(Product $product, int $index): array
    {
        if ($product->stock_quantity <= 0)
            return self::COLOR_OUT_STOCK;
        if ($product->stock_alert_level && $product->stock_quantity <= $product->stock_alert_level)
            return self::COLOR_LOW_STOCK;
        return $index % 2 === 0 ? self::COLOR_ROW_NORMAL : self::COLOR_ROW_ALT;
    }

    private function statusLabel(Product $product): string
    {
        if ($product->stock_quantity <= 0) return 'غير متوفر';
        if ($product->stock_alert_level && $product->stock_quantity <= $product->stock_alert_level)
            return 'منخفض';
        return 'متوفر';
    }

    private function cut(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
}
