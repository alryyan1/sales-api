<?php

namespace App\Services;

use App\Models\Sale;
use App\Services\Pdf\MyCustomTCPDF;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DailySalesPdfService
{
    /**
     * Generate a PDF report of daily sales with filters
     *
     * @param array $filters
     * @return string PDF content
     */
    public function generateDailySalesPdf(array $filters = []): string
    {
        // Build query with filters
        $query = Sale::with(['items.product', 'client', 'payments', 'user']);

        // Apply date filters and determine filename date
        $filenameDate = Carbon::today(); // Default for filename
        
        if (isset($filters['date'])) {
            $targetDate = Carbon::parse($filters['date']);
            $filenameDate = $targetDate;
            $query->whereDate('sale_date', $targetDate);
        } elseif (isset($filters['start_date']) || isset($filters['end_date'])) {
            if (isset($filters['start_date'])) {
                $filenameDate = Carbon::parse($filters['start_date']);
                $query->whereDate('sale_date', '>=', $filters['start_date']);
            }
            if (isset($filters['end_date'])) {
                $query->whereDate('sale_date', '<=', $filters['end_date']);
            }
        } else {
            // Default to today if no date filters
            $query->whereDate('sale_date', Carbon::today());
        }

        // Apply other filters
        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (isset($filters['sale_id'])) {
            $query->where('id', $filters['sale_id']);
        }
        if (isset($filters['product_id'])) {
            $query->whereHas('items', function($q) use ($filters) {
                $q->where('product_id', $filters['product_id']);
            });
        }
        if (isset($filters['start_time'])) {
            $query->whereTime('created_at', '>=', $filters['start_time']);
        }
        if (isset($filters['end_time'])) {
            $query->whereTime('created_at', '<=', $filters['end_time']);
        }

        // Get sales (include sales with items or payments)
        $sales = $query->where(function($query) {
                $query->whereHas('items')->orWhereHas('payments');
            })
            ->orderBy('created_at', 'asc')
            ->get();

        // Determine report title based on filters
        $reportTitle = $this->getReportTitle($filters);

        // Calculate summary statistics
        $summary = $this->calculateSummary($sales);

        // Create PDF using the custom TCPDF class
        $pdf = new MyCustomTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // Set document information
        $pdf->SetTitle($reportTitle);
        $pdf->SetSubject('Sales Report');

        // Add a page
        $pdf->AddPage();

        // Generate PDF content using cell method
        $this->generatePdfContent($pdf, $sales, $summary, $filters);

        // Return PDF content
        return $pdf->Output('daily_sales_report_' . $filenameDate->format('Y-m-d') . '.pdf', 'S');
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
        $totalPaid = 0;
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
                $totalPaid += $payment->amount;
            }
        }

        return [
            'totalSales' => $totalSales,
            'totalAmount' => $totalAmount,
            'totalPaid' => $totalPaid,
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
     * @param array $filters
     * @return void
     */
    private function generatePdfContent($pdf, $sales, array $summary, array $filters): void
    {
        // Set RTL direction
        $pdf->setRTL(true);

        // Professional Header
        $this->generateHeader($pdf, $filters);
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
            $this->generateSalesTable($pdf, $sales, $filters);
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
     * @param array $filters
     * @return void
     */
    private function generateHeader($pdf, array $filters): void
    {
        // Company header with border
        $pdf->SetFillColor(51, 122, 183);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('arial', 'B', 20);
        $pdf->Cell(0, 12, 'تقرير المبيعات', 0, 1, 'C', true);
        
        // Date and time
        $pdf->SetTextColor(51, 51, 51);
        $pdf->SetFont('arial', '', 12);
        
        // Determine date range for display
        $dateText = $this->getDateRangeText($filters);
        $pdf->Cell(0, 8, $dateText, 0, 1, 'C');
        
        // Add user filter information if present
        if (isset($filters['user_id']) && !empty($filters['user_id'])) {
            $user = \App\Models\User::find($filters['user_id']);
            if ($user) {
                $pdf->Cell(0, 6, 'المستخدم: ' . $user->name, 0, 1, 'C');
            }
        }
        
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

        // Dynamic 5-column layout across usable width
        $margins = $pdf->getMargins();
        $usableWidth = $pdf->getPageWidth() - ($margins['left'] ?? 0) - ($margins['right'] ?? 0);
        $numCols = 5;
        $baseColWidth = round($usableWidth / $numCols, 2);
        $colWidths = array_fill(0, $numCols, $baseColWidth);
        $colWidths[$numCols - 1] = $usableWidth - array_sum(array_slice($colWidths, 0, $numCols - 1));

        // Table headers
        $pdf->Cell($colWidths[0], 10, 'إجمالي المبيعات', 1, 0, 'C', true);
        $pdf->Cell($colWidths[1], 10, 'إجمالي المبلغ', 1, 0, 'C', true);
        $pdf->Cell($colWidths[2], 10, 'إجمالي المدفوع', 1, 0, 'C', true);
        $pdf->Cell($colWidths[3], 10, 'إجمالي العناصر', 1, 0, 'C', true);
        $pdf->Cell($colWidths[4], 10, 'متوسط المبيعات', 1, 1, 'C', true);

        // Table data
        $pdf->SetFont('arial', 'B', 12);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Cell($colWidths[0], 10, (string)$summary['totalSales'], 1, 0, 'C', true);
        $pdf->Cell($colWidths[1], 10, number_format($summary['totalAmount'], 0), 1, 0, 'C', true);
        $pdf->Cell($colWidths[2], 10, number_format($summary['totalPaid'], 0), 1, 0, 'C', true);
        $pdf->Cell($colWidths[3], 10, (string)$summary['totalItems'], 1, 0, 'C', true);
        $pdf->Cell($colWidths[4], 10, number_format($summary['averageSale'], 0), 1, 1, 'C', true);
        
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
            $pdf->Cell(45, 8, number_format($amount, 0), 1, 0, 'C', true);
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
     * @param array $filters
     * @return void
     */
    private function generateSalesTable($pdf, $sales, array $filters = []): void
    {
        $pdf->SetFont('arial', 'B', 16);
        $pdf->SetTextColor(51, 122, 183);
        $pdf->Cell(0, 10, 'تفاصيل المبيعات', 0, 1, 'R');
        $pdf->Ln(3);

        // Check if user filter is applied

        $showUserColumn = !isset($filters['user_id']) || empty($filters['user_id']);
        $page_width = $pdf->GetPageWidth() - 20;
        $column_width = $page_width / 7;
        // Table headers
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(248, 249, 250);
        $pdf->SetTextColor(51, 51, 51);
        $pdf->Cell($column_width, 10, 'رقم المبيعات', 1, 0, 'C', true);
        $pdf->Cell($column_width, 10, 'الوقت', 1, 0, 'C', true);
        $pdf->Cell($column_width, 10, 'العميل', 1, 0, 'C', true);
        if ($showUserColumn) {
            $pdf->Cell($column_width, 10, 'المستخدم', 1, 0, 'C', true);
        }
        $pdf->Cell($column_width, 10, 'العناصر', 1, 0, 'C', true);
        $pdf->Cell($column_width, 10, 'المبلغ', 1, 0, 'C', true);
        $pdf->Cell($column_width, 10, 'طرق الدفع', 1, 1, 'C', true);

        // Table data
        $pdf->SetFont('arial', '', 9);
        $pdf->SetFillColor(255, 255, 255);
        $rowCount = 0;
        foreach ($sales as $sale) {
            // Alternate row colors for better readability
            $fillColor = ($rowCount % 2 == 0) ? [255, 255, 255] : [248, 249, 250];
            $pdf->SetFillColor($fillColor[0], $fillColor[1], $fillColor[2]);
            
            $pdf->Cell($column_width, 8, ($sale->sale_order_number ?? $sale->id), 'TB', 0, 'C', true);
            $pdf->Cell($column_width, 8, Carbon::parse($sale->created_at)->format('H:i'), 'TB', 0, 'C', true);
            $pdf->Cell($column_width, 8, ($sale->client ? $sale->client->name : 'بدون عميل'), 'TB', 0, 'C', true);
            if ($showUserColumn) {
                $pdf->Cell($column_width, 8, ($sale->user ? $sale->user->name : 'غير محدد'), 'TB', 0, 'C', true);
            }
            $pdf->Cell($column_width, 8, $sale->items->sum('quantity'), 'TB', 0, 'C', true);
            $pdf->Cell($column_width, 8, number_format($sale->total_amount, 0), 'TB', 0, 'C', true);
            $pdf->Cell($column_width, 8, $this->formatPaymentMethods($sale->payments), 'TB', 1, 'C', true);
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
            $formatted[] = $methodName . ' (' . number_format($payment->amount, 0) . ')';
        }
        return implode(', ', $formatted);
    }

    /**
     * Get report title based on filters
     *
     * @param array $filters
     * @return string
     */
    private function getReportTitle(array $filters): string
    {
        if (isset($filters['date'])) {
            return 'Daily Sales Report - ' . Carbon::parse($filters['date'])->format('Y-m-d');
        } elseif (isset($filters['start_date']) && isset($filters['end_date'])) {
            return 'Sales Report - ' . $filters['start_date'] . ' to ' . $filters['end_date'];
        } elseif (isset($filters['start_date'])) {
            return 'Sales Report - From ' . $filters['start_date'];
        } elseif (isset($filters['end_date'])) {
            return 'Sales Report - Until ' . $filters['end_date'];
        } else {
            return 'Sales Report - ' . Carbon::today()->format('Y-m-d');
        }
    }

    /**
     * Get date range text for display
     *
     * @param array $filters
     * @return string
     */
    private function getDateRangeText(array $filters): string
    {
        if (isset($filters['date'])) {
            $date = Carbon::parse($filters['date']);
            return 'التاريخ: ' . $date->format('Y-m-d') . ' (' . $this->getArabicDayName($date) . ')';
        } elseif (isset($filters['start_date']) && isset($filters['end_date'])) {
            return 'الفترة: من ' . $filters['start_date'] . ' إلى ' . $filters['end_date'];
        } elseif (isset($filters['start_date'])) {
            return 'من تاريخ: ' . $filters['start_date'];
        } elseif (isset($filters['end_date'])) {
            return 'إلى تاريخ: ' . $filters['end_date'];
        } else {
            $today = Carbon::today();
            return 'التاريخ: ' . $today->format('Y-m-d') . ' (' . $this->getArabicDayName($today) . ')';
        }
    }
} 