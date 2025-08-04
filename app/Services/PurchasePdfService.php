<?php

namespace App\Services;

use App\Models\Purchase;
use App\Services\Pdf\MyCustomTCPDF;
use Illuminate\Support\Facades\DB;

class PurchasePdfService
{
    /**
     * Generate a PDF report for a specific purchase
     *
     * @param Purchase $purchase
     * @return string PDF content
     */
    public function generatePurchasePdf(Purchase $purchase): string
    {
        // Load the purchase with relationships
        $purchase->load([
            'supplier',
            'user',
            'items.product.category',
            'items.product.stockingUnit',
            'items.product.sellableUnit'
        ]);

        // Create PDF using the custom TCPDF class
        $pdf = new MyCustomTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // Set document information
        $pdf->SetTitle('Purchase Details - #' . $purchase->id);
        $pdf->SetSubject('Purchase Details');

        // Add a page
        $pdf->AddPage();

        // Create the HTML content
        $html = $this->generateHtmlContent($purchase);

        // Print text using writeHTMLCell()
        $pdf->writeHTML($html, true, false, true, false, '');

        // Return PDF content
        return $pdf->Output('purchase_' . $purchase->id . '.pdf', 'S');
    }

    /**
     * Generate HTML content for the PDF
     *
     * @param Purchase $purchase
     * @return string
     */
    private function generateHtmlContent(Purchase $purchase): string
    {
        $html = '
        <style>
            body { font-family: arial; direction: rtl; text-align: right; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: center; font-size: 10px; font-family: arial; }
            th { background-color: #f2f2f2; font-weight: bold; font-family: arial; }
            .header { margin-bottom: 20px; }
            .header-item { margin: 5px 0; font-family: arial; }
            .summary { margin-bottom: 20px; }
            .summary-item { margin: 5px 0; font-family: arial; }
            h2 { text-align: center; margin-bottom: 15px; font-family: arial; }
            .status-received { background-color: #d4edda; color: #155724; }
            .status-pending { background-color: #fff3cd; color: #856404; }
            .status-ordered { background-color: #d1ecf1; color: #0c5460; }
        </style>';

        // Header section
        $html .= '<div class="header">';
        $html .= '<h2>تفاصيل المشتريات</h2>';
        $html .= '<div class="header-item"><strong>رقم المشتريات:</strong> #' . $purchase->id . '</div>';
        $html .= '<div class="header-item"><strong>المورد:</strong> ' . ($purchase->supplier ? htmlspecialchars($purchase->supplier->name) : 'غير محدد') . '</div>';
        $html .= '<div class="header-item"><strong>تاريخ المشتريات:</strong> ' . $purchase->purchase_date . '</div>';
        $html .= '<div class="header-item"><strong>الحالة:</strong> <span class="status-' . $purchase->status . '">' . $this->getStatusText($purchase->status) . '</span></div>';
        
        if ($purchase->reference_number) {
            $html .= '<div class="header-item"><strong>رقم المرجع:</strong> ' . htmlspecialchars($purchase->reference_number) . '</div>';
        }
        
        if ($purchase->notes) {
            $html .= '<div class="header-item"><strong>ملاحظات:</strong> ' . htmlspecialchars($purchase->notes) . '</div>';
        }
        
        $html .= '<div class="header-item"><strong>تم الإنشاء بواسطة:</strong> ' . ($purchase->user ? htmlspecialchars($purchase->user->name) : 'غير محدد') . '</div>';
        $html .= '<div class="header-item"><strong>تاريخ الإنشاء:</strong> ' . $purchase->created_at->format('Y-m-d H:i:s') . '</div>';
        $html .= '</div>';

        // Items table
        $html .= '<table>';
        $html .= '<thead>';
        $html .= '<tr>';
        $html .= '<th>#</th>';
        $html .= '<th>المنتج</th>';
        $html .= '<th>رقم الدفعة</th>';
        $html .= '<th>الكمية</th>';
        $html .= '<th>سعر الوحدة</th>';
        $html .= '<th>السعر الإجمالي</th>';
        $html .= '<th>سعر البيع المقترح</th>';
        $html .= '<th>تاريخ انتهاء الصلاحية</th>';
        $html .= '</tr>';
        $html .= '</thead>';
        $html .= '<tbody>';

        $totalAmount = 0;
        foreach ($purchase->items as $index => $item) {
            $itemTotal = $item->quantity * $item->unit_cost;
            $totalAmount += $itemTotal;
            
            $html .= '<tr>';
            $html .= '<td>' . ($index + 1) . '</td>';
            $html .= '<td>' . ($item->product ? htmlspecialchars($item->product->name) : 'منتج محذوف') . '</td>';
            $html .= '<td>' . ($item->batch_number ? htmlspecialchars($item->batch_number) : '---') . '</td>';
            $html .= '<td>' . number_format($item->quantity) . ' ' . ($item->product && $item->product->stockingUnit ? $item->product->stockingUnit->name : 'وحدة') . '</td>';
                    $html .= '<td>' . number_format($item->unit_cost, 0) . '</td>';
        $html .= '<td>' . number_format($itemTotal, 0) . '</td>';
        $html .= '<td>' . ($item->sale_price ? number_format($item->sale_price, 0) : '---') . '</td>';
            $html .= '<td>' . ($item->expiry_date ? $item->expiry_date : '---') . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '</table>';

        // Summary section
        $html .= '<div class="summary">';
        $html .= '<div class="summary-item"><strong>إجمالي العناصر:</strong> ' . $purchase->items->count() . '</div>';
        $html .= '<div class="summary-item"><strong>إجمالي المبلغ:</strong> ' . number_format($totalAmount, 0) . '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get status text in Arabic
     *
     * @param string $status
     * @return string
     */
    private function getStatusText(string $status): string
    {
        return match ($status) {
            'received' => 'مستلم',
            'pending' => 'قيد الانتظار',
            'ordered' => 'مطلوب',
            default => $status,
        };
    }
} 