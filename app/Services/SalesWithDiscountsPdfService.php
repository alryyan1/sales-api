<?php

namespace App\Services;

use App\Models\Sale;
use App\Services\Pdf\PdfHeaderRenderer;
use Carbon\Carbon;
use TCPDF;
use Exception;
use Illuminate\Support\Facades\Log;

class SalesWithDiscountsPdfService
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

    public function generate(string $startDate, string $endDate): string
    {
        try {
            $data = $this->buildData($startDate, $endDate);
            $this->renderer = new PdfHeaderRenderer('sales_with_discounts');
            $this->initPdf();
            $this->pdf->AddPage();
            $this->renderer->render($this->pdf);

            $this->drawHeader($startDate, $endDate, count($data['sales']));
            $this->drawSummary($data['totals']);
            $this->drawTable($data['sales'], $data['totals']);
            $this->drawFooter();

            return $this->pdf->Output('sales_with_discounts.pdf', 'S');

        } catch (Exception $e) {
            Log::error('SalesWithDiscountsPdfService failed', ['error' => $e->getMessage()]);
            throw new Exception('Failed to generate sales-with-discounts PDF: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data
    // ─────────────────────────────────────────────────────────────────────────

    private function buildData(string $startDate, string $endDate): array
    {
        $sales = Sale::with('client:id,name')
            ->whereDate('sale_date', '>=', $startDate)
            ->whereDate('sale_date', '<=', $endDate)
            ->where('discount_amount', '>', 0)
            ->orderBy('sale_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $totals = [
            'total_amount'   => (float) $sales->sum('total_amount'),
            'total_paid'     => (float) $sales->sum('paid_amount'),
            'total_discount' => (float) $sales->sum('discount_amount'),
        ];
        $totals['total_due'] = $totals['total_amount'] - $totals['total_paid'];

        $rows = $sales->map(fn ($sale) => [
            'id'            => $sale->id,
            'date'          => Carbon::parse($sale->sale_date)->format('Y-m-d'),
            'client'        => $sale->client?->name ?? '—',
            'total'         => (float) $sale->total_amount,
            'paid'          => (float) $sale->paid_amount,
            'discount'      => (float) $sale->discount_amount,
            'discount_type' => $sale->discount_type ?? '—',
        ])->values()->all();

        return ['sales' => $rows, 'totals' => $totals];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Init
    // ─────────────────────────────────────────────────────────────────────────

    private function initPdf(): void
    {
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->setPrintHeader(false);
        $this->pdf->SetCreator('Sales Management System');
        $this->pdf->SetAuthor('Sales Management System');
        $this->pdf->SetTitle('تقرير المبيعات المخفضة');
        $this->pdf->SetSubject('Sales With Discounts Report');
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetMargins(self::M, $this->renderer->getTopMargin(), self::M);
        $this->pdf->SetAutoPageBreak(true, 25);
        $this->pdf->SetFont(self::F, '', 9);
        $this->pdf->setRTL(false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section: Header
    // ─────────────────────────────────────────────────────────────────────────

    private function drawHeader(string $startDate, string $endDate, int $count): void
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
        $this->pdf->Cell($W, 10, 'تقرير المبيعات المخفضة', 0, 1, 'C');

        $this->pdf->SetFont(self::F, '', 9);
        $this->pdf->SetTextColor(...self::MID);
        $this->pdf->Cell($W, 5, 'من ' . $startDate . ' إلى ' . $endDate, 0, 1, 'C');

        $this->pdf->Ln(3);

        $this->pdf->SetFont(self::F, '', 8);
        $this->pdf->SetTextColor(...self::TEXT);
        $this->pdf->Cell($W / 2, 5, 'عدد العمليات: ' . $count, 0, 0, 'L');
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

    private function drawSummary(array $totals): void
    {
        $W      = $this->W();
        $colW   = $W / 4;
        $labelW = $colW * 0.6;
        $valW   = $colW * 0.4;
        $rowH   = 8;

        $this->pdf->SetFont(self::F, 'B', 9);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Cell($W, 7, 'ملخص التقرير', 1, 1, 'C', true);

        $pairs = [
            ['إجمالي المبيعات', number_format($totals['total_amount'],   2)],
            ['إجمالي المدفوع',  number_format($totals['total_paid'],     2)],
            ['إجمالي الخصم',    number_format($totals['total_discount'], 2)],
            ['المستحق',         number_format($totals['total_due'],      2)],
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

    private function drawTable(array $sales, array $totals): void
    {
        $W = $this->W();

        $w = [
            'id'       => $W * 0.08,
            'date'     => $W * 0.15,
            'client'   => $W * 0.24,
            'total'    => $W * 0.15,
            'paid'     => $W * 0.15,
            'discount' => $W * 0.15,
            'type'     => $W * 0.08,
        ];

        $this->drawTableHeader($w);

        if (empty($sales)) {
            $this->pdf->SetFont(self::F, '', 9);
            $this->pdf->SetFillColor(...self::WHITE);
            $this->pdf->SetTextColor(...self::MID);
            $this->pdf->SetDrawColor(...self::BORDER);
            $this->pdf->Cell($W, 12, 'لا توجد مبيعات مخفضة', 1, 1, 'C', true);
            return;
        }

        $this->pdf->SetLineWidth(0.2);

        foreach ($sales as $s) {
            if ($this->pdf->GetY() + self::RH > $this->pdf->getPageHeight() - 28) {
                $this->pdf->AddPage();
                $this->renderer->render($this->pdf);
                $this->drawTableHeader($w);
            }

            $this->pdf->SetFillColor(...self::WHITE);
            $this->pdf->SetDrawColor(...self::BORDER);

            $this->pdf->SetFont(self::F, '', 7.5);
            $this->pdf->SetTextColor(...self::MID);
            $this->pdf->Cell($w['id'], self::RH, '#' . $s['id'], 'LRB', 0, 'C', true);

            $this->pdf->SetFont(self::F, '', 8.5);
            $this->pdf->SetTextColor(...self::TEXT);
            $this->pdf->Cell($w['date'],   self::RH, $s['date'],   'LRB', 0, 'C', true);
            $this->pdf->Cell($w['client'], self::RH, $s['client'], 'LRB', 0, 'R', true);

            $this->pdf->SetFont(self::F, 'B', 8.5);
            $this->pdf->SetTextColor(...self::BLACK);
            $this->pdf->Cell($w['total'],    self::RH, number_format($s['total'],    2), 'LRB', 0, 'C', true);
            $this->pdf->Cell($w['paid'],     self::RH, number_format($s['paid'],     2), 'LRB', 0, 'C', true);
            $this->pdf->Cell($w['discount'], self::RH, number_format($s['discount'], 2), 'LRB', 0, 'C', true);

            $this->pdf->SetFont(self::F, '', 7.5);
            $this->pdf->SetTextColor(...self::MID);
            $this->pdf->Cell($w['type'], self::RH, $s['discount_type'], 'LRB', 1, 'C', true);
        }

        // Totals row
        $this->pdf->SetFont(self::F, 'B', 8.5);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.4);

        $this->pdf->Cell($w['id'] + $w['date'] + $w['client'], self::RH, 'الإجمالي', 1, 0, 'R', true);
        $this->pdf->Cell($w['total'],    self::RH, number_format($totals['total_amount'],   2), 1, 0, 'C', true);
        $this->pdf->Cell($w['paid'],     self::RH, number_format($totals['total_paid'],     2), 1, 0, 'C', true);
        $this->pdf->Cell($w['discount'], self::RH, number_format($totals['total_discount'], 2), 1, 0, 'C', true);
        $this->pdf->Cell($w['type'],     self::RH, '', 1, 1, 'C', true);

        $this->pdf->SetTextColor(...self::TEXT);
    }

    private function drawTableHeader(array $w): void
    {
        $this->pdf->SetFont(self::F, 'B', 8.5);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.3);

        $this->pdf->Cell($w['id'],       8, '#',      1, 0, 'C', true);
        $this->pdf->Cell($w['date'],     8, 'التاريخ', 1, 0, 'C', true);
        $this->pdf->Cell($w['client'],   8, 'العميل', 1, 0, 'C', true);
        $this->pdf->Cell($w['total'],    8, 'المجموع', 1, 0, 'C', true);
        $this->pdf->Cell($w['paid'],     8, 'المدفوع', 1, 0, 'C', true);
        $this->pdf->Cell($w['discount'], 8, 'الخصم',   1, 0, 'C', true);
        $this->pdf->Cell($w['type'],     8, 'النوع',   1, 1, 'C', true);
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
