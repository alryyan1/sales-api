<?php

namespace App\Services;

use App\Models\Product;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

class ProductExcelService
{
    /**
     * Generate an Excel report of products
     *
     * @param array $filters
     * @return string Excel file content
     */
    public function generateProductsExcel(array $filters = []): string
    {
        // Build query with filters
        $query = Product::query()
            ->with(['category', 'stockingUnit', 'sellableUnit']);

        // Apply filters
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('scientific_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (!empty($filters['in_stock_only'])) {
            $query->where('stock_quantity', '>', 0);
        }

        // Get all products (no pagination for Excel)
        $products = $query->orderBy('name')->get();

        // Create new Spreadsheet object
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set document properties
        $spreadsheet->getProperties()
            ->setCreator('Sales System')
            ->setLastModifiedBy('Sales System')
            ->setTitle('Products Report')
            ->setSubject('Products Report')
            ->setDescription('Products report generated from Sales System');

        // Set RTL direction for Arabic support
        $sheet->setRightToLeft(true);

        // Define headers
        $headers = [
            'A1' => '#',
            'B1' => 'الاسم',
            'C1' => 'الاسم العلمي',
            'D1' => 'رمز المنتج',
            'E1' => 'الفئة',
            'F1' => 'المخزون',
            'G1' => 'الوحدة',
            'H1' => 'مستوى التنبيه',
            'I1' => 'الحالة',
            'J1' => 'تاريخ الإنشاء',
            'K1' => 'آخر تحديث'
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
        $sheet->getColumnDimension('A')->setWidth(8);  // #
        $sheet->getColumnDimension('B')->setWidth(25); // Name
        $sheet->getColumnDimension('C')->setWidth(25); // Scientific Name
        $sheet->getColumnDimension('D')->setWidth(15); // SKU
        $sheet->getColumnDimension('E')->setWidth(20); // Category
        $sheet->getColumnDimension('F')->setWidth(12); // Stock
        $sheet->getColumnDimension('G')->setWidth(12); // Unit
        $sheet->getColumnDimension('H')->setWidth(15); // Alert Level
        $sheet->getColumnDimension('I')->setWidth(15); // Status
        $sheet->getColumnDimension('J')->setWidth(15); // Created At
        $sheet->getColumnDimension('K')->setWidth(15); // Updated At

        // Add data rows
        $row = 2;
        foreach ($products as $index => $product) {
            $stockStatus = $this->getStockStatus($product);
            
            $sheet->setCellValue('A' . $row, $index + 1);
            $sheet->setCellValue('B' . $row, $product->name);
            $sheet->setCellValue('C' . $row, $product->scientific_name ?: '-');
            $sheet->setCellValue('D' . $row, $product->sku ?: '-');
            $sheet->setCellValue('E' . $row, $product->category?->name ?: '-');
            $sheet->setCellValue('F' . $row, $product->stock_quantity);
            $sheet->setCellValue('G' . $row, $product->sellableUnit?->name ?: '-');
            $sheet->setCellValue('H' . $row, $product->stock_alert_level ?: '-');
            $sheet->setCellValue('I' . $row, $stockStatus);
            $sheet->setCellValue('J' . $row, $product->created_at ? $product->created_at->format('Y-m-d H:i') : '-');
            $sheet->setCellValue('K' . $row, $product->updated_at ? $product->updated_at->format('Y-m-d H:i') : '-');

            // Style status column based on stock status
            $statusStyle = $this->getStatusStyle($product);
            $sheet->getStyle('I' . $row)->applyFromArray($statusStyle);

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
        $sheet->setCellValue('A' . $summaryRow, 'إجمالي المنتجات:');
        $sheet->setCellValue('B' . $summaryRow, $products->count());
        
        $summaryRow++;
        $sheet->setCellValue('A' . $summaryRow, 'متوفر:');
        $sheet->setCellValue('B' . $summaryRow, $products->where('stock_quantity', '>', 0)->count());
        
        $summaryRow++;
        $sheet->setCellValue('A' . $summaryRow, 'غير متوفر:');
        $sheet->setCellValue('B' . $summaryRow, $products->where('stock_quantity', '<=', 0)->count());

        // Add filter information if any
        if (!empty($filters)) {
            $summaryRow += 2;
            $sheet->setCellValue('A' . $summaryRow, 'الفلترة المطبقة:');
            $sheet->getStyle('A' . $summaryRow)->getFont()->setBold(true);
            
            if (!empty($filters['search'])) {
                $summaryRow++;
                $sheet->setCellValue('A' . $summaryRow, 'مصطلح البحث:');
                $sheet->setCellValue('B' . $summaryRow, $filters['search']);
            }
            
            if (!empty($filters['category_id'])) {
                $category = \App\Models\Category::find($filters['category_id']);
                if ($category) {
                    $summaryRow++;
                    $sheet->setCellValue('A' . $summaryRow, 'الفئة:');
                    $sheet->setCellValue('B' . $summaryRow, $category->name);
                }
            }
            
            if (!empty($filters['in_stock_only'])) {
                $summaryRow++;
                $sheet->setCellValue('A' . $summaryRow, 'الفلتر:');
                $sheet->setCellValue('B' . $summaryRow, 'المتوفر فقط');
            }
        }

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
     * Get stock status for a product
     *
     * @param Product $product
     * @return string
     */
    private function getStockStatus(Product $product): string
    {
        if ($product->stock_quantity <= 0) {
            return 'غير متوفر';
        }
        
        if ($product->stock_alert_level && $product->stock_quantity <= $product->stock_alert_level) {
            return 'مخزون منخفض';
        }
        
        return 'متوفر';
    }

    /**
     * Get style for status column based on stock status
     *
     * @param Product $product
     * @return array
     */
    private function getStatusStyle(Product $product): array
    {
        if ($product->stock_quantity <= 0) {
            return [
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FFCCCC'],
                ],
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'CC0000'],
                ],
            ];
        }
        
        if ($product->stock_alert_level && $product->stock_quantity <= $product->stock_alert_level) {
            return [
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FFFFCC'],
                ],
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'CC6600'],
                ],
            ];
        }
        
        return [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'CCFFCC'],
            ],
            'font' => [
                'bold' => true,
                'color' => ['rgb' => '006600'],
            ],
        ];
    }
} 