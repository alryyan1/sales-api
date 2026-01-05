<?php

namespace App\Services;

use App\Models\Sale;
use Carbon\Carbon;

class SaleDetailPdfService
{
    /**
     * Generate PDF report for a single sale with full details
     *
     * @param Sale $sale
     * @return string PDF content
     */
    public function generate(Sale $sale): string
    {
        // Get settings
        $settings = (new SettingsService())->getAll();
        $companyName = $settings['company_name'] ?? 'Company';
        $companyAddress = $settings['company_address'] ?? '';
        $companyPhone = $settings['company_phone'] ?? '';
        $currencySymbol = $settings['currency_symbol'] ?? 'SDG';

        // Generate PDF
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Sales System');
        $pdf->SetAuthor('Sales System');
        $pdf->SetTitle('Sale Detail Report #' . $sale->id);
        $pdf->SetSubject('Sale Detail Report');
        $pdf->SetMargins(20, 25, 20);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->setRTL(true);
        $pdf->AddPage();

        // Formal Report Header
        $pdf->SetFont('arial', 'B', 18);
        $pdf->Cell(0, 8, $companyName, 0, 1, 'C');
        
        if ($companyAddress) {
            $pdf->SetFont('arial', '', 10);
            $pdf->Cell(0, 5, $companyAddress, 0, 1, 'C');
        }
        
        if ($companyPhone) {
            $pdf->SetFont('arial', '', 9);
            $pdf->Cell(0, 5, 'هاتف: ' . $companyPhone, 0, 1, 'C');
        }
        
        // Horizontal line separator
        $pdf->SetLineWidth(0.5);
        $pdf->Line(20, $pdf->GetY() + 3, 190, $pdf->GetY() + 3);
        $pdf->Ln(8);
        
        // Report Title
        $pdf->SetFont('arial', 'B', 16);
        $pdf->Cell(0, 8, 'تفاصيل عملية البيع', 0, 1, 'C');
        $pdf->Ln(3);
        
        // Sale Information Section
        $pdf->SetFont('arial', 'B', 12);
        $pdf->Cell(0, 7, 'معلومات العملية', 0, 1, 'R');
        $pdf->SetLineWidth(0.3);
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(4);
        
        $pdf->SetFont('arial', 'B', 10);
        $pdf->Cell(60, 7, 'رقم العملية:', 'B', 0, 'R');
        $pdf->SetFont('arial', '', 10);
        $pdf->Cell(50, 7, '#' . $sale->id, 'B', 1, 'L');
        
        $pdf->SetFont('arial', 'B', 10);
        $pdf->Cell(60, 7, 'التاريخ:', 'B', 0, 'R');
        $pdf->SetFont('arial', '', 10);
        $pdf->Cell(50, 7, Carbon::parse($sale->sale_date)->format('Y-m-d'), 'B', 1, 'L');
        
        if ($sale->invoice_number) {
            $pdf->SetFont('arial', 'B', 10);
            $pdf->Cell(60, 7, 'رقم الفاتورة:', 'B', 0, 'R');
            $pdf->SetFont('arial', '', 10);
            $pdf->Cell(50, 7, $sale->invoice_number, 'B', 1, 'L');
        }
        
        if ($sale->sale_order_number) {
            $pdf->SetFont('arial', 'B', 10);
            $pdf->Cell(60, 7, 'رقم الطلب:', 'B', 0, 'R');
            $pdf->SetFont('arial', '', 10);
            $pdf->Cell(50, 7, $sale->sale_order_number, 'B', 1, 'L');
        }
        
        if ($sale->client) {
            $pdf->SetFont('arial', 'B', 10);
            $pdf->Cell(60, 7, 'العميل:', 'B', 0, 'R');
            $pdf->SetFont('arial', '', 10);
            $pdf->Cell(50, 7, $sale->client->name, 'B', 1, 'L');
        }
        
        if ($sale->user) {
            $pdf->SetFont('arial', 'B', 10);
            $pdf->Cell(60, 7, 'المستخدم:', 'B', 0, 'R');
            $pdf->SetFont('arial', '', 10);
            $pdf->Cell(50, 7, $sale->user->name, 'B', 1, 'L');
        }
        
        if ($sale->shift_id) {
            $pdf->SetFont('arial', 'B', 10);
            $pdf->Cell(60, 7, 'الوردية:', 'B', 0, 'R');
            $pdf->SetFont('arial', '', 10);
            $pdf->Cell(50, 7, '#' . $sale->shift_id, 'B', 1, 'L');
        }
        
        $pdf->Ln(5);

        // Sale Items Section
        if ($sale->items && $sale->items->count() > 0) {
            $pdf->SetFont('arial', 'B', 12);
            $pdf->Cell(0, 7, 'عناصر البيع (' . $sale->items->count() . ')', 0, 1, 'R');
            $pdf->SetLineWidth(0.3);
            $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
            $pdf->Ln(4);

            // Items Table Header
            $pdf->SetFont('arial', 'B', 9);
            $pdf->SetFillColor(240, 240, 240);
            $pdf->Cell(80, 7, 'المنتج', 1, 0, 'C', true);
            $pdf->Cell(25, 7, 'الكمية', 1, 0, 'C', true);
            $pdf->Cell(30, 7, 'سعر الوحدة', 1, 0, 'C', true);
            $pdf->Cell(35, 7, 'الإجمالي', 1, 1, 'C', true);

            // Items Table Rows
            $pdf->SetFont('arial', '', 9);
            $rowCount = 0;
            $subtotal = 0;
            
            foreach ($sale->items as $item) {
                $itemTotal = (float) ($item->total_price ?? ($item->quantity * $item->unit_price));
                $subtotal += $itemTotal;
                
                $fill = ($rowCount % 2 == 0);
                $pdf->SetFillColor($fill ? 250 : 255, $fill ? 250 : 255, $fill ? 250 : 255);
                
                $productName = $item->product_name ?? $item->product?->name ?? 'منتج غير معروف';
                $pdf->Cell(80, 6, mb_substr($productName, 0, 35), 1, 0, 'R', $fill);
                $pdf->Cell(25, 6, number_format($item->quantity, 2), 1, 0, 'C', $fill);
                $pdf->Cell(30, 6, number_format($item->unit_price, 2), 1, 0, 'L', $fill);
                $pdf->Cell(35, 6, number_format($itemTotal, 2), 1, 1, 'L', $fill);
                
                $rowCount++;
            }
            
            $pdf->Ln(5);
        }

        // Financial Summary Section
        $pdf->SetFont('arial', 'B', 12);
        $pdf->Cell(0, 7, 'الملخص المالي', 0, 1, 'R');
        $pdf->SetLineWidth(0.3);
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(4);
        
        $subtotal = $sale->items->sum(function ($item) {
            return (float) ($item->total_price ?? ($item->quantity * $item->unit_price));
        });
        
        $pdf->SetFont('arial', 'B', 10);
        $pdf->Cell(90, 7, 'المجموع الفرعي:', 'B', 0, 'R');
        $pdf->SetFont('arial', '', 10);
        $pdf->Cell(50, 7, number_format($subtotal, 2) . ' ' . $currencySymbol, 'B', 1, 'L');
        
        if ($sale->discount_amount && $sale->discount_amount > 0) {
            $pdf->SetFont('arial', 'B', 10);
            $pdf->Cell(90, 7, 'الخصم (' . ($sale->discount_type === 'percentage' ? $sale->discount_amount . '%' : 'ثابت') . '):', 'B', 0, 'R');
            $pdf->SetFont('arial', '', 10);
            $pdf->Cell(50, 7, number_format($sale->discount_amount, 2) . ' ' . $currencySymbol, 'B', 1, 'L');
        }
        
        $pdf->SetFont('arial', 'B', 10);
        $pdf->Cell(90, 7, 'المبلغ الإجمالي:', 'B', 0, 'R');
        $pdf->SetFont('arial', '', 10);
        $pdf->Cell(50, 7, number_format($sale->total_amount, 2) . ' ' . $currencySymbol, 'B', 1, 'L');
        
        $pdf->SetFont('arial', 'B', 10);
        $pdf->Cell(90, 7, 'المدفوع:', 'B', 0, 'R');
        $pdf->SetFont('arial', '', 10);
        $pdf->Cell(50, 7, number_format($sale->paid_amount ?? 0, 2) . ' ' . $currencySymbol, 'B', 1, 'L');
        
        $dueAmount = ($sale->due_amount ?? ($sale->total_amount - ($sale->paid_amount ?? 0)));
        $pdf->SetFont('arial', 'B', 10);
        $pdf->Cell(90, 7, 'المستحق:', 'B', 0, 'R');
        $pdf->SetFont('arial', '', 10);
        $pdf->Cell(50, 7, number_format($dueAmount, 2) . ' ' . $currencySymbol, 'B', 1, 'L');
        
        $pdf->Ln(5);

        // Payments Section
        if ($sale->payments && $sale->payments->count() > 0) {
            $pdf->SetFont('arial', 'B', 12);
            $pdf->Cell(0, 7, 'المدفوعات (' . $sale->payments->count() . ')', 0, 1, 'R');
            $pdf->SetLineWidth(0.3);
            $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
            $pdf->Ln(4);

            // Payments Table Header
            $pdf->SetFont('arial', 'B', 9);
            $pdf->SetFillColor(240, 240, 240);
            $pdf->Cell(50, 7, 'طريقة الدفع', 1, 0, 'C', true);
            $pdf->Cell(40, 7, 'المبلغ', 1, 0, 'C', true);
            $pdf->Cell(50, 7, 'التاريخ', 1, 0, 'C', true);
            $pdf->Cell(45, 7, 'ملاحظات', 1, 1, 'C', true);

            // Payments Table Rows
            $pdf->SetFont('arial', '', 9);
            $rowCount = 0;
            
            foreach ($sale->payments as $payment) {
                $fill = ($rowCount % 2 == 0);
                $pdf->SetFillColor($fill ? 250 : 255, $fill ? 250 : 255, $fill ? 250 : 255);
                
                $methodLabel = $this->getPaymentMethodLabel($payment->method ?? 'cash');
                $pdf->Cell(50, 6, $methodLabel, 1, 0, 'C', $fill);
                $pdf->Cell(40, 6, number_format($payment->amount, 2), 1, 0, 'L', $fill);
                
                $paymentDate = $payment->payment_date 
                    ? (strpos($payment->payment_date, 'T') !== false 
                        ? Carbon::parse($payment->payment_date)->format('Y-m-d')
                        : $payment->payment_date)
                    : '-';
                $pdf->Cell(50, 6, $paymentDate, 1, 0, 'C', $fill);
                $pdf->Cell(45, 6, mb_substr($payment->notes ?? '-', 0, 20), 1, 1, 'R', $fill);
                
                $rowCount++;
            }
            
            // $pdf->Ln(5);
        }

        // Notes Section
        if ($sale->notes) {
            $pdf->SetFont('arial', 'B', 12);
            $pdf->Cell(0, 7, 'ملاحظات', 0, 1, 'R');
            $pdf->SetLineWidth(0.3);
            $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
            $pdf->Ln(4);
            
            $pdf->SetFont('arial', '', 10);
            $pdf->MultiCell(0, 6, $sale->notes, 0, 'R');
            $pdf->Ln(3);
        }

        // Footer
        // $pdf->SetY(-15);
        // $pdf->SetFont('arial', '', 8);
        // $pdf->Cell(0, 10, 'صفحة ' . $pdf->getAliasNumPage() . ' من ' . $pdf->getAliasNbPages(), 0, 0, 'C');

        // Output PDF
        $pdfFileName = 'sale_detail_' . $sale->id . '_' . now()->format('Y-m-d_His') . '.pdf';
        return $pdf->Output($pdfFileName, 'S');
    }

    /**
     * Get Arabic label for payment method
     *
     * @param string $method
     * @return string
     */
    private function getPaymentMethodLabel($method)
    {
        $labels = [
            'cash' => 'نقدي',
            'visa' => 'فيزا',
            'mastercard' => 'ماستركارد',
            'bank_transfer' => 'تحويل بنكي',
            'mada' => 'مدى',
            'store_credit' => 'رصيد متجر',
            'other' => 'أخرى',
            'refund' => 'استرداد',
        ];
        
        return $labels[$method] ?? $method;
    }
}

