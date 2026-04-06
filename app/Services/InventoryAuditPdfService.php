<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Category;
use App\Models\Warehouse;
use App\Services\Pdf\PdfHeaderRenderer;
use Illuminate\Support\Facades\DB;
use TCPDF;

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

        // Fetch products grouped by category
        $categories = Category::with(['products' => function ($query) use ($filters) {
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
        }, 'products.sellableUnit'])->get();

        // Create PDF - A4 Landscape
        $renderer = new PdfHeaderRenderer('inventory_audit');
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetTitle('محضر حصر كميات');
        $pdf->SetMargins(5, $renderer->getTopMargin(), 5);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->AddPage();
        $renderer->render($pdf);
        $pdf->setRTL(false);

        // Header Section
        $pdf->SetFont('arial', 'B', 10);
        $whNames = $warehouses->pluck('name')->implode(' + ');
        $reportTitle = 'محضر حصر بكميات بضائع الطاقة الشمسية الموجود بمخازن الشركة ( ' . $whNames . ' ) بتاريخ ' . now()->format('d/m/Y') . 'م';
        $pdf->MultiCell(0, 6, $reportTitle, 0, 'C');
        $pdf->Ln(3);

        // Table Layout Calculations (A4 Landscape width = 297mm, Margins 5+5=10, Total 287mm)
        $colCatWidth = 20; // Vertical category
        $colNumWidth = 10;
        $colNameWidth = 120; // Much more space for product names
        $colUnitWidth = 18;
        
        $fixedWidth = $colCatWidth + $colNumWidth + $colNameWidth + $colUnitWidth; // 168mm
        $colSumWidth = 25; // Total Balance column
        
        $availableForWh = 287 - $fixedWidth - $colSumWidth; // 287 - 168 - 25 = 94mm
        
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
        $pdf->Cell($colNumWidth, 9, 'رقم', 1, 0, 'C', true);
        $pdf->Cell($colCatWidth, 9, 'تصنيف', 1, 1, 'C', true);

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

        foreach ($categories as $category) {
            $products = $category->products;
            if ($products->isEmpty()) continue;

            $catName = $category->name;
            $color = $catColors[$catName] ?? $catColors['default'];
            $pdf->SetFillColor($color[0], $color[1], $color[2]);

            $startY = $pdf->GetY();
            
            foreach ($products as $index => $product) {
                if ($pdf->GetY() + 8 > $pdf->getPageHeight() - 10) {
                     $pdf->AddPage();
                     $renderer->render($pdf);
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
                
                $pdf->Cell($colNumWidth, 8, $index + 1, 1, 0, 'C', true);
                $pdf->Cell($colCatWidth, 8, '', 1, 1, 'C', true);
            }

            // Vertical category text (at the far left)
            $endY = $pdf->GetY();
            $boxHeight = $endY - $startY;
            $catX = 5 + $colSumWidth + ($whCount * $colWhWidth) + $colUnitWidth + $colNameWidth + $colNumWidth;
            $pdf->SetXY($catX, $startY);
            
            $pdf->StartTransform();
            $pdf->Rotate(90, $catX + ($colCatWidth/2), $startY + ($boxHeight/2));
            $pdf->SetFont('arial', 'B', 11);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell($boxHeight, $colCatWidth, $catName, 0, 0, 'C', false);
            $pdf->StopTransform();
            
            $pdf->SetXY(5, $endY);
        }

        return $pdf->Output('inventory_audit.pdf', 'S');
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
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->setRTL(false); // Important for Arabic text
        $pdf->AddPage();

        // Header
        $pdf->SetFont('arial', 'B', 16);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 15, 'منتجات المستودع', 0, 1, 'C');
        $pdf->SetFont('arial', 'B', 12);
        $pdf->Cell(0, 10, $warehouse->name, 0, 1, 'C');
        $pdf->Ln(5);

        // Table headers
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(240, 240, 240);

        $colWidths = [15, 60, 25, 30, 30, 30]; // ID, Name, SKU, Category, Quantity, Unit Price

        $pdf->Cell($colWidths[0], 10, 'الرقم', 1, 0, 'C', true);
        $pdf->Cell($colWidths[1], 10, 'اسم المنتج', 1, 0, 'C', true);
        $pdf->Cell($colWidths[2], 10, 'الرمز', 1, 0, 'C', true);
        $pdf->Cell($colWidths[3], 10, 'الفئة', 1, 0, 'C', true);
        $pdf->Cell($colWidths[4], 10, 'الكمية', 1, 0, 'C', true);
        $pdf->Cell($colWidths[5], 10, 'السعر', 1, 1, 'C', true);

        // Table data
        $pdf->SetFont('arial', '', 9);
        $pdf->SetFillColor(255, 255, 255);

        $index = 0;
        foreach ($products as $product) {
            $warehouseStock = $product->warehouses->first();
            $quantity = $warehouseStock ? $warehouseStock->pivot->quantity : 0;

            $pdf->Cell($colWidths[0], 8, ++$index, 1, 0, 'C', true);
            $pdf->Cell($colWidths[1], 8, $product->name, 1, 0, 'L', true);
            $pdf->Cell($colWidths[2], 8, $product->sku ?: '-', 1, 0, 'C', true);
            $pdf->Cell($colWidths[3], 8, $product->category?->name ?: '-', 1, 0, 'C', true);
            $pdf->Cell($colWidths[4], 8, number_format($quantity), 1, 0, 'C', true);
            $pdf->Cell($colWidths[5], 8, number_format($product->latest_cost_per_sellable_unit ?: 0, 2), 1, 1, 'R', true);
        }

        // Summary
        $pdf->Ln(5);
        $pdf->SetFont('arial', 'B', 10);
        $pdf->Cell(0, 10, 'إجمالي المنتجات: ' . $products->count(), 0, 1, 'R');

        return $pdf->Output('warehouse_products.pdf', 'S');
    }
}
