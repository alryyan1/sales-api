<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Client;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use TCPDF;

class SalesReportPdfService
{
    // PDF Layout Constants
    private const ORIENTATION = 'P';
    private const UNIT = 'mm';
    private const FORMAT = 'A4';
    private const MARGIN = 15;

    // Font Configuration
    private const FONT_MAIN = 'arial'; // Standard Arial
    private const SIZE_TITLE = 20;
    private const SIZE_SECTION = 12;
    private const SIZE_BODY = 9;

    private string $companyName;
    private string $companyAddress;
    private string $companyPhone;
    private string $currencySymbol;

    public function generate(
        Collection $sales,
        array $validatedFilters,
        array $summaryStats,
        array $paymentMethods,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        ?string $baseUrl = null
    ): string {
        $this->initializeSettings($baseUrl);
        $pdf = $this->initializePdf();

        // --- PAGE 1: REPORT OVERVIEW (popup-style summary) ---
        $pdf->AddPage();
        $this->renderHeader($pdf, $summaryStats, $startDate, $endDate);
        $this->renderFilters($pdf, $validatedFilters);
        $this->renderSummaryPopupStyle($pdf, $summaryStats, $paymentMethods);

        if (!empty($paymentMethods)) {
            $this->renderPaymentMethodTable($pdf, $paymentMethods);
        }

        // --- PAGE 2+: DETAILED LOG ---
        $pdf->AddPage();
        $this->renderSectionTitle($pdf, 'سجل المبيعات التفصيلي');
        $this->renderSalesTable($pdf, $sales);

        $pdfFileName = 'Report_' . now()->format('Y-m-d') . '.pdf';
        return $pdf->Output($pdfFileName, 'S');
    }

    private function initializeSettings(?string $baseUrl): void
    {
        $settings = (new SettingsService())->getAll();
        $this->companyName = $settings['company_name'] ?? 'Company Name';
        $this->companyAddress = $settings['company_address'] ?? '';
        $this->companyPhone = $settings['company_phone'] ?? '';
        $this->currencySymbol = $settings['currency_symbol'] ?? 'SAR';
    }

