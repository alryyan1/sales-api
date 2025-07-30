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
        
        // Get sales for the specified date
        $sales = Sale::with(['items.product', 'client', 'payments'])
            ->whereDate('sale_date', $targetDate)
            ->where('status', 'completed')
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

        // Get top selling products
        $productSales = [];
        foreach ($sales as $sale) {
            foreach ($sale->items as $item) {
                // Try to get product name from the relationship first, then fallback to product_name field
                $productName = null;
                if ($item->product && $item->product->name) {
                    $productName = $item->product->name;
                } elseif ($item->product_name) {
                    $productName = $item->product_name;
                } else {
                    $productName = 'Unknown Product';
                }
                
                if (!isset($productSales[$productName])) {
                    $productSales[$productName] = [
                        'quantity' => 0,
                        'revenue' => 0
                    ];
                }
                $productSales[$productName]['quantity'] += $item->quantity;
                $productSales[$productName]['revenue'] += $item->total_price;
            }
        }

        // Sort by quantity and get top 10
        uasort($productSales, function ($a, $b) {
            return $b['quantity'] <=> $a['quantity'];
        });
        $topProducts = array_slice($productSales, 0, 10, true);

        return [
            'totalSales' => $totalSales,
            'totalAmount' => $totalAmount,
            'totalItems' => $totalItems,
            'paymentMethods' => $paymentMethods,
            'topProducts' => $topProducts,
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

        // Header
        $pdf->SetFont('arial', 'B', 18);
        $pdf->Cell(0, 10, 'تقرير المبيعات اليومية', 0, 1, 'C');
        
        $pdf->SetFont('arial', '', 12);
        $pdf->Cell(0, 8, 'التاريخ: ' . $date->format('Y-m-d') . ' (' . $date->format('l') . ')', 0, 1, 'C');
        $pdf->Ln(5);

        // Summary Cards
        $this->generateSummaryCards($pdf, $summary);
        $pdf->Ln(10);

        // Payment Methods Summary
        if (!empty($summary['paymentMethods'])) {
            $this->generatePaymentMethodsTable($pdf, $summary);
            $pdf->Ln(10);
        }

        // Top Products
        if (!empty($summary['topProducts'])) {
            $this->generateTopProductsTable($pdf, $summary);
            $pdf->Ln(10);
        }

        // Detailed Sales Table
        if ($sales->count() > 0) {
            $this->generateSalesTable($pdf, $sales);
        } else {
            $pdf->SetFont('arial', '', 14);
            $pdf->Cell(0, 20, 'لا توجد مبيعات لهذا اليوم', 0, 1, 'C');
        }
    }

    /**
     * Generate summary cards section
     *
     * @param MyCustomTCPDF $pdf
     * @param array $summary
     * @return void
     */
    private function generateSummaryCards($pdf, array $summary): void
    {
        $pdf->SetFont('arial', 'B', 14);
        $pdf->Cell(0, 8, 'ملخص المبيعات', 0, 1, 'R');
        $pdf->Ln(3);

        // Table headers
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(242, 242, 242);
        $pdf->Cell(35, 8, 'إجمالي المبيعات', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'إجمالي المبلغ', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'إجمالي العناصر', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'متوسط المبيعات', 1, 1, 'C', true);

        // Table data
        $pdf->SetFont('arial', 'B', 10);
        $pdf->Cell(35, 8, (string)$summary['totalSales'], 1, 0, 'C');
        $pdf->Cell(35, 8, number_format($summary['totalAmount'], 2), 1, 0, 'C');
        $pdf->Cell(35, 8, (string)$summary['totalItems'], 1, 0, 'C');
        $pdf->Cell(35, 8, number_format($summary['averageSale'], 2), 1, 1, 'C');
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
        $pdf->SetFont('arial', 'B', 14);
        $pdf->Cell(0, 8, 'طرق الدفع', 0, 1, 'R');
        $pdf->Ln(3);

        // Table headers
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(242, 242, 242);
        $pdf->Cell(60, 8, 'طريقة الدفع', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'المبلغ', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'النسبة', 1, 1, 'C', true);

        // Table data
        $pdf->SetFont('arial', '', 9);
        foreach ($summary['paymentMethods'] as $method => $amount) {
            $percentage = $summary['totalAmount'] > 0 ? ($amount / $summary['totalAmount']) * 100 : 0;
            $pdf->Cell(60, 7, $this->getPaymentMethodName($method), 1, 0, 'C');
            $pdf->Cell(40, 7, number_format($amount, 2), 1, 0, 'C');
            $pdf->Cell(30, 7, number_format($percentage, 1) . '%', 1, 1, 'C');
        }
    }

    /**
     * Generate top products table
     *
     * @param MyCustomTCPDF $pdf
     * @param array $summary
     * @return void
     */
    private function generateTopProductsTable($pdf, array $summary): void
    {
        $pdf->SetFont('arial', 'B', 14);
        $pdf->Cell(0, 8, 'أفضل المنتجات مبيعاً', 0, 1, 'R');
        $pdf->Ln(3);

        // Table headers
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(242, 242, 242);
        $pdf->Cell(80, 8, 'المنتج', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'الكمية', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'الإيرادات', 1, 1, 'C', true);

        // Table data
        $pdf->SetFont('arial', '', 9);
        foreach ($summary['topProducts'] as $productName => $data) {
            $pdf->Cell(80, 7, $productName, 1, 0, 'C');
            $pdf->Cell(30, 7, $data['quantity'], 1, 0, 'C');
            $pdf->Cell(40, 7, number_format($data['revenue'], 2), 1, 1, 'C');
        }
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
        $pdf->SetFont('arial', 'B', 14);
        $pdf->Cell(0, 8, 'تفاصيل المبيعات', 0, 1, 'R');
        $pdf->Ln(3);

        // Table headers
        $pdf->SetFont('arial', 'B', 9);
        $pdf->SetFillColor(242, 242, 242);
        $pdf->Cell(25, 8, 'رقم المبيعات', 1, 0, 'C', true);
        $pdf->Cell(20, 8, 'الوقت', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'العميل', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'العناصر', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'المبلغ', 1, 0, 'C', true);
        $pdf->Cell(50, 8, 'طرق الدفع', 1, 1, 'C', true);

        // Table data
        $pdf->SetFont('arial', '', 8);
        foreach ($sales as $sale) {
            $pdf->Cell(25, 6, ($sale->sale_order_number ?? $sale->id), 1, 0, 'C');
            $pdf->Cell(20, 6, Carbon::parse($sale->created_at)->format('H:i'), 1, 0, 'C');
            $pdf->Cell(40, 6, ($sale->client ? $sale->client->name : 'بدون عميل'), 1, 0, 'C');
            $pdf->Cell(25, 6, $sale->items->sum('quantity'), 1, 0, 'C');
            $pdf->Cell(30, 6, number_format($sale->total_amount, 2), 1, 0, 'C');
            $pdf->Cell(50, 6, $this->formatPaymentMethods($sale->payments), 1, 1, 'C');
        }
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