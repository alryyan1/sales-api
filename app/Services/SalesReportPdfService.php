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
    private const ORIENTATION = 'L';
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

        // --- PAGE 1: REPORT OVERVIEW ---
        $pdf->AddPage();
        $this->renderHeader($pdf, $startDate, $endDate);
        $this->renderFilters($pdf, $validatedFilters);
        $this->renderSummaryTable($pdf, $summaryStats);
        
        if (!empty($paymentMethods)) {
            $this->renderPaymentMethodTable($pdf, $paymentMethods);
        }

        // --- PAGE 2+: DETAILED LOG ---
        $pdf->AddPage();
        $this->renderSectionTitle($pdf, 'سجل المبيعات التفصيلي | Detailed Sales Ledger');
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

    private function renderHeader(TCPDF $pdf, ?Carbon $startDate, ?Carbon $endDate): void
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

        // Document Metadata
        $pdf->SetFont(self::FONT_MAIN, 'B', 16);
        $pdf->Cell(0, 10, 'تقرير تحليل المبيعات | SALES ANALYSIS REPORT', 0, 1, 'C');
        
        $pdf->SetFont(self::FONT_MAIN, '', 10);
        $period = $this->buildPeriodText($startDate, $endDate);
        $pdf->Cell(0, 6, "Report Period: $period", 0, 1, 'C');
        $pdf->Cell(0, 6, "Generated: " . now()->format('Y-m-d H:i'), 0, 1, 'C');
        $pdf->Ln(10);
    }

    private function renderSummaryTable(TCPDF $pdf, array $stats): void
    {
        $this->renderSectionTitle($pdf, 'الملخص المالي | Financial Summary');
        
        $pdf->SetFont(self::FONT_MAIN, 'B', self::SIZE_BODY);
        $pdf->SetFillColor(240, 240, 240); // Subtle gray for header only

        // Table Header
        $pdf->Cell(100, 8, 'البند', 1, 0, 'C', true);
        $pdf->Cell(80, 8, 'القيمة', 1, 1, 'C', true);

        $pdf->SetFont(self::FONT_MAIN, '', self::SIZE_BODY);
        
        // Order: total sales, total paid, discounts, expense, net
        $totalDiscount = $stats['totalDiscount'] ?? 0;
        $totalExpenses = $stats['totalExpenses'] ?? 0;
        $net = ($stats['totalAmount'] ?? 0) - $totalDiscount - $totalExpenses;
        
        $rows = [
            ['إجمالي المبيعات', $stats['totalAmount'] ?? 0, true],
            ['إجمالي المدفوع', $stats['totalPaid'] ?? 0, true],
            ['إجمالي الخصومات', $totalDiscount, true],
            ['إجمالي المصروفات', $totalExpenses, true],
            ['صافي المبيعات', $net, true],
        ];

        foreach ($rows as $row) {
            $pdf->Cell(100, 8, $row[0], 1, 0, 'R');
            $val = $row[2] ? number_format($row[1], 2) . ' ' . $this->currencySymbol : number_format($row[1]);
            $pdf->Cell(80, 8, $val, 1, 1, 'C');
        }
        $pdf->Ln(10);
    }

    private function renderSalesTable(TCPDF $pdf, Collection $sales): void
    {
        $cols = [
            ['w' => 12, 't' => '#'],
            ['w' => 25, 't' => 'التاريخ'],
            ['w' => 50, 't' => 'العميل'],
            ['w' => 30, 't' => 'المستخدم'],
            ['w' => 25, 't' => 'الإجمالي'],
            ['w' => 25, 't' => 'الخصم'],
            ['w' => 25, 't' => 'المدفوع'],
            ['w' => 25, 't' => 'المتبقي'],
            ['w' => 45, 't' => 'طريقة الدفع'],
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
                // Redraw headers on new page
                $pdf->SetFont(self::FONT_MAIN, 'B', 9);
                foreach ($cols as $col) { $pdf->Cell($col['w'], 9, $col['t'], 1, 0, 'C', true); }
                $pdf->Ln();
                $pdf->SetFont(self::FONT_MAIN, '', 8);
            }

            $discount = (float)($sale->discount_amount ?? 0);
            $due = $sale->due_amount ?? ($sale->total_amount - $discount - $sale->paid_amount);

            $pdf->Cell($cols[0]['w'], 7, $sale->id, 1, 0, 'C');
            $pdf->Cell($cols[1]['w'], 7, date('Y-m-d', strtotime($sale->sale_date)), 1, 0, 'C');
            $pdf->Cell($cols[2]['w'], 7, ' ' . mb_substr($sale->client->name ?? 'عميل عام', 0, 25), 1, 0, 'C');
            $pdf->Cell($cols[3]['w'], 7, ' ' . mb_substr($sale->user->name ?? '-', 0, 15), 1, 0, 'C');
            $pdf->Cell($cols[4]['w'], 7, number_format($sale->total_amount, 2), 1, 0, 'C');
            $pdf->Cell($cols[5]['w'], 7, number_format($discount, 2), 1, 0, 'C');
            $pdf->Cell($cols[6]['w'], 7, number_format($sale->paid_amount, 2), 1, 0, 'C');
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
        if (empty($texts)) return;

        $pdf->SetFont(self::FONT_MAIN, '', 9);
        $pdf->Cell(0, 7, "Applied Filters: " . implode(' | ', $texts), 'B', 1, 'R');
        $pdf->Ln(5);
    }

    private function renderPaymentMethodTable(TCPDF $pdf, array $methods): void
    {
        $this->renderSectionTitle($pdf, 'طرق الدفع | Payment Methods');
        $pdf->SetFont(self::FONT_MAIN, '', self::SIZE_BODY);
        
        foreach ($methods as $method => $amount) {
            $pdf->Cell(50, 8, $this->getPaymentMethodLabel($method), 1, 0, 'R');
            $pdf->Cell(40, 8, number_format($amount, 2), 1, 1, 'C');
        }
        $pdf->Ln(5);
    }

    private function buildPeriodText(?Carbon $startDate, ?Carbon $endDate): string
    {
        if ($startDate && $endDate) return $startDate->format('Y-m-d') . ' to ' . $endDate->format('Y-m-d');
        if ($startDate) return 'From ' . $startDate->format('Y-m-d');
        if ($endDate) return 'Until ' . $endDate->format('Y-m-d');
        return 'All Records';
    }

    private function buildFilterTexts(array $filters): array
    {
        $texts = [];
        if (!empty($filters['client_id'])) $texts[] = "Client: " . (Client::find($filters['client_id'])->name ?? 'N/A');
        if (!empty($filters['user_id'])) $texts[] = "User: " . (User::find($filters['user_id'])->name ?? 'N/A');
        if (!empty($filters['status'])) $texts[] = "Status: " . $filters['status'];
        return $texts;
    }

    private function formatPaymentMethods(Sale $sale): string
    {
        return $sale->payments->map(function($p) {
            return $this->getPaymentMethodLabel($p->method) . ': ' . number_format($p->amount);
        })->implode(' , ');
    }

    private function getPaymentMethodLabel(string $method): string
    {
        $labels = [
            'cash' => 'Cash', 'visa' => 'Visa/Mada', 'bank_transfer' => 'Bank', 'refund' => 'Refund'
        ];
        return $labels[$method] ?? $method;
    }
}