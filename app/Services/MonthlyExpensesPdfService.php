<?php

namespace App\Services;

use App\Models\Expense;
use App\Services\Pdf\PdfHeaderRenderer;
use Carbon\Carbon;
use TCPDF;
use Exception;
use Illuminate\Support\Facades\Log;

class MonthlyExpensesPdfService
{
    private TCPDF $pdf;
    private PdfHeaderRenderer $renderer;

    // ── Palette (black & white only) ─────────────────────────────────────────
    private const BLACK  = [0,   0,   0];
    private const MID    = [120, 120, 120];
    private const WHITE  = [255, 255, 255];
    private const BORDER = [160, 160, 160];
    private const TEXT   = [30,  30,  30];

    // ── Layout ────────────────────────────────────────────────────────────────
    private const M  = 15;   // page margin (mm)
    private const RH = 7;    // table row height (mm)
    private const F  = 'arial';

    // ─────────────────────────────────────────────────────────────────────────

    public function generate(int $year, int $month): string
    {
        try {
            $data = $this->buildData($year, $month);
            $this->renderer = new PdfHeaderRenderer('monthly_expenses');
            $this->initPdf($data['month_name'], $year);
            $this->pdf->AddPage();
            $this->renderer->render($this->pdf);

            $this->drawHeader($data['month_name'], $year);
            $this->drawSummary($data['month_summary']);
            $this->drawTable($data['daily_breakdown'], $data['month_summary']);
            $this->drawFooter();

            return $this->pdf->Output('monthly_expenses_' . $year . '_' . $month . '.pdf', 'S');

        } catch (Exception $e) {
            Log::error('MonthlyExpensesPdfService failed', ['error' => $e->getMessage()]);
            throw new Exception('Failed to generate monthly expenses PDF: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data
    // ─────────────────────────────────────────────────────────────────────────

    private function buildData(int $year, int $month): array
    {
        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate   = Carbon::createFromDate($year, $month, 1)->endOfMonth();

        $expenses = Expense::query()
            ->whereBetween('expense_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->orderBy('expense_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $expensesByDate = $expenses->groupBy(function ($expense) {
            return Carbon::parse($expense->expense_date)->format('Y-m-d');
        });

        $dailyBreakdown = [];
        $monthSummary   = ['total' => 0.0, 'cash_total' => 0.0, 'bank_total' => 0.0];

        $currentDay = $startDate->copy();
        while ($currentDay->lte($endDate)) {
            $dayStr       = $currentDay->toDateString();
            $dayExpenses  = $expensesByDate->get($dayStr) ?? collect();
            $dayTotal     = (float) $dayExpenses->sum('amount');
            $dayCashTotal = (float) $dayExpenses->where('payment_method', 'cash')->sum('amount');
            $dayBankTotal = (float) $dayExpenses->where('payment_method', 'bank')->sum('amount');

            $dailyBreakdown[] = [
                'date'       => $dayStr,
                'total'      => $dayTotal,
                'cash_total' => $dayCashTotal,
                'bank_total' => $dayBankTotal,
            ];

            $monthSummary['total']      += $dayTotal;
            $monthSummary['cash_total'] += $dayCashTotal;
            $monthSummary['bank_total'] += $dayBankTotal;

            $currentDay->addDay();
        }

        return [
            'month_name'      => $startDate->isoFormat('MMMM'),
            'daily_breakdown' => $dailyBreakdown,
            'month_summary'   => $monthSummary,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Init
    // ─────────────────────────────────────────────────────────────────────────

    private function initPdf(string $monthName, int $year): void
    {
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->setPrintHeader(false);
        $this->pdf->SetCreator('Sales Management System');
        $this->pdf->SetAuthor('Sales Management System');
        $this->pdf->SetTitle('تقرير المصروفات الشهري — ' . $monthName . ' ' . $year);
        $this->pdf->SetSubject('Monthly Expenses Report');
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetMargins(self::M, $this->renderer->getTopMargin(), self::M);
        $this->pdf->SetAutoPageBreak(true, 25);
        $this->pdf->SetFont(self::F, '', 9);
        $this->pdf->setRTL(false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section: Header
    // ─────────────────────────────────────────────────────────────────────────

    private function drawHeader(string $monthName, int $year): void
    {
        $W = $this->W();

        $this->pdf->SetDrawColor(...self::BLACK);
        $this->pdf->SetLineWidth(1.2);
        $this->pdf->Line(self::M, self::M, self::M + $W, self::M);

        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Line(self::M, self::M + 2.5, self::M + $W, self::M + 2.5);

        $this->pdf->SetY(self::M + 7);

        $this->pdf->SetFont(self::F, 'B', 16);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->Cell($W, 10, 'تقرير المصروفات الشهري', 0, 1, 'C');

        $this->pdf->SetFont(self::F, '', 9);
        $this->pdf->SetTextColor(...self::MID);
        $this->pdf->Cell($W, 5, $monthName . ' ' . $year, 0, 1, 'C');

        $this->pdf->Ln(3);

        $this->pdf->SetFont(self::F, '', 8);
        $this->pdf->SetTextColor(...self::TEXT);
        $this->pdf->Cell($W / 2, 5, 'تاريخ الإصدار: ' . now()->format('Y-m-d'), 0, 1, 'R');

        $this->pdf->Ln(3);

        $this->pdf->SetDrawColor(...self::BLACK);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Line(self::M, $this->pdf->GetY(), self::M + $W, $this->pdf->GetY());
        $this->pdf->SetLineWidth(1.0);
        $this->pdf->Line(self::M, $this->pdf->GetY() + 2.5, self::M + $W, $this->pdf->GetY() + 2.5);

        $this->pdf->Ln(8);
        $this->pdf->SetTextColor(...self::TEXT);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section: Summary
    // ─────────────────────────────────────────────────────────────────────────

    private function drawSummary(array $summary): void
    {
        $W      = $this->W();
        $colW   = $W / 3;
        $labelW = $colW * 0.55;
        $valW   = $colW * 0.45;
        $rowH   = 8;

        $this->pdf->SetFont(self::F, 'B', 9);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Cell($W, 7, 'ملخص الشهر', 1, 1, 'C', true);

        $pairs = [
            ['إجمالي المصروفات',    number_format($summary['total'],      2)],
            ['المصروفات النقدية',   number_format($summary['cash_total'], 2)],
            ['المصروفات البنكية',   number_format($summary['bank_total'], 2)],
        ];

        $this->pdf->SetFillColor(...self::WHITE);
        foreach ($pairs as [$label, $value]) {
            $this->pdf->Cell($labelW, $rowH, $label . ':', 1, 0, 'R', true);
            $this->pdf->Cell($valW, $rowH, $value, 1, 0, 'C', true);
        }

        $this->pdf->Ln($rowH + 6);
        $this->pdf->SetTextColor(...self::TEXT);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section: Table
    // ─────────────────────────────────────────────────────────────────────────

    private function drawTable(array $days, array $summary): void
    {
        $W = $this->W();

        $w = [
            'date'   => $W * 0.25,
            'total'  => $W * 0.25,
            'cash'   => $W * 0.25,
            'bank'   => $W * 0.25,
        ];

        $this->drawTableHeader($w);

        $this->pdf->SetLineWidth(0.2);

        foreach ($days as $day) {
            if ($this->pdf->GetY() + self::RH > $this->pdf->getPageHeight() - 28) {
                $this->pdf->AddPage();
                $this->renderer->render($this->pdf);
                $this->drawTableHeader($w);
            }

            $this->pdf->SetFillColor(...self::WHITE);
            $this->pdf->SetDrawColor(...self::BORDER);

            $this->pdf->SetFont(self::F, '', 8.5);
            $this->pdf->SetTextColor(...self::TEXT);
            $this->pdf->Cell($w['date'], self::RH, $day['date'], 'LRB', 0, 'C', true);

            $this->pdf->SetFont(self::F, 'B', 8.5);
            $this->pdf->SetTextColor(...self::BLACK);
            $this->pdf->Cell($w['total'], self::RH, number_format($day['total'], 2), 'LRB', 0, 'C', true);
            $this->pdf->Cell($w['cash'],  self::RH, number_format($day['cash_total'], 2), 'LRB', 0, 'C', true);
            $this->pdf->Cell($w['bank'],  self::RH, number_format($day['bank_total'], 2), 'LRB', 1, 'C', true);
        }

        // Totals row
        $this->pdf->SetFont(self::F, 'B', 8.5);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.4);

        $this->pdf->Cell($w['date'],  self::RH, 'الإجمالي', 1, 0, 'R', true);
        $this->pdf->Cell($w['total'], self::RH, number_format($summary['total'],      2), 1, 0, 'C', true);
        $this->pdf->Cell($w['cash'],  self::RH, number_format($summary['cash_total'], 2), 1, 0, 'C', true);
        $this->pdf->Cell($w['bank'],  self::RH, number_format($summary['bank_total'], 2), 1, 1, 'C', true);

        $this->pdf->SetTextColor(...self::TEXT);
    }

    private function drawTableHeader(array $w): void
    {
        $this->pdf->SetFont(self::F, 'B', 8.5);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.3);

        $this->pdf->Cell($w['date'],  8, 'التاريخ', 1, 0, 'C', true);
        $this->pdf->Cell($w['total'], 8, 'الإجمالي', 1, 0, 'C', true);
        $this->pdf->Cell($w['cash'],  8, 'نقدي',    1, 0, 'C', true);
        $this->pdf->Cell($w['bank'],  8, 'بنكي',    1, 1, 'C', true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section: Footer
    // ─────────────────────────────────────────────────────────────────────────

    private function drawFooter(): void
    {
        $W = $this->W();

        $this->pdf->SetAutoPageBreak(false);
        $this->pdf->SetY(-16);

        $this->pdf->SetDrawColor(...self::BLACK);
        $this->pdf->SetLineWidth(0.8);
        $this->pdf->Line(self::M, $this->pdf->GetY(), self::M + $W, $this->pdf->GetY());
        $this->pdf->SetLineWidth(0.2);
        $this->pdf->Line(self::M, $this->pdf->GetY() + 1.5, self::M + $W, $this->pdf->GetY() + 1.5);
        $this->pdf->Ln(3.5);

        $this->pdf->SetFont(self::F, '', 7.5);
        $this->pdf->SetTextColor(...self::MID);

        $this->pdf->Cell($W / 2, 5, 'نظام إدارة المبيعات  ·  طُبع: ' . now()->format('Y-m-d  H:i'), 0, 0, 'R');
        $this->pdf->Cell($W / 2, 5, 'صفحة ' . $this->pdf->getAliasNumPage() . ' / ' . $this->pdf->getAliasNbPages(), 0, 1, 'L');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function W(): float
    {
        return $this->pdf->getPageWidth() - self::M * 2;
    }
}
