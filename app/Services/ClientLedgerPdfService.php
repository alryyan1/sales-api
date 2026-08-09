<?php

namespace App\Services;

use App\Models\Client;
use App\Services\Pdf\PdfHeaderRenderer;
use App\Services\Pdf\PdfLocale;
use Carbon\Carbon;
use TCPDF;
use Exception;
use Illuminate\Support\Facades\Log;

class ClientLedgerPdfService extends TCPDF
{
    private array $settings = [];
    private PdfHeaderRenderer $renderer;
    private string $locale = 'ar';

    /**
     * Return the Arabic or English string depending on the detected report locale.
     */
    private function t(string $ar, string $en): string
    {
        return $this->locale === 'en' ? $en : $ar;
    }
    // ── Palette ───────────────────────────────────────────────────────────────
    private const BLACK  = [0,   0,   0];
    private const DARK   = [30,  30,  30];
    private const MID    = [100, 100, 100];
    private const LIGHT  = [180, 180, 180];
    private const WHITE  = [255, 255, 255];
    private const FILL   = [245, 245, 245];
    private const HEADER_BG = [30, 30, 30];

    // ── Layout ────────────────────────────────────────────────────────────────
    private const M  = 20;   // margin (mm)
    private const RH = 7;    // row height (mm)
    private const F  = 'arial';

    // ─────────────────────────────────────────────────────────────────────────

    public function generate(Client $client): string
    {
        try {
            $this->locale = PdfLocale::detect();
            $data = $this->buildLedgerData($client);
            $this->init($client);

            // ── Page 1: cover with client info + summary ──
            $this->AddPage();
            $this->renderer->render($this);
            $this->drawPageHeader($this->t('كشف حساب عميل', 'Client Account Statement'));
            $this->drawIssueLine($client);
            $this->drawClientSection($client);
            $this->drawSummarySection($data['summary'], count($data['entries']));
            $this->drawPageFooter();

            // ── Page 2+: invoices table ──
            $this->AddPage();
            $this->renderer->render($this);
            $this->drawPageHeader($this->t('تفاصيل الفواتير', 'Invoice Details'));
            $this->drawInvoicesTable($data['entries'], $data['summary']);
            $this->drawPageFooter();

            return $this->Output('client_ledger_' . $client->id . '.pdf', 'S');

        } catch (Exception $e) {
            Log::error('ClientLedgerPdfService failed', [
                'client_id' => $client->id,
                'error'     => $e->getMessage(),
            ]);
            throw new Exception('Failed to generate client ledger PDF: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data
    // ─────────────────────────────────────────────────────────────────────────

    private function buildLedgerData(Client $client): array
    {
        $client->load([
            'sales' => fn($q) => $q->orderBy('sale_date', 'asc')->orderBy('created_at', 'asc'),
            'sales.items',
            'sales.payments',
        ]);

        $entries = $client->sales->map(function ($sale) {
            $itemsTotal = (float) $sale->items->sum('total_price');
            $discount   = (float) ($sale->discount_amount ?? 0);
            $total      = max(0.0, $itemsTotal - $discount);
            $paid       = (float) $sale->payments->sum('amount');
            $due        = max(0.0, $total - $paid);

            return [
                'sale_id'     => $sale->id,
                'date'        => $sale->sale_date instanceof Carbon
                    ? $sale->sale_date->format('Y-m-d')
                    : Carbon::parse($sale->sale_date)->format('Y-m-d'),
                'items_count' => $sale->items->count(),
                'total'       => $total,
                'paid'        => $paid,
                'due'         => $due,
            ];
        })->values()->all();

        $totalSales    = array_sum(array_column($entries, 'total'));
        $totalPayments = array_sum(array_column($entries, 'paid'));
        $balance       = $totalSales - $totalPayments;

        return [
            'entries' => $entries,
            'summary' => compact('totalSales', 'totalPayments', 'balance'),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Init
    // ─────────────────────────────────────────────────────────────────────────

    private function init(Client $client): void
    {
        $this->settings = app(\App\Services\SettingsService::class)->getAll();

        $this->renderer = new PdfHeaderRenderer('client_ledger');
        $this->setPrintHeader(false);
        $this->SetCreator('Sales Management System');
        $this->SetAuthor($this->settings['company_name'] ?? 'Sales Management System');
        $this->SetTitle($this->t('كشف حساب — ', 'Statement — ') . $client->name);
        $this->SetSubject('Client Account Statement');
        $this->setPrintFooter(false);
        $this->SetMargins(self::M, $this->renderer->getTopMargin(), self::M);
        $this->SetAutoPageBreak(true, 25);
        $this->SetFont(self::F, '', 10);
        $this->setRTL(false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Shared: company header (logo + name + address)
    // ─────────────────────────────────────────────────────────────────────────

    private function drawCompanyHeader(): void
    {
        $this->renderer->render($this);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Shared: page header (title bar)
    // ─────────────────────────────────────────────────────────────────────────

    private function drawPageHeader(string $title): void
    {
        $W = $this->W();

        // Section title
        $this->SetFont(self::F, 'B', 13);
        $this->SetTextColor(...self::BLACK);
        $this->Cell($W, 9, $title, 0, 1, 'C');

        // Thin rule below title
        $this->SetDrawColor(...self::LIGHT);
        $this->SetLineWidth(0.4);
        $this->Line(self::M, $this->GetY() + 1, self::M + $W, $this->GetY() + 1);

        $this->Ln(5);
        $this->SetTextColor(...self::DARK);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Shared: page footer
    // ─────────────────────────────────────────────────────────────────────────

    private function drawPageFooter(): void
    {
        $W = $this->W();
        $this->SetAutoPageBreak(false);
        $this->SetY(-18);

        $this->SetDrawColor(...self::LIGHT);
        $this->SetLineWidth(0.4);
        $this->Line(self::M, $this->GetY(), self::M + $W, $this->GetY());
        $this->Ln(3);

        $this->SetFont(self::F, '', 8);
        $this->SetTextColor(...self::MID);
        $this->Cell($W / 2, 5, $this->t('نظام إدارة المبيعات', 'Sales Management System'), 0, 0, 'R');
        $this->Cell($W / 2, 5,
            $this->locale === 'en'
                ? 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages()
                : 'صفحة ' . $this->getAliasNumPage() . ' من ' . $this->getAliasNbPages(),
            0, 1, 'L');

        $this->SetAutoPageBreak(true, 25);
        $this->SetTextColor(...self::DARK);
    }

    private function drawIssueLine(Client $client): void
    {
        $W = $this->W();
        $this->SetFont(self::F, '', 8.5);
        $this->SetTextColor(...self::MID);
        $this->Cell($W / 2, 5, $this->t('رقم العميل: #', 'Client No: #') . str_pad($client->id, 5, '0', STR_PAD_LEFT), 0, 0, 'R');
        $this->Cell($W / 2, 5, $this->t('تاريخ الإصدار: ', 'Issue Date: ') . now()->format('Y-m-d'), 0, 1, 'L');
        $this->Ln(3);
        $this->SetTextColor(...self::DARK);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Page 1 — Client section
    // ─────────────────────────────────────────────────────────────────────────

    private function drawClientSection(Client $client): void
    {
        $W = $this->W();

        $this->sectionTitle($this->t('بيانات العميل', 'Client Information'));

        $rows = [
            [$this->t('الاسم', 'Name'),                     $client->name],
            [$this->t('رقم الهاتف', 'Phone'),                $client->phone   ?? '—'],
            [$this->t('البريد الإلكتروني', 'Email'),         $client->email   ?? '—'],
            [$this->t('العنوان', 'Address'),                 $client->address ?? '—'],
        ];

        $labelW = $W * 0.30;
        $valW   = $W * 0.70;

        foreach ($rows as [$label, $value]) {
            $this->SetFillColor(...self::FILL);
            $this->SetDrawColor(...self::LIGHT);
            $this->SetLineWidth(0.3);

            $this->SetFont(self::F, 'B', 10);
            $this->SetTextColor(...self::MID);
            $this->Cell($labelW, 9, $label, 'B', 0, 'R', false);

            $this->SetFont(self::F, '', 10);
            $this->SetTextColor(...self::DARK);
            $this->Cell($valW, 9, $value, 'B', 1, 'R', false);
        }

        $this->Ln(8);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Page 1 — Summary section
    // ─────────────────────────────────────────────────────────────────────────

    private function drawSummarySection(array $summary, int $invoiceCount): void
    {
        $W = $this->W();
        $currency = $this->settings['currency_symbol'] ?? 'OMR';

        $this->sectionTitle($this->t('الملخص المالي', 'Financial Summary'));

        $rows = [
            [$this->t('عدد الفواتير', 'Invoice Count'),        (string) $invoiceCount],
            [$this->t('إجمالي المبيعات', 'Total Sales'),        number_format($summary['totalSales'],    3) . ' ' . $currency],
            [$this->t('إجمالي المدفوعات', 'Total Payments'),    number_format($summary['totalPayments'], 3) . ' ' . $currency],
            [$this->t('الرصيد المستحق', 'Balance Due'),         number_format($summary['balance'],       3) . ' ' . $currency],
        ];

        $labelW = $W * 0.40;
        $valW   = $W * 0.60;

        foreach ($rows as $i => [$label, $value]) {
            $isLast = ($i === count($rows) - 1);

            $this->SetDrawColor(...self::LIGHT);
            $this->SetLineWidth(0.3);

            $this->SetFont(self::F, 'B', $isLast ? 11 : 10);
            $this->SetTextColor(...($isLast ? self::BLACK : self::MID));
            $this->Cell($labelW, 10, $label, $isLast ? 'TB' : 'B', 0, 'R');

            $this->SetFont(self::F, 'B', $isLast ? 12 : 10);
            $this->SetTextColor(...self::DARK);
            $this->Cell($valW, 10, $value, $isLast ? 'TB' : 'B', 1, 'R');
        }

        // Balance in words
        $this->Ln(6);
        $this->SetDrawColor(...self::LIGHT);
        $this->SetLineWidth(0.3);
        $this->SetFillColor(...self::FILL);
        $W = $this->W();
        if ($this->locale === 'en') {
            $wordAmount = $this->numberToEnglishWords($summary['balance']);
            $this->Cell($W, 9,
                'Balance Due in Words:   ' . $wordAmount . ' Omani Rial',
                'TB', 1, 'L', true);
        } else {
            $wordAmount = $this->numberToArabicWords($summary['balance']);
            $this->Cell($W, 9,
                'الرصيد المستحق كتابةً:   ' . $wordAmount . ' ريال عماني',
                'TB', 1, 'R', true);
        }

        // Signature line
        $this->Ln(14);
        $lineW = 60;

        $this->SetDrawColor(...self::DARK);
        $this->SetLineWidth(0.5);
        $this->Line(self::M, $this->GetY(), self::M + $lineW, $this->GetY());

        $this->Ln(3);
        $this->SetFont(self::F, '', 9);
        $this->SetTextColor(...self::MID);
        $this->Cell($lineW, 5, $this->t('توقيع المسؤول', 'Authorized Signature'), 0, 1, 'C');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Page 2 — Invoices table
    // ─────────────────────────────────────────────────────────────────────────

    private function drawInvoicesTable(array $entries, array $summary): void
    {
        $W = $this->W();

        $w = [
            'no'    => $W * 0.07,
            'date'  => $W * 0.17,
            'sale'  => $W * 0.13,
            'items' => $W * 0.10,
            'total' => $W * 0.165,
            'paid'  => $W * 0.165,
            'due'   => 0,
        ];
        $w['due'] = $W - array_sum($w);

        $this->drawInvoiceTableHeader($w);

        if (empty($entries)) {
            $this->SetFont(self::F, '', 9);
            $this->SetTextColor(...self::MID);
            $this->Cell($W, 12, $this->t('لا توجد فواتير مسجّلة لهذا العميل', 'No invoices recorded for this client'), 'B', 1, 'C');
            $this->drawInvoiceTotalsRow($w, $summary);
            return;
        }

        $this->SetDrawColor(...self::LIGHT);
        $this->SetLineWidth(0.25);

        foreach ($entries as $i => $entry) {
            if ($this->GetY() + self::RH > $this->getPageHeight() - 30) {
                $this->drawPageFooter();
                $this->AddPage();
                $this->renderer->render($this);
                $this->drawPageHeader($this->t('تفاصيل الفواتير (تابع)', 'Invoice Details (continued)'));
                $this->drawInvoiceTableHeader($w);
            }

            // Alternate fill
            $even = ($i % 2 === 0);
            $this->SetFillColor(...($even ? self::WHITE : self::FILL));
            $this->SetTextColor(...self::DARK);

            $this->SetFont(self::F, '', 8);
            $this->SetTextColor(...self::MID);
            $this->Cell($w['no'],    self::RH, $i + 1,               'B', 0, 'C', $even ? false : true);

            $this->SetFont(self::F, '', 9);
            $this->SetTextColor(...self::DARK);
            $this->Cell($w['date'],  self::RH, $entry['date'],        'B', 0, 'C', $even ? false : true);
            $this->Cell($w['sale'],  self::RH, $entry['sale_id'],     'B', 0, 'C', $even ? false : true);
            $this->Cell($w['items'], self::RH, $entry['items_count'], 'B', 0, 'C', $even ? false : true);

            $this->SetFont(self::F, 'B', 9);
            $this->Cell($w['total'], self::RH, number_format($entry['total'], 3), 'B', 0, 'C', $even ? false : true);

            $this->SetFont(self::F, '', 9);
            $this->Cell($w['paid'],  self::RH,
                $entry['paid'] > 0 ? number_format($entry['paid'], 3) : '—',
                'B', 0, 'C', $even ? false : true);

            $this->SetFont(self::F, 'B', 9);
            $this->SetTextColor($entry['due'] > 0 ? 150 : 100, $entry['due'] > 0 ? 0 : 100, 0);
            $this->Cell($w['due'], self::RH,
                $entry['due'] > 0 ? number_format($entry['due'], 3) : $this->t('مسدّد', 'Paid'),
                'B', 1, 'C', $even ? false : true);
        }

        $this->drawInvoiceTotalsRow($w, $summary);
    }

    private function drawInvoiceTableHeader(array $w): void
    {
        $this->SetFont(self::F, 'B', 9);
        $this->SetFillColor(...self::HEADER_BG);
        $this->SetTextColor(...self::WHITE);
        $this->SetDrawColor(...self::HEADER_BG);
        $this->SetLineWidth(0);

        $this->Cell($w['no'],    9, '#',                                     0, 0, 'C', true);
        $this->Cell($w['date'],  9, $this->t('التاريخ', 'Date'),             0, 0, 'C', true);
        $this->Cell($w['sale'],  9, $this->t('رقم الفاتورة', 'Invoice No'),  0, 0, 'C', true);
        $this->Cell($w['items'], 9, $this->t('الأصناف', 'Items'),            0, 0, 'C', true);
        $this->Cell($w['total'], 9, $this->t('الإجمالي', 'Total'),           0, 0, 'C', true);
        $this->Cell($w['paid'],  9, $this->t('المدفوع', 'Paid'),             0, 0, 'C', true);
        $this->Cell($w['due'],   9, $this->t('المتبقي', 'Due'),              0, 1, 'C', true);

        $this->SetTextColor(...self::DARK);
        $this->SetDrawColor(...self::LIGHT);
        $this->SetLineWidth(0.25);
    }

    private function drawInvoiceTotalsRow(array $w, array $summary): void
    {
        $W = $this->W();

        // Separator
        $this->SetDrawColor(...self::BLACK);
        $this->SetLineWidth(0.8);
        $this->Line(self::M, $this->GetY() + 1, self::M + $W, $this->GetY() + 1);
        $this->Ln(3);

        $spanW = $w['no'] + $w['date'] + $w['sale'] + $w['items'];

        $this->SetFont(self::F, 'B', 10);
        $this->SetFillColor(...self::FILL);
        $this->SetTextColor(...self::BLACK);
        $this->SetDrawColor(...self::LIGHT);
        $this->SetLineWidth(0.3);

        $this->Cell($spanW,      self::RH, $this->t('الإجمالي', 'Total'),           'B', 0, 'R', true);
        $this->Cell($w['total'], self::RH, number_format($summary['totalSales'],    3), 'B', 0, 'C', true);
        $this->Cell($w['paid'],  self::RH, number_format($summary['totalPayments'], 3), 'B', 0, 'C', true);
        $this->Cell($w['due'],   self::RH, number_format($summary['balance'],       3), 'B', 1, 'C', true);

        $this->SetTextColor(...self::DARK);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper: section title
    // ─────────────────────────────────────────────────────────────────────────

    private function sectionTitle(string $title): void
    {
        $W = $this->W();

        $this->SetFont(self::F, 'B', 11);
        $this->SetTextColor(...self::BLACK);
        $this->SetDrawColor(...self::BLACK);
        $this->SetLineWidth(0.5);
        $this->Cell($W, 8, $title, 'B', 1, 'R');

        $this->Ln(3);
        $this->SetTextColor(...self::DARK);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Arabic number-to-words
    // ─────────────────────────────────────────────────────────────────────────

    private function numberToArabicWords(float $number): string
    {
        $number = round($number, 3);
        if ($number == 0) return 'صفر';

        $conjunction = ' و ';

        $dictionary = [
            0 => 'صفر', 1 => 'واحد', 2 => 'اثنان', 3 => 'ثلاثة',
            4 => 'أربعة', 5 => 'خمسة', 6 => 'ستة', 7 => 'سبعة',
            8 => 'ثمانية', 9 => 'تسعة', 10 => 'عشرة', 11 => 'أحد عشر',
            12 => 'اثنا عشر', 13 => 'ثلاثة عشر', 14 => 'أربعة عشر',
            15 => 'خمسة عشر', 16 => 'ستة عشر', 17 => 'سبعة عشر',
            18 => 'ثمانية عشر', 19 => 'تسعة عشر', 20 => 'عشرون',
            30 => 'ثلاثون', 40 => 'أربعون', 50 => 'خمسون',
            60 => 'ستون', 70 => 'سبعون', 80 => 'ثمانون', 90 => 'تسعون',
            100 => 'مائة', 200 => 'مائتان', 300 => 'ثلاثمائة',
            400 => 'أربعمائة', 500 => 'خمسمائة', 600 => 'ستمائة',
            700 => 'سبعمائة', 800 => 'ثمانمائة', 900 => 'تسعمائة',
            1000 => 'ألف', 2000 => 'ألفان', 3000 => 'ثلاثة آلاف',
            4000 => 'أربعة آلاف', 5000 => 'خمسة آلاف', 6000 => 'ستة آلاف',
            7000 => 'سبعة آلاف', 8000 => 'ثمانية آلاف', 9000 => 'تسعة آلاف',
            10000 => 'عشرة آلاف',
        ];

        $parts       = explode('.', (string) $number);
        $integerPart = (int) $parts[0];
        $decimalPart = isset($parts[1]) ? (int) substr($parts[1], 0, 3) : 0;

        $convert = function (int $num) use ($dictionary, $conjunction, &$convert): string {
            if ($num <= 20)  return $dictionary[$num];
            if ($num < 100) {
                $units = $num % 10;
                $tens  = (int) ($num / 10) * 10;
                return ($units > 0 ? $dictionary[$units] . $conjunction : '') . $dictionary[$tens];
            }
            if ($num < 1000) {
                $h = (int) ($num / 100) * 100;
                $r = $num % 100;
                return $dictionary[$h] . ($r > 0 ? $conjunction . $convert($r) : '');
            }
            if ($num < 1_000_000) {
                $th  = (int) ($num / 1000);
                $r   = $num % 1000;
                $str = $th === 1 ? $dictionary[1000]
                     : ($th === 2 ? $dictionary[2000]
                     : ($th <= 10 ? $dictionary[$th * 1000]
                     : $convert($th) . ' ألف'));
                return $str . ($r > 0 ? $conjunction . $convert($r) : '');
            }
            if ($num < 1_000_000_000) {
                $m   = (int) ($num / 1_000_000);
                $r   = $num % 1_000_000;
                $str = $m === 1 ? 'مليون'
                     : ($m === 2 ? 'مليونان'
                     : ($m <= 10 ? $convert($m) . ' ملايين'
                     : $convert($m) . ' مليون'));
                return $str . ($r > 0 ? $conjunction . $convert($r) : '');
            }
            $b   = (int) ($num / 1_000_000_000);
            $r   = $num % 1_000_000_000;
            $str = $b === 1 ? 'مليار'
                 : ($b === 2 ? 'ملياران'
                 : ($b <= 10 ? $convert($b) . ' مليارات'
                 : $convert($b) . ' مليار'));
            return $str . ($r > 0 ? $conjunction . $convert($r) : '');
        };

        $result = $convert($integerPart);
        if ($decimalPart > 0) {
            $result .= $conjunction . $convert($decimalPart) . ' قرشاً';
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // English number-to-words
    // ─────────────────────────────────────────────────────────────────────────

    private function numberToEnglishWords(float $number): string
    {
        $number = round($number, 3);
        if ($number == 0) {
            return 'Zero';
        }

        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
            'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tensWords = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        $convertInteger = function (int $num) use ($ones, $tensWords, &$convertInteger): string {
            if ($num < 20) {
                return $ones[$num];
            }
            if ($num < 100) {
                $ten = (int) ($num / 10);
                $rest = $num % 10;
                return trim($tensWords[$ten] . ($rest > 0 ? ' ' . $ones[$rest] : ''));
            }
            if ($num < 1000) {
                $hundreds = (int) ($num / 100);
                $rest = $num % 100;
                return trim($ones[$hundreds] . ' Hundred' . ($rest > 0 ? ' ' . $convertInteger($rest) : ''));
            }
            if ($num < 1000000) {
                $thousands = (int) ($num / 1000);
                $rest = $num % 1000;
                return trim($convertInteger($thousands) . ' Thousand' . ($rest > 0 ? ' ' . $convertInteger($rest) : ''));
            }
            if ($num < 1000000000) {
                $millions = (int) ($num / 1000000);
                $rest = $num % 1000000;
                return trim($convertInteger($millions) . ' Million' . ($rest > 0 ? ' ' . $convertInteger($rest) : ''));
            }
            $billions = (int) ($num / 1000000000);
            $rest = $num % 1000000000;
            return trim($convertInteger($billions) . ' Billion' . ($rest > 0 ? ' ' . $convertInteger($rest) : ''));
        };

        $parts = explode('.', (string) $number);
        $integerPart = (int) $parts[0];
        $decimalPart = isset($parts[1]) ? (int) substr($parts[1], 0, 3) : 0;

        $result = $convertInteger($integerPart);
        if ($decimalPart > 0) {
            $result .= ' and ' . $convertInteger($decimalPart) . ' Baisa';
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function W(): float
    {
        return $this->getPageWidth() - self::M * 2;
    }
}
