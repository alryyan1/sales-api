<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Insurance claims report ("المطالبة"): for each insured sale, the share the
 * insurance company owes = (subtotal - discount) - patient's co-pay share.
 * Grouped per insurance company with full invoice details.
 */
class InsuranceClaimReportController extends Controller
{
    public function index(Request $request)
    {
        return response()->json($this->buildReport($this->validateFilters($request)));
    }

    /**
     * Same report as index(), as an .xlsx file: a companies summary sheet plus
     * one sheet of invoice details per insurance company.
     */
    public function excel(Request $request)
    {
        $validated = $this->validateFilters($request);
        $report = $this->buildReport($validated);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()->setCreator('Sales System')->setTitle('المطالبة');

        // --- Summary sheet ---
        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('ملخص الشركات');
        $period = trim(($validated['start_date'] ?? '').' - '.($validated['end_date'] ?? ''), ' -');
        $summary->setCellValue('A1', 'المطالبة — مستحقات شركات التأمين'.($period ? " ({$period})" : ''));
        $summary->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $rows = $report['companies']->map(fn ($c) => [
            $c['insurance_company_name'],
            $c['invoices_count'],
            $c['total_before_copay'],
            $c['patient_share'],
            $c['claim_amount'],
        ])->all();
        $rows[] = [
            'الإجمالي',
            $report['totals']['invoices_count'],
            $report['totals']['total_before_copay'],
            $report['totals']['patient_share'],
            $report['totals']['claim_amount'],
        ];
        $this->writeTable(
            $summary,
            3,
            ['شركة التأمين', 'عدد الفواتير', 'إجمالي الفواتير الأصلي', 'المبلغ بعد التحمل', 'مبلغ المطالبة'],
            $rows,
            ['C', 'D', 'E']
        );

        // --- One details sheet per company ---
        $usedTitles = [mb_strtolower($summary->getTitle())];
        foreach ($report['companies'] as $company) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->sheetTitle($company['insurance_company_name'], $usedTitles));
            $sheet->setCellValue('A1', 'تفاصيل فواتير '.$company['insurance_company_name']);
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

            $rows = $report['sales']
                ->where('insurance_company_id', $company['insurance_company_id'])
                ->map(fn ($s) => [
                    $s['id'],
                    $s['sale_date'],
                    $s['patient_name'],
                    (string) $s['insurance_card_number'],
                    $s['total_before_copay'],
                    $s['copay_percentage'] !== null ? $s['copay_percentage'].'%' : '—',
                    $s['patient_share'],
                    $s['claim_amount'],
                ])->values()->all();
            $rows[] = ['الإجمالي', '', '', '', $company['total_before_copay'], '', $company['patient_share'], $company['claim_amount']];
            $this->writeTable(
                $sheet,
                3,
                ['رقم الفاتورة', 'التاريخ', 'المريض', 'رقم البطاقة', 'الإجمالي الأصلي', 'نسبة التحمل', 'المبلغ بعد التحمل', 'مبلغ المطالبة'],
                $rows,
                ['E', 'G', 'H']
            );
        }

        $spreadsheet->setActiveSheetIndex(0);

        $tempFile = tempnam(sys_get_temp_dir(), 'claims_');
        (new Xlsx($spreadsheet))->save($tempFile);

        return response()->download($tempFile, 'insurance_claims_'.now()->format('Y_m_d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'insurance_company_id' => ['nullable', 'integer', 'exists:insurance_companies,id'],
        ]);
    }

    /**
     * Writes a header row + data rows starting at $startRow (the last row is styled as
     * a totals row), right-to-left, with money formatting on $moneyColumns.
     */
    private function writeTable(Worksheet $sheet, int $startRow, array $headers, array $rows, array $moneyColumns): void
    {
        $sheet->setRightToLeft(true);
        $sheet->fromArray($headers, null, 'A'.$startRow);
        $sheet->fromArray($rows, null, 'A'.($startRow + 1), true);

        $lastCol = chr(ord('A') + count($headers) - 1);
        $lastRow = $startRow + count($rows);

        $sheet->getStyle("A{$startRow}:{$lastCol}{$startRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1976d2']],
        ]);
        $sheet->getStyle("A{$startRow}:{$lastCol}{$lastRow}")->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '999999']]],
        ]);
        $sheet->getStyle("A{$lastRow}:{$lastCol}{$lastRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E3F2FD']],
        ]);
        foreach ($moneyColumns as $col) {
            $sheet->getStyle($col.($startRow + 1).':'.$col.$lastRow)->getNumberFormat()->setFormatCode('#,##0.00');
        }
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /** Excel sheet titles: max 31 chars, none of []:*?/\ and unique within the workbook. */
    private function sheetTitle(string $name, array &$usedTitles): string
    {
        $clean = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name));
        $base = mb_substr($clean !== '' ? $clean : 'شركة', 0, 28);
        $title = $base;
        for ($i = 2; in_array(mb_strtolower($title), $usedTitles, true); $i++) {
            $title = $base.' '.$i;
        }
        $usedTitles[] = mb_strtolower($title);

        return $title;
    }

    private function buildReport(array $validated): array
    {
        $itemTotals = DB::table('sale_items')
            ->select('sale_id', DB::raw('SUM(total_price) as items_total'))
            ->groupBy('sale_id');

        $query = DB::table('sales')
            ->join('patients', 'patients.id', '=', 'sales.patient_id')
            ->join('insurance_companies', 'insurance_companies.id', '=', 'patients.insurance_company_id')
            ->leftJoinSub($itemTotals, 'it', 'it.sale_id', '=', 'sales.id')
            ->where('sales.is_quote', false)
            ->where('sales.is_returned', false);

        if (! empty($validated['start_date'])) {
            $query->whereDate('sales.sale_date', '>=', $validated['start_date']);
        }
        if (! empty($validated['end_date'])) {
            $query->whereDate('sales.sale_date', '<=', $validated['end_date']);
        }
        if (! empty($validated['insurance_company_id'])) {
            $query->where('insurance_companies.id', $validated['insurance_company_id']);
        }

        $rows = $query
            ->select([
                'sales.id',
                'sales.number',
                'sales.sale_date',
                'sales.copay_percentage',
                'patients.id as patient_id',
                'patients.name as patient_name',
                'patients.insurance_card_number',
                'insurance_companies.id as insurance_company_id',
                'insurance_companies.name as insurance_company_name',
                DB::raw('COALESCE(it.items_total, 0) - COALESCE(sales.discount_amount, 0) as total_before_copay'),
            ])
            ->orderBy('insurance_companies.name')
            ->orderBy('sales.sale_date')
            ->orderBy('sales.id')
            ->get();

        $sales = $rows->map(function ($row) {
            $totalBeforeCopay = round((float) $row->total_before_copay, 4);
            $copay = $row->copay_percentage !== null ? (float) $row->copay_percentage : null;
            $patientShare = $copay === null ? $totalBeforeCopay : round($totalBeforeCopay * $copay / 100, 4);

            return [
                'id' => $row->id,
                'number' => $row->number,
                'sale_date' => $row->sale_date,
                'patient_id' => $row->patient_id,
                'patient_name' => $row->patient_name,
                'insurance_card_number' => $row->insurance_card_number,
                'insurance_company_id' => $row->insurance_company_id,
                'insurance_company_name' => $row->insurance_company_name,
                'total_before_copay' => $totalBeforeCopay,
                'copay_percentage' => $copay,
                'patient_share' => $patientShare,
                'claim_amount' => round($totalBeforeCopay - $patientShare, 4),
            ];
        })->values();

        $companies = $sales->groupBy('insurance_company_id')->map(fn ($group) => [
            'insurance_company_id' => $group->first()['insurance_company_id'],
            'insurance_company_name' => $group->first()['insurance_company_name'],
            'invoices_count' => $group->count(),
            'total_before_copay' => round($group->sum('total_before_copay'), 4),
            'patient_share' => round($group->sum('patient_share'), 4),
            'claim_amount' => round($group->sum('claim_amount'), 4),
        ])->values();

        return [
            'companies' => $companies,
            'sales' => $sales,
            'totals' => [
                'invoices_count' => $sales->count(),
                'total_before_copay' => round($sales->sum('total_before_copay'), 4),
                'patient_share' => round($sales->sum('patient_share'), 4),
                'claim_amount' => round($sales->sum('claim_amount'), 4),
            ],
        ];
    }
}
