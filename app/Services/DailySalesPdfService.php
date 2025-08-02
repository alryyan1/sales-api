<?php

namespace App\Services;

use App\Models\Sale;
use App\Services\Pdf\MyCustomTCPDF;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DailySalesPdfService
{
    /**
     * Generate a PDF report of daily sales
     *
     * @param string|null $date
     * @return string PDF content
     */
    public function generateDailySalesPdf(?string $date = null): string
    {
        // Use today's date if no date provided
        $targetDate = $date ? Carbon::parse($date) : Carbon::today();
        
        // Get sales for the specified date (include sales with items or payments)
        $sales = Sale::with(['items.product', 'client', 'payments'])
            ->whereDate('sale_date', $targetDate)
            ->where(function($query) {
                $query->whereHas('items')->orWhereHas('payments');
            })
            ->orderBy('created_at', 'asc')
            ->get();

        // Calculate summary statistics
        $summary = $this->calculateSummary($sales);

        // Create PDF using the custom TCPDF class
        $pdf = new MyCustomTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // Set document information
        $pdf->SetTitle('Daily Sales Report - ' . $targetDate->format('Y-m-d'));
        $pdf->SetSubject('Daily Sales Report');

        // Add a page
        $pdf->AddPage();

        // Generate PDF content using cell method
        $this->generatePdfContent($pdf, $sales, $summary, $targetDate);

        // Return PDF content
        return $pdf->Output('daily_sales_report_' . $targetDate->format('Y-m-d') . '.pdf', 'S');
    }

    /**
     * Calculate summary statistics for the sales
     *
     * @param \Illuminate\Database\Eloquent\Collection $sales
     * @return array
     */
    private function calculateSummary($sales): array
    {
        $totalSales = $sales->count();
        $totalAmount = $sales->sum('total_amount');
        $totalItems = $sales->sum(function ($sale) {
            return $sale->items->sum('quantity');
        });

        // Group by payment method
        $paymentMethods = [];
        foreach ($sales as $sale) {
            foreach ($sale->payments as $payment) {
                $method = $payment->method;
                if (!isset($paymentMethods[$method])) {
                    $paymentMethods[$method] = 0;
                }
                $paymentMethods[$method] += $payment->amount;
            }
        }

        return [
            'totalSales' => $totalSales,
            'totalAmount' => $totalAmount,
            'totalItems' => $totalItems,
            'paymentMethods' => $paymentMethods,
            'averageSale' => $totalSales > 0 ? $totalAmount / $totalSales : 0
        ];
    }

    /**
     * Generate PDF content using TCPDF cell method
     *
     * @param MyCustomTCPDF $pdf
     * @param \Illuminate\Database\Eloquent\Collection $sales
     * @param array $summary
     * @param Carbon $date
     * @return void
     */
    private function generatePdfContent($pdf, $sales, array $summary, Carbon $date): void
    {
        // Set RTL direction
        $pdf->setRTL(true);

        // Professional Header
        $this->generateHeader($pdf, $date);
        $pdf->Ln(8);

        // Executive Summary
        $this->generateExecutiveSummary($pdf, $summary);
        $pdf->Ln(10);

        // Payment Methods Summary
        if (!empty($summary['paymentMethods'])) {
            $this->generatePaymentMethodsTable($pdf, $summary);
            $pdf->Ln(10);
        }

        // Detailed Sales Table
        if ($sales->count() > 0) {
            $this->generateSalesTable($pdf, $sales);
        } else {
            $pdf->SetFont('arial', '', 14);
            $pdf->Cell(0, 20, 'لا توجد مبيعات لهذا اليوم', 0, 1, 'C');
        }

        // Footer
        $this->generateFooter($pdf);
    }

    /**
     * Generate professional header
     *
     * @param MyCustomTCPDF $pdf
     * @param Carbon $date
     * @return void
     */
    private function generateHeader($pdf, Carbon $date): void
    {
        // Company header with border
        $pdf->SetFillColor(51, 122, 183);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('arial', 'B', 20);
        $pdf->Cell(0, 12, 'تقرير المبيعات اليومية', 0, 1, 'C', true);
        
        // Date and time
        $pdf->SetTextColor(51, 51, 51);
        $pdf->SetFont('arial', '', 12);
        $pdf->Cell(0, 8, 'التاريخ: ' . $date->format('Y-m-d') . ' (' . $this->getArabicDayName($date) . ')', 0, 1, 'C');
        $pdf->Cell(0, 6, 'وقت التقرير: ' . now()->format('H:i:s'), 0, 1, 'C');
        
        // Reset colors
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * Generate executive summary section
     *
     * @param MyCustomTCPDF $pdf
     * @param array $summary
     * @return void
     */
    private function generateExecutiveSummary($pdf, array $summary): void
    {
        $pdf->SetFont('arial', 'B', 16);
        $pdf->SetTextColor(51, 122, 183);
        $pdf->Cell(0, 10, 'الملخص التنفيذي', 0, 1, 'R');
        $pdf->Ln(3);

        // Summary table with professional styling
        $pdf->SetFont('arial', 'B', 11);
        $pdf->SetFillColor(248, 249, 250);
        $pdf->SetTextColor(51, 51, 51);
        
        // Table headers
        $pdf->Cell(47.5, 10, 'إجمالي المبيعات', 1, 0, 'C', true);
        $pdf->Cell(47.5, 10, 'إجمالي المبلغ', 1, 0, 'C', true);
        $pdf->Cell(47.5, 10, 'إجمالي العناصر', 1, 0, 'C', true);
        $pdf->Cell(47.5, 10, 'متوسط المبيعات', 1, 1, 'C', true);

        // Table data
        $pdf->SetFont('arial', 'B', 12);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Cell(47.5, 10, (string)$summary['totalSales'], 1, 0, 'C', true);
        $pdf->Cell(47.5, 10, number_format($summary['totalAmount'], 2), 1, 0, 'C', true);
        $pdf->Cell(47.5, 10, (string)$summary['totalItems'], 1, 0, 'C', true);
        $pdf->Cell(47.5, 10, number_format($summary['averageSale'], 2), 1, 1, 'C', true);
        
        // Reset colors
        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * Generate professional footer
     *
     * @param MyCustomTCPDF $pdf
     * @return void
     */
    private function generateFooter($pdf): void
    {
        $pdf->Ln(15);
        $pdf->SetFont('arial', '', 9);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->Cell(0, 6, 'تم إنشاء هذا التقرير تلقائياً بواسطة نظام إدارة المبيعات', 0, 1, 'C');
        $pdf->Cell(0, 6, 'صفحة ' . $pdf->getAliasNumPage() . ' من ' . $pdf->getAliasNbPages(), 0, 1, 'C');
    }

    /**
     * Get Arabic day name
     *
     * @param Carbon $date
     * @return string
     */
    private function getArabicDayName(Carbon $date): string
    {
        $days = [
            'Sunday' => 'الأحد',
            'Monday' => 'الاثنين',
            'Tuesday' => 'الثلاثاء',
            'Wednesday' => 'الأربعاء',
            'Thursday' => 'الخميس',
            'Friday' => 'الجمعة',
            'Saturday' => 'السبت'
        ];
        
        return $days[$date->format('l')] ?? $date->format('l');
    }

    /**
     * Generate payment methods table
     *
     * @param MyCustomTCPDF $pdf
     * @param array $summary
     * @return void
     */
    private function generatePaymentMethodsTable($pdf, array $summary): void
    {
        $pdf->SetFont('arial', 'B', 16);
        $pdf->SetTextColor(51, 122, 183);
        $pdf->Cell(0, 10, 'تحليل طرق الدفع', 0, 1, 'R');
        $pdf->Ln(3);

        // Table headers
        $pdf->SetFont('arial', 'B', 11);
        $pdf->SetFillColor(248, 249, 250);
        $pdf->SetTextColor(51, 51, 51);
        $pdf->Cell(65, 10, 'طريقة الدفع', 1, 0, 'C', true);
        $pdf->Cell(45, 10, 'المبلغ', 1, 0, 'C', true);
        $pdf->Cell(40, 10, 'النسبة المئوية', 1, 1, 'C', true);

        // Table data
        $pdf->SetFont('arial', '', 10);
        $pdf->SetFillColor(255, 255, 255);
        foreach ($summary['paymentMethods'] as $method => $amount) {
            $percentage = $summary['totalAmount'] > 0 ? ($amount / $summary['totalAmount']) * 100 : 0;
            $pdf->Cell(65, 8, $this->getPaymentMethodName($method), 1, 0, 'C', true);
            $pdf->Cell(45, 8, number_format($amount, 2), 1, 0, 'C', true);
            $pdf->Cell(40, 8, number_format($percentage, 1) . '%', 1, 1, 'C', true);
        }
        
        // Reset colors
        $pdf->SetTextColor(0, 0, 0);
    }



    /**
     * Generate detailed sales table
     *
     * @param MyCustomTCPDF $pdf
     * @param \Illuminate\Database\Eloquent\Collection $sales
     * @return void
     */
    private function generateSalesTable($pdf, $sales): void
    {
        $pdf->SetFont('arial', 'B', 16);
        $pdf->SetTextColor(51, 122, 183);
        $pdf->Cell(0, 10, 'تفاصيل المبيعات', 0, 1, 'R');
        $pdf->Ln(3);

        // Table headers
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(248, 249, 250);
        $pdf->SetTextColor(51, 51, 51);
        $pdf->Cell(25, 10, 'رقم المبيعات', 1, 0, 'C', true);
        $pdf->Cell(20, 10, 'الوقت', 1, 0, 'C', true);
        $pdf->Cell(40, 10, 'العميل', 1, 0, 'C', true);
        $pdf->Cell(25, 10, 'العناصر', 1, 0, 'C', true);
        $pdf->Cell(30, 10, 'المبلغ', 1, 0, 'C', true);
        $pdf->Cell(50, 10, 'طرق الدفع', 1, 1, 'C', true);

        // Table data
        $pdf->SetFont('arial', '', 9);
        $pdf->SetFillColor(255, 255, 255);
        $rowCount = 0;
        foreach ($sales as $sale) {
            // Alternate row colors for better readability
            $fillColor = ($rowCount % 2 == 0) ? [255, 255, 255] : [248, 249, 250];
            $pdf->SetFillColor($fillColor[0], $fillColor[1], $fillColor[2]);
            
            $pdf->Cell(25, 8, ($sale->sale_order_number ?? $sale->id), 1, 0, 'C', true);
            $pdf->Cell(20, 8, Carbon::parse($sale->created_at)->format('H:i'), 1, 0, 'C', true);
            $pdf->Cell(40, 8, ($sale->client ? $sale->client->name : 'بدون عميل'), 1, 0, 'C', true);
            $pdf->Cell(25, 8, $sale->items->sum('quantity'), 1, 0, 'C', true);
            $pdf->Cell(30, 8, number_format($sale->total_amount, 2), 1, 0, 'C', true);
            $pdf->Cell(50, 8, $this->formatPaymentMethods($sale->payments), 1, 1, 'C', true);
            $rowCount++;
        }
        
        // Reset colors
        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * Get payment method name in Arabic
     *
     * @param string $method
     * @return string
     */
    private function getPaymentMethodName(string $method): string
    {
        $methods = [
            'cash' => 'نقداً',
            'visa' => 'فيزا',
            'mastercard' => 'ماستركارد',
            'bank_transfer' => 'تحويل بنكي',
            'mada' => 'مدى',
            'store_credit' => 'رصيد المحل',
            'other' => 'أخرى'
        ];

        return $methods[$method] ?? $method;
    }

    /**
     * Format payment methods for display
     *
     * @param \Illuminate\Database\Eloquent\Collection $payments
     * @return string
     */
    private function formatPaymentMethods($payments): string
    {
        $formatted = [];
        foreach ($payments as $payment) {
            $methodName = $this->getPaymentMethodName($payment->method);
            $formatted[] = $methodName . ' (' . number_format($payment->amount, 2) . ')';
        }
        return implode(', ', $formatted);
    }
} 