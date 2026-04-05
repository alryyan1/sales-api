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
    private const ORIENTATION = 'P';
    private const UNIT        = 'mm';
    private const FORMAT      = 'A4';
    private const PAGE_W      = 210;
    private const MARGIN      = 14;
    private const BODY_W      = self::PAGE_W - 2 * self::MARGIN;
    private const FONT        = 'arial';

    private string $companyName;
    private string $currencySymbol;
    private PdfHeaderRenderer $renderer;

    // ─────────────────────────────────────────────────────────────────────────

    public function generate(
        Collection $sales,
        array      $validatedFilters,
        array      $summaryStats,
        array      $paymentMethods,
        ?Carbon    $startDate = null,
        ?Carbon    $endDate   = null,
        ?string    $baseUrl   = null
    ): string {
        $settings = (new SettingsService())->getAll();
        $this->companyName    = $settings['company_name']    ?? '';
        $this->currencySymbol = $settings['currency_symbol'] ?? 'SAR';
        $this->renderer       = new PdfHeaderRenderer('sales_report');

        $pdf = $this->makePdf();

        // ── PAGE 1: SUMMARY ──────────────────────────────────────────────────
        $pdf->AddPage();
        $this->renderer->render($pdf);
        $this->renderHeader($pdf, $summaryStats, $startDate, $endDate, $validatedFilters);
        $this->renderKpi($pdf, $summaryStats);
        $this->renderMatrix($pdf, $summaryStats, $paymentMethods,
            $sales->sum(fn(Sale $s) => (float)($s->discount_amount ?? 0)));

        // ── PAGE 2+: DETAIL ───────────────────────────────────────────────────
        $pdf->AddPage();
        $this->renderer->render($pdf);
        $this->sectionTitle($pdf, 'سجل المبيعات التفصيلي');
        $this->renderSalesTable($pdf, $sales);

        return $pdf->Output('Report_' . now()->format('Y-m-d') . '.pdf', 'S');
    }

    // ── PDF init ──────────────────────────────────────────────────────────────

    private function makePdf(): TCPDF
    {
        $pdf = new TCPDF(self::ORIENTATION, self::UNIT, self::FORMAT, true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(self::MARGIN, $this->renderer->getTopMargin(), self::MARGIN);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->setRTL(false);
        $pdf->SetFont(self::FONT, '', 8);
        return $pdf;
    }

    // ── Header ────────────────────────────────────────────────────────────────

    private function renderHeader(TCPDF $pdf, array $stats, ?Carbon $start, ?Carbon $end, array $filters): void
    {
        // Title
        $pdf->SetFont(self::FONT, 'B', 14);
        $pdf->Cell(self::BODY_W, 8, 'تقرير المبيعات', 0, 1, 'C');

        // Shift / period line
        $pdf->SetFont(self::FONT, '', 8);
        $pdf->Cell(self::BODY_W, 5, $this->buildSubTitle($stats, $start, $end), 0, 1, 'C');

        // Separator
        $this->hRule($pdf, 0.5);

        // Meta row: print date (left) | filters (right)
        $pdf->SetFont(self::FONT, '', 7);
        $pdf->SetTextColor(80, 80, 80);
        $filterText = $this->buildFilterLine($filters);
        $pdf->Cell(self::BODY_W / 2, 5, 'طباعة: ' . now()->format('Y-m-d  h:i A'), 0, 0, 'L');
        $pdf->Cell(self::BODY_W / 2, 5, $filterText, 0, 1, 'R');
        $pdf->SetTextColor(0, 0, 0);

        $this->hRule($pdf, 0.2);
        $pdf->Ln(3);
    }

    private function buildSubTitle(array $stats, ?Carbon $start, ?Carbon $end): string
    {
        if (!empty($stats['shift'])) {
            $s     = $stats['shift'];
            $parts = ['وردية #' . ($s['id'] ?? '—')];
            if (!empty($s['opened_at'])) $parts[] = $s['opened_at'];
            if (!empty($s['user_name'])) $parts[] = $s['user_name'];
            return implode('   ·   ', $parts);
        }
        return $this->buildPeriodText($start, $end);
    }

    // ── KPI row ───────────────────────────────────────────────────────────────

    private function renderKpi(TCPDF $pdf, array $stats): void
    {
        $items = [
            'عدد الفواتير'    => number_format((int)($stats['totalSales']  ?? 0)),
            'إجمالي المبيعات' => $this->fmt($stats['totalAmount'] ?? 0),
            'المدفوع'         => $this->fmt($stats['totalPaid']   ?? 0),
            'المتبقي'         => $this->fmt($stats['totalDue']    ?? 0),
        ];

        $w = self::BODY_W / count($items);

        // Labels
        $pdf->SetFont(self::FONT, '', 7);
        $pdf->SetTextColor(80, 80, 80);
        foreach ($items as $label => $_) {
            $pdf->Cell($w, 5, $label, 'TLR', 0, 'C');
        }
        $pdf->Ln();

        // Values
        $pdf->SetFont(self::FONT, 'B', 10);
        $pdf->SetTextColor(0, 0, 0);
        foreach ($items as $_ => $value) {
            $pdf->Cell($w, 7, $value, 'BLR', 0, 'C');
        }
        $pdf->Ln();

        $pdf->Ln(4);
    }

    // ── Financial Matrix ──────────────────────────────────────────────────────

    private function renderMatrix(TCPDF $pdf, array $stats, array $paymentMethods, float $totalDiscount): void
    {
        $this->sectionTitle($pdf, 'ملخص مالي');

        // RTL columns: الخصم | الإجمالي | أوكاش | فوري | بنكك | نقدي | البيان
        $cols = [
            ['w' => 20, 't' => 'الخصم'],
            ['w' => 26, 't' => 'الإجمالي'],
            ['w' => 22, 't' => 'أوكاش'],
            ['w' => 22, 't' => 'فوري'],
            ['w' => 22, 't' => 'بنكك'],
            ['w' => 22, 't' => 'نقدي'],
            ['w' => 48, 't' => 'البيان'],
        ];

        // Header
        $pdf->SetFont(self::FONT, 'B', 8);
        foreach ($cols as $col) {
            $pdf->Cell($col['w'], 6, $col['t'], 1, 0, 'C');
        }
        $pdf->Ln();

        // Calculations
        $revCash   = (float)($paymentMethods['cash']          ?? 0);
        $revBank   = (float)($paymentMethods['bankak']        ?? 0)
                   + (float)($paymentMethods['visa']          ?? 0)
                   + (float)($paymentMethods['bank_transfer'] ?? 0)
                   + (float)($paymentMethods['bank']          ?? 0);
        $revFawry  = (float)($paymentMethods['fawry']         ?? 0);
        $revOcash  = (float)($paymentMethods['ocash']         ?? 0);
        $revTotal  = array_sum($paymentMethods);

        $eb        = $stats['expenses_breakdown'] ?? [];
        $expCash   = (float)($eb['cash']   ?? 0);
        $expBank   = (float)($eb['bankak'] ?? 0) + (float)($eb['bank'] ?? 0);
        $expFawry  = (float)($eb['fawry']  ?? 0);
        $expOcash  = (float)($eb['ocash']  ?? 0);
        $expTotal  = (float)($stats['totalExpenses'] ?? 0);

        $rb        = $stats['returns_breakdown'] ?? [];
        $retCash   = (float)($rb['cash']   ?? 0);
        $retBank   = (float)($rb['bankak'] ?? 0);
        $retFawry  = (float)($rb['fawry']  ?? 0);
        $retOcash  = (float)($rb['ocash']  ?? 0);
        $retTotal  = (float)($stats['totalReturns'] ?? 0);

        $netCash   = $revCash  - $expCash  - $retCash;
        $netBank   = $revBank  - $expBank  - $retBank;
        $netFawry  = $revFawry - $expFawry - $retFawry;
        $netOcash  = $revOcash - $expOcash - $retOcash;
        $netTotal  = $revTotal - $expTotal - $retTotal;

        $rows = [
            ['label' => 'الإيرادات',        'cash' => $revCash,  'bank' => $revBank,  'fawry' => $revFawry,  'ocash' => $revOcash,  'total' => $revTotal,  'disc' => $totalDiscount],
            ['label' => 'المصروفات',         'cash' => $expCash,  'bank' => $expBank,  'fawry' => $expFawry,  'ocash' => $expOcash,  'total' => $expTotal,  'disc' => 0],
            ['label' => 'مردودات المبيعات', 'cash' => $retCash,  'bank' => $retBank,  'fawry' => $retFawry,  'ocash' => $retOcash,  'total' => $retTotal,  'disc' => 0],
        ];

        $pdf->SetFont(self::FONT, '', 8);
        foreach ($rows as $row) {
            $pdf->Cell($cols[0]['w'], 6, $row['disc'] > 0 ? $this->fmt($row['disc']) : '—', 1, 0, 'C');
            $pdf->Cell($cols[1]['w'], 6, $this->fmt($row['total']),  1, 0, 'C');
            $pdf->Cell($cols[2]['w'], 6, $this->fmt($row['ocash']),  1, 0, 'C');
            $pdf->Cell($cols[3]['w'], 6, $this->fmt($row['fawry']),  1, 0, 'C');
            $pdf->Cell($cols[4]['w'], 6, $this->fmt($row['bank']),   1, 0, 'C');
            $pdf->Cell($cols[5]['w'], 6, $this->fmt($row['cash']),   1, 0, 'C');
            $pdf->Cell($cols[6]['w'], 6, $row['label'],              1, 1, 'R');
        }

        // Net row (bold)
        $pdf->SetFont(self::FONT, 'B', 8);
        $pdf->Cell($cols[0]['w'], 6, '—',                   1, 0, 'C');
        $pdf->Cell($cols[1]['w'], 6, $this->fmt($netTotal), 1, 0, 'C');
        $pdf->Cell($cols[2]['w'], 6, $this->fmt($netOcash), 1, 0, 'C');
        $pdf->Cell($cols[3]['w'], 6, $this->fmt($netFawry), 1, 0, 'C');
        $pdf->Cell($cols[4]['w'], 6, $this->fmt($netBank),  1, 0, 'C');
        $pdf->Cell($cols[5]['w'], 6, $this->fmt($netCash),  1, 0, 'C');
        $pdf->Cell($cols[6]['w'], 6, 'الصافي',              1, 1, 'R');

        $pdf->Ln(4);
    }

    // ── Sales Detail Table ────────────────────────────────────────────────────

    private function renderSalesTable(TCPDF $pdf, Collection $sales): void
    {
        $cols = [
            ['w' =>  8, 't' => '#'],
            ['w' => 19, 't' => 'التاريخ'],
            ['w' => 24, 't' => 'المستخدم'],
            ['w' => 24, 't' => 'العميل'],
            ['w' => 18, 't' => 'الإجمالي'],
            ['w' => 13, 't' => 'الخصم'],
            ['w' => 18, 't' => 'المدفوع'],
            ['w' => 13, 't' => 'المتبقي'],
            ['w' => 45, 't' => 'طريقة الدفع'],
        ];

        $this->tableHeader($pdf, $cols);

        $pdf->SetFont(self::FONT, '', 7);
        $rowH = 6;

        foreach ($sales as $i => $sale) {
            if ($pdf->GetY() > 272) {
                $pdf->AddPage();
                $this->renderer->render($pdf);
                $this->sectionTitle($pdf, 'سجل المبيعات التفصيلي (تابع)');
                $this->tableHeader($pdf, $cols);
                $pdf->SetFont(self::FONT, '', 7);
            }

            $total    = $this->getSaleTotalAmount($sale);
            $paid     = $this->getSalePaidAmount($sale);
            $discount = (float)($sale->discount_amount ?? 0);
            $due      = max(0, $total - $discount - $paid);

            $pdf->Cell($cols[0]['w'], $rowH, (string)($sale->number ?? $sale->id),                                1, 0, 'C');
            $pdf->Cell($cols[1]['w'], $rowH, $sale->sale_date ? date('Y-m-d', strtotime($sale->sale_date)) : '—', 1, 0, 'C');
            $pdf->Cell($cols[2]['w'], $rowH, $sale->user?->name   ?? '—',                                         1, 0, 'C');
            $pdf->Cell($cols[3]['w'], $rowH, $sale->client?->name ?? 'عميل عام',                                  1, 0, 'C');
            $pdf->Cell($cols[4]['w'], $rowH, $this->fmt($total),                                                   1, 0, 'C');
            $pdf->Cell($cols[5]['w'], $rowH, $this->fmt($discount),                                                1, 0, 'C');
            $pdf->Cell($cols[6]['w'], $rowH, $this->fmt($paid),                                                    1, 0, 'C');
            $pdf->Cell($cols[7]['w'], $rowH, $this->fmt($due),                                                     1, 0, 'C');
            $pdf->Cell($cols[8]['w'], $rowH, $sale->is_quote ? 'تسعيره' : $this->formatPayments($sale),           1, 1, 'C');
        }

        // Totals footer
        $pdf->SetFont(self::FONT, 'B', 7);
        $labelW      = $cols[0]['w'] + $cols[1]['w'] + $cols[2]['w'] + $cols[3]['w'];
        $sumTotal    = $sales->sum(fn($s) => $this->getSaleTotalAmount($s));
        $sumDiscount = $sales->sum(fn($s) => (float)($s->discount_amount ?? 0));
        $sumPaid     = $sales->sum(fn($s) => $this->getSalePaidAmount($s));
        $sumDue      = $sales->sum(fn($s) => max(0, $this->getSaleTotalAmount($s) - (float)($s->discount_amount ?? 0) - $this->getSalePaidAmount($s)));

        $pdf->Cell($labelW,       $rowH, 'الإجمالي',           1, 0, 'R');
        $pdf->Cell($cols[4]['w'], $rowH, $this->fmt($sumTotal),    1, 0, 'C');
        $pdf->Cell($cols[5]['w'], $rowH, $this->fmt($sumDiscount), 1, 0, 'C');
        $pdf->Cell($cols[6]['w'], $rowH, $this->fmt($sumPaid),     1, 0, 'C');
        $pdf->Cell($cols[7]['w'], $rowH, $this->fmt($sumDue),      1, 0, 'C');
        $pdf->Cell($cols[8]['w'], $rowH, '',                        1, 1, 'C');
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    private function sectionTitle(TCPDF $pdf, string $title): void
    {
        $pdf->SetFont(self::FONT, 'B', 9);
        $pdf->Cell(self::BODY_W, 6, $title, 'B', 1, 'R');
        $pdf->SetFont(self::FONT, '', 8);
        $pdf->Ln(2);
    }

    private function tableHeader(TCPDF $pdf, array $cols): void
    {
        $pdf->SetFont(self::FONT, 'B', 7);
        foreach ($cols as $col) {
            $pdf->Cell($col['w'], 6, $col['t'], 1, 0, 'C');
        }
        $pdf->Ln();
        $pdf->SetFont(self::FONT, '', 7);
    }

    private function hRule(TCPDF $pdf, float $lw): void
    {
        $pdf->SetLineWidth($lw);
        $y = $pdf->GetY();
        $pdf->Line(self::MARGIN, $y, self::PAGE_W - self::MARGIN, $y);
        $pdf->SetLineWidth(0.2);
        $pdf->Ln(1);
    }

    private function fmt(float $v): string
    {
        return number_format($v, 2);
    }

    private function buildPeriodText(?Carbon $s, ?Carbon $e): string
    {
        if ($s && $e) return $s->format('Y-m-d') . ' — ' . $e->format('Y-m-d');
        if ($s)       return 'من ' . $s->format('Y-m-d');
        if ($e)       return 'إلى ' . $e->format('Y-m-d');
        return 'كل السجلات';
    }

    private function buildFilterLine(array $filters): string
    {
        $parts = [];
        if (!empty($filters['client_id'])) {
            $c = Client::find($filters['client_id']);
            $parts[] = 'العميل: ' . ($c?->name ?? '—');
        }
        if (!empty($filters['user_id'])) {
            $u = User::find($filters['user_id']);
            $parts[] = 'المستخدم: ' . ($u?->name ?? '—');
        }
        return implode('  |  ', $parts);
    }

    private function formatPayments(Sale $sale): string
    {
        $payments = $sale->payments ?? collect();
        if ($payments->isEmpty()) return '—';
        return $payments->map(fn($p) =>
            $this->methodLabel($p->method ?? 'cash') . ': ' . $this->fmt((float)($p->amount ?? 0))
        )->implode(' · ');
    }

    private function methodLabel(string $m): string
    {
        return ['cash' => 'نقدي', 'bankak' => 'بنكك', 'bank' => 'بنك',
                'fawry' => 'فوري', 'ocash' => 'أوكاش', 'visa' => 'فيزا',
                'bank_transfer' => 'بنك', 'refund' => 'مرتجع'][$m] ?? $m;
    }

    private function getSaleTotalAmount(Sale $sale): float
    {
        if (isset($sale->total_amount) && $sale->total_amount !== '') return (float)$sale->total_amount;
        return (float)($sale->items ?? collect())->sum('total_price');
    }

    private function getSalePaidAmount(Sale $sale): float
    {
        if (isset($sale->paid_amount) && $sale->paid_amount !== '') return (float)$sale->paid_amount;
        return (float)($sale->payments ?? collect())->sum('amount');
    }
}
