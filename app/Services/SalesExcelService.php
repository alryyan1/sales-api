<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Client;
use App\Models\User;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class SalesExcelService
{
    /** Brand colour used for the title band and header row. */
    private const BRAND = '1F3864';
    private const HEADER = '2E5395';
    private const BAND = 'EEF2F9';
    private const TOTALS = 'DCE6F5';

    /** First data column and last data column. */
    private const FIRST_COL = 'A';
    private const LAST_COL = 'K';

    /**
     * Generate a professionally formatted Excel report of the sales list,
     * honouring the same filters as the sales list page.
     *
     * @param array $filters start_date, end_date, client_id, user_id, shift_id, status, search
     * @return string Raw .xlsx file content
     */
    public function generateSalesExcel(array $filters = []): string
    {
        $settings = (new SettingsService())->getAll();
        $companyName = $settings['company_name'] ?? config('app.name', 'Sales System');
        $companyContact = trim(implode('  •  ', array_filter([
            $settings['company_address'] ?? null,
            !empty($settings['company_phone']) && $settings['company_phone'] !== '0'
                ? ($settings['company_phone'] . (!empty($settings['company_phone_2']) ? ' / ' . $settings['company_phone_2'] : ''))
                : null,
        ])));
        $currency = $settings['currency_symbol'] ?? $settings['currency_code'] ?? '';
        $moneyFormat = ($currency !== '' ? '#,##0.00" ' . $currency . '"' : '#,##0.00');

        $sales = $this->buildQuery($filters)->get();

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator($companyName)
            ->setTitle('Sales Report')
            ->setSubject('Sales Report')
            ->setDescription('Sales list export generated from ' . $companyName);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('المبيعات');
        $sheet->setRightToLeft(true);
        $sheet->getDefaultRowDimension()->setRowHeight(18);
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);

        $lastCol = self::LAST_COL;

        // ---- Title band -------------------------------------------------------
        $sheet->setCellValue('A1', $companyName);
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::BRAND]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);

        $sheet->setCellValue('A2', 'تقرير المبيعات');
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::HEADER]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $metaLine = $this->metaLine($filters, $companyContact);
        $sheet->setCellValue('A3', $metaLine);
        $sheet->mergeCells("A3:{$lastCol}3");
        $sheet->getStyle('A3')->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '555555']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(3)->setRowHeight(28);

        // ---- Column headers --------------------------------------------------
        $headerRow = 5;
        $headers = [
            'A' => 'رقم الفاتورة',
            'B' => 'التاريخ',
            'C' => 'وقت الإنشاء',
            'D' => 'العميل',
            'E' => 'الإجمالي',
            'F' => 'التكلفة',
            'G' => 'الربح',
            'H' => 'المدفوع',
            'I' => 'المتبقي',
            'J' => 'المردود',
            'K' => 'الحالة / الكاشير',
        ];
        foreach ($headers as $col => $label) {
            $sheet->setCellValue($col . $headerRow, $label);
        }
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::HEADER]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(24);

        // ---- Data rows -----------------------------------------------------
        $statusLabels = [
            'completed' => 'مكتملة',
            'pending' => 'معلقة',
            'draft' => 'مسودة',
            'cancelled' => 'ملغاة',
        ];

        $row = $headerRow + 1;
        $firstDataRow = $row;
        $sumTotal = $sumCost = $sumProfit = $sumPaid = $sumDue = $sumReturned = 0.0;

        foreach ($sales as $sale) {
            $total = (float) $sale->calculated_total_amount;
            $cost = (float) $sale->calculated_cost_amount;
            $paid = (float) $sale->calculated_paid_amount;
            $due = (float) $sale->calculated_due_amount;
            $returned = (float) $sale->returns->sum(
                fn ($return) => $return->items->sum(fn ($item) => (float) $item->quantity * (float) $item->price)
            );
            $profit = $total - $cost;

            $sheet->setCellValueExplicit('A' . $row, '#' . $sale->id, DataType::TYPE_STRING);
            $sheet->setCellValue('B' . $row, optional($sale->sale_date)->format('Y-m-d') ?: '—');
            $sheet->setCellValue('C' . $row, $sale->created_at ? $sale->created_at->format('Y-m-d H:i') : '—');
            $sheet->setCellValue('D' . $row, $sale->client?->name ?: 'عميل نقدي');
            $sheet->setCellValue('E' . $row, round($total, 2));
            $sheet->setCellValue('F' . $row, round($cost, 2));
            $sheet->setCellValue('G' . $row, round($profit, 2));
            $sheet->setCellValue('H' . $row, round($paid, 2));
            $sheet->setCellValue('I' . $row, round($due, 2));
            $sheet->setCellValue('J' . $row, round($returned, 2));
            $sheet->setCellValue('K' . $row, trim(
                ($statusLabels[$sale->status] ?? ($sale->status ?? '—')) .
                ($sale->user?->name ? ' — ' . $sale->user->name : '')
            ));

            $sumTotal += $total;
            $sumCost += $cost;
            $sumProfit += $profit;
            $sumPaid += $paid;
            $sumDue += $due;
            $sumReturned += $returned;

            $row++;
        }

        $lastDataRow = $row - 1;
        $hasData = $lastDataRow >= $firstDataRow;

        if ($hasData) {
            // Borders + alignment for the whole data block.
            $sheet->getStyle("A{$firstDataRow}:{$lastCol}{$lastDataRow}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D6DEE9']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle("A{$firstDataRow}:C{$lastDataRow}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("E{$firstDataRow}:J{$lastDataRow}")
                ->getNumberFormat()->setFormatCode($moneyFormat);
            $sheet->getStyle("E{$firstDataRow}:J{$lastDataRow}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            // Zebra striping.
            for ($r = $firstDataRow; $r <= $lastDataRow; $r++) {
                if (($r - $firstDataRow) % 2 === 1) {
                    $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::BAND);
                }
            }

            // Totals row.
            $totalsRow = $lastDataRow + 1;
            $sheet->setCellValue('A' . $totalsRow, 'الإجمالي');
            $sheet->mergeCells("A{$totalsRow}:D{$totalsRow}");
            $sheet->setCellValue('E' . $totalsRow, round($sumTotal, 2));
            $sheet->setCellValue('F' . $totalsRow, round($sumCost, 2));
            $sheet->setCellValue('G' . $totalsRow, round($sumProfit, 2));
            $sheet->setCellValue('H' . $totalsRow, round($sumPaid, 2));
            $sheet->setCellValue('I' . $totalsRow, round($sumDue, 2));
            $sheet->setCellValue('J' . $totalsRow, round($sumReturned, 2));
            $sheet->getStyle("A{$totalsRow}:{$lastCol}{$totalsRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 11],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::TOTALS]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B7C4D8']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle("E{$totalsRow}:J{$totalsRow}")
                ->getNumberFormat()->setFormatCode($moneyFormat);
            $sheet->getStyle("E{$totalsRow}:J{$totalsRow}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("A{$totalsRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getRowDimension($totalsRow)->setRowHeight(22);

            $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$lastDataRow}");
        } else {
            $sheet->setCellValue('A' . $firstDataRow, 'لا توجد فواتير مطابقة للفلاتر المحددة.');
            $sheet->mergeCells("A{$firstDataRow}:{$lastCol}{$firstDataRow}");
            $sheet->getStyle("A{$firstDataRow}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        // ---- Summary footnote ---------------------------------------------
        $noteRow = ($hasData ? $lastDataRow + 3 : $firstDataRow + 2);
        $sheet->setCellValue('A' . $noteRow, 'عدد الفواتير: ' . $sales->count()
            . '   |   تاريخ التقرير: ' . now()->format('Y-m-d H:i'));
        $sheet->mergeCells("A{$noteRow}:{$lastCol}{$noteRow}");
        $sheet->getStyle("A{$noteRow}")->applyFromArray([
            'font' => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '777777']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // ---- Column widths --------------------------------------------------
        $widths = ['A' => 13, 'B' => 13, 'C' => 17, 'D' => 30, 'E' => 16, 'F' => 16, 'G' => 16, 'H' => 16, 'I' => 16, 'J' => 15, 'K' => 26];
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        // ---- Freeze + print setup ----------------------------------------
        $sheet->freezePane('A' . ($headerRow + 1));
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);
        $sheet->getHeaderFooter()->setOddFooter('&C&P / &N');
        $sheet->setPrintGridlines(false);
        $sheet->setShowGridlines(false);

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');

        return ob_get_clean();
    }

    /**
     * Build the filtered sale query shared with the sales list page.
     */
    private function buildQuery(array $filters)
    {
        $query = Sale::query()->with([
            'client:id,name',
            'user:id,name',
            'items',
            'returns.items',
        ]);

        $startDate = !empty($filters['start_date']) ? Carbon::parse($filters['start_date'])->startOfDay() : null;
        $endDate = !empty($filters['end_date']) ? Carbon::parse($filters['end_date'])->endOfDay() : null;

        if (!empty($filters['shift_id'])) {
            $query->where('shift_id', $filters['shift_id']);
        } else {
            if ($startDate) {
                $query->whereDate('sale_date', '>=', $startDate);
            }
            if ($endDate) {
                $query->whereDate('sale_date', '<=', $endDate);
            }
        }

        if (!empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }
        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        return $query->orderBy('sale_date', 'desc')->orderBy('id', 'desc');
    }

    /**
     * One-line description of the report scope shown under the title.
     */
    private function metaLine(array $filters, string $companyContact): string
    {
        $parts = [];

        if (!empty($filters['shift_id'])) {
            $parts[] = 'الوردية #' . $filters['shift_id'];
        } elseif (!empty($filters['start_date']) || !empty($filters['end_date'])) {
            $parts[] = 'الفترة: ' . ($filters['start_date'] ?? '…') . ' ← ' . ($filters['end_date'] ?? '…');
        } else {
            $parts[] = 'كل الفترات';
        }

        if (!empty($filters['client_id'])) {
            $parts[] = 'العميل: ' . (optional(Client::find($filters['client_id']))->name ?? ('#' . $filters['client_id']));
        }
        if (!empty($filters['user_id'])) {
            $parts[] = 'الكاشير: ' . (optional(User::find($filters['user_id']))->name ?? ('#' . $filters['user_id']));
        }
        if (!empty($filters['status'])) {
            $parts[] = 'الحالة: ' . $filters['status'];
        }
        if (!empty($filters['search'])) {
            $parts[] = 'بحث: "' . $filters['search'] . '"';
        }

        $line = implode('   |   ', $parts);
        if ($companyContact !== '') {
            $line .= "\n" . $companyContact;
        }

        return $line;
    }
}
