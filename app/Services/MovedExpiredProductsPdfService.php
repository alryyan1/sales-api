<?php

namespace App\Services;

use App\Models\PurchaseItem;
use App\Services\Pdf\MyCustomTCPDF;
use Carbon\Carbon;

class MovedExpiredProductsPdfService
{
    /**
     * Generate a PDF report for moved/expired products
     *
     * @param array $filters
     * @return string PDF content
     */
    public function generatePdf(array $filters = []): string
    {
        // Build query with filters (matches ReportController::movedExpiredProductsReport)
        $query = PurchaseItem::query()
            ->with(['product:id,name,sku'])
            ->where('is_moved_to_expired', true);

        if (!empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (!empty($filters['search'])) {
            $searchTerm = $filters['search'];
            $query->where(function ($q) use ($searchTerm) {
                $q->where('batch_number', 'like', "%{$searchTerm}%")
                    ->orWhereHas('product', function ($q2) use ($searchTerm) {
                        $q2->where('name', 'like', "%{$searchTerm}%")
                            ->orWhere('sku', 'like', "%{$searchTerm}%");
                    });
            });
        }

        $sortBy = $filters['sort_by'] ?? 'expiry_date';
        $sortDirection = $filters['sort_direction'] ?? 'desc';

        if ($sortBy === 'products.name') {
            $query->join('products', 'purchase_items.product_id', '=', 'products.id')
                ->orderBy('products.name', $sortDirection)
                ->select('purchase_items.*');
        } else {
            $query->orderBy($sortBy, $sortDirection);
        }

        // Get all items (no pagination for PDF)
        $items = $query->get();

        // Create PDF using the custom TCPDF class
        $pdf = new MyCustomTCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

        // Set document information
        $pdf->SetTitle('تقرير المنتجات التالفة والمنتهية');
        $pdf->SetSubject('Moved Expired Products Report');

        // Add a page
        $pdf->AddPage();

        // Create the HTML content
        $html = $this->generateHtmlContent($items, $filters);

        // Print text using writeHTMLCell()
        $pdf->writeHTML($html, true, false, true, false, '');

        // Return PDF content
        return $pdf->Output('moved_expired_products_report.pdf', 'S');
    }

    /**
     * Generate HTML content for the PDF
     *
     * @param \Illuminate\Database\Eloquent\Collection $items
     * @param array $filters
     * @return string
     */
    private function generateHtmlContent($items, array $filters): string
    {
        $totalItems = $items->count();
        $totalQuantity = $items->sum('quantity');

        $html = '
        <style>
            body { font-family: arial; direction: rtl; text-align: right; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { border: 1px solid #ddd; padding: 6px; text-align: center; font-size: 10px; font-family: arial; }
            th { background-color: #d32f2f; color: white; font-weight: bold; font-family: arial; }
            .summary { margin-bottom: 20px; background-color: #f8f9fa; padding: 10px; border-radius: 5px; }
            .summary-item { margin: 3px 0; font-family: arial; font-size: 11px; }
            h2 { text-align: center; margin-bottom: 15px; font-family: arial; color: #d32f2f; }
            .header-info { text-align: center; margin-bottom: 10px; font-family: arial; font-size: 10px; }
        </style>';

        // Header section
        $html .= '<div class="header-info">';
        $html .= '<h2>تقرير المنتجات التالفة / المنتهية</h2>';
        $html .= '<div><strong>تاريخ التقرير:</strong> ' . now()->format('Y-m-d H:i:s') . '</div>';
        $html .= '</div>';

        // Summary section
        $html .= '<div class="summary">';
        $html .= '<div class="summary-item"><strong>إجمالي المنتجات المسحوبة:</strong> ' . $totalItems . '</div>';
        $html .= '<div class="summary-item"><strong>إجمالي الكمية المسحوبة:</strong> ' . number_format($totalQuantity) . '</div>';

        if (!empty($filters['search'])) {
            $html .= '<div class="summary-item"><strong>مصطلح البحث:</strong> ' . htmlspecialchars($filters['search']) . '</div>';
        }

        $html .= '</div>';

        // Products table
        $html .= '
        <table>
            <thead>
                <tr>
                    <th width="5%">#</th>
                    <th width="30%">اسم المنتج</th>
                    <th width="15%">الباركود</th>
                    <th width="15%">رقم الدفعة</th>
                    <th width="10%">تاريخ الانتهاء</th>
                    <th width="10%">الكمية المسحوبة</th>
                    <th width="15%">تاريخ السحب</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($items as $index => $item) {
            $expiryDate = $item->expiry_date ? Carbon::parse($item->expiry_date)->format('Y-m-d') : '-';
            $pulledDate = $item->updated_at ? $item->updated_at->format('Y-m-d') : '-';

            $html .= '<tr>';
            $html .= '<td>' . ($index + 1) . '</td>';
            $html .= '<td>' . htmlspecialchars($item->product->name ?? 'منتج غير معروف') . '</td>';
            $html .= '<td>' . htmlspecialchars($item->product->sku ?? '-') . '</td>';
            $html .= '<td>' . htmlspecialchars($item->batch_number ?? '-') . '</td>';
            $html .= '<td dir="ltr">' . $expiryDate . '</td>';
            $html .= '<td><strong>' . number_format($item->quantity) . '</strong></td>';
            $html .= '<td dir="ltr">' . $pulledDate . '</td>';
            $html .= '</tr>';
        }

        if ($items->isEmpty()) {
            $html .= '<tr><td colspan="7">لا توجد بيانات</td></tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }
}
