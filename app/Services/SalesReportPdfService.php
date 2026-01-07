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
        $this->companyName = $settings['company_name'] ?? 'Company';
        $this->companyAddress = $settings['company_address'] ?? '';
        $this->companyPhone = $settings['company_phone'] ?? '';
        $this->currencySymbol = $settings['currency_symbol'] ?? 'SDG';
        $this->baseUrl = $baseUrl;

        // Initialize PDF
        $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Sales System');
        $pdf->SetAuthor($this->companyName);
        $pdf->SetTitle('تقرير المبيعات Detailed Sales Report');
        $pdf->SetSubject('Sales Report');
        $pdf->SetMargins(15, 20, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->setRTL(true);
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

        // Add First Page
        $pdf->AddPage();

        // 1. Render Header
        $this->renderHeader($pdf, $startDate, $endDate);

        // 2. Render Filters
        $this->renderFilters($pdf, $validatedFilters);

        // 3. Render Summary Stats
        $this->renderSummaryStats($pdf, $summaryStats);

        // 4. Render Payment Methods
        if (!empty($paymentMethods)) {
            $this->renderPaymentMethods($pdf, $paymentMethods);
        }

        // 5. Render Sales Table
        $this->renderSalesTable($pdf, $sales);

        // Output PDF
        $pdfFileName = 'Sales_Report_' . now()->format('Y-m-d_His') . '.pdf';
        return $pdf->Output($pdfFileName, 'S');
    }

    private $companyName;
    private $companyAddress;
    private $companyPhone;
    private $currencySymbol;
    private $baseUrl;

    private function renderHeader($pdf, $startDate, $endDate)
    {
        // Company Info (Right aligned in RTL)
        $pdf->SetFont('arial', 'B', 16);
        $pdf->Cell(0, 8, $this->companyName, 0, 1, 'R');

        $pdf->SetFont('arial', '', 10);
        if ($this->companyAddress) {
            $pdf->Cell(0, 5, $this->companyAddress, 0, 1, 'R');
        }
        if ($this->companyPhone) {
            $pdf->Cell(0, 5, 'هاتف: ' . $this->companyPhone, 0, 1, 'R');
        }

        // Report Title (Center)
        $pdf->SetY(20);
        $pdf->SetFont('arial', 'B', 22);
        $pdf->Cell(0, 10, 'تقرير المبيعات', 0, 1, 'C');
        $pdf->SetFont('arial', '', 12);
        $pdf->Cell(0, 8, 'Sales Report', 0, 1, 'C');

        // Date Period (Left aligned in RTL -> typically mirrored, but let's force position)
        $currentY = $pdf->GetY();
        $pdf->SetY(20);
        $pdf->SetFont('arial', '', 10);

        // Construct period string
        $periodText = '';
        if ($startDate && $endDate) {
            $periodText = $startDate->format('Y-m-d') . ' : ' . $endDate->format('Y-m-d');
        } elseif ($startDate) {
            $periodText = 'من: ' . $startDate->format('Y-m-d');
        } elseif ($endDate) {
            $periodText = 'إلى: ' . $endDate->format('Y-m-d');
        } else {
            $periodText = 'كل الفترات';
        }

        $pdf->Cell(0, 5, 'تاريخ التقرير: ' . now()->format('Y-m-d H:i'), 0, 1, 'L');
        $pdf->Cell(0, 5, 'الفترة: ' . $periodText, 0, 1, 'L');

        // Separator Line
        $pdf->SetY($currentY + 15);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(15, $pdf->GetY(), 282, $pdf->GetY());
        $pdf->Ln(5);
    }

    private function renderFilters($pdf, $filters)
    {
        $filterTexts = [];
        if (!empty($filters['client_id'])) {
            $client = Client::find($filters['client_id']);
            if ($client) $filterTexts[] = 'العميل: ' . $client->name;
        }
        if (!empty($filters['user_id'])) {
            $user = User::find($filters['user_id']);
            if ($user) $filterTexts[] = 'الموظف: ' . $user->name;
        }
        if (!empty($filters['shift_id'])) {
            $filterTexts[] = 'رقم الوردية: ' . $filters['shift_id'];
        }
        if (!empty($filters['status'])) {
            $filterTexts[] = 'الحالة: ' . $filters['status'];
        }

        if (!empty($filterTexts)) {
            $pdf->SetFont('arial', '', 10);
            $pdf->SetFillColor(245, 247, 250);
            $pdf->SetTextColor(80, 80, 80);

            $text = 'الفلاتر المطبقة: ' . implode('   |   ', $filterTexts);
            $pdf->MultiCell(0, 8, $text, 0, 'R', true, 1, '', '', true, 0, false, true, 8, 'M');
            $pdf->Ln(5);

            // Reset colors
            $pdf->SetTextColor(0, 0, 0);
        }
    }

    private function renderSummaryStats($pdf, $stats)
    {
        $pdf->SetFont('arial', 'B', 14);
        $pdf->Cell(0, 8, 'ملخص المبيعات', 0, 1, 'R');
        $pdf->Ln(2);

        // Draw 4 Boxes
        // Width = (297 - 30) / 4 = 66.75
        $w = 66;
        $h = 24;
        $gap = 5; // not strictly needed if we assume adjacent or spaced cells

        $y = $pdf->GetY();

        // Define styles
        // Box 1: Total Sales Count
        $pdf->SetFillColor(241, 245, 249); // slate-100
        $pdf->Rect(15, $y, $w, $h, 'F');
        $this->renderSummaryBoxContent($pdf, 15, $y, $w, 'عدد الفواتير', number_format($stats['totalSales']));

        // Box 2: Total Amount
        $pdf->SetFillColor(240, 253, 244); // green-50 (or blueish)
        $pdf->Rect(15 + $w + 2, $y, $w, $h, 'F');
        $this->renderSummaryBoxContent($pdf, 15 + $w + 2, $y, $w, 'إجمالي المبيعات', number_format($stats['totalAmount'], 2), $this->currencySymbol);

        // Box 3: Total Paid
        $pdf->SetFillColor(236, 253, 245); // emerald-50
        $pdf->Rect(15 + ($w * 2) + 4, $y, $w, $h, 'F');
        $this->renderSummaryBoxContent($pdf, 15 + ($w * 2) + 4, $y, $w, 'إجمالي المدفوع', number_format($stats['totalPaid'], 2), $this->currencySymbol, [22, 163, 74]); // green text

        // Box 4: Total Due
        $pdf->SetFillColor(254, 242, 242); // red-50
        $pdf->Rect(15 + ($w * 3) + 6, $y, $w, $h, 'F');
        $dueColor = $stats['totalDue'] > 0 ? [220, 38, 38] : [22, 163, 74];
        $this->renderSummaryBoxContent($pdf, 15 + ($w * 3) + 6, $y, $w, 'المستحق (الآجل)', number_format($stats['totalDue'], 2), $this->currencySymbol, $dueColor);

        $pdf->SetY($y + $h + 8);
    }

    private function renderSummaryBoxContent($pdf, $x, $y, $w, $label, $value, $suffix = '', $valueColor = [0, 0, 0])
    {
        // Save current XY
        $resetX = $pdf->GetX();
        $resetY = $pdf->GetY();

        $pdf->SetXY($x, $y + 3);
        $pdf->SetFont('arial', '', 10);
        $pdf->SetTextColor(100, 116, 139); // slate-500
        $pdf->Cell($w, 6, $label, 0, 1, 'C');

        $pdf->SetXY($x, $y + 11);
        $pdf->SetFont('arial', 'B', 13);
        $pdf->SetTextColor($valueColor[0], $valueColor[1], $valueColor[2]);
        $valText = $value . ($suffix ? ' ' . $suffix : '');
        $pdf->Cell($w, 8, $valText, 0, 1, 'C');

        // Reset
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($resetX, $resetY);
    }

    private function renderPaymentMethods($pdf, $methods)
    {
        $pdf->SetFont('arial', 'B', 12);
        $pdf->Cell(0, 8, 'تفاصيل الدفع', 0, 1, 'R');
        $pdf->Ln(1);

        $pdf->SetFont('arial', '', 10);
        $pdf->SetLineWidth(0.2);

        // Header
        $pdf->SetFillColor(248, 250, 252);
        $pdf->Cell(100, 8, 'طريقة الدفع', 1, 0, 'C', true);
        $pdf->Cell(60, 8, 'المبلغ', 1, 1, 'C', true);

        // Rows
        $pdf->SetFont('arial', '', 10);
        foreach ($methods as $method => $amount) {
            $label = $this->getPaymentMethodLabel($method);
            $pdf->Cell(100, 8, $label, 1, 0, 'R');
            $pdf->Cell(60, 8, number_format($amount, 2) . ' ' . $this->currencySymbol, 1, 1, 'C');
        }
        $pdf->Ln(8);
    }

    private function renderSalesTable($pdf, $sales)
    {
        // Table Config
        $cols = [
            ['w' => 15, 'txt' => '#', 'align' => 'C'],
            ['w' => 25, 'txt' => 'التاريخ', 'align' => 'C'],
            ['w' => 20, 'txt' => 'الوقت', 'align' => 'C'],
            ['w' => 45, 'txt' => 'العميل', 'align' => 'R'],
            ['w' => 30, 'txt' => 'المستخدم', 'align' => 'R'],
            ['w' => 30, 'txt' => 'الإجمالي', 'align' => 'L'],
            ['w' => 25, 'txt' => 'المدفوع', 'align' => 'L'],
            ['w' => 25, 'txt' => 'المتبقي', 'align' => 'L'],
            ['w' => 52, 'txt' => 'طرق الدفع', 'align' => 'R'],
        ];

        // Header
        $this->renderTableHeader($pdf, $cols);

        // Body
        $pdf->SetFont('arial', '', 9);
        $fill = false;

        foreach ($sales as $sale) {
            // Check page break
            if ($pdf->GetY() > 180) { // Leave space for footer
                $pdf->AddPage();
                $this->renderTableHeader($pdf, $cols);
            }

            $fill = !$fill;
            $pdf->SetFillColor(250, 250, 250); // Very light gray for alternate

            // Data Preparation
            $time = Carbon::parse($sale->sale_date)->format('H:i');
            $date = Carbon::parse($sale->sale_date)->format('Y-m-d');
            $clientName = mb_substr($sale->client->name ?? 'عميل عام', 0, 25);
            $userName = mb_substr($sale->user->name ?? '-', 0, 15);

            // Payment breakdown string
            $payInfo = [];
            foreach ($sale->payments as $p) {
                $m = $p->method == 'cash' ? 'نقد' : ($p->method == 'visa' ? 'فيزا' : $p->method);
                $payInfo[] = $m . ':' . number_format($p->amount);
            }
            $payStr = implode(', ', $payInfo);
            if (mb_strlen($payStr) > 30) $payStr = mb_substr($payStr, 0, 27) . '...';

            $due = $sale->due_amount ?? ($sale->total_amount - $sale->paid_amount);

            // Calculation Constants
            $h = 8; // Row height

            // Render Cells
            // 1. ID (Link)
            $idTxt = $sale->id;
            $pdf->SetTextColor(37, 99, 235); // Blue link
            // Assuming we want a link, but TCPDF Cell with link is nicer.
            // For now simple cell
            $pdf->Cell($cols[0]['w'], $h, $idTxt, 1, 0, 'C', $fill, $this->baseUrl ? $this->baseUrl . '/reports/sales/' . $sale->id . '/pdf' : '');
            $pdf->SetTextColor(0, 0, 0);

            // 2. Date
            $pdf->Cell($cols[1]['w'], $h, $date, 1, 0, 'C', $fill);
            // 3. Time
            $pdf->Cell($cols[2]['w'], $h, $time, 1, 0, 'C', $fill);
            // 4. Client
            $pdf->Cell($cols[3]['w'], $h, $clientName, 1, 0, 'R', $fill);
            // 5. User
            $pdf->Cell($cols[4]['w'], $h, $userName, 1, 0, 'R', $fill);

            // Numbers
            $pdf->Cell($cols[5]['w'], $h, number_format($sale->total_amount, 2), 1, 0, 'L', $fill);
            $pdf->Cell($cols[6]['w'], $h, number_format($sale->paid_amount, 2), 1, 0, 'L', $fill);

            // Due (Red if > 0)
            if ($due > 0.1) $pdf->SetTextColor(220, 38, 38);
            else $pdf->SetTextColor(22, 163, 74);
            $pdf->Cell($cols[7]['w'], $h, number_format($due, 2), 1, 0, 'L', $fill);
            $pdf->SetTextColor(0, 0, 0);

            // Payments
            $pdf->SetFont('arial', '', 8); // Smaller font for payments details
            $pdf->Cell($cols[8]['w'], $h, $payStr, 1, 1, 'R', $fill);
            $pdf->SetFont('arial', '', 9); // Reset
        }

        // Final Footer Total line? optional.
    }

    private function renderTableHeader($pdf, $cols)
    {
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(51, 65, 85); // Slate-700
        $pdf->SetTextColor(255, 255, 255);

        foreach ($cols as $col) {
            $pdf->Cell($col['w'], 9, $col['txt'], 1, 0, 'C', true);
        }
        $pdf->Ln(); // New line

        // Reset
        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * Get Arabic label for payment method
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
