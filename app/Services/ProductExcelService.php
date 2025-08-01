<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Category;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

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

    /**
     * Get column headers from Excel file
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return array
     */
    public function getExcelHeaders($file): array
    {
        $spreadsheet = IOFactory::load($file->getPathname());
        $worksheet = $spreadsheet->getActiveSheet();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
        
        $headers = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cellValue = $worksheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . '1')->getValue();
            if ($cellValue) {
                $headers[] = $cellValue;
            }
        }
        
        return $headers;
    }

    /**
     * Preview products from Excel file
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param array $columnMapping
     * @param bool $skipHeader
     * @return array
     */
    public function previewProducts($file, array $columnMapping, bool $skipHeader = true): array
    {
        $spreadsheet = IOFactory::load($file->getPathname());
        $worksheet = $spreadsheet->getActiveSheet();
        $highestRow = $worksheet->getHighestRow();
        
        $previewData = [];
        $startRow = $skipHeader ? 2 : 1;
        
        // Cache headers to avoid repeated calls
        $headers = $this->getExcelHeaders($file);
        
        // Create column index mapping for better performance
        $columnIndexMapping = [];
        foreach ($columnMapping as $productField => $excelColumn) {
            if ($excelColumn && $excelColumn !== 'skip') {
                $columnIndex = array_search($excelColumn, $headers);
                if ($columnIndex !== false) {
                    $columnIndexMapping[$productField] = $columnIndex + 1;
                }
            }
        }
        
        // Log the mapping for debugging
        Log::info("Preview mapping:", [
            'headers' => $headers,
            'columnMapping' => $columnMapping,
            'columnIndexMapping' => $columnIndexMapping
        ]);
        
        // Process first 50 rows for preview
        $previewRows = min(50, $highestRow - $startRow + 1);
        
        for ($row = $startRow; $row < $startRow + $previewRows; $row++) {
            $rowData = [];
            
            // Read row data based on column mapping
            foreach ($columnIndexMapping as $productField => $columnIndex) {
                $cellValue = $worksheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex) . $row)->getValue();
                if ($cellValue !== null && $cellValue !== '') {
                    // Convert data types appropriately
                    if ($productField === 'sku') {
                        $rowData[$productField] = (string) $cellValue;
                    } elseif ($productField === 'stock_quantity') {
                        $rowData[$productField] = is_numeric($cellValue) ? (float) $cellValue : 0;
                    } else {
                        $rowData[$productField] = $cellValue;
                    }
                }
            }
            
            // Apply default values for unmapped or skipped columns
            $rowData = $this->applyDefaultValues($rowData, $columnMapping);
            
            // Only add rows that have a product name
            if (!empty($rowData['name'])) {
                $previewData[] = $rowData;
            }
        }
        
        // Clear memory
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        gc_collect_cycles();
        
        return $previewData;
    }

    /**
     * Import products from Excel file
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param array $columnMapping
     * @param bool $skipHeader
     * @return array
     */
    public function importProducts($file, array $columnMapping, bool $skipHeader = true): array
    {
        // Set memory limit and execution time for large imports
        ini_set('memory_limit', '512M');
        set_time_limit(300); // 5 minutes
        
        $spreadsheet = IOFactory::load($file->getPathname());
        $worksheet = $spreadsheet->getActiveSheet();
        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
        
        $imported = 0;
        $errors = 0;
        $errorDetails = [];
        $startRow = $skipHeader ? 2 : 1;
        
        // Cache headers to avoid repeated calls
        $headers = $this->getExcelHeaders($file);
        
        // Create column index mapping for better performance
        $columnIndexMapping = [];
        foreach ($columnMapping as $productField => $excelColumn) {
            if ($excelColumn && $excelColumn !== 'skip') {
                $columnIndex = array_search($excelColumn, $headers);
                if ($columnIndex !== false) {
                    $columnIndexMapping[$productField] = $columnIndex + 1;
                }
            }
        }
        
        // Log the mapping for debugging
        Log::info("Import mapping:", [
            'headers' => $headers,
            'columnMapping' => $columnMapping,
            'columnIndexMapping' => $columnIndexMapping
        ]);
        
        // Process in batches to avoid memory issues
        $batchSize = 100;
        $currentBatch = [];
        
        DB::beginTransaction();
        
        try {
            for ($row = $startRow; $row <= $highestRow; $row++) {
                $rowData = [];
                
                // Read row data based on column mapping (optimized)
                foreach ($columnIndexMapping as $productField => $columnIndex) {
                    $cellValue = $worksheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex) . $row)->getValue();
                    if ($cellValue !== null && $cellValue !== '') {
                        // Convert data types appropriately
                        if ($productField === 'sku') {
                            $rowData[$productField] = (string) $cellValue;
                        } elseif ($productField === 'stock_quantity') {
                            $rowData[$productField] = is_numeric($cellValue) ? (float) $cellValue : 0;
                        } else {
                            $rowData[$productField] = $cellValue;
                        }
                    }
                }
                
                // Debug logging for first few rows
                if ($row <= 5) {
                    Log::info("Row {$row} data:", [
                        'columnMapping' => $columnMapping,
                        'columnIndexMapping' => $columnIndexMapping,
                        'rowData' => $rowData
                    ]);
                }
                
                // Apply default values for unmapped or skipped columns
                $rowData = $this->applyDefaultValues($rowData, $columnMapping);
                
                // Skip rows that don't have a product name
                if (empty($rowData['name'])) {
                    continue;
                }
                
                // Validate and process the row data
                $validationResult = $this->validateProductData($rowData);
                
                if ($validationResult['valid']) {
                    $currentBatch[] = $rowData;
                    $imported++;
                } else {
                    $errors++;
                    $errorDetails[] = [
                        'row' => $row,
                        'errors' => $validationResult['errors']
                    ];
                }
                
                // Process batch when it reaches the batch size
                if (count($currentBatch) >= $batchSize) {
                    $this->createProductsBatch($currentBatch);
                    $currentBatch = [];
                    
                    // Log progress for large imports
                    if ($highestRow > 1000) {
                        Log::info("Import progress: {$row}/{$highestRow} rows processed");
                    }
                }
            }
            
            // Process remaining batch
            if (!empty($currentBatch)) {
                $this->createProductsBatch($currentBatch);
            }
            
            DB::commit();
            
            // Clear memory
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
            gc_collect_cycles();
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Product import error: ' . $e->getMessage());
            throw $e;
        }
        
        return [
            'imported' => $imported,
            'errors' => $errors,
            'errorDetails' => $errorDetails
        ];
    }

    /**
     * Apply default values for unmapped or skipped columns
     *
     * @param array $data
     * @param array $columnMapping
     * @return array
     */
    private function applyDefaultValues(array $data, array $columnMapping): array
    {
        // Define default values for each field
        $defaultValues = [
            'name' => null,
            'sku' => null,
            'scientific_name' => null,
            'stock_quantity' => 0, // Keep 0 as default for stock quantity
        ];
        
        // Apply defaults for any field that is not in the data or was skipped
        foreach ($defaultValues as $field => $defaultValue) {
            if (!isset($data[$field])) {
                $data[$field] = $defaultValue;
            }
        }
        
        return $data;
    }

    /**
     * Validate product data for import
     *
     * @param array $data
     * @param array $categories
     * @param array $units
     * @return array
     */
    private function validateProductData(array $data): array
    {
        // Ensure required fields are present and have valid data
        if (empty($data['name']) || trim($data['name']) === '') {
            return [
                'valid' => false,
                'errors' => ['name' => ['The name field is required.']]
            ];
        }
        
        // Validate SKU if provided
        if (isset($data['sku']) && $data['sku'] !== null && $data['sku'] !== '') {
            // Check if SKU already exists
            $existingProduct = Product::where('sku', $data['sku'])->first();
            if ($existingProduct) {
                return [
                    'valid' => false,
                    'errors' => ['sku' => ['The sku has already been taken.']]
                ];
            }
        }
        
        // Validate stock quantity if provided
        if (isset($data['stock_quantity']) && $data['stock_quantity'] !== null && $data['stock_quantity'] !== '') {
            if (!is_numeric($data['stock_quantity']) || $data['stock_quantity'] < 0) {
                return [
                    'valid' => false,
                    'errors' => ['stock_quantity' => ['The stock quantity field must be a number.']]
                ];
            }
        }
        
        return ['valid' => true, 'errors' => []];
    }

    /**
     * Create products in batch for better performance
     *
     * @param array $batchData
     * @param array $categories
     * @return void
     */
    private function createProductsBatch(array $batchData): void
    {
        $productsToCreate = [];
        
        foreach ($batchData as $data) {
            $productsToCreate[] = [
                'name' => trim($data['name'] ?? ''),
                'sku' => isset($data['sku']) && $data['sku'] !== '' ? (string) $data['sku'] : null,
                'scientific_name' => isset($data['scientific_name']) && $data['scientific_name'] !== '' ? (string) $data['scientific_name'] : null,
                'stock_quantity' => isset($data['stock_quantity']) && is_numeric($data['stock_quantity']) ? (int) $data['stock_quantity'] : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        
        if (!empty($productsToCreate)) {
            Product::insert($productsToCreate);
        }
    }

    /**
     * Create product from validated data
     *
     * @param array $data
     * @param array $categories
     * @param array $units
     * @return Product
     */
    private function createProduct(array $data): Product
    {
        $productData = [
            'name' => trim($data['name'] ?? ''),
            'sku' => isset($data['sku']) && $data['sku'] !== '' ? (string) $data['sku'] : null,
            'scientific_name' => isset($data['scientific_name']) && $data['scientific_name'] !== '' ? (string) $data['scientific_name'] : null,
            'stock_quantity' => isset($data['stock_quantity']) && is_numeric($data['stock_quantity']) ? (int) $data['stock_quantity'] : 0,
        ];
        
        return Product::create($productData);
    }
} 