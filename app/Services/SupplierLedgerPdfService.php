<?php

namespace App\Services;

use App\Models\Supplier;
use App\Services\Pdf\PdfHeaderRenderer;
use App\Services\Pdf\PdfLocale;
use TCPDF;
use Exception;
use Illuminate\Support\Facades\Log;

class SupplierLedgerPdfService
{
    private TCPDF $pdf;
    private PdfHeaderRenderer $renderer;
    private array $settings = [];
    private string $locale = 'ar';

    /**
     * Return the Arabic or English string depending on the detected report locale.
     */
    private function t(string $ar, string $en): string
    {
        return $this->locale === 'en' ? $en : $ar;
    }

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
            $this->locale = PdfLocale::detect();
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
                'description' => $this->t('مشتريات #', 'Purchase #') . str_pad($purchase->id, 5, '0', STR_PAD_LEFT)
                                 . ($purchase->reference_number ? '  (' . $purchase->reference_number . ')' : ''),
                'debit'       => $purchase->total_amount,
                'credit'      => $paid,
                'balance'     => $purchaseBalance,
            ]);
        }

        if ($directPayments->isNotEmpty()) {
            $entries->push([
                'date'        => now()->format('Y-m-d'),
                'description' => $this->t('مدفوعات مباشرة (غير مرتبطة بمشتريات)', 'Direct payments (not linked to purchases)'),
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
        $this->settings = app(\App\Services\SettingsService::class)->getAll();

        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->setPrintHeader(false);
        $this->pdf->SetCreator('Sales Management System');
        $this->pdf->SetAuthor('Sales Management System');
        $this->pdf->SetTitle($this->t('كشف حساب مورد — ', 'Statement — ') . $supplier->name);
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
        // Anchor to wherever the company branding block (logo/name/address,
        // drawn by PdfHeaderRenderer just before this call) actually left the
        // cursor — never assume it ends at the page margin.
        $y = $this->pdf->GetY();

        // Heavy top rule
        $this->pdf->SetDrawColor(...self::BLACK);
        $this->pdf->SetLineWidth(1.2);
        $this->pdf->Line(self::M, $y, self::M + $W, $y);

        // Thin rule 2 mm below
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Line(self::M, $y + 2.5, self::M + $W, $y + 2.5);

        $this->pdf->SetY($y + 7);

        // Main title
        $this->pdf->SetFont(self::F, 'B', 16);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->Cell($W, 10, $this->t('كشف حساب مورّد', 'Supplier Account Statement'), 0, 1, 'C');

        // System sub-title
        $this->pdf->SetFont(self::F, '', 9);
        $this->pdf->SetTextColor(...self::MID);
        $this->pdf->Cell($W, 5, $this->t('نظام إدارة المبيعات', 'Sales Management System'), 0, 1, 'C');

        $this->pdf->Ln(3);

        // Meta line: supplier ref  |  issue date
        $this->pdf->SetFont(self::F, '', 8);
        $this->pdf->SetTextColor(...self::TEXT);
        $currency = $this->settings['currency_symbol'] ?? 'OMR';
        $this->pdf->Cell($W / 2, 5, $this->t('رقم المورّد: #', 'Supplier No: #') . str_pad($supplier->id, 5, '0', STR_PAD_LEFT) . $this->t('   |   العملة: ', '   |   Currency: ') . $currency, 0, 0, 'L');
        $this->pdf->Cell($W / 2, 5, $this->t('تاريخ الإصدار: ', 'Issue Date: ') . now()->format('Y-m-d'), 0, 1, 'R');

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
        $labelW = $W * 0.25;
        $valW   = $W * 0.75;
        $rowH   = 7;

        // Section heading — white fill, bold underline
        $this->pdf->SetFont(self::F, 'B', 9);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Cell($W, 7, $this->t('بيانات المورّد', 'Supplier Information'), 1, 1, 'C', true);

        $rows = [
            [$this->t('اسم المورّد', 'Supplier Name'),        $supplier->name],
            [$this->t('رقم الهاتف', 'Phone'),                  $supplier->phone ?? '—'],
            [$this->t('المسؤول', 'Contact Person'),            $supplier->contact_person ?? '—'],
            [$this->t('البريد الإلكتروني', 'Email'),           $supplier->email ?? '—'],
        ];

        $this->pdf->SetFillColor(...self::WHITE);
        foreach ($rows as [$label, $value]) {
            $this->pdf->SetFont(self::F, 'B', 10);
            $this->pdf->Cell($labelW, $rowH, $label . ':', 1, 0, 'R', true);

            $this->pdf->Cell($valW, $rowH, $value, 1, 1, 'R', true);
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
        $labelW = $colW * 0.45;
        $valW   = $colW * 0.55;
        $rowH   = 8;
        $currency = $this->settings['currency_symbol'] ?? 'OMR';

        // Section heading
        $this->pdf->SetFont(self::F, 'B', 9);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Cell($W, 7, $this->t('الملخص المالي', 'Financial Summary'), 1, 1, 'C', true);

        $pairs = [
            [$this->t('إجمالي المشتريات', 'Total Purchases'), number_format($summary['totalPurchases'], 3) . ' ' . $currency],
            [$this->t('إجمالي المدفوعات', 'Total Payments'),  number_format($summary['totalPayments'],  3) . ' ' . $currency],
            [$this->t('الرصيد المستحق', 'Balance Due'),       number_format($summary['balance'],        3) . ' ' . $currency],
        ];

        $this->pdf->SetFillColor(...self::WHITE);
        foreach ($pairs as [$label, $value]) {
            $this->pdf->SetFont(self::F, 'B', 9);
            $this->pdf->Cell($labelW, $rowH, $label . ':', 1, 0, 'R', true);

            $this->pdf->SetFont(self::F, 'B', 7.5);
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
            $this->pdf->Cell($W, 12, $this->t('لا توجد معاملات مسجّلة', 'No transactions recorded'), 1, 1, 'C', true);
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
            $this->pdf->Cell($w['debit'],  self::RH, $entry['debit']  > 0 ? number_format($entry['debit'],  3) : '—', 'LRB', 0, 'C', true);
            $this->pdf->Cell($w['credit'], self::RH, $entry['credit'] > 0 ? number_format($entry['credit'], 3) : '—', 'LRB', 0, 'C', true);
            $this->pdf->Cell($w['bal'],    self::RH, number_format($entry['balance'], 3), 'LRB', 1, 'C', true);
        }

        // Totals row — white fill, bold, full border
        $this->pdf->SetFont(self::F, 'B', 8.5);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.4);

        $this->pdf->Cell($w['no'] + $w['date'] + $w['desc'], self::RH, $this->t('الإجمالي', 'Total'), 1, 0, 'R', true);
        $this->pdf->Cell($w['debit'],  self::RH, number_format($summary['totalPurchases'], 3), 1, 0, 'C', true);
        $this->pdf->Cell($w['credit'], self::RH, number_format($summary['totalPayments'],  3), 1, 0, 'C', true);
        $this->pdf->Cell($w['bal'],    self::RH, number_format($summary['balance'],        3), 1, 1, 'C', true);

        $this->pdf->SetTextColor(...self::TEXT);
    }

    private function drawTableHeader(array $w): void
    {
        $this->pdf->SetFont(self::F, 'B', 8.5);
        $this->pdf->SetFillColor(...self::WHITE);
        $this->pdf->SetTextColor(...self::BLACK);
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.3);

        $this->pdf->Cell($w['no'],     8, '#',                                    1, 0, 'C', true);
        $this->pdf->Cell($w['date'],   8, $this->t('التاريخ', 'Date'),            1, 0, 'C', true);
        $this->pdf->Cell($w['desc'],   8, $this->t('البيان', 'Description'),      1, 0, 'C', true);
        $this->pdf->Cell($w['debit'],  8, $this->t('مدين', 'Debit'),              1, 0, 'C', true);
        $this->pdf->Cell($w['credit'], 8, $this->t('دائن', 'Credit'),             1, 0, 'C', true);
        $this->pdf->Cell($w['bal'],    8, $this->t('الرصيد', 'Balance'),          1, 1, 'C', true);
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

        $this->pdf->Cell($W / 2, 5, $this->t('نظام إدارة المبيعات  ·  طُبع: ', 'Sales Management System  ·  Printed: ') . now()->format('Y-m-d  H:i'), 0, 0, 'R');
        $this->pdf->Cell($W / 2, 5, $this->t('صفحة ', 'Page ') . $this->pdf->getAliasNumPage() . ' / ' . $this->pdf->getAliasNbPages(), 0, 1, 'L');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function W(): float
    {
        return $this->pdf->getPageWidth() - self::M * 2;
    }
}
