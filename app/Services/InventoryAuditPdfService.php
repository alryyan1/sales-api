<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Category;
use App\Models\Warehouse;
use App\Services\Pdf\PdfHeaderRenderer;
use TCPDF;

class InventoryAuditPdfService
{
    private const COLOR_BRAND      = [45,  55,  72];
    private const COLOR_TITLE_BAND = [240, 244, 255];
    private const COLOR_TEXT       = [51,  58,  69];
    private const COLOR_MUTED      = [110, 118, 129];
    private const COLOR_WARNING    = [200,  40,  40];
    private const COLOR_BORDER     = [222, 226, 231];

    // Generic, print-friendly per-category palette — cycled by category position.
    // Not tied to any specific client's category names (see fix note below).
    private const CATEGORY_PALETTE = [
        [219, 234, 254], // blue
        [220, 252, 231], // green
        [255, 237, 213], // orange
        [237, 233, 254], // purple
        [224, 242, 241], // teal
        [254, 226, 226], // red
        [254, 249, 195], // yellow
        [252, 231, 243], // pink
    ];

    /**
     * Generate the Inventory Audit PDF report (all products × all warehouses balance matrix)
     *
     * @param array $filters
     * @return string PDF content
     */
    public function generate(array $filters = []): string
    {
        // Fetch warehouses
        $warehousesQuery = Warehouse::query();
        if (!empty($filters['warehouse_id'])) {
            $warehousesQuery->where('id', $filters['warehouse_id']);
        }
        $warehouses = $warehousesQuery->orderBy('id')->get();
        $whCount = $warehouses->count();

        // Fetch products grouped by category
        $categories = Category::with(['products' => function ($query) use ($filters) {
            if (!empty($filters['search'])) {
                $search = $filters['search'];
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('sku', 'like', "%{$search}%");
                });
            }

            if (!empty($filters['warehouse_id'])) {
                $warehouseId = $filters['warehouse_id'];
                $query->whereHas('warehouses', function ($q) use ($warehouseId) {
                    $q->where('warehouse_id', $warehouseId)
                      ->where('quantity', '>', 0);
                });
            }

            // Load all warehouse stock
            $query->with(['warehouses']);

            $query->orderBy('name');
        }, 'products.sellableUnit'])->get();

        // Item noun used in the title/labels — reuses the same business_type setting the
        // sidebar already uses to distinguish "المعدات" (equipment) vs "المنتجات" (pharmacy),
        // instead of the previous hardcoded "المعدات" wording, which was wrong for any
        // non-equipment client (see also the hardcoded category-name colour map removed below).
        $businessType = app(SettingsService::class)->getAll()['business_type'] ?? 'equipment';
        $itemNoun = $businessType === 'pharmacy' ? 'المنتجات' : 'المعدات';

        // Create PDF - A4 Landscape
        $renderer = new PdfHeaderRenderer('inventory_audit');
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetTitle("تقرير بكميات {$itemNoun}");
        $pdf->SetMargins(5, $renderer->getTopMargin(), 5);
        $pdf->SetAutoPageBreak(false, 10);
        $pdf->AddPage();
        $renderer->render($pdf);
        $pdf->setRTL(false);

        // Header Section
        if ($warehouses->count() === 1) {
            $reportTitle = "تقرير بكميات {$itemNoun} لمخزن ( " . $warehouses->first()->name . ' ) بتاريخ ' . now()->format('d/m/Y') . 'م';
        } else {
            $reportTitle = "تقرير بكميات {$itemNoun} لكل مخازن الشركة بتاريخ " . now()->format('d/m/Y') . 'م';
        }
        $this->drawTitleBand($pdf, $reportTitle);

        // Table Layout Calculations (A4 Landscape width = 297mm, Margins 5+5=10, Total 287mm)
        $colNumWidth = 10;
        $colNameWidth = 140; // Extra space gained by removing category column
        $colUnitWidth = 18;

        $fixedWidth = $colNumWidth + $colNameWidth + $colUnitWidth; // 168mm
        $colSumWidth = 25; // Total Balance column

        $availableForWh = 287 - $fixedWidth - $colSumWidth;

        $colWhWidth = $whCount > 0 ? floor($availableForWh / $whCount) : 0;

        // Increase warehouse columns by a third
        $colWhWidth = floor($colWhWidth * 1.33);

        // Ensure minimum width
        if ($colWhWidth < 15) $colWhWidth = 15;

        // Recalculate Name width to fit exactly
        $currentTotal = $fixedWidth + $colSumWidth + ($whCount * $colWhWidth);
        if ($currentTotal > 287) {
            $colNameWidth -= ($currentTotal - 287);
        } else if ($currentTotal < 287) {
             $colNameWidth += (287 - $currentTotal);
        }

        $totalTableWidth = $colSumWidth + ($whCount * $colWhWidth) + $colUnitWidth + $colNameWidth + $colNumWidth;

        $printTableColumnHeaders = function () use ($pdf, $colSumWidth, $colWhWidth, $colUnitWidth, $colNameWidth, $colNumWidth, $warehouses) {
            $pdf->SetFont('arial', 'B', 10);
            $pdf->SetFillColor(self::COLOR_BRAND[0], self::COLOR_BRAND[1], self::COLOR_BRAND[2]);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell($colSumWidth, 9, 'رصيد الصنف', 1, 0, 'C', true);
            foreach ($warehouses->reverse() as $wh) {
                $pdf->Cell($colWhWidth, 9, $wh->name, 1, 0, 'C', true);
            }
            $pdf->Cell($colUnitWidth, 9, 'الوحدة', 1, 0, 'C', true);
            $pdf->Cell($colNameWidth, 9, 'بيان الصنف', 1, 0, 'C', true);
            $pdf->Cell($colNumWidth, 9, 'رقم', 1, 1, 'C', true);
            $pdf->SetTextColor(self::COLOR_TEXT[0], self::COLOR_TEXT[1], self::COLOR_TEXT[2]);
        };

        $categoryIndex = 0;
        foreach ($categories as $category) {
            $products = $category->products;
            if ($products->isEmpty()) continue;

            $catName = $category->name;
            $color = self::CATEGORY_PALETTE[$categoryIndex % count(self::CATEGORY_PALETTE)];
            $categoryIndex++;

            // Keep the category title + column header + at least one row together, so a
            // section never opens as an orphan heading at the very bottom of a page.
            if ($pdf->GetY() + 10 + 9 + 8 > $pdf->getPageHeight() - 10) {
                $pdf->AddPage();
                $pdf->SetTextColor(self::COLOR_TEXT[0], self::COLOR_TEXT[1], self::COLOR_TEXT[2]);
                $renderer->render($pdf);
            }

            // Category title above its own table
            $pdf->Ln(3);
            $pdf->SetFont('arial', 'B', 12);
            $pdf->SetFillColor($color[0], $color[1], $color[2]);
            $pdf->SetTextColor(self::COLOR_TEXT[0], self::COLOR_TEXT[1], self::COLOR_TEXT[2]);
            $pdf->Cell($totalTableWidth, 10, $catName, 1, 1, 'C', true);

            // Column headers for this category's table
            $printTableColumnHeaders();

            $pdf->SetFillColor($color[0], $color[1], $color[2]);

            foreach ($products as $index => $product) {
                if ($pdf->GetY() + 8 > $pdf->getPageHeight() - 10) {
                    $pdf->AddPage();
                    $pdf->SetTextColor(self::COLOR_TEXT[0], self::COLOR_TEXT[1], self::COLOR_TEXT[2]);
                    $renderer->render($pdf);
                    // Category title continued
                    $pdf->SetFont('arial', 'B', 12);
                    $pdf->SetFillColor($color[0], $color[1], $color[2]);
                    $pdf->SetTextColor(self::COLOR_TEXT[0], self::COLOR_TEXT[1], self::COLOR_TEXT[2]);
                    $pdf->Cell($totalTableWidth, 10, $catName . ' (تابع)', 1, 1, 'C', true);
                    $printTableColumnHeaders();
                    $pdf->SetFillColor($color[0], $color[1], $color[2]);
                }

                // Total Balance across all warehouses
                $totalStock = 0;
                foreach ($warehouses as $wh) {
                    $totalStock += $product->warehouses->where('id', $wh->id)->first()?->pivot->quantity ?? 0;
                }

                // Only flag it visually when the balance is actually a problem (zero) —
                // colouring every single row red regardless of stock level (the previous
                // behaviour) conveys no real signal and reads as an alarm on a healthy report.
                $pdf->SetFont('arial', 'B', 10);
                if ($totalStock <= 0) {
                    $pdf->SetTextColor(self::COLOR_WARNING[0], self::COLOR_WARNING[1], self::COLOR_WARNING[2]);
                } else {
                    $pdf->SetTextColor(self::COLOR_TEXT[0], self::COLOR_TEXT[1], self::COLOR_TEXT[2]);
                }
                $pdf->Cell($colSumWidth, 8, number_format($totalStock), 1, 0, 'C', true);

                $pdf->SetTextColor(self::COLOR_TEXT[0], self::COLOR_TEXT[1], self::COLOR_TEXT[2]);
                $pdf->SetFont('arial', '', 9);

                // Individual warehouses
                foreach ($warehouses->reverse() as $wh) {
                    $stock = $product->warehouses->where('id', $wh->id)->first()?->pivot->quantity ?? 0;
                    $pdf->Cell($colWhWidth, 8, number_format($stock), 1, 0, 'C', true);
                }

                $pdf->Cell($colUnitWidth, 8, $product->sellableUnit?->name ?: 'وحدة', 1, 0, 'C', true);

                $x = $pdf->GetX();
                $y = $pdf->GetY();
                $pdf->MultiCell($colNameWidth, 8, $product->name, 1, 'L', true, 0);
                $pdf->SetXY($x + $colNameWidth, $y);

                $pdf->Cell($colNumWidth, 8, $index + 1, 1, 1, 'C', true);
            }
        }

        return $pdf->Output('inventory_audit.pdf', 'S');
    }

    private function drawTitleBand(TCPDF $pdf, string $title): void
    {
        $pageW = $pdf->getPageWidth() - 10;
        $bandY = $pdf->GetY();
        $pdf->SetFillColor(self::COLOR_TITLE_BAND[0], self::COLOR_TITLE_BAND[1], self::COLOR_TITLE_BAND[2]);
        $pdf->Rect(5, $bandY, $pageW, 10, 'F');

        $pdf->SetFont('arial', 'B', 11);
        $pdf->SetTextColor(self::COLOR_BRAND[0], self::COLOR_BRAND[1], self::COLOR_BRAND[2]);
        $pdf->SetY($bandY + 1);
        $pdf->MultiCell(0, 6, $title, 0, 'C');

        $pdf->SetFillColor(self::COLOR_BRAND[0], self::COLOR_BRAND[1], self::COLOR_BRAND[2]);
        $pdf->Rect(5, $bandY + 10, $pageW, 0.6, 'F');
        $pdf->Ln(4);
        $pdf->SetTextColor(self::COLOR_TEXT[0], self::COLOR_TEXT[1], self::COLOR_TEXT[2]);
    }

    /**
     * Generate Warehouse Products PDF report for a specific warehouse
     *
     * @param array $filters
     * @return string PDF content
     */
    public function generateWarehouseProducts(array $filters = []): string
    {
        $warehouseId = $filters['warehouse_id'] ?? null;
        if (!$warehouseId) {
            throw new \InvalidArgumentException('Warehouse ID is required');
        }

        // Fetch the specific warehouse
        $warehouse = Warehouse::findOrFail($warehouseId);

        // Fetch products for this warehouse that have stock
        $products = Product::with(['warehouses' => function ($query) use ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }, 'sellableUnit', 'category'])
        ->whereHas('warehouses', function ($query) use ($warehouseId) {
            $query->where('warehouse_id', $warehouseId)
                  ->where('quantity', '>', 0);
        });

        // Apply search filter if provided
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $products->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $products = $products->orderBy('name')->get();

        // Create PDF - A4 Portrait
        $renderer = new PdfHeaderRenderer('warehouse_products');
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetTitle('منتجات المستودع - ' . $warehouse->name);
        $pdf->SetMargins(10, $renderer->getTopMargin(), 10);
        $pdf->SetAutoPageBreak(false, 10);
        $pdf->setRTL(false); // Important for Arabic text
        $pdf->AddPage();
        $renderer->render($pdf);

        $pageW = $pdf->getPageWidth() - 20;
        $bandY = $pdf->GetY();
        $pdf->SetFillColor(self::COLOR_TITLE_BAND[0], self::COLOR_TITLE_BAND[1], self::COLOR_TITLE_BAND[2]);
        $pdf->Rect(10, $bandY, $pageW, 15, 'F');
        $pdf->SetFont('arial', 'B', 16);
        $pdf->SetTextColor(self::COLOR_BRAND[0], self::COLOR_BRAND[1], self::COLOR_BRAND[2]);
        $pdf->SetY($bandY + 1);
        $pdf->Cell(0, 8, 'منتجات المستودع', 0, 1, 'C');
        $pdf->SetFont('arial', 'B', 11);
        $pdf->Cell(0, 6, $warehouse->name, 0, 1, 'C');
        $pdf->SetFillColor(self::COLOR_BRAND[0], self::COLOR_BRAND[1], self::COLOR_BRAND[2]);
        $pdf->Rect(10, $bandY + 15, $pageW, 0.6, 'F');
        $pdf->Ln(4);
        $pdf->SetTextColor(self::COLOR_TEXT[0], self::COLOR_TEXT[1], self::COLOR_TEXT[2]);

        $colWidths = [15, 60, 25, 30, 30, 30]; // #, Name, SKU, Category, Quantity, Sale price

        $printHeaders = function () use ($pdf, $colWidths) {
            $pdf->SetFont('arial', 'B', 10);
            $pdf->SetFillColor(self::COLOR_BRAND[0], self::COLOR_BRAND[1], self::COLOR_BRAND[2]);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell($colWidths[0], 10, 'الرقم', 1, 0, 'C', true);
            $pdf->Cell($colWidths[1], 10, 'اسم المنتج', 1, 0, 'C', true);
            $pdf->Cell($colWidths[2], 10, 'الرمز', 1, 0, 'C', true);
            $pdf->Cell($colWidths[3], 10, 'الفئة', 1, 0, 'C', true);
            $pdf->Cell($colWidths[4], 10, 'الكمية', 1, 0, 'C', true);
            $pdf->Cell($colWidths[5], 10, 'سعر البيع', 1, 1, 'C', true);
            $pdf->SetTextColor(self::COLOR_TEXT[0], self::COLOR_TEXT[1], self::COLOR_TEXT[2]);
        };
        $printHeaders();

        $pdf->SetFont('arial', '', 9);
        $index = 0;
        foreach ($products as $product) {
            if ($pdf->GetY() + 8 > $pdf->getPageHeight() - 10) {
                $pdf->AddPage();
                $pdf->SetTextColor(self::COLOR_TEXT[0], self::COLOR_TEXT[1], self::COLOR_TEXT[2]);
                $renderer->render($pdf);
                $printHeaders();
                $pdf->SetFont('arial', '', 9);
            }

            $warehouseStock = $product->warehouses->first();
            $quantity = $warehouseStock ? $warehouseStock->pivot->quantity : 0;
            $fill = $index % 2 === 1;
            $pdf->SetFillColor($fill ? 247 : 255, $fill ? 248 : 255, $fill ? 250 : 255);

            $pdf->Cell($colWidths[0], 8, ++$index, 1, 0, 'C', true);
            $pdf->Cell($colWidths[1], 8, $product->name, 1, 0, 'L', true);
            $pdf->Cell($colWidths[2], 8, $product->sku ?: '-', 1, 0, 'C', true);
            $pdf->Cell($colWidths[3], 8, $product->category?->name ?: '-', 1, 0, 'C', true);
            $pdf->Cell($colWidths[4], 8, number_format($quantity), 1, 0, 'C', true);
            // Sale price, not cost — the column is labelled "سعر البيع" (sale price); the
            // previous version showed latest_cost_per_sellable_unit (the purchase cost)
            // under this label, which is a different figure than what it claimed to show.
            $pdf->Cell($colWidths[5], 8, number_format($product->last_sale_price_per_sellable_unit ?: 0, 2), 1, 1, 'R', true);
        }

        // Summary
        $pdf->Ln(5);
        $pdf->SetFont('arial', 'B', 10);
        $pdf->Cell(0, 10, 'إجمالي المنتجات: ' . $products->count(), 0, 1, 'R');

        return $pdf->Output('warehouse_products.pdf', 'S');
    }
}