    private function initializePdf(): TCPDF
    {
        $pdf = new TCPDF(self::ORIENTATION, self::UNIT, self::FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('System');
        $pdf->SetAuthor($this->companyName);
        $pdf->SetTitle('Sales Report');
        $pdf->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setRTL(true);
        $pdf->SetFont(self::FONT_MAIN, '', 10);
        return $pdf;
    }

    private function renderHeader(TCPDF $pdf, array $summaryStats, ?Carbon $startDate, ?Carbon $endDate): void
    {
        // Brand Identity
        $pdf->SetFont(self::FONT_MAIN, 'B', self::SIZE_TITLE);
        $pdf->Cell(0, 12, $this->companyName, 0, 1, 'R');

        $pdf->SetFont(self::FONT_MAIN, '', self::SIZE_BODY);
        $pdf->Cell(0, 5, $this->companyAddress, 0, 1, 'R');
        $pdf->Cell(0, 5, 'Tel: ' . $this->companyPhone, 0, 1, 'R');

        $pdf->Ln(4);
        $pdf->SetLineWidth(0.4);
        $pdf->Line(self::MARGIN, $pdf->GetY(), 282, $pdf->GetY());
        $pdf->Ln(8);

        // Title: ملخص الوردية when shift, else ملخص المبيعات
        $pdf->SetFont(self::FONT_MAIN, 'B', 16);
        $title = !empty($summaryStats['shift']) ? 'ملخص الوردية' : 'ملخص المبيعات';
        $pdf->Cell(0, 10, $title, 0, 1, 'C');

        $pdf->SetFont(self::FONT_MAIN, '', 10);
        if (!empty($summaryStats['shift'])) {
            $pdf->Cell(0, 6, 'الوردية #' . ($summaryStats['shift']['id'] ?? ''), 0, 1, 'C');
        } else {
            $period = $this->buildPeriodText($startDate, $endDate);
            $pdf->Cell(0, 6, $period, 0, 1, 'C');
        }
        $pdf->Cell(0, 6, 'تاريخ التقرير: ' . now()->format('Y-m-d H:i'), 0, 1, 'C');
        $pdf->Ln(10);
    }

    /**
     * Summary in the same style as the POS shift summary popup.
     */
    private function renderSummaryPopupStyle(TCPDF $pdf, array $stats, array $paymentMethods): void
    {
        $pdf->SetFont(self::FONT_MAIN, 'B', self::SIZE_BODY);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(100, 8, 'البند', 1, 0, 'R', true);
        $pdf->Cell(80, 8, 'القيمة', 1, 1, 'C', true);
        $pdf->SetFont(self::FONT_MAIN, '', self::SIZE_BODY);

        $cash = (float) ($paymentMethods['cash'] ?? 0);
        $bankak = (float) ($paymentMethods['bankak'] ?? 0);
        $expenseCash = (float) ($stats['expenseCash'] ?? 0);
        $expenseBank = (float) ($stats['expenseBank'] ?? 0);
        $netCash = $cash - $expenseCash;
        $netBank = $bankak - $expenseBank;

        $rows = [];
        if (!empty($stats['shift'])) {
            $shift = $stats['shift'];
            $rows[] = ['وردية #' . ($shift['id'] ?? ''), '', false];
            if (!empty($shift['opened_at'])) {
                $rows[] = ['وقت الفتح', $shift['opened_at'], false];
            }
            if (!empty($shift['user_name'])) {
                $rows[] = ['فتح بواسطة', $shift['user_name'], false];
            }
        }
        $rows[] = ['نقدي', number_format($cash, 2) . ' ' . $this->currencySymbol, true];
        $rows[] = ['بنكك', number_format($bankak, 2) . ' ' . $this->currencySymbol, true];
        $rows[] = ['مصروف نقدي', number_format($expenseCash, 2) . ' ' . $this->currencySymbol, true];
        $rows[] = ['مصروف بنك', number_format($expenseBank, 2) . ' ' . $this->currencySymbol, true];
        $rows[] = ['صافي نقدي', number_format($netCash, 2) . ' ' . $this->currencySymbol, true];
        $rows[] = ['صافي بنك', number_format($netBank, 2) . ' ' . $this->currencySymbol, true];

        foreach ($rows as $row) {
            $pdf->Cell(100, 8, $row[0], 1, 0, 'R');
            $pdf->Cell(80, 8, $row[1], 1, 1, 'C');
        }
        $pdf->Ln(10);
    }

    private function renderSalesTable(TCPDF $pdf, Collection $sales): void
    {
        $cols = [
            ['w' => 8, 't' => '#'], // Reduced 10->8
            ['w' => 20, 't' => 'التاريخ'], // Reduced 22->20
            ['w' => 22, 't' => 'العميل'], // Reduced 25->22
            ['w' => 24, 't' => 'البائع'], // Reduced 30->24
            ['w' => 17, 't' => 'الإجمالي'], // Reduced 18->17
            ['w' => 13, 't' => 'الخصم'], // Reduced 15->13
            ['w' => 17, 't' => 'المدفوع'], // Reduced 18->17
            ['w' => 13, 't' => 'المتبقي'], // Reduced 15->13
            ['w' => 46, 't' => 'طريقة الدفع'], // Increased 27->46
        ];

        // Column Headers
        $pdf->SetFont(self::FONT_MAIN, 'B', 9);
        $pdf->SetFillColor(240, 240, 240);
        foreach ($cols as $col) {
            $pdf->Cell($col['w'], 9, $col['t'], 1, 0, 'C', true);
        }
        $pdf->Ln();

        // Table Body
        $pdf->SetFont(self::FONT_MAIN, '', 8);
        foreach ($sales as $sale) {
            if ($pdf->GetY() > 185) {
                $pdf->AddPage();
                $pdf->SetFont(self::FONT_MAIN, 'B', 9);
                foreach ($cols as $col) {
                    $pdf->Cell($col['w'], 9, $col['t'], 1, 0, 'C', true);
                }
                $pdf->Ln();
                $pdf->SetFont(self::FONT_MAIN, '', 8);
            }

            $totalAmount = $this->getSaleTotalAmount($sale);
            $paidAmount = $this->getSalePaidAmount($sale);
            $discount = (float) ($sale->discount_amount ?? 0);
            $due = isset($sale->due_amount) && $sale->due_amount !== null && $sale->due_amount !== ''
                ? (float) $sale->due_amount
                : max(0, $totalAmount - $discount - $paidAmount);

            $clientName = $sale->client?->name ?? 'عميل عام';
            $userName = $sale->user?->name ?? '—';
            $saleDate = $sale->sale_date ? date('Y-m-d', strtotime($sale->sale_date)) : '—';

            $pdf->Cell($cols[0]['w'], 7, (string) $sale->id, 1, 0, 'C');
            $pdf->Cell($cols[1]['w'], 7, $saleDate, 1, 0, 'C');
            $pdf->Cell($cols[2]['w'], 7, ' ' . mb_substr($clientName, 0, 25), 1, 0, 'C');
            $pdf->Cell($cols[3]['w'], 7, ' ' . $userName, 1, 0, 'C');
            $pdf->Cell($cols[4]['w'], 7, number_format($totalAmount, 2), 1, 0, 'C');
            $pdf->Cell($cols[5]['w'], 7, number_format($discount, 2), 1, 0, 'C');
            $pdf->Cell($cols[6]['w'], 7, number_format($paidAmount, 2), 1, 0, 'C');
            $pdf->Cell($cols[7]['w'], 7, number_format($due, 2), 1, 0, 'C');
            $pdf->Cell($cols[8]['w'], 7, ' ' . $this->formatPaymentMethods($sale), 1, 1, 'C');
        }
    }

    private function renderSectionTitle(TCPDF $pdf, string $title): void
    {
        $pdf->SetFont(self::FONT_MAIN, 'B', self::SIZE_SECTION);
        $pdf->Cell(0, 10, $title, 0, 1, 'R');
        $pdf->Ln(1);
    }

    private function renderFilters(TCPDF $pdf, array $filters): void
    {
        $texts = $this->buildFilterTexts($filters);
        if (empty($texts)) {
            return;
        }
        $pdf->SetFont(self::FONT_MAIN, '', 9);
        $pdf->Cell(0, 7, 'الفلاتر: ' . implode(' | ', $texts), 'B', 1, 'R');
        $pdf->Ln(5);
    }

    private function renderPaymentMethodTable(TCPDF $pdf, array $methods): void
    {
        $this->renderSectionTitle($pdf, 'طرق الدفع');
        $pdf->SetFont(self::FONT_MAIN, '', self::SIZE_BODY);

        foreach ($methods as $method => $amount) {
            $label = $this->getPaymentMethodLabel((string) $method);
            $pdf->Cell(50, 8, $label, 1, 0, 'R');
            $pdf->Cell(40, 8, number_format((float) $amount, 2), 1, 1, 'C');
        }
        $pdf->Ln(5);
    }

    private function buildPeriodText(?Carbon $startDate, ?Carbon $endDate): string
    {
        if ($startDate && $endDate) {
            return $startDate->format('Y-m-d') . ' - ' . $endDate->format('Y-m-d');
        }
        if ($startDate) {
            return 'من ' . $startDate->format('Y-m-d');
        }
        if ($endDate) {
            return 'إلى ' . $endDate->format('Y-m-d');
        }
        return 'كل السجلات';
    }

    private function buildFilterTexts(array $filters): array
    {
        $texts = [];
        if (!empty($filters['client_id'])) {
            $client = Client::find($filters['client_id']);
            $texts[] = 'العميل: ' . ($client?->name ?? '—');
        }
        if (!empty($filters['user_id'])) {
            $user = User::find($filters['user_id']);
            $texts[] = 'المستخدم: ' . ($user?->name ?? '—');
        }
        if (!empty($filters['status'])) {
            $texts[] = 'الحالة: ' . ($filters['status'] ?? '—');
        }
        return $texts;
    }

    private function formatPaymentMethods(Sale $sale): string
    {
        $payments = $sale->payments ?? collect();
        if ($payments->isEmpty()) {
            return '—';
        }
        return $payments->map(function ($p) {
            $method = $p->method ?? 'cash';
            $amount = (float) ($p->amount ?? 0);
            return $this->getPaymentMethodLabel($method) . ': ' . number_format($amount, 2);
        })->implode(' ، ');
    }

    private function getPaymentMethodLabel(string $method): string
    {
        $labels = [
            'cash' => 'نقدي',
            'bankak' => 'بنكك',
            'bank' => 'بنك',
            'fawry' => 'فوري',
            'ocash' => 'أوكاش',
            'visa' => 'فيزا',
            'bank_transfer' => 'بنك',
            'refund' => 'مرتجع',
        ];
        return $labels[$method] ?? $method;
    }

    private function getSaleTotalAmount(Sale $sale): float
    {
        if (isset($sale->total_amount) && $sale->total_amount !== null && $sale->total_amount !== '') {
            return (float) $sale->total_amount;
        }
        $items = $sale->items ?? collect();
        return (float) $items->sum('total_price');
    }

    private function getSalePaidAmount(Sale $sale): float
    {
        if (isset($sale->paid_amount) && $sale->paid_amount !== null && $sale->paid_amount !== '') {
            return (float) $sale->paid_amount;
        }
        $payments = $sale->payments ?? collect();
        return (float) $payments->sum('amount');
    }
}