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
    // PDF Configuration Constants
    private const PDF_ORIENTATION = 'L';
    private const PDF_UNIT = 'mm';
    private const PDF_FORMAT = 'A4';
    private const PDF_MARGIN_LEFT = 15;
    private const PDF_MARGIN_TOP = 20;
    private const PDF_MARGIN_RIGHT = 15;
    private const PDF_MARGIN_BOTTOM = 20;
    private const PDF_PAGE_BREAK_THRESHOLD = 180;

    // Font Configuration
    private const FONT_FAMILY = 'arial';
    private const FONT_SIZE_TITLE = 22;
    private const FONT_SIZE_SUBTITLE = 12;
    private const FONT_SIZE_HEADING = 16;
    private const FONT_SIZE_SECTION = 14;
    private const FONT_SIZE_BODY = 10;
    private const FONT_SIZE_TABLE = 9;
    private const FONT_SIZE_SMALL = 8;


    // Table Configuration
    private const TABLE_ROW_HEIGHT = 8;
    private const TABLE_HEADER_HEIGHT = 9;
    private const TABLE_LINE_WIDTH = 0.2;

    // Company Information
    private string $companyName;
    private string $companyAddress;
    private string $companyPhone;
    private string $currencySymbol;
    private ?string $baseUrl;

    /**
     * Generate PDF report for sales
     *
     * @param Collection $sales
     * @param array $validatedFilters
     * @param array $summaryStats
     * @param array $paymentMethods
     * @param Carbon|null $startDate
     * @param Carbon|null $endDate
     * @param string|null $baseUrl
     * @return string PDF content
     */
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

        $pdf->AddPage();
        $this->renderHeader($pdf, $startDate, $endDate);
        $this->renderFilters($pdf, $validatedFilters);
        $this->renderSummaryStats($pdf, $summaryStats);

        if (!empty($paymentMethods)) {
            $this->renderPaymentMethods($pdf, $paymentMethods);
        }

        $this->renderSalesTable($pdf, $sales);

        $pdfFileName = 'Sales_Report_' . now()->format('Y-m-d_His') . '.pdf';
        return $pdf->Output($pdfFileName, 'S');
    }

    /**
     * Initialize company settings from SettingsService
     */
    private function initializeSettings(?string $baseUrl): void
    {
        $settings = (new SettingsService())->getAll();
        $this->companyName = $settings['company_name'] ?? 'Company';
        $this->companyAddress = $settings['company_address'] ?? '';
        $this->companyPhone = $settings['company_phone'] ?? '';
        $this->currencySymbol = $settings['currency_symbol'] ?? 'SDG';
        $this->baseUrl = $baseUrl;
    }

    /**
     * Initialize TCPDF instance with configuration
     */
    private function initializePdf(): TCPDF
    {
        $pdf = new TCPDF(
            self::PDF_ORIENTATION,
            self::PDF_UNIT,
            self::PDF_FORMAT,
            true,
            'UTF-8',
            false
        );

        $pdf->SetCreator('Sales System');
        $pdf->SetAuthor($this->companyName);
        $pdf->SetTitle('تقرير المبيعات Detailed Sales Report');
        $pdf->SetSubject('Sales Report');
        $pdf->SetMargins(
            self::PDF_MARGIN_LEFT,
            self::PDF_MARGIN_TOP,
            self::PDF_MARGIN_RIGHT
        );
        $pdf->SetAutoPageBreak(true, self::PDF_MARGIN_BOTTOM);
        $pdf->setRTL(true);
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

        return $pdf;
    }

    /**
     * Render PDF header with company info, title, and date period - simple format
     */
    private function renderHeader(TCPDF $pdf, ?Carbon $startDate, ?Carbon $endDate): void
    {
        // Company info
        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_HEADING);
        $pdf->Cell(0, 8, $this->companyName, 0, 1, 'R');

        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_BODY);
        if ($this->companyAddress) {
            $pdf->Cell(0, 5, $this->companyAddress, 0, 1, 'R');
        }
        if ($this->companyPhone) {
            $pdf->Cell(0, 5, 'هاتف: ' . $this->companyPhone, 0, 1, 'R');
        }

        $pdf->Ln(5);

        // Report title
        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_TITLE);
        $pdf->Cell(0, 10, 'تقرير المبيعات', 0, 1, 'C');
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_SUBTITLE);
        $pdf->Cell(0, 6, 'Sales Report', 0, 1, 'C');

        $pdf->Ln(3);

        // Date period
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_BODY);
        $periodText = $this->buildPeriodText($startDate, $endDate);
        $pdf->Cell(0, 5, 'تاريخ التقرير: ' . now()->format('Y-m-d H:i'), 0, 1, 'L');
        $pdf->Cell(0, 5, 'الفترة: ' . $periodText, 0, 1, 'L');

        // Simple separator line
        $this->renderSeparatorLine($pdf);
    }

    /**
     * Build period text string from dates
     */
    private function buildPeriodText(?Carbon $startDate, ?Carbon $endDate): string
    {
        if ($startDate && $endDate) {
            return $startDate->format('Y-m-d') . ' : ' . $endDate->format('Y-m-d');
        } elseif ($startDate) {
            return 'من: ' . $startDate->format('Y-m-d');
        } elseif ($endDate) {
            return 'إلى: ' . $endDate->format('Y-m-d');
        }
        return 'كل الفترات';
    }

    /**
     * Render separator line after header - simple format
     */
    private function renderSeparatorLine(TCPDF $pdf): void
    {
        $pdf->Ln(5);
        $pdf->SetLineWidth(0.3);
        $pageWidth = $pdf->getPageWidth() - self::PDF_MARGIN_LEFT - self::PDF_MARGIN_RIGHT;
        $pdf->Line(
            self::PDF_MARGIN_LEFT,
            $pdf->GetY(),
            self::PDF_MARGIN_LEFT + $pageWidth,
            $pdf->GetY()
        );
        $pdf->Ln(5);
    }

    /**
     * Render applied filters section
     */
    private function renderFilters(TCPDF $pdf, array $filters): void
    {
        $filterTexts = $this->buildFilterTexts($filters);

        if (empty($filterTexts)) {
            return;
        }

        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_BODY);
        $text = 'الفلاتر المطبقة: ' . implode('   |   ', $filterTexts);
        $pdf->Cell(0, 6, $text, 0, 1, 'R');
        $pdf->Ln(3);
    }

    /**
     * Build filter text array from filter data
     */
    private function buildFilterTexts(array $filters): array
    {
        $filterTexts = [];

        if (!empty($filters['client_id'])) {
            $client = Client::find($filters['client_id']);
            if ($client) {
                $filterTexts[] = 'العميل: ' . $client->name;
            }
        }

        if (!empty($filters['user_id'])) {
            $user = User::find($filters['user_id']);
            if ($user) {
                $filterTexts[] = 'الموظف: ' . $user->name;
            }
        }

        if (!empty($filters['shift_id'])) {
            $filterTexts[] = 'رقم الوردية: ' . $filters['shift_id'];
        }

        if (!empty($filters['status'])) {
            $filterTexts[] = 'الحالة: ' . $filters['status'];
        }

        return $filterTexts;
    }

    /**
     * Render summary statistics section - simple text format
     */
    private function renderSummaryStats(TCPDF $pdf, array $stats): void
    {
        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_SECTION);
        $pdf->Cell(0, 8, 'ملخص المبيعات', 0, 1, 'R');
        $pdf->Ln(3);

        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_BODY);
        
        // Simple text-based summary
        $pdf->Cell(0, 6, 'عدد الفواتير: ' . number_format($stats['totalSales']), 0, 1, 'R');
        $pdf->Cell(0, 6, 'إجمالي المبيعات: ' . number_format($stats['totalAmount'], 2) . ' ' . $this->currencySymbol, 0, 1, 'R');
        $pdf->Cell(0, 6, 'إجمالي المدفوع: ' . number_format($stats['totalPaid'], 2) . ' ' . $this->currencySymbol, 0, 1, 'R');
        $pdf->Cell(0, 6, 'المستحق (الآجل): ' . number_format($stats['totalDue'], 2) . ' ' . $this->currencySymbol, 0, 1, 'R');
        
        $pdf->Ln(5);
    }

    /**
     * Render payment methods breakdown section - simple format
     */
    private function renderPaymentMethods(TCPDF $pdf, array $methods): void
    {
        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_SUBTITLE);
        $pdf->Cell(0, 8, 'تفاصيل الدفع', 0, 1, 'R');
        $pdf->Ln(2);

        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_BODY);
        $pdf->SetLineWidth(self::TABLE_LINE_WIDTH);

        // Simple table header
        $pdf->Cell(100, 8, 'طريقة الدفع', 1, 0, 'C');
        $pdf->Cell(60, 8, 'المبلغ', 1, 1, 'C');

        // Rows
        foreach ($methods as $method => $amount) {
            $label = $this->getPaymentMethodLabel($method);
            $amountText = number_format($amount, 2) . ' ' . $this->currencySymbol;
            $pdf->Cell(100, 8, $label, 1, 0, 'R');
            $pdf->Cell(60, 8, $amountText, 1, 1, 'C');
        }

        $pdf->Ln(8);
    }

    /**
     * Render sales table with all sales data
     */
    private function renderSalesTable(TCPDF $pdf, Collection $sales): void
    {
        $columns = $this->getTableColumns();
        $this->renderTableHeader($pdf, $columns);

        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_TABLE);

        foreach ($sales as $sale) {
            if ($this->shouldAddPageBreak($pdf)) {
                $pdf->AddPage();
                $this->renderTableHeader($pdf, $columns);
            }

            $this->renderSalesTableRow($pdf, $sale, $columns);
        }
    }

    /**
     * Get table column configuration
     */
    private function getTableColumns(): array
    {
        return [
            ['w' => 15, 'txt' => '#', 'align' => 'C'],
            ['w' => 25, 'txt' => 'التاريخ', 'align' => 'C'],
            ['w' => 20, 'txt' => 'الوقت', 'align' => 'C'],
            ['w' => 45, 'txt' => 'العميل', 'align' => 'R'],
            ['w' => 30, 'txt' => 'المستخدم', 'align' => 'R'],
            ['w' => 30, 'txt' => 'الإجمالي', 'align' => 'L'],
            ['w' => 25, 'txt' => 'المدفوع', 'align' => 'L'],
            ['w' => 25, 'txt' => 'المتبقي', 'align' => 'L'],
            ['w' => 52, 'txt' => 'طرق الدفع', 'align' => 'R'],
        ];
    }

    /**
     * Check if page break is needed
     */
    private function shouldAddPageBreak(TCPDF $pdf): bool
    {
        return $pdf->GetY() > self::PDF_PAGE_BREAK_THRESHOLD;
    }

    /**
     * Render a single sales table row - simple format without fill
     */
    private function renderSalesTableRow(TCPDF $pdf, Sale $sale, array $columns): void
    {
        $rowData = $this->prepareSaleRowData($sale);

        // Simple cells without fill colors
        $pdf->Cell($columns[0]['w'], self::TABLE_ROW_HEIGHT, (string)$sale->id, 1, 0, 'C');
        $pdf->Cell($columns[1]['w'], self::TABLE_ROW_HEIGHT, $rowData['date'], 1, 0, 'C');
        $pdf->Cell($columns[2]['w'], self::TABLE_ROW_HEIGHT, $rowData['time'], 1, 0, 'C');
        $pdf->Cell($columns[3]['w'], self::TABLE_ROW_HEIGHT, $rowData['clientName'], 1, 0, 'R');
        $pdf->Cell($columns[4]['w'], self::TABLE_ROW_HEIGHT, $rowData['userName'], 1, 0, 'R');
        $pdf->Cell($columns[5]['w'], self::TABLE_ROW_HEIGHT, $rowData['totalAmount'], 1, 0, 'L');
        $pdf->Cell($columns[6]['w'], self::TABLE_ROW_HEIGHT, $rowData['paidAmount'], 1, 0, 'L');
        $pdf->Cell($columns[7]['w'], self::TABLE_ROW_HEIGHT, $rowData['dueAmount'], 1, 0, 'L');
        
        // Payment methods
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_SMALL);
        $pdf->Cell($columns[8]['w'], self::TABLE_ROW_HEIGHT, $rowData['paymentMethods'], 1, 1, 'R');
        $pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_TABLE);
    }

    /**
     * Prepare sale row data for rendering
     */
    private function prepareSaleRowData(Sale $sale): array
    {
        $saleDate = Carbon::parse($sale->sale_date);
        $due = $sale->due_amount ?? ($sale->total_amount - $sale->paid_amount);

        return [
            'date' => $saleDate->format('Y-m-d'),
            'time' => $saleDate->format('H:i'),
            'clientName' => mb_substr($sale->client->name ?? 'عميل عام', 0, 25),
            'userName' => mb_substr($sale->user->name ?? '-', 0, 15),
            'totalAmount' => number_format($sale->total_amount, 2),
            'paidAmount' => number_format($sale->paid_amount, 2),
            'due' => $due,
            'dueAmount' => number_format($due, 2),
            'paymentMethods' => $this->formatPaymentMethods($sale),
        ];
    }

    /**
     * Format payment methods string for display
     */
    private function formatPaymentMethods(Sale $sale): string
    {
        $payInfo = [];
        foreach ($sale->payments as $payment) {
            $methodLabel = $this->getPaymentMethodLabel($payment->method);
            $payInfo[] = $methodLabel . ':' . number_format($payment->amount);
        }

        $payStr = implode(', ', $payInfo);
        if (mb_strlen($payStr) > 30) {
            $payStr = mb_substr($payStr, 0, 27) . '...';
        }

        return $payStr;
    }

    /**
     * Render table header - simple format
     */
    private function renderTableHeader(TCPDF $pdf, array $columns): void
    {
        $pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_BODY);
        foreach ($columns as $column) {
            $pdf->Cell($column['w'], self::TABLE_HEADER_HEIGHT, $column['txt'], 1, 0, 'C');
        }
        $pdf->Ln();
    }

    /**
     * Get Arabic label for payment method
     */
    private function getPaymentMethodLabel(string $method): string
    {
        $labels = [
            'cash' => 'نقد',
            'visa' => 'فيزا',
            'mastercard' => 'ماستركارد',
            'bank_transfer' => 'تحويل بنكي',
            'mada' => 'مدى',
            'store_credit' => 'رصيد متجر',
            'other' => 'أخرى',
            'refund' => 'استرداد',
        ];

        return $labels[$method] ?? $method;
    }

}
