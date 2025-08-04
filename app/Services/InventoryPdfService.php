<?php

namespace App\Services;

use App\Models\Product;
use App\Services\Pdf\MyCustomTCPDF;
use Illuminate\Support\Facades\DB;

class InventoryPdfService
{
    /**
     * Generate a professional inventory PDF report
     *
     * @param array $filters
     * @return string PDF content
     */
    public function generateInventoryPdf(array $filters = []): string
    {
        // Build query with filters
        $query = Product::query()
            ->with(['category', 'stockingUnit', 'sellableUnit']);

        // Apply filters
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['low_stock_only'])) {
            $query->whereNotNull('stock_alert_level')
                ->whereColumn('stock_quantity', '<=', 'stock_alert_level');
        }

        if (!empty($filters['out_of_stock_only'])) {
            $query->where('stock_quantity', '<=', 0);
        }

        // Get all products (no pagination for PDF)
        $products = $query->orderBy('name')->get();

        // Create PDF using the custom TCPDF class
        $pdf = new MyCustomTCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

        // Set document information
        $pdf->SetTitle('Inventory Report');
        $pdf->SetSubject('Inventory Report');

        // Add a page
        $pdf->AddPage();

        // Create the HTML content
        $html = $this->generateHtmlContent($products, $filters);

        // Print text using writeHTMLCell()
        $pdf->writeHTML($html, true, false, true, false, '');

        // Return PDF content
        return $pdf->Output('inventory_report.pdf', 'S');
    }

    /**
     * Generate HTML content for the PDF
     *
     * @param \Illuminate\Database\Eloquent\Collection $products
     * @param array $filters
     * @return string
     */
    private function generateHtmlContent($products, array $filters): string
    {
        $totalProducts = $products->count();
        $totalInStock = $products->where('stock_quantity', '>', 0)->count();
        $totalOutOfStock = $products->where('stock_quantity', '<=', 0)->count();
        $totalLowStock = $products->filter(function ($product) {
            return $product->stock_alert_level && $product->stock_quantity > 0 && $product->stock_quantity <= $product->stock_alert_level;
        })->count();

        $html = '
        <style>
            body { font-family: arial; direction: rtl; text-align: right; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { border: 1px solid #ddd; padding: 4px; text-align: center; font-size: 7px; font-family: arial; }
            th { background-color: #4472C4; color: white; font-weight: bold; font-family: arial; }
            .summary { margin-bottom: 20px; background-color: #f8f9fa; padding: 10px; border-radius: 5px; }
            .summary-item { margin: 3px 0; font-family: arial; font-size: 9px; }
            .low-stock { background-color: #fff3cd; }
            .out-of-stock { background-color: #f8d7da; }
            .in-stock { background-color: #d1edff; }
            h2 { text-align: center; margin-bottom: 15px; font-family: arial; color: #4472C4; }
            .header-info { text-align: center; margin-bottom: 10px; font-family: arial; font-size: 8px; }
        </style>';

        // Header section
        $html .= '<div class="header-info">';
        $html .= '<h2>تقرير المخزون</h2>';
        $html .= '<div><strong>تاريخ التقرير:</strong> ' . now()->format('Y-m-d H:i:s') . '</div>';
        $html .= '</div>';

        // Summary section
        $html .= '<div class="summary">';
        $html .= '<div class="summary-item"><strong>إجمالي المنتجات:</strong> ' . $totalProducts . '</div>';
        $html .= '<div class="summary-item"><strong>متوفر:</strong> ' . $totalInStock . '</div>';
        $html .= '<div class="summary-item"><strong>مخزون منخفض:</strong> ' . $totalLowStock . '</div>';
        $html .= '<div class="summary-item"><strong>غير متوفر:</strong> ' . $totalOutOfStock . '</div>';
        
        if (!empty($filters['search'])) {
            $html .= '<div class="summary-item"><strong>مصطلح البحث:</strong> ' . htmlspecialchars($filters['search']) . '</div>';
        }
        
        if (!empty($filters['low_stock_only'])) {
            $html .= '<div class="summary-item"><strong>الفلتر:</strong> مخزون منخفض فقط</div>';
        }
        
        if (!empty($filters['out_of_stock_only'])) {
            $html .= '<div class="summary-item"><strong>الفلتر:</strong> غير متوفر فقط</div>';
        }
        
        $html .= '</div>';

        // Products table
        $html .= '
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>رمز المنتج</th>
                    <th>اسم المنتج</th>
                    <th>المخزون الحالي</th>
                    <th>مستوى التنبيه</th>
                    <th>آخر تكلفة</th>
                    <th>آخر سعر بيع</th>
                    <th>إجمالي المشتريات</th>
                    <th>إجمالي المبيعات</th>
                    <th>الوحدة القابلة للبيع</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($products as $index => $product) {
            $stockStatus = $this->getStockStatus($product);
            $rowClass = $this->getRowClass($product);
            
            $html .= '<tr class="' . $rowClass . '">';
            $html .= '<td>' . ($index + 1) . '</td>';
            $html .= '<td>' . htmlspecialchars($product->sku ?: '-') . '</td>';
            $html .= '<td>' . htmlspecialchars($product->name) . '</td>';
            $html .= '<td>' . number_format($product->stock_quantity) . '</td>';
            $html .= '<td>' . ($product->stock_alert_level ? number_format($product->stock_alert_level) : '-') . '</td>';
                    $html .= '<td>' . ($product->latest_cost_per_sellable_unit ? number_format($product->latest_cost_per_sellable_unit, 0) : '-') . '</td>';
        $html .= '<td>' . ($product->last_sale_price_per_sellable_unit ? number_format($product->last_sale_price_per_sellable_unit, 0) : '-') . '</td>';
            $html .= '<td>' . number_format($product->total_items_purchased) . '</td>';
            $html .= '<td>' . number_format($product->total_items_sold) . '</td>';
            $html .= '<td>' . htmlspecialchars($product->sellableUnit?->name ?: '-') . '</td>';
            $html .= '<td>' . $stockStatus . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
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
     * Get CSS class for table row based on stock status
     *
     * @param Product $product
     * @return string
     */
    private function getRowClass(Product $product): string
    {
        if ($product->stock_quantity <= 0) {
            return 'out-of-stock';
        }
        
        if ($product->stock_alert_level && $product->stock_quantity <= $product->stock_alert_level) {
            return 'low-stock';
        }
        
        return 'in-stock';
    }
} 