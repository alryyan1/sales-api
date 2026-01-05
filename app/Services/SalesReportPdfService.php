<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Client;
use App\Models\User;
use Carbon\Carbon;

class SalesReportPdfService
{
    /**
     * Generate PDF report for sales
     *
     * @param \Illuminate\Database\Eloquent\Collection $sales
     * @param array $validatedFilters
     * @param array $summaryStats
     * @param array $paymentMethods
     * @param Carbon|null $startDate
     * @param Carbon|null $endDate
     * @return string PDF content
     */
    public function generate(
        $sales,
        array $validatedFilters,
        array $summaryStats,
        array $paymentMethods,
        $startDate = null,
        $endDate = null,
        $baseUrl = null
    ): string {
        // Get settings
        $settings = (new SettingsService())->getAll();
        $companyName = $settings['company_name'] ?? 'Company';
        $companyAddress = $settings['company_address'] ?? '';
        $companyPhone = $settings['company_phone'] ?? '';
        $currencySymbol = $settings['currency_symbol'] ?? 'SDG';

        // Generate PDF
        $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Sales System');
        $pdf->SetAuthor('Sales System');
        $pdf->SetTitle('Sales Report');
        $pdf->SetSubject('Sales Report');
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
        $pdf->Line(20, $pdf->GetY() + 3, 277, $pdf->GetY() + 3);
        $pdf->Ln(8);
        
        // Report Title
        $pdf->SetFont('arial', 'B', 16);
        $pdf->Cell(0, 8, 'تقرير المبيعات', 0, 1, 'C');
        $pdf->Ln(3);
        
        // Report Period
        $pdf->SetFont('arial', '', 11);
        if ($startDate && $endDate) {
            $pdf->Cell(0, 6, 'الفترة: من ' . $startDate->format('Y-m-d') . ' إلى ' . $endDate->format('Y-m-d'), 0, 1, 'C');
        } elseif ($startDate) {
            $pdf->Cell(0, 6, 'الفترة: من ' . $startDate->format('Y-m-d'), 0, 1, 'C');
        } elseif ($endDate) {
            $pdf->Cell(0, 6, 'الفترة: حتى ' . $endDate->format('Y-m-d'), 0, 1, 'C');
        }
        
        $pdf->SetFont('arial', '', 9);
        $pdf->Cell(0, 5, 'تاريخ إعداد التقرير: ' . now()->format('Y-m-d H:i'), 0, 1, 'C');
        $pdf->Ln(8);

        // Applied Filters
        $filters = [];
        if (!empty($validatedFilters['client_id'])) {
            $client = Client::find($validatedFilters['client_id']);
            if ($client) {
                $filters[] = 'العميل: ' . $client->name;
            }
        }
        if (!empty($validatedFilters['user_id'])) {
            $user = User::find($validatedFilters['user_id']);
            if ($user) {
                $filters[] = 'المستخدم: ' . $user->name;
            }
        }
        if (!empty($validatedFilters['shift_id'])) {
            $filters[] = 'الوردية: #' . $validatedFilters['shift_id'];
        }
        if (!empty($validatedFilters['status'])) {
            $filters[] = 'الحالة: ' . $validatedFilters['status'];
        }

        if (!empty($filters)) {
            $pdf->SetFont('arial', 'B', 10);
            $pdf->Cell(0, 6, 'الفلاتر المطبقة:', 0, 1, 'R');
            $pdf->SetFont('arial', '', 9);
            $pdf->Cell(0, 5, implode(' | ', $filters), 0, 1, 'R');
            $pdf->Ln(5);
        }

        // Summary Section - Formal Style
        $pdf->SetFont('arial', 'B', 12);
        $pdf->Cell(0, 7, 'ملخص المبيعات', 0, 1, 'R');
        $pdf->SetLineWidth(0.3);
        $pdf->Line(20, $pdf->GetY(), 277, $pdf->GetY());
        $pdf->Ln(4);
        
        $pdf->SetFont('arial', 'B', 10);
        $pdf->Cell(90, 7, 'عدد المبيعات:', 'B', 0, 'R');
        $pdf->SetFont('arial', '', 10);
        $pdf->Cell(50, 7, number_format($summaryStats['totalSales']), 'B', 1, 'L');
        
        $pdf->SetFont('arial', 'B', 10);
        $pdf->Cell(90, 7, 'إجمالي المبيعات:', 'B', 0, 'R');
        $pdf->SetFont('arial', '', 10);
        $pdf->Cell(50, 7, number_format($summaryStats['totalAmount'], 2) . ' ' . $currencySymbol, 'B', 1, 'L');
        
        $pdf->SetFont('arial', 'B', 10);
        $pdf->Cell(90, 7, 'إجمالي المدفوع:', 'B', 0, 'R');
        $pdf->SetFont('arial', '', 10);
        $pdf->Cell(50, 7, number_format($summaryStats['totalPaid'], 2) . ' ' . $currencySymbol, 'B', 1, 'L');
        
        $pdf->SetFont('arial', 'B', 10);
        $pdf->Cell(90, 7, 'المستحق:', 'B', 0, 'R');
        $pdf->SetFont('arial', '', 10);
        $pdf->Cell(50, 7, number_format($summaryStats['totalDue'], 2) . ' ' . $currencySymbol, 'B', 1, 'L');
        $pdf->Ln(8);

        // Payment Methods Breakdown - Formal Style
        if (!empty($paymentMethods)) {
            $pdf->SetFont('arial', 'B', 12);
            $pdf->Cell(0, 7, 'تفاصيل المدفوعات', 0, 1, 'R');
            $pdf->SetLineWidth(0.3);
            $pdf->Line(20, $pdf->GetY(), 277, $pdf->GetY());
            $pdf->Ln(4);
            
            $pdf->SetFont('arial', '', 10);
            foreach ($paymentMethods as $method => $amount) {
                $methodLabel = $this->getPaymentMethodLabel($method);
                $pdf->SetFont('arial', 'B', 10);
                $pdf->Cell(90, 6, $methodLabel . ':', 'B', 0, 'R');
                $pdf->SetFont('arial', '', 10);
                $pdf->Cell(50, 6, number_format($amount, 2) . ' ' . $currencySymbol, 'B', 1, 'L');
            }
            $pdf->Ln(5);
        }

        // Add new page for Sales Table
        $pdf->AddPage();

        // Sales Table Header - Formal Style
        $pdf->SetFont('arial', 'B', 12);
        $pdf->Cell(0, 7, 'قائمة المبيعات', 0, 1, 'R');
        $pdf->SetLineWidth(0.3);
        $pdf->Line(20, $pdf->GetY(), 277, $pdf->GetY());
        $pdf->Ln(4);

        // Table Header - Formal Style (Black and White)
        // Adjusted widths to fit A4 landscape: 297mm - 40mm margins = 257mm available
        // Column order: Sale ID, Date, Client, User, Total, Discount, Total Paid, Cash, Bank, Due
        $pdf->SetFont('arial', 'B', 9);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(16, 7, 'رقم البيع', 1, 0, 'C', true);
        $pdf->Cell(20, 7, 'التاريخ', 1, 0, 'C', true);
        $pdf->Cell(32, 7, 'العميل', 1, 0, 'C', true);
        $pdf->Cell(26, 7, 'المستخدم', 1, 0, 'C', true);
        $pdf->Cell(24, 7, 'الإجمالي', 1, 0, 'C', true);
        $pdf->Cell(20, 7, 'الخصم', 1, 0, 'C', true);
        $pdf->Cell(24, 7, 'المدفوع', 1, 0, 'C', true);
        $pdf->Cell(20, 7, 'النقد', 1, 0, 'C', true);
        $pdf->Cell(20, 7, 'البنك', 1, 0, 'C', true);
        $pdf->Cell(24, 7, 'المستحق', 1, 1, 'C', true);

        // Table Rows - Formal Style
        $pdf->SetFont('arial', '', 8);
        $rowCount = 0;
        
        foreach ($sales as $sale) {
            if ($rowCount > 0 && $rowCount % 25 == 0) {
                $pdf->AddPage();
                // Repeat header
                $pdf->SetFont('arial', 'B', 12);
                $pdf->Cell(0, 7, 'قائمة المبيعات', 0, 1, 'R');
                $pdf->SetLineWidth(0.3);
                $pdf->Line(20, $pdf->GetY(), 277, $pdf->GetY());
                $pdf->Ln(4);
                
                $pdf->SetFont('arial', 'B', 9);
                $pdf->SetFillColor(240, 240, 240);
                $pdf->Cell(16, 7, 'رقم البيع', 1, 0, 'C', true);
                $pdf->Cell(20, 7, 'التاريخ', 1, 0, 'C', true);
                $pdf->Cell(32, 7, 'العميل', 1, 0, 'C', true);
                $pdf->Cell(26, 7, 'المستخدم', 1, 0, 'C', true);
                $pdf->Cell(24, 7, 'الإجمالي', 1, 0, 'C', true);
                $pdf->Cell(20, 7, 'الخصم', 1, 0, 'C', true);
                $pdf->Cell(24, 7, 'المدفوع', 1, 0, 'C', true);
                $pdf->Cell(20, 7, 'النقد', 1, 0, 'C', true);
                $pdf->Cell(20, 7, 'البنك', 1, 0, 'C', true);
                $pdf->Cell(24, 7, 'المستحق', 1, 1, 'C', true);
                $pdf->SetFont('arial', '', 8);
            }

            // Alternating row colors (subtle gray/white)
            $fill = ($rowCount % 2 == 0);
            $pdf->SetFillColor($fill ? 250 : 255, $fill ? 250 : 255, $fill ? 250 : 255);
            
            // Calculate cash and bank totals for this sale
            $cashTotal = 0;
            $bankTotal = 0;
            foreach ($sale->payments as $payment) {
                $method = $payment->method ?? 'cash';
                $amount = (float) $payment->amount;
                if ($method === 'cash') {
                    $cashTotal += $amount;
                } elseif (in_array($method, ['visa', 'mastercard', 'mada', 'bank_transfer'])) {
                    $bankTotal += $amount;
                }
            }
            
            $discountAmount = $sale->discount_amount ?? 0;
            $discountDisplay = $discountAmount > 0 ? number_format($discountAmount, 2) : '-';
            
            // Create hyperlink for sale ID
            $saleIdText = '#' . $sale->id;
            if ($baseUrl) {
                $linkUrl = $baseUrl . '/reports/sales/' . $sale->id . '/pdf';
                $pdf->SetTextColor(0, 0, 255); // Blue color for links
                $pdf->Cell(16, 6, $saleIdText, 1, 0, 'C', $fill, $linkUrl);
                $pdf->SetTextColor(0, 0, 0); // Reset to black
            } else {
                $pdf->Cell(16, 6, $saleIdText, 1, 0, 'C', $fill);
            }
            $pdf->Cell(20, 6, Carbon::parse($sale->sale_date)->format('Y-m-d'), 1, 0, 'C', $fill);
            $pdf->Cell(32, 6, mb_substr($sale->client?->name ?? 'عميل عام', 0, 18), 1, 0, 'R', $fill);
            $pdf->Cell(26, 6, mb_substr($sale->user?->name ?? '-', 0, 14), 1, 0, 'R', $fill);
            $pdf->Cell(24, 6, number_format($sale->total_amount, 2), 1, 0, 'L', $fill);
            $pdf->Cell(20, 6, $discountDisplay, 1, 0, 'L', $fill);
            $pdf->Cell(24, 6, number_format($sale->paid_amount, 2), 1, 0, 'L', $fill);
            $pdf->Cell(20, 6, number_format($cashTotal, 2), 1, 0, 'L', $fill);
            $pdf->Cell(20, 6, number_format($bankTotal, 2), 1, 0, 'L', $fill);
            $pdf->Cell(24, 6, number_format($sale->due_amount ?? ($sale->total_amount - $sale->paid_amount), 2), 1, 1, 'L', $fill);
            
            $rowCount++;
        }

        // Footer
        $pdf->SetY(-15);
        $pdf->SetFont('arial', '', 8);
        $pdf->Cell(0, 10, 'صفحة ' . $pdf->getAliasNumPage() . ' من ' . $pdf->getAliasNbPages(), 0, 0, 'C');

        // Output PDF
        $pdfFileName = 'sales_report_' . now()->format('Y-m-d_His') . '.pdf';
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

