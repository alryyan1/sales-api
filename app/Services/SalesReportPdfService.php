<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Client;
use App\Models\User;
use App\Services\Pdf\PdfHeaderRenderer;
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
    private PdfHeaderRenderer $renderer;

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
        $this->renderer = new PdfHeaderRenderer('sales_report');
        $pdf = $this->initializePdf();

        // --- PAGE 1: REPORT OVERVIEW (popup-style summary) ---
        $pdf->AddPage();
        $this->renderer->render($pdf);
        $totalDiscount = $sales->sum(fn(Sale $s) => (float) ($s->discount_amount ?? 0));
        $this->renderHeader($pdf, $summaryStats, $startDate, $endDate);
        $this->renderFilters($pdf, $validatedFilters);
        $this->renderSummaryPopupStyle($pdf, $summaryStats, $paymentMethods, $totalDiscount);

        // --- PAGE 2+: DETAILED LOG ---
        $pdf->AddPage();
        $this->renderer->render($pdf);
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
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('System');
        $pdf->SetAuthor($this->companyName);
        $pdf->SetTitle('Sales Report');
        $pdf->SetMargins(self::MARGIN, $this->renderer->getTopMargin(), self::MARGIN);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setRTL(false);
        $pdf->SetFont(self::FONT_MAIN, '', 10);
        return $pdf;
    }

    private function renderHeader(TCPDF $pdf, array $summaryStats, ?Carbon $startDate, ?Carbon $endDate): void
    {
        // Report Title & Date/Shift Info
        $pdf->SetFont(self::FONT_MAIN, 'B', 14);

        $title = 'تقرير المبيعات والوردية';
        if (!empty($summaryStats['shift'])) {
            $title .= ' - وردية رقم #' . ($summaryStats['shift']['id'] ?? '');
        }

        $pdf->Cell(0, 8, $title, 0, 1, 'C');

        $pdf->SetFont(self::FONT_MAIN, '', 10);

        // Context Info (Shift Opened/Closed or Date Range)
        if (!empty($summaryStats['shift'])) {
            $info = 'تاريخ الفتح: ' . ($summaryStats['shift']['opened_at'] ?? '—');
            if (!empty($summaryStats['shift']['user_name'])) {
                $info .= ' | المستخدم: ' . $summaryStats['shift']['user_name'];
            }
            $pdf->Cell(0, 6, $info, 0, 1, 'C');
        } else {
            $period = $this->buildPeriodText($startDate, $endDate);
            $pdf->Cell(0, 6, 'الفترة: ' . $period, 0, 1, 'C');
        }

        $pdf->Cell(0, 6, 'تاريخ الطباعة: ' . now()->format('Y-m-d h:i A'), 0, 1, 'C');

        $pdf->Ln(5);
        $pdf->SetLineWidth(0.4);
        $pdf->Line(self::MARGIN, $pdf->GetY(), 210 - self::MARGIN, $pdf->GetY());
        $pdf->Ln(5);
    }

    /**
     * Render the 6-column financial summary table
     * Columns: Item | Cash | Bankak | Fawry | Ocash | Total
     * Rows: Revenue, Expenses, Returns, Net
     */
    private function renderSummaryPopupStyle(TCPDF $pdf, array $stats, array $paymentMethods, float $totalDiscount = 0): void
    {
        $cols = [
            ['w' => 40, 't' => 'البيان'],     // Item
            ['w' => 25, 't' => 'نقدي'],     // Cash
            ['w' => 25, 't' => 'بنكك'],     // Bankak
            ['w' => 25, 't' => 'فوري'],     // Fawry
            ['w' => 25, 't' => 'أوكاش'],    // Ocash
            ['w' => 30, 't' => 'الإجمالي'], // Total
        ];

        // Prepare Data Rows
        // 1. Revenues (Sales)
        $revCash = (float)($paymentMethods['cash'] ?? 0);
        $revBankak = (float)($paymentMethods['bankak'] ?? 0);
        $revFawry = (float)($paymentMethods['fawry'] ?? 0);
        $revOcash = (float)($paymentMethods['ocash'] ?? 0);
        // Combine other bank methods (visa, etc) into Bankak or handle separately? 
        // Request asked for specific 6 columns. Any 'other' payments usually go to Bank/Visa. 
        // For strict adherence to cols, we'll map generic 'visa'/'bank' to Bankak or add to 'Total' but show 0 in specific cols if not matching.
        // Let's assume 'visa'/'bank_transfer' -> Bankak for simplicity in this specific 6-col layout, or just ignore if strictly those 4 methods.
        // Better: Add 'visa' to 'Bankak' column for display if User implies 'Bank' generally, OR just display exact matches.
        // Given the columns: Cash, Bankak, Fawry, Ocash. 
        // If there are other methods (like Visa), they won't fit a specific column. We will put them in Total.

        $revTotal = array_sum($paymentMethods); // Total of ALL methods

        // 2. Expenses
        $expBreakdown = $stats['expenses_breakdown'] ?? [];
        $expCash = (float)($expBreakdown['cash'] ?? 0);
        $expBankak = (float)($expBreakdown['bankak'] ?? 0); // Assuming mapped to 'bankak' or generic 'bank'
        $expFawry = (float)($expBreakdown['fawry'] ?? 0);
        $expOcash = (float)($expBreakdown['ocash'] ?? 0);
        // Add generic 'bank' expense to bankak col? or just total? 
        // Commonly 'bank' expense implies bank transfer/online.
        $expBankGeneric = (float)($expBreakdown['bank'] ?? 0);
        $expBankak += $expBankGeneric;

        $expTotal = (float)($stats['totalExpenses'] ?? 0);

        // 3. Returns (Sales Returns)
        $retBreakdown = $stats['returns_breakdown'] ?? [];
        $retCash = (float)($retBreakdown['cash'] ?? 0);
        $retBankak = (float)($retBreakdown['bankak'] ?? 0);
        $retFawry = (float)($retBreakdown['fawry'] ?? 0);
        $retOcash = (float)($retBreakdown['ocash'] ?? 0);
        $retTotal = (float)($stats['totalReturns'] ?? 0);

        // 4. Net (Revenue - Expense - Returns)
        $netCash = $revCash - $expCash - $retCash;
        $netBankak = $revBankak - $expBankak - $retBankak;
        $netFawry = $revFawry - $expFawry - $retFawry;
        $netOcash = $revOcash - $expOcash - $retOcash;
        $netTotal = $revTotal - $expTotal - $retTotal;


        // -- RENDER TABLE --

        $pdf->SetFont(self::FONT_MAIN, 'B', 10);

        // Header
        $pdf->SetFillColor(230, 230, 230);
        foreach ($cols as $col) {
            $pdf->Cell($col['w'], 9, $col['t'], 1, 0, 'C', true);
        }
        $pdf->Ln();

        // Row 1: Revenues
        $pdf->SetFont(self::FONT_MAIN, '', 10);
        $this->renderRow($pdf, $cols, 'الإيرادات', $revCash, $revBankak, $revFawry, $revOcash, $revTotal);

        // Row 2: Expenses
        $this->renderRow($pdf, $cols, 'المصروفات', $expCash, $expBankak, $expFawry, $expOcash, $expTotal);

        // Row 3: Returns
        $this->renderRow($pdf, $cols, 'مردودات المبيعات', $retCash, $retBankak, $retFawry, $retOcash, $retTotal);

        // Row 4: Net Totals (Footer)
        $pdf->SetFont(self::FONT_MAIN, 'B', 10);
        $pdf->SetFillColor(240, 248, 255); // Light Blue
        $this->renderRow($pdf, $cols, 'الصافي', $netCash, $netBankak, $netFawry, $netOcash, $netTotal, true);

        $pdf->Ln(10);
    }

    private function renderRow($pdf, $cols, $label, $v1, $v2, $v3, $v4, $total, $fill = false)
    {
        $pdf->Cell($cols[0]['w'], 8, $label, 1, 0, 'R', $fill);
        $pdf->Cell($cols[1]['w'], 8, number_format($v1, 2), 1, 0, 'C', $fill); // Cash
        $pdf->Cell($cols[2]['w'], 8, number_format($v2, 2), 1, 0, 'C', $fill); // Bankak
        $pdf->Cell($cols[3]['w'], 8, number_format($v3, 2), 1, 0, 'C', $fill); // Fawry
        $pdf->Cell($cols[4]['w'], 8, number_format($v4, 2), 1, 0, 'C', $fill); // Ocash
        $pdf->Cell($cols[5]['w'], 8, number_format($total, 2), 1, 0, 'C', $fill); // Total
        $pdf->Ln();
    }

    private function renderSalesTable(TCPDF $pdf, Collection $sales): void
    {
        $cols = [
            ['w' => 8, 't' => '#'], // Reduced 10->8
            ['w' => 20, 't' => 'التاريخ'], // Reduced 22->20
            ['w' => 24, 't' => 'المستخدم'], // Reduced 30->24
            ['w' => 17, 't' => 'الإجمالي'], // Reduced 18->17
            ['w' => 13, 't' => 'الخصم'], // Reduced 15->13
            ['w' => 17, 't' => 'المدفوع'], // Reduced 18->17
            ['w' => 13, 't' => 'المتبقي'], // Reduced 15->13
            ['w' => 66, 't' => 'طريقة الدفع'], // Increased 27->46
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
                $this->renderer->render($pdf);
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
            $pdf->Cell($cols[2]['w'], 7, ' ' . $userName, 1, 0, 'C');
            $pdf->Cell($cols[3]['w'], 7, number_format($totalAmount, 2), 1, 0, 'C');
            $pdf->Cell($cols[4]['w'], 7, number_format($discount, 2), 1, 0, 'C');
            $pdf->Cell($cols[5]['w'], 7, number_format($paidAmount, 2), 1, 0, 'C');
            $pdf->Cell($cols[6]['w'], 7, number_format($due, 2), 1, 0, 'C');
            $pdf->Cell($cols[7]['w'], 7, ' ' . $this->formatPaymentMethods($sale), 1, 1, 'C');
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
