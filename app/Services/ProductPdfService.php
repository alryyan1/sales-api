<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseItem;
use App\Services\Pdf\MyCustomTCPDF;
use Illuminate\Support\Facades\DB;

class ProductPdfService
{
    /**
     * Generate a PDF report of products
     *
     * @param array $filters
     * @return string PDF content
     */
    public function generateProductsPdf(array $filters = []): string
    {
        // Build query with filters
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
                    ->limit(1)
            ])
            ->with(['category', 'stockingUnit', 'sellableUnit']);

        // Apply filters
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('scientific_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (!empty($filters['in_stock_only'])) {
            $query->where('stock_quantity', '>', 0);
        }

        if (!empty($filters['low_stock_only'])) {
            $query->where(function ($q) {
                $q->whereNotNull('stock_alert_level')
                    ->where('stock_quantity', '<=', DB::raw('stock_alert_level'));
            });
        }

        // Get all products (no pagination for PDF)
        $products = $query->orderBy('name')->get();

        // Create PDF using the custom TCPDF class
        $pdf = new MyCustomTCPDF('L', 'mm', 'A4', true, 'UTF-8', false); // Landscape for better table fit

        // Set document information
        $pdf->SetTitle('Products Report');
        $pdf->SetSubject('Products Report');
        $pdf->SetAutoPageBreak(true, 15);

        // Add a page
        $pdf->AddPage();

        // Generate PDF content using cells
        $this->generatePdfContent($pdf, $products, $filters);

        // Return PDF content
        return $pdf->Output('products_report.pdf', 'S');
    }

    /**
     * Generate PDF content using TCPDF cells
     *
     * @param MyCustomTCPDF $pdf
     * @param \Illuminate\Database\Eloquent\Collection $products
     * @param array $filters
     * @return void
     */
    private function generatePdfContent($pdf, $products, array $filters): void
    {
        $totalProducts = $products->count();
        $totalInStock = $products->where('stock_quantity', '>', 0)->count();
        $totalOutOfStock = $totalProducts - $totalInStock;

        // Title
        $pdf->SetFont('arial', 'B', 16);
        $pdf->Cell(0, 10, 'تقرير المنتجات', 0, 1, 'C');
        $pdf->Ln(5);

        // Summary section
        $pdf->SetFont('arial', '', 10);
        $pdf->Cell(50, 6, 'إجمالي المنتجات:', 0, 0, 'R');
        $pdf->Cell(30, 6, $totalProducts, 0, 1, 'L');

        $pdf->Cell(50, 6, 'متوفر:', 0, 0, 'R');
        $pdf->Cell(30, 6, $totalInStock, 0, 1, 'L');

        $pdf->Cell(50, 6, 'غير متوفر:', 0, 0, 'R');
        $pdf->Cell(30, 6, $totalOutOfStock, 0, 1, 'L');

        if (!empty($filters['search'])) {
            $pdf->Cell(50, 6, 'مصطلح البحث:', 0, 0, 'R');
            $pdf->Cell(100, 6, $filters['search'], 0, 1, 'L');
        }

        if (!empty($filters['category_id'])) {
            $category = \App\Models\Category::find($filters['category_id']);
            if ($category) {
                $pdf->Cell(50, 6, 'الفئة:', 0, 0, 'R');
                $pdf->Cell(100, 6, $category->name, 0, 1, 'L');
            }
        }

        if (!empty($filters['in_stock_only'])) {
            $pdf->Cell(50, 6, 'الفلتر:', 0, 0, 'R');
            $pdf->Cell(100, 6, 'المتوفر فقط', 0, 1, 'L');
        }

        $pdf->Cell(50, 6, 'تاريخ التقرير:', 0, 0, 'R');
        $pdf->Cell(100, 6, now()->format('Y-m-d H:i:s'), 0, 1, 'L');

        $pdf->Ln(5);

        // Table header
        $pdf->SetFont('arial', 'B', 8);
        $pdf->SetFillColor(242, 242, 242);

        // Column widths (total should be ~277mm for A4 landscape)
        $colWidths = [10, 35, 30, 20, 25, 18, 20, 22, 22, 20, 25];

        $pdf->Cell($colWidths[0], 7, '#', 1, 0, 'C', true);
        $pdf->Cell($colWidths[1], 7, 'الاسم', 1, 0, 'C', true);
        $pdf->Cell($colWidths[2], 7, 'الاسم العلمي', 1, 0, 'C', true);
        $pdf->Cell($colWidths[3], 7, 'رمز المنتج', 1, 0, 'C', true);
        $pdf->Cell($colWidths[4], 7, 'الفئة', 1, 0, 'C', true);
        $pdf->Cell($colWidths[5], 7, 'المخزون', 1, 0, 'C', true);
        $pdf->Cell($colWidths[6], 7, 'الوحدة', 1, 0, 'C', true);
        $pdf->Cell($colWidths[7], 7, 'احدث تكلفة', 1, 0, 'C', true);
        $pdf->Cell($colWidths[8], 7, 'اخر سعر بيع', 1, 0, 'C', true);
        $pdf->Cell($colWidths[9], 7, 'مستوى التنبيه', 1, 0, 'C', true);
        $pdf->Cell($colWidths[10], 7, 'الحالة', 1, 1, 'C', true);

        // Table rows
        $pdf->SetFont('arial', '', 7);
        
        // Calculate totals
        $totalCost = 0;
        $totalSellPrice = 0;
        
        foreach ($products as $index => $product) {
            $stockStatus = $this->getStockStatus($product);
            $fillColor = $this->getFillColor($product);
            
            $pdf->SetFillColor($fillColor[0], $fillColor[1], $fillColor[2]);
            
            $pdf->Cell($colWidths[0], 6, ($index + 1), 1, 0, 'C', true);
            $pdf->Cell($colWidths[1], 6, $this->truncate($product->name, 25), 1, 0, 'C', true);
            $pdf->Cell($colWidths[2], 6, $this->truncate($product->scientific_name ?: '-', 20), 1, 0, 'C', true);
            $pdf->Cell($colWidths[3], 6, $product->sku ?: '-', 1, 0, 'C', true);
            $pdf->Cell($colWidths[4], 6, $this->truncate($product->category?->name ?: '-', 18), 1, 0, 'C', true);
            $pdf->Cell($colWidths[5], 6, number_format($product->stock_quantity), 1, 0, 'C', true);
            $pdf->Cell($colWidths[6], 6, $this->truncate($product->sellableUnit?->name ?: '-', 15), 1, 0, 'C', true);
            $pdf->Cell($colWidths[7], 6, $product->latest_cost_per_sellable_unit ? number_format($product->latest_cost_per_sellable_unit, 2) : '-', 1, 0, 'C', true);
            $pdf->Cell($colWidths[8], 6, $product->last_sale_price_per_sellable_unit ? number_format($product->last_sale_price_per_sellable_unit, 2) : '-', 1, 0, 'C', true);
            $pdf->Cell($colWidths[9], 6, $product->stock_alert_level ? number_format($product->stock_alert_level) : '-', 1, 0, 'C', true);
            $pdf->Cell($colWidths[10], 6, $stockStatus, 1, 1, 'C', true);
            
            // Add to totals
            if ($product->latest_cost_per_sellable_unit) {
                $totalCost += $product->latest_cost_per_sellable_unit * $product->stock_quantity;
            }
            if ($product->last_sale_price_per_sellable_unit) {
                $totalSellPrice += $product->last_sale_price_per_sellable_unit * $product->stock_quantity;
            }
        }
        
        // Totals row
        $pdf->SetFont('arial', 'B', 8);
        $pdf->SetFillColor(220, 220, 220);
        
        // Empty cells before totals
        $pdf->Cell($colWidths[0] + $colWidths[1] + $colWidths[2] + $colWidths[3] + $colWidths[4] + $colWidths[5] + $colWidths[6], 7, 'الإجمالي', 1, 0, 'C', true);
        $pdf->Cell($colWidths[7], 7, number_format($totalCost, 2), 1, 0, 'C', true);
        $pdf->Cell($colWidths[8], 7, number_format($totalSellPrice, 2), 1, 0, 'C', true);
        $pdf->Cell($colWidths[9] + $colWidths[10], 7, '', 1, 1, 'C', true);
    }

    /**
     * Truncate text to fit in cell
     *
     * @param string $text
     * @param int $maxLength
     * @return string
     */
    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) > $maxLength) {
            return mb_substr($text, 0, $maxLength - 2) . '..';
        }
        return $text;
    }

    /**
     * Get stock status for a product
     *
     * @param Product $product
     * @return string
     */
    private function getStockStatus(Product $product): string
    {
        if ($product->stock_quantity <= 0) {
            return 'غير متوفر';
        }

        if ($product->stock_alert_level && $product->stock_quantity <= $product->stock_alert_level) {
            return 'مخزون منخفض';
        }

        return 'متوفر';
    }

    /**
     * Get fill color for table row based on stock status
     *
     * @param Product $product
     * @return array RGB color array
     */
    private function getFillColor(Product $product): array
    {
        if ($product->stock_quantity <= 0) {
            return [248, 215, 218]; // Light red (out-of-stock)
        }

        if ($product->stock_alert_level && $product->stock_quantity <= $product->stock_alert_level) {
            return [255, 243, 205]; // Light yellow (low-stock)
        }

        return [209, 237, 255]; // Light blue (in-stock)
    }
}
