<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\SaleItem;
use App\Services\Pdf\PdfHeaderRenderer;
use Carbon\Carbon;
use DB;
use TCPDF;
use Exception;
use Illuminate\Support\Facades\Log;

class MonthlyRevenuePdfService
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
    private const M  = 12;   // page margin (mm)
    private const RH = 7;    // table row height (mm)
    private const F  = 'arial';

    // ─────────────────────────────────────────────────────────────────────────

    public function generate(int $year, int $month): string
    {
        try {
            $data = $this->buildData($year, $month);
            $this->renderer = new PdfHeaderRenderer('monthly_revenue');
            $this->initPdf($data['month_name']);
            $this->pdf->AddPage();
            $this->renderer->render($this->pdf);

            $this->drawHeader($data['month_name']);
            $this->drawTable($data['daily_breakdown'], $data['month_summary']);
            $this->drawFooter();

            return $this->pdf->Output('monthly_revenue_' . $year . '_' . $month . '.pdf', 'S');

        } catch (Exception $e) {
            Log::error('MonthlyRevenuePdfService failed', ['error' => $e->getMessage()]);
            throw new Exception('Failed to generate monthly revenue PDF: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data
    // ─────────────────────────────────────────────────────────────────────────

    private function buildData(int $year, int $month): array
    {
        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate   = Carbon::createFromDate($year, $month, 1)->endOfMonth();

        $dailySales = SaleItem::query()
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->select(
                DB::raw('DATE(COALESCE(sales.sale_date, sales.created_at)) as sale_day'),
                DB::raw('SUM(sale_items.total_price) as total_sales')
            )
            ->whereBetween(DB::raw('DATE(COALESCE(sales.sale_date, sales.created_at))'), [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('sale_day')
            ->get()
            ->keyBy('sale_day');

        $dailyPaymentsByMethod = Payment::query()
            ->join('sales', 'payments.sale_id', '=', 'sales.id')
            ->select(
                DB::raw('DATE(payments.payment_date) as payment_day'),
                'payments.method',
                DB::raw('SUM(payments.amount) as total_amount_by_method')
            )
            ->whereBetween(DB::raw('DATE(COALESCE(sales.sale_date, sales.created_at))'), [$startDate->toDateString(), $endDate->toDateString()])
            ->whereBetween('payments.payment_date', [$startDate, $endDate])
            ->groupBy('payment_day', 'payments.method')
            ->orderBy('payment_day', 'asc')
            ->orderBy('payments.method', 'asc')
            ->get()
            ->groupBy('payment_day')
            ->map(function ($paymentsOnDay) {
                return $paymentsOnDay->mapWithKeys(fn ($g) => [$g->method => (float) $g->total_amount_by_method]);
            });

        $dailyExpenses = Expense::query()
            ->select(
                DB::raw('DATE(expense_date) as expense_day'),
                DB::raw('SUM(amount) as total_expense')
            )
            ->whereBetween('expense_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('expense_day')
            ->get()
            ->keyBy('expense_day');

        $bankMethods = \App\Support\PaymentMethods::bank();

        $dailyBreakdown = [];
        $monthSummary   = [
            'total_sales'   => 0.0,
            'total_paid'    => 0.0,
            'total_cash'    => 0.0,
            'total_bank'    => 0.0,
            'total_expense' => 0.0,
            'net'           => 0.0,
        ];

        $currentDay = $startDate->copy();
        while ($currentDay->lte($endDate)) {
            $dayStr          = $currentDay->toDateString();
            $paymentsForDay   = $dailyPaymentsByMethod->get($dayStr) ?? collect();
            $dailyTotalSales  = (float) ($dailySales->get($dayStr)->total_sales ?? 0);
            $dailyTotalPaid   = (float) $paymentsForDay->sum();
            $dailyTotalCash   = (float) ($paymentsForDay->get('cash') ?? 0);
            $dailyTotalBank   = (float) $paymentsForDay->filter(fn ($amount, $method) => in_array($method, $bankMethods))->sum();
            $dailyTotalExpense = (float) ($dailyExpenses->get($dayStr)->total_expense ?? 0);
            $dailyNet         = $dailyTotalPaid - $dailyTotalExpense;

            $dailyBreakdown[] = [
                'date'          => $dayStr,
                'total_sales'   => $dailyTotalSales,
                'total_paid'    => $dailyTotalPaid,
                'total_cash'    => $dailyTotalCash,
                'total_bank'    => $dailyTotalBank,
                'total_expense' => $dailyTotalExpense,
                'net'           => $dailyNet,
            ];

            $monthSummary['total_sales']   += $dailyTotalSales;
            $monthSummary['total_paid']    += $dailyTotalPaid;
            $monthSummary['total_cash']    += $dailyTotalCash;
            $monthSummary['total_bank']    += $dailyTotalBank;
            $monthSummary['total_expense'] += $dailyTotalExpense;
            $monthSummary['net']           += $dailyNet;

            $currentDay->addDay();
        }

        return [
            'month_name'      => $startDate->isoFormat('MMMM YYYY'),
            'daily_breakdown' => $dailyBreakdown,
            'month_summary'   => $monthSummary,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Init
    // ─────────────────────────────────────────────────────────────────────────

    private function initPdf(string $monthName): void
    {
        $this->pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->setPrintHeader(false);
        $this->pdf->SetCreator('Sales Management System');
        $this->pdf->SetAuthor('Sales Management System');
        $this->pdf->SetTitle('تقرير المبيعات الشهري — ' . $monthName);
        $this->pdf->SetSubject('Monthly Revenue Report');
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetMargins(self::M, $this->renderer->getTopMargin(), self::M);
        $this->pdf->SetAutoPageBreak(true, 20);
        $this->pdf->SetFont(self::F, '', 9);
        $this->pdf->setRTL(false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section: Header
    // ─────────────────────────────────────────────────────────────────────────

    private function drawHeader(string $monthName): void
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
        $this->pdf->Cell($W, 10, 'تقرير المبيعات الشهري', 0, 1, 'C');

        $this->pdf->SetFont(self::F, '', 9);
        $this->pdf->SetTextColor(...self::MID);
        $this->pdf->Cell($W, 5, $monthName . '  ·  تاريخ الإصدار: ' . now()->format('Y-m-d'), 0, 1, 'C');

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
    // Section: Table
    // ─────────────────────────────────────────────────────────────────────────

    private function drawTable(array $days, array $summary): void
    {
        $W = $this->W();

        $w = [
            'date'    => $W * 0.13,
            'sales'   => $W * 0.145,
            'paid'    => $W * 0.145,
            'cash'    => $W * 0.145,
            'bank'    => $W * 0.145,
            'expense' => $W * 0.145,
            'net'     => $W * 0.145,
        ];

        $this->drawTableHeader($w);
        $this->pdf->SetLineWidth(0.2);

        foreach ($days as $day) {
            if ($this->pdf->GetY() + self::RH > $this->pdf->getPageHeight() - 22) {
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
            $this->pdf->Cell($w['sales'],   self::RH, number_format($day['total_sales'],   2), 'LRB', 0, 'C', true);
            $this->pdf->Cell($w['paid'],    self::RH, number_format($day['total_paid'],    2), 'LRB', 0, 'C', true);
            $this->pdf->Cell($w['cash'],    self::RH, number_format($day['total_cash'],    2), 'LRB', 0, 'C', true);
            $this->pdf->Cell($w['bank'],    self::RH, number_format($day['total_bank'],    2), 'LRB', 0, 'C', true);
            $this->pdf->Cell($w['expense'], self::RH, number_format($day['total_expense'], 2), 'LRB', 0, 'C', true);
            $this->pdf->Cell($w['net'],     self::RH, number_format($day['net'],           2), 'LRB', 1, 'C', true);
        }

        // Totals row
        $this->pdf->SetFont(self::F, 'B', 8.5);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.4);

        $this->pdf->Cell($w['date'],    self::RH, 'الإجمالي', 1, 0, 'R', true);
        $this->pdf->Cell($w['sales'],   self::RH, number_format($summary['total_sales'],   2), 1, 0, 'C', true);
        $this->pdf->Cell($w['paid'],    self::RH, number_format($summary['total_paid'],    2), 1, 0, 'C', true);
        $this->pdf->Cell($w['cash'],    self::RH, number_format($summary['total_cash'],    2), 1, 0, 'C', true);
        $this->pdf->Cell($w['bank'],    self::RH, number_format($summary['total_bank'],    2), 1, 0, 'C', true);
        $this->pdf->Cell($w['expense'], self::RH, number_format($summary['total_expense'], 2), 1, 0, 'C', true);
        $this->pdf->Cell($w['net'],     self::RH, number_format($summary['net'],           2), 1, 1, 'C', true);

        $this->pdf->SetTextColor(...self::TEXT);
    }

    private function drawTableHeader(array $w): void
    {
        $this->pdf->SetFont(self::F, 'B', 8.5);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.3);

        $this->pdf->Cell($w['date'],    8, 'التاريخ',          1, 0, 'C', true);
        $this->pdf->Cell($w['sales'],   8, 'إجمالي المبيعات',   1, 0, 'C', true);
        $this->pdf->Cell($w['paid'],    8, 'إجمالي المدفوع',    1, 0, 'C', true);
        $this->pdf->Cell($w['cash'],    8, 'إجمالي النقدي',     1, 0, 'C', true);
        $this->pdf->Cell($w['bank'],    8, 'إجمالي البنكي',     1, 0, 'C', true);
        $this->pdf->Cell($w['expense'], 8, 'إجمالي المصروفات',  1, 0, 'C', true);
        $this->pdf->Cell($w['net'],     8, 'صافي',             1, 1, 'C', true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section: Footer
    // ─────────────────────────────────────────────────────────────────────────

    private function drawFooter(): void
    {
        $W = $this->W();

        $this->pdf->SetAutoPageBreak(false);
        $this->pdf->SetY(-14);

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
