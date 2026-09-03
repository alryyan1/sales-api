<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseItem;
use App\Services\Pdf\PdfHeaderRenderer;
use Illuminate\Support\Facades\DB;
use TCPDF;

class ProductPdfService
{
    private TCPDF $pdf;
    private PdfHeaderRenderer $renderer;

    // ── Colors ────────────────────────────────────────────────────────────────
    private const COLOR_HEADER_BG   = [45,  55,  72];
    private const COLOR_HEADER_TEXT = [255, 255, 255];
    private const COLOR_ROW_ALT     = [247, 248, 250];
    private const COLOR_ROW_NORMAL  = [255, 255, 255];
    private const COLOR_BORDER      = [222, 226, 231];
    private const COLOR_TOTAL_BG    = [45,  55,  72];
    private const COLOR_TITLE_BAND  = [240, 244, 255];
    private const COLOR_TEXT        = [51,  58,  69];

    // Status → text colour, reused for both the summary cards and the row status label.
    private const STATUS_COLORS = [
        'in_stock'     => [22,  163,  74],
        'out_of_stock' => [220,  38,  38],
        'low_stock'    => [180, 110,   0],
        'service'      => [71,  85, 105],
    ];

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
    //    #    Name  Sci   SKU   Cat   Qty   Unit  Cost  Sale  Status
    private const COLS = [7, 59, 30, 24, 35, 18, 22, 24, 27, 27];

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
                // Product::stock_quantity is a live accessor (SUM over product_warehouse,
                // one query per access, not cached) — computing it here as a single
                // correlated subquery avoids an N+1 that made this report very slow for
                // any real product count (it was being read multiple times per row).
                'stock_quantity_raw' => DB::table('product_warehouse')
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('product_id', 'products.id'),
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

        // Soft tinted banner behind the title, with a solid accent bar under it, instead of
        // a plain heading + thin grey rule — gives the report a clearer visual anchor.
        $bandY = $pdf->GetY();
        $pdf->SetFillColor(self::COLOR_TITLE_BAND[0], self::COLOR_TITLE_BAND[1], self::COLOR_TITLE_BAND[2]);
        $pdf->Rect(self::MARGIN, $bandY, $pageW, 11, 'F');

        $pdf->SetFont('arial', 'B', self::F_TITLE);
        $pdf->SetTextColor(self::COLOR_HEADER_BG[0], self::COLOR_HEADER_BG[1], self::COLOR_HEADER_BG[2]);
        $pdf->SetY($bandY + 1.5);
        $pdf->Cell(0, 8, 'تقرير المنتجات', 0, 1, 'C');

        $pdf->SetFillColor(self::COLOR_HEADER_BG[0], self::COLOR_HEADER_BG[1], self::COLOR_HEADER_BG[2]);
        $pdf->Rect(self::MARGIN, $bandY + 11, $pageW, 0.8, 'F');
        $pdf->Ln(3.5);

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
        // Services carry no warehouse stock but are always sellable — exclude them from
        // out-of-stock/low-stock counts, matching Product::scopeHasStock()'s treatment.
        $outOfStock = $products->filter(fn($p) => ! $p->is_service && $this->stockQty($p) <= 0)->count();
        $lowStock   = $products->filter(fn($p) =>
            ! $p->is_service && $p->stock_alert_level && $this->stockQty($p) > 0 && $this->stockQty($p) <= $p->stock_alert_level
        )->count();
        $inStock    = $total - $outOfStock;

        $pageW = $pdf->getPageWidth() - self::MARGIN * 2;
        $gap   = 3;
        $boxW  = ($pageW - $gap * 3) / 4;
        $boxH  = 14;
        $y     = $pdf->GetY();

        $stats = [
            ['إجمالي المنتجات', $total,      [240, 244, 255], self::COLOR_HEADER_BG],
            ['متوفر',           $inStock,     [237, 252, 244], self::STATUS_COLORS['in_stock']],
            ['غير متوفر',       $outOfStock,  [255, 240, 240], self::STATUS_COLORS['out_of_stock']],
            ['مخزون منخفض',     $lowStock,    [255, 249, 219], self::STATUS_COLORS['low_stock']],
        ];

        foreach ($stats as $i => [$label, $val, $bg, $fg]) {
            $x = self::MARGIN + $i * ($boxW + $gap);
            $pdf->SetFillColor($bg[0], $bg[1], $bg[2]);
            // Flat, borderless rounded card — a lighter, more modern look than a boxed/ruled grid.
            $pdf->RoundedRect($x, $y, $boxW, $boxH, 2, '1111', 'F');
            // Slim accent bar on the card's leading edge, in the stat's colour.
            $pdf->SetFillColor($fg[0], $fg[1], $fg[2]);
            $pdf->RoundedRect($x, $y, 1.6, $boxH, 0.8, '1001', 'F');

            $pdf->SetFont('arial', 'B', 12);
            $pdf->SetTextColor($fg[0], $fg[1], $fg[2]);
            $pdf->SetXY($x, $y + 2);
            $pdf->Cell($boxW, 6, (string) $val, 0, 0, 'C');

            $pdf->SetFont('arial', '', self::F_SMALL);
            $pdf->SetTextColor(90, 98, 110);
            $pdf->SetXY($x, $y + 8.5);
            $pdf->Cell($boxW, 4, $label, 0, 0, 'C');
        }

        $pdf->SetXY(self::MARGIN, $y + $boxH + 4);
        $pdf->SetTextColor(self::COLOR_TEXT[0], self::COLOR_TEXT[1], self::COLOR_TEXT[2]);
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

