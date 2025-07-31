<?php

namespace App\Services;

use App\Models\Purchase;
use Illuminate\Database\Eloquent\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

class PurchaseExcelService
{
    /**
     * Generate an Excel report of purchases
     *
     * @param Collection $purchases
     * @return string Excel file content
     */
    public function generatePurchasesExcel(Collection $purchases): string
    {
        // Create new Spreadsheet object
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set document properties
        $spreadsheet->getProperties()
            ->setCreator('Sales System')
            ->setLastModifiedBy('Sales System')
            ->setTitle('Purchases Report')
            ->setSubject('Purchases Report')
            ->setDescription('Purchases report generated from Sales System');

        // Set RTL direction for Arabic support
        $sheet->setRightToLeft(true);

        // Define headers
        $headers = [
            'A1' => '#',
            'B1' => 'المعرف',
            'C1' => 'تاريخ الشراء',
            'D1' => 'تاريخ الإنشاء',
            'E1' => 'الرقم المرجعي',
            'F1' => 'المورد',
            'G1' => 'الحالة',
            'H1' => 'المبلغ الإجمالي',
            'I1' => 'عدد المنتجات',
            'J1' => 'المستخدم',
            'K1' => 'الملاحظات'
        ];

        // Set headers
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }

        // Style headers
        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ];

        $sheet->getStyle('A1:K1')->applyFromArray($headerStyle);

        // Set column widths
        $sheet->getColumnDimension('A')->setWidth(8);   // #
        $sheet->getColumnDimension('B')->setWidth(10);  // ID
        $sheet->getColumnDimension('C')->setWidth(15);  // Purchase Date
        $sheet->getColumnDimension('D')->setWidth(15);  // Created At
        $sheet->getColumnDimension('E')->setWidth(20);  // Reference Number
        $sheet->getColumnDimension('F')->setWidth(25);  // Supplier
        $sheet->getColumnDimension('G')->setWidth(15);  // Status
        $sheet->getColumnDimension('H')->setWidth(15);  // Total Amount
        $sheet->getColumnDimension('I')->setWidth(15);  // Items Count
        $sheet->getColumnDimension('J')->setWidth(20);  // User
        $sheet->getColumnDimension('K')->setWidth(30);  // Notes

        // Add data rows
        $row = 2;
        foreach ($purchases as $index => $purchase) {
            $sheet->setCellValue('A' . $row, $index + 1);
            $sheet->setCellValue('B' . $row, $purchase->id);
            $sheet->setCellValue('C' . $row, $purchase->purchase_date);
            $sheet->setCellValue('D' . $row, $purchase->created_at ? $purchase->created_at->format('Y-m-d H:i') : '-');
            $sheet->setCellValue('E' . $row, $purchase->reference_number ?: '-');
            $sheet->setCellValue('F' . $row, $purchase->supplier?->name ?: '-');
            $sheet->setCellValue('G' . $row, $this->getStatusText($purchase->status));
            $sheet->setCellValue('H' . $row, number_format($purchase->total_amount, 2));
            $sheet->setCellValue('I' . $row, $purchase->items?->count() ?: 0);
            $sheet->setCellValue('J' . $row, $purchase->user?->name ?: '-');
            $sheet->setCellValue('K' . $row, $purchase->notes ?: '-');

            // Style status column based on status
            $statusStyle = $this->getStatusStyle($purchase->status);
            $sheet->getStyle('G' . $row)->applyFromArray($statusStyle);

            $row++;
        }

        // Style data rows
        $dataStyle = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CCCCCC'],
                ],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];

        $lastRow = $row - 1;
        if ($lastRow >= 2) {
            $sheet->getStyle('A2:K' . $lastRow)->applyFromArray($dataStyle);
        }

        // Add summary information
        $summaryRow = $lastRow + 2;
        $sheet->setCellValue('A' . $summaryRow, 'ملخص التقرير');
        $sheet->getStyle('A' . $summaryRow)->getFont()->setBold(true)->setSize(14);
        $sheet->mergeCells('A' . $summaryRow . ':K' . $summaryRow);

        $summaryRow++;
        $sheet->setCellValue('A' . $summaryRow, 'إجمالي المشتريات:');
        $sheet->setCellValue('B' . $summaryRow, $purchases->count());
        
        $summaryRow++;
        $sheet->setCellValue('A' . $summaryRow, 'إجمالي المبالغ:');
        $sheet->setCellValue('B' . $summaryRow, number_format($purchases->sum('total_amount'), 2));
        
        $summaryRow++;
        $sheet->setCellValue('A' . $summaryRow, 'تم الاستلام:');
        $sheet->setCellValue('B' . $summaryRow, $purchases->where('status', 'received')->count());
        
        $summaryRow++;
        $sheet->setCellValue('A' . $summaryRow, 'قيد الانتظار:');
        $sheet->setCellValue('B' . $summaryRow, $purchases->where('status', 'pending')->count());
        
        $summaryRow++;
        $sheet->setCellValue('A' . $summaryRow, 'تم الطلب:');
        $sheet->setCellValue('B' . $summaryRow, $purchases->where('status', 'ordered')->count());

        // Add generation date
        $summaryRow += 2;
        $sheet->setCellValue('A' . $summaryRow, 'تاريخ التقرير:');
        $sheet->setCellValue('B' . $summaryRow, now()->format('Y-m-d H:i:s'));

        // Create Excel file
        $writer = new Xlsx($spreadsheet);
        
        // Capture output
        ob_start();
        $writer->save('php://output');
        $excelContent = ob_get_clean();

        return $excelContent;
    }

    /**
     * Get status text in Arabic
     *
     * @param string $status
     * @return string
     */
    private function getStatusText(string $status): string
    {
        return match ($status) {
            'received' => 'تم الاستلام',
            'pending' => 'قيد الانتظار',
            'ordered' => 'تم الطلب',
            default => $status,
        };
    }

    /**
     * Get style for status column based on status
     *
     * @param string $status
     * @return array
     */
    private function getStatusStyle(string $status): array
    {
        return match ($status) {
            'received' => [
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'CCFFCC'],
                ],
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => '006600'],
                ],
            ],
            'pending' => [
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FFFFCC'],
                ],
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'CC6600'],
                ],
            ],
            'ordered' => [
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'CCE5FF'],
                ],
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => '0066CC'],
                ],
            ],
            default => [
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'F0F0F0'],
                ],
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => '666666'],
                ],
            ],
        };
    }
} 