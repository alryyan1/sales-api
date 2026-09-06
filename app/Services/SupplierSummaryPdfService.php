<?php

namespace App\Services;

use App\Models\Supplier;
use App\Services\Pdf\PdfHeaderRenderer;
use TCPDF;
use Exception;
use Illuminate\Support\Facades\Log;

class SupplierSummaryPdfService
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

    public function generate(): string
    {
        try {
            $suppliers = $this->buildSummary();
            $this->renderer = new PdfHeaderRenderer('supplier_summary');
            $this->initPdf();
            $this->pdf->AddPage();
            $this->renderer->render($this->pdf);

            $this->drawHeader(count($suppliers));
            $this->drawTable($suppliers);
            $this->drawFooter();

            return $this->pdf->Output('suppliers_summary.pdf', 'S');

        } catch (Exception $e) {
            Log::error('SupplierSummaryPdfService failed', ['error' => $e->getMessage()]);
            throw new Exception('Failed to generate suppliers summary PDF: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data
    // ─────────────────────────────────────────────────────────────────────────

    private function buildSummary(): array
    {
        $suppliers = Supplier::with(['purchases', 'payments', 'purchaseReturns.items'])->orderBy('name')->get();

        return $suppliers->map(function ($supplier) {
            $totalDebit  = $supplier->purchases->sum('total_amount');
            $totalCredit = $supplier->payments->sum('amount') + $supplier->purchaseReturns->sum('total_amount');

            return [
                'name'    => $supplier->name,
                'debit'   => (float) $totalDebit,
                'credit'  => (float) $totalCredit,
                'balance' => (float) ($totalDebit - $totalCredit),
            ];
        })->values()->all();
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
        $this->pdf->SetTitle('كشف حساب الموردين');
        $this->pdf->SetSubject('Suppliers Summary Statement');
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetMargins(self::M, $this->renderer->getTopMargin(), self::M);
        $this->pdf->SetAutoPageBreak(true, 25);
        $this->pdf->SetFont(self::F, '', 9);
        $this->pdf->setRTL(false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section: Header
    // ─────────────────────────────────────────────────────────────────────────

    private function drawHeader(int $count): void
    {
        $W = $this->W();
        $y = max($this->pdf->GetY(), self::M);

        $this->pdf->SetDrawColor(...self::BLACK);
        $this->pdf->SetLineWidth(1.2);
        $this->pdf->Line(self::M, $y, self::M + $W, $y);

        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Line(self::M, $y + 2.5, self::M + $W, $y + 2.5);

        $this->pdf->SetY($y + 7);

        $this->pdf->SetFont(self::F, 'B', 16);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->Cell($W, 10, 'كشف حساب الموردين', 0, 1, 'C');

        $this->pdf->SetFont(self::F, '', 9);
        $this->pdf->SetTextColor(...self::MID);
        $this->pdf->Cell($W, 5, 'نظام إدارة المبيعات', 0, 1, 'C');

        $this->pdf->Ln(3);

        $this->pdf->SetFont(self::F, '', 8);
        $this->pdf->SetTextColor(...self::TEXT);
        $this->pdf->Cell($W / 2, 5, 'عدد الموردين: ' . $count, 0, 0, 'L');
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
    // Section: Table
    // ─────────────────────────────────────────────────────────────────────────

    private function drawTable(array $suppliers): void
    {
        $W = $this->W();

        $w = [
            'no'      => $W * 0.06,
            'name'    => $W * 0.40,
            'debit'   => $W * 0.18,
            'credit'  => $W * 0.18,
            'balance' => $W * 0.18,
        ];

        $this->drawTableHeader($w);

        if (empty($suppliers)) {
            $this->pdf->SetFont(self::F, '', 9);
            $this->pdf->SetFillColor(...self::WHITE);
            $this->pdf->SetTextColor(...self::MID);
            $this->pdf->SetDrawColor(...self::BORDER);
            $this->pdf->Cell($W, 12, 'لا يوجد موردون', 1, 1, 'C', true);
            return;
        }

        $this->pdf->SetLineWidth(0.2);

        $totals = ['debit' => 0.0, 'credit' => 0.0, 'balance' => 0.0];

        foreach ($suppliers as $i => $s) {
            if ($this->pdf->GetY() + self::RH > $this->pdf->getPageHeight() - 28) {
                $this->pdf->AddPage();
                $this->renderer->render($this->pdf);
                $this->drawTableHeader($w);
            }

            $totals['debit']   += $s['debit'];
            $totals['credit']  += $s['credit'];
            $totals['balance'] += $s['balance'];

            $this->pdf->SetFillColor(...self::WHITE);
            $this->pdf->SetDrawColor(...self::BORDER);

            $this->pdf->SetFont(self::F, '', 7.5);
            $this->pdf->SetTextColor(...self::MID);
            $this->pdf->Cell($w['no'], self::RH, $i + 1, 'LRB', 0, 'C', true);

            $this->pdf->SetFont(self::F, '', 8.5);
            $this->pdf->SetTextColor(...self::TEXT);
            $this->pdf->Cell($w['name'], self::RH, $s['name'], 'LRB', 0, 'C', true);

            $this->pdf->SetFont(self::F, 'B', 8.5);
            $this->pdf->SetTextColor(...self::BLACK);
            $this->pdf->Cell($w['debit'],   self::RH, number_format($s['debit'],   2), 'LRB', 0, 'C', true);
            $this->pdf->Cell($w['credit'],  self::RH, number_format($s['credit'],  2), 'LRB', 0, 'C', true);
            $this->pdf->Cell($w['balance'], self::RH, number_format($s['balance'], 2), 'LRB', 1, 'C', true);
        }

        // Totals row
        $this->pdf->SetFont(self::F, 'B', 8.5);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.4);

        $this->pdf->Cell($w['no'] + $w['name'], self::RH, 'الإجمالي', 1, 0, 'R', true);
        $this->pdf->Cell($w['debit'],   self::RH, number_format($totals['debit'],   2), 1, 0, 'C', true);
        $this->pdf->Cell($w['credit'],  self::RH, number_format($totals['credit'],  2), 1, 0, 'C', true);
        $this->pdf->Cell($w['balance'], self::RH, number_format($totals['balance'], 2), 1, 1, 'C', true);

        $this->pdf->SetTextColor(...self::TEXT);
    }

    private function drawTableHeader(array $w): void
    {
        $this->pdf->SetFont(self::F, 'B', 8.5);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.3);

        $this->pdf->Cell($w['no'],      8, '#',        1, 0, 'C', true);
        $this->pdf->Cell($w['name'],    8, 'المورد',   1, 0, 'C', true);
        $this->pdf->Cell($w['debit'],   8, 'مدين',     1, 0, 'C', true);
        $this->pdf->Cell($w['credit'],  8, 'دائن',     1, 0, 'C', true);
        $this->pdf->Cell($w['balance'], 8, 'الرصيد',   1, 1, 'C', true);
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