        $labels = ['#', 'الاسم', 'الاسم العلمي', 'الكود', 'الفئة', 'المخزون', 'الوحدة', 'آخر تكلفة', 'سعر البيع', 'الحالة'];
        $last   = count($labels) - 1;
        foreach ($labels as $i => $lbl) {
            $pdf->Cell(self::COLS[$i], self::HEADER_H, $lbl, 0, ($i === $last ? 1 : 0), 'C', true);
        }
        $pdf->SetTextColor(self::COLOR_TEXT[0], self::COLOR_TEXT[1], self::COLOR_TEXT[2]);
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
                // Reset from the previous row's status colour first — PdfHeaderRenderer's
                // company-name text doesn't set its own colour, so it would otherwise inherit
                // whatever colour the last status cell left active.
                $pdf->SetTextColor(self::COLOR_TEXT[0], self::COLOR_TEXT[1], self::COLOR_TEXT[2]);
                $this->renderer->render($pdf);
                $this->drawTableHeader();
                $pdf->SetFont('arial', '', self::F_BODY);
                $pdf->SetLineWidth(0.1);
                $pdf->SetDrawColor(self::COLOR_BORDER[0], self::COLOR_BORDER[1], self::COLOR_BORDER[2]);
            }

            // Plain zebra striping — the status column's colour already flags out-of-stock/
            // low-stock rows, so tinting the whole row on top of that read as noisy/dated.
            [$r, $g, $b] = $i % 2 === 0 ? self::COLOR_ROW_NORMAL : self::COLOR_ROW_ALT;
            $pdf->SetFillColor($r, $g, $b);

            $cost  = $product->latest_cost_per_sellable_unit
                ? number_format((float) $product->latest_cost_per_sellable_unit, 2) : '-';
            $sale  = $product->last_sale_price_per_sellable_unit
                ? number_format((float) $product->last_sale_price_per_sellable_unit, 2) : '-';

            $cells = [
                $i + 1,
                $this->cut($product->name, 34),
                $this->cut($product->scientific_name ?: '-', 20),
                $this->cut($product->sku ?: '-', 14),
                $this->cut($product->category?->name ?: '-', 19),
                $product->is_service ? '-' : number_format($this->stockQty($product)),
                $this->cut($product->sellableUnit?->name ?: '-', 11),
                $cost,
                $sale,
            ];

            $pdf->SetFont('arial', '', self::F_BODY);
            $pdf->SetTextColor(self::COLOR_TEXT[0], self::COLOR_TEXT[1], self::COLOR_TEXT[2]);
            foreach ($cells as $j => $val) {
                $pdf->Cell(self::COLS[$j], self::ROW_H, (string) $val, 'B', 0, 'C', true);
            }

            // Status column — coloured, bold text on the same row background, instead of a
            // full colour-tinted row (see comment above) or a boxed badge.
            $fg = self::STATUS_COLORS[$this->statusColorKey($product)];
            $pdf->SetFont('arial', 'B', self::F_BODY);
            $pdf->SetTextColor($fg[0], $fg[1], $fg[2]);
            $pdf->Cell(self::COLS[9], self::ROW_H, $this->statusLabel($product), 'B', 1, 'C', true);
        }
    }

    // ── Totals footer ────────────────────────────────────────────────────────

    private function drawTotalsFooter($products): void
    {
        $pdf   = $this->pdf;
        $pageW = $pdf->getPageWidth() - self::MARGIN * 2;

        $totalCost = $products->sum(fn($p) =>
            ((float) ($p->latest_cost_per_sellable_unit ?? 0)) * $this->stockQty($p)
        );

        // The rows loop only checks room for one row at a time, so a table that ends right
        // at the bottom margin would otherwise push this whole block past the page edge with
        // no header on the overflow page (auto page break has no header, since setPrintHeader
        // is off and the header is drawn manually after each AddPage() elsewhere).
        $footerHeight = 2 + self::ROW_H + 3 + 1.5 + 4;
        if ($pdf->GetY() + $footerHeight > $pdf->getPageHeight() - self::MARGIN) {
            $pdf->AddPage();
            $this->renderer->render($pdf);
        }

        $pdf->Ln(2);

        // Total cost row
        [$r, $g, $b] = self::COLOR_TOTAL_BG;
        $pdf->SetFillColor($r, $g, $b);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('arial', 'B', self::F_SECTION);

        $labelW = 55;
        $valueW = 40;
        $spacer = $pageW - $labelW - $valueW;

        $totalH = self::ROW_H + 2;
        $pdf->Cell($spacer, $totalH, '', 0, 0);
        $pdf->RoundedRect(self::MARGIN + $spacer, $pdf->GetY(), $labelW + $valueW, $totalH, 1.5, '1111', 'F');
        $pdf->Cell($labelW, $totalH, 'إجمالي تكلفة المخزون:', 0, 0, 'R');
        $pdf->Cell($valueW, $totalH, number_format($totalCost, 2), 0, 1, 'C');

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

    private function statusColorKey(Product $product): string
    {
        if ($product->is_service) return 'service';
        if ($this->stockQty($product) <= 0) return 'out_of_stock';
        if ($product->stock_alert_level && $this->stockQty($product) <= $product->stock_alert_level)
            return 'low_stock';
        return 'in_stock';
    }

    private function statusLabel(Product $product): string
    {
        return match ($this->statusColorKey($product)) {
            'service'      => 'خدمة',
            'out_of_stock' => 'غير متوفر',
            'low_stock'    => 'منخفض',
            default        => 'متوفر',
        };
    }

    /**
     * Fast stock read from the stock_quantity_raw subquery column selected in buildQuery() —
     * NOT Product::stock_quantity, which is a live per-access accessor (see comment there).
     */
    private function stockQty(Product $product): int
    {
        return (int) ($product->getAttribute('stock_quantity_raw') ?? 0);
    }

    private function cut(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
}
