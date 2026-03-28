<?php

namespace App\Services;

use App\Models\Supplier;
use App\Services\Pdf\PdfHeaderRenderer;
use TCPDF;
use Exception;
use Illuminate\Support\Facades\Log;

class SupplierLedgerPdfService
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

    public function generate(Supplier $supplier): string
    {
        try {
            $data = $this->buildLedgerData($supplier);
            $this->renderer = new PdfHeaderRenderer('supplier_ledger');
            $this->initPdf($supplier);
            $this->pdf->AddPage();
            $this->renderer->render($this->pdf);

            $this->drawHeader($supplier);
            $this->drawSupplierBox($supplier);
            $this->drawSummary($data['summary']);
            $this->drawTable($data['entries'], $data['summary']);
            $this->drawFooter();

            return $this->pdf->Output('supplier_ledger_' . $supplier->id . '.pdf', 'S');

        } catch (Exception $e) {
            Log::error('SupplierLedgerPdfService failed', [
                'supplier_id' => $supplier->id,
                'error'       => $e->getMessage(),
            ]);
            throw new Exception('Failed to generate supplier ledger PDF: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data
    // ─────────────────────────────────────────────────────────────────────────

    private function buildLedgerData(Supplier $supplier): array
    {
        $purchases = $supplier->purchases()
            ->with('payments')
            ->orderBy('purchase_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        $directPayments = $supplier->payments()
            ->whereNull('purchase_id')
            ->get();

        $totalPurchases       = $purchases->sum('total_amount');
        $totalPaidOnPurchases = $purchases->sum(fn($p) => $p->payments->sum('amount'));
        $totalDirectPayments  = $directPayments->sum('amount');
        $totalPayments        = $totalPaidOnPurchases + $totalDirectPayments;
        $balance              = $totalPurchases - $totalPayments;

        $entries = collect();

        foreach ($purchases as $purchase) {
            $paid            = $purchase->payments->sum('amount');
            $purchaseBalance = $purchase->total_amount - $paid;

            $entries->push([
                'date'        => $purchase->purchase_date->format('Y-m-d'),
                'description' => 'مشتريات #' . str_pad($purchase->id, 5, '0', STR_PAD_LEFT)
                                 . ($purchase->reference_number ? '  (' . $purchase->reference_number . ')' : ''),
                'debit'       => $purchase->total_amount,
                'credit'      => $paid,
                'balance'     => $purchaseBalance,
            ]);
        }

        if ($directPayments->isNotEmpty()) {
            $entries->push([
                'date'        => now()->format('Y-m-d'),
                'description' => 'مدفوعات مباشرة (غير مرتبطة بمشتريات)',
                'debit'       => 0,
                'credit'      => $totalDirectPayments,
                'balance'     => -$totalDirectPayments,
            ]);
        }

        return [
            'summary' => compact('totalPurchases', 'totalPayments', 'balance'),
            'entries' => $entries->values()->all(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Init
    // ─────────────────────────────────────────────────────────────────────────

    private function initPdf(Supplier $supplier): void
    {
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->setPrintHeader(false);
        $this->pdf->SetCreator('Sales Management System');
        $this->pdf->SetAuthor('Sales Management System');
        $this->pdf->SetTitle('كشف حساب مورد — ' . $supplier->name);
        $this->pdf->SetSubject('Supplier Account Statement');
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetMargins(self::M, $this->renderer->getTopMargin(), self::M);
        $this->pdf->SetAutoPageBreak(true, 25);
        $this->pdf->SetFont(self::F, '', 9);
        $this->pdf->setRTL(false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section: Header
    // ─────────────────────────────────────────────────────────────────────────

    private function drawHeader(Supplier $supplier): void
    {
        $W = $this->W();

        // Heavy top rule
        $this->pdf->SetDrawColor(...self::BLACK);
        $this->pdf->SetLineWidth(1.2);
        $this->pdf->Line(self::M, self::M, self::M + $W, self::M);

        // Thin rule 2 mm below
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Line(self::M, self::M + 2.5, self::M + $W, self::M + 2.5);

        $this->pdf->SetY(self::M + 7);

        // Main title
        $this->pdf->SetFont(self::F, 'B', 16);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->Cell($W, 10, 'كشف حساب مورّد', 0, 1, 'C');

        // System sub-title
        $this->pdf->SetFont(self::F, '', 9);
        $this->pdf->SetTextColor(...self::MID);
        $this->pdf->Cell($W, 5, 'نظام إدارة المبيعات', 0, 1, 'C');

        $this->pdf->Ln(3);

        // Meta line: supplier ref  |  issue date
        $this->pdf->SetFont(self::F, '', 8);
        $this->pdf->SetTextColor(...self::TEXT);
        $this->pdf->Cell($W / 2, 5, 'رقم المورّد: #' . str_pad($supplier->id, 5, '0', STR_PAD_LEFT), 0, 0, 'L');
        $this->pdf->Cell($W / 2, 5, 'تاريخ الإصدار: ' . now()->format('Y-m-d'), 0, 1, 'R');

        $this->pdf->Ln(3);

        // Thin rule then heavy rule
        $this->pdf->SetDrawColor(...self::BLACK);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Line(self::M, $this->pdf->GetY(), self::M + $W, $this->pdf->GetY());
        $this->pdf->SetLineWidth(1.0);
        $this->pdf->Line(self::M, $this->pdf->GetY() + 2.5, self::M + $W, $this->pdf->GetY() + 2.5);

        $this->pdf->Ln(8);
        $this->pdf->SetTextColor(...self::TEXT);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section: Supplier info
    // ─────────────────────────────────────────────────────────────────────────

    private function drawSupplierBox(Supplier $supplier): void
    {
        $W      = $this->W();
        $colW   = $W / 2;
        $labelW = $colW * 0.40;
        $valW   = $colW * 0.60;
        $rowH   = 7;

        // Section heading — white fill, bold underline
        $this->pdf->SetFont(self::F, 'B', 9);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Cell($W, 7, 'بيانات المورّد', 1, 1, 'C', true);

        $rows = [
            ['اسم المورّد',  $supplier->name,                  'رقم الهاتف',        $supplier->phone ?? '—'],
            ['المسؤول',      $supplier->contact_person ?? '—', 'البريد الإلكتروني', $supplier->email ?? '—'],
        ];

        $this->pdf->SetFillColor(...self::WHITE);
        foreach ($rows as [$l1, $v1, $l2, $v2]) {
            $this->pdf->SetFont(self::F, 'B', 10);
            // $this->pdf->SetTextColor(...self::MID);
            $this->pdf->Cell($labelW, $rowH, $l1 . ':', 1, 0, 'R', true);

            $this->pdf->Cell($valW, $rowH, $v1, 1, 0, 'R', true);

            $this->pdf->Cell($labelW, $rowH, $l2 . ':', 1, 0, 'R', true);

            $this->pdf->Cell($valW, $rowH, $v2, 1, 1, 'R', true);
        }

        $this->pdf->Ln(6);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section: Financial summary
    // ─────────────────────────────────────────────────────────────────────────

    private function drawSummary(array $summary): void
    {
        $W      = $this->W();
        $colW   = $W / 3;
        $labelW = $colW * 0.55;
        $valW   = $colW * 0.45;
        $rowH   = 8;

        // Section heading
        $this->pdf->SetFont(self::F, 'B', 9);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Cell($W, 7, 'الملخص المالي', 1, 1, 'C', true);

        $pairs = [
            ['إجمالي المشتريات', number_format($summary['totalPurchases'], 2)],
            ['إجمالي المدفوعات', number_format($summary['totalPayments'],  2)],
            ['الرصيد المستحق',   number_format($summary['balance'],        2)],
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
    // Section: Ledger table
    // ─────────────────────────────────────────────────────────────────────────

    private function drawTable(array $entries, array $summary): void
    {
        $W = $this->W();

        $w = [
            'no'     => $W * 0.055,
            'date'   => $W * 0.135,
            'desc'   => $W * 0.395,
            'debit'  => $W * 0.135,
            'credit' => $W * 0.135,
            'bal'    => $W * 0.145,
        ];

        $this->drawTableHeader($w);

        if (empty($entries)) {
            $this->pdf->SetFont(self::F, '', 9);
            $this->pdf->SetFillColor(...self::WHITE);
            $this->pdf->SetTextColor(...self::MID);
            $this->pdf->SetDrawColor(...self::BORDER);
            $this->pdf->Cell($W, 12, 'لا توجد معاملات مسجّلة', 1, 1, 'C', true);
            return;
        }

        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetLineWidth(0.2);

        foreach ($entries as $i => $entry) {
            if ($this->pdf->GetY() + self::RH > $this->pdf->getPageHeight() - 28) {
                $this->pdf->AddPage();
                $this->renderer->render($this->pdf);
                $this->drawTableHeader($w);
            }

            $this->pdf->SetFillColor(...self::WHITE);
            $this->pdf->SetDrawColor(...self::BORDER);

            // Row #
            $this->pdf->SetFont(self::F, '', 7.5);
            $this->pdf->SetTextColor(...self::MID);
            $this->pdf->Cell($w['no'], self::RH, $i + 1, 'LRB', 0, 'C', true);

            // Date
            $this->pdf->SetFont(self::F, '', 8.5);
            $this->pdf->SetTextColor(...self::TEXT);
            $this->pdf->Cell($w['date'], self::RH, $entry['date'], 'LRB', 0, 'C', true);

            // Description
            $this->pdf->Cell($w['desc'], self::RH, $entry['description'], 'LRB', 0, 'R', true);

            // Debit / Credit / Balance
            $this->pdf->SetFont(self::F, 'B', 8.5);
            $this->pdf->SetTextColor(...self::BLACK);
            $this->pdf->Cell($w['debit'],  self::RH, $entry['debit']  > 0 ? number_format($entry['debit'],  2) : '—', 'LRB', 0, 'C', true);
            $this->pdf->Cell($w['credit'], self::RH, $entry['credit'] > 0 ? number_format($entry['credit'], 2) : '—', 'LRB', 0, 'C', true);
            $this->pdf->Cell($w['bal'],    self::RH, number_format($entry['balance'], 2), 'LRB', 1, 'C', true);
        }

        // Totals row — white fill, bold, full border
        $this->pdf->SetFont(self::F, 'B', 8.5);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.4);

        $this->pdf->Cell($w['no'] + $w['date'] + $w['desc'], self::RH, 'الإجمالي', 1, 0, 'R', true);
        $this->pdf->Cell($w['debit'],  self::RH, number_format($summary['totalPurchases'], 2), 1, 0, 'C', true);
        $this->pdf->Cell($w['credit'], self::RH, number_format($summary['totalPayments'],  2), 1, 0, 'C', true);
        $this->pdf->Cell($w['bal'],    self::RH, number_format($summary['balance'],        2), 1, 1, 'C', true);

        $this->pdf->SetTextColor(...self::TEXT);
    }

    private function drawTableHeader(array $w): void
    {
        $this->pdf->SetFont(self::F, 'B', 8.5);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.3);

        $this->pdf->Cell($w['no'],     8, '#',       1, 0, 'C', true);
        $this->pdf->Cell($w['date'],   8, 'التاريخ', 1, 0, 'C', true);
        $this->pdf->Cell($w['desc'],   8, 'البيان',  1, 0, 'C', true);
        $this->pdf->Cell($w['debit'],  8, 'مدين',    1, 0, 'C', true);
        $this->pdf->Cell($w['credit'], 8, 'دائن',    1, 0, 'C', true);
        $this->pdf->Cell($w['bal'],    8, 'الرصيد',  1, 1, 'C', true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section: Footer
    // ─────────────────────────────────────────────────────────────────────────

    private function drawFooter(): void
    {
        $W = $this->W();

        // Disable auto page break before anchoring to bottom
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
