<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Category;
use App\Models\Warehouse;
use App\Services\Pdf\MyCustomTCPDF;
use Illuminate\Support\Facades\DB;

class InventoryAuditPdfService
{
    /**
     * Generate the Inventory Audit PDF report
     *
     * @param array $filters
     * @return string PDF content
     */
    public function generate(array $filters = []): string
    {
        // Fetch all warehouses
        $warehouses = Warehouse::orderBy('id')->get();
        $whCount = $warehouses->count();

        // 1. Fetch filtered categories and their products
        $categoriesQuery = Category::query();
        if (!empty($filters['category_id'])) {
            $categoriesQuery->where('id', $filters['category_id']);
        }

        $categories = $categoriesQuery->with([
            'products' => function ($query) use ($filters) {
                if (!empty($filters['search'])) {
                    $search = $filters['search'];
                    $query->where(function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%");
                    });
                }

                // Load all warehouse stock
                $query->with(['warehouses']);

                $query->orderBy('name');
            },
            'products.sellableUnit'
        ])->get();

        // 2. Prepare unified data structure for categories and products
        $categoryData = [];
        foreach ($categories as $cat) {
            if ($cat->products->isNotEmpty()) {
                $categoryData[] = (object) [
                    'name' => $cat->name,
                    'products' => $cat->products
                ];
            }
        }

        // 3. Include uncategorized products if no specific category is selected
        if (empty($filters['category_id'])) {
            $unCatQuery = Product::whereNull('category_id')
                ->with(['warehouses', 'sellableUnit']);

            if (!empty($filters['search'])) {
                $search = $filters['search'];
                $unCatQuery->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            }

            $uncategorizedProducts = $unCatQuery->orderBy('name')->get();

            if ($uncategorizedProducts->isNotEmpty()) {
                $categoryData[] = (object) [
                    'name' => 'غير مصنف', // Uncategorized in Arabic
                    'products' => $uncategorizedProducts
                ];
            }
        }

        // Create PDF - A5 size
        $pdf = new MyCustomTCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetTitle('محضر حصر كميات');
        $pdf->setPrintHeader(false); // Custom header in the body
        $pdf->SetMargins(5, 5, 5); // Smaller margins for A5
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->AddPage();
        $pdf->setRTL(true);

        // Header Section
        $pdf->SetFont('arial', 'B', 12);
        $pdf->Cell(0, 7, 'بسم الله الرحمن الرحيم', 0, 1, 'C');
        $pdf->SetFont('arial', 'B', 14);
        $pdf->Cell(0, 8, 'شركة أوقر للإستثمار والإنتاج الغذائي المحدودة', 0, 1, 'C');
        $pdf->Ln(2);

        $pdf->SetFont('arial', 'B', 10);
        $whNames = $warehouses->pluck('name')->implode(' + ');
        $reportTitle = 'محضر حصر بكميات البضائع الموجود بمخازن الشركة ( ' . $whNames . ' ) بتاريخ ' . now()->format('d/m/Y') . 'م';
        $pdf->MultiCell(0, 6, $reportTitle, 0, 'C');
        $pdf->Ln(3);

        // Table Layout Calculations (A4 Landscape width = 297mm, Margins 5+5=10, Total 287mm)
        $colNumWidth = 10;
        $colNameWidth = 140; // Increased width (was 120 + 20 from Category)
        $colUnitWidth = 18;

        $fixedWidth = $colNumWidth + $colNameWidth + $colUnitWidth; // 10 + 140 + 18 = 168mm
        $colSumWidth = 25; // Total Balance column

        $availableForWh = 287 - $fixedWidth - $colSumWidth; // 287 - 168 - 25 = 94mm

        $colWhWidth = $whCount > 0 ? floor($availableForWh / $whCount) : 0;

        // Increase warehouse columns by a third
        $colWhWidth = floor($colWhWidth * 1.33);

        // Ensure minimum width
        if ($colWhWidth < 15)
            $colWhWidth = 15;

        // Recalculate Name width to fit exactly
        $currentTotal = $fixedWidth + $colSumWidth + ($whCount * $colWhWidth);
        if ($currentTotal > 287) {
            $colNameWidth -= ($currentTotal - 287);
        } else if ($currentTotal < 287) {
            // Distribute extra space to Name column
            $colNameWidth += (287 - $currentTotal);
        }

        // Table Header
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(68, 114, 196);
        $pdf->SetTextColor(255, 255, 255);

        // Header cells (Reverted RTL order)
        $pdf->Cell($colSumWidth, 9, 'رصيد الصنف', 1, 0, 'C', true);

        // Loop for warehouses
        foreach ($warehouses->reverse() as $wh) {
            $pdf->Cell($colWhWidth, 9, $wh->name, 1, 0, 'C', true);
        }

        $pdf->Cell($colUnitWidth, 9, 'الوحدة', 1, 0, 'C', true);
        $pdf->Cell($colNameWidth, 9, 'بيان الصنف', 1, 0, 'C', true);
        $pdf->Cell($colNumWidth, 9, 'رقم', 1, 1, 'C', true);

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('arial', '', 8);

        // Category Colors
        $catColors = [
            'محولات سكنية' => [146, 208, 80],
            'منظمات' => [0, 176, 240],
            'بطاريات ليثيوم' => [255, 255, 0],
            'نواشف زراعية' => [0, 176, 80],
            'default' => [255, 255, 255]
        ];

        foreach ($categoryData as $category) {
            $products = $category->products;
            if ($products->isEmpty())
                continue;

            $catName = $category->name;
            $color = $catColors[$catName] ?? $catColors['default'];
            $pdf->SetFillColor($color[0], $color[1], $color[2]);
            $pdf->SetFont('arial', 'B', 11);
            $pdf->Cell(287, 8, $catName, 1, 1, 'C', true);

            $startY = $pdf->GetY();

            foreach ($products as $index => $product) {
                if ($pdf->GetY() + 8 > $pdf->getPageHeight() - 10) {
                    $pdf->AddPage();
                    $startY = $pdf->GetY();
                }

                $pdf->SetFont('arial', 'B', 10);
                $pdf->SetTextColor(255, 0, 0);

                // Total Balance (Red)
                $totalStock = 0;
                foreach ($warehouses as $wh) {
                    $totalStock += $product->warehouses->where('id', $wh->id)->first()?->pivot->quantity ?? 0;
                }
                $pdf->Cell($colSumWidth, 8, $totalStock, 1, 0, 'C', true);

                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFont('arial', '', 9);

                // Individual warehouses
                foreach ($warehouses->reverse() as $wh) {
                    $stock = $product->warehouses->where('id', $wh->id)->first()?->pivot->quantity ?? 0;
                    $pdf->Cell($colWhWidth, 8, $stock, 1, 0, 'C', true);
                }

                $pdf->Cell($colUnitWidth, 8, $product->sellableUnit?->name ?: 'وحدة', 1, 0, 'C', true);

                $x = $pdf->GetX();
                $y = $pdf->GetY();
                $pdf->MultiCell($colNameWidth, 8, $product->name, 1, 'L', true, 0);
                $pdf->SetXY($x + $colNameWidth, $y);

                $pdf->Cell($colNumWidth, 8, $index + 1, 1, 1, 'C', true);
            }

            $pdf->SetXY(5, $pdf->GetY());
        }

        return $pdf->Output('inventory_audit.pdf', 'S');
    }
}
