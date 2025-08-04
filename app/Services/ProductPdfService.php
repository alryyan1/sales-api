<?php

namespace App\Services;

use App\Models\Product;
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
        $pdf = new MyCustomTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // Set document information
        $pdf->SetTitle('Products Report');
        $pdf->SetSubject('Products Report');

        // Add a page
        $pdf->AddPage();

        // Create the HTML content
        $html = $this->generateHtmlContent($products, $filters);

        // Print text using writeHTMLCell()
        $pdf->writeHTML($html, true, false, true, false, '');

        // Return PDF content
        return $pdf->Output('products_report.pdf', 'S');
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
        $totalOutOfStock = $totalProducts - $totalInStock;

        $html = '
        <style>
            body { font-family: arial; direction: rtl; text-align: right; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { border: 1px solid #ddd; padding: 6px; text-align: center; font-size: 8px; font-family: arial; }
            th { background-color: #f2f2f2; font-weight: bold; font-family: arial; }
            .summary { margin-bottom: 20px; }
            .summary-item { margin: 5px 0; font-family: arial; }
            .low-stock { background-color: #fff3cd; }
            .out-of-stock { background-color: #f8d7da; }
            .in-stock { background-color: #d1edff; }
            h2 { text-align: center; margin-bottom: 15px; font-family: arial; }
        </style>';

        // Summary section
        $html .= '<div class="summary">';
        $html .= '<h2>تقرير المنتجات</h2>';
        $html .= '<div class="summary-item"><strong>إجمالي المنتجات:</strong> ' . $totalProducts . '</div>';
        $html .= '<div class="summary-item"><strong>متوفر:</strong> ' . $totalInStock . '</div>';
        $html .= '<div class="summary-item"><strong>غير متوفر:</strong> ' . $totalOutOfStock . '</div>';
        
        if (!empty($filters['search'])) {
            $html .= '<div class="summary-item"><strong>مصطلح البحث:</strong> ' . htmlspecialchars($filters['search']) . '</div>';
        }
        
        if (!empty($filters['category_id'])) {
            $category = \App\Models\Category::find($filters['category_id']);
            if ($category) {
                $html .= '<div class="summary-item"><strong>الفئة:</strong> ' . htmlspecialchars($category->name) . '</div>';
            }
        }
        
        if (!empty($filters['in_stock_only'])) {
            $html .= '<div class="summary-item"><strong>الفلتر:</strong> المتوفر فقط</div>';
        }
        
        $html .= '<div class="summary-item"><strong>تاريخ التقرير:</strong> ' . now()->format('Y-m-d H:i:s') . '</div>';
        $html .= '</div>';

        // Products table
        $html .= '
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الاسم</th>
                    <th>الاسم العلمي</th>
                    <th>رمز المنتج</th>
                    <th>الفئة</th>
                    <th>المخزون</th>
                    <th>الوحدة</th>
                    <th>مستوى التنبيه</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($products as $index => $product) {
            $stockStatus = $this->getStockStatus($product);
            $rowClass = $this->getRowClass($product);
            
            $html .= '<tr class="' . $rowClass . '">';
            $html .= '<td>' . ($index + 1) . '</td>';
            $html .= '<td>' . htmlspecialchars($product->name) . '</td>';
            $html .= '<td>' . htmlspecialchars($product->scientific_name ?: '-') . '</td>';
            $html .= '<td>' . htmlspecialchars($product->sku ?: '-') . '</td>';
            $html .= '<td>' . htmlspecialchars($product->category?->name ?: '-') . '</td>';
            $html .= '<td>' . number_format($product->stock_quantity) . '</td>';
            $html .= '<td>' . htmlspecialchars($product->sellableUnit?->name ?: '-') . '</td>';
            $html .= '<td>' . ($product->stock_alert_level ? number_format($product->stock_alert_level) : '-') . '</td>';
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