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
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\PurchaseItem;

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
            $sheet->setCellValue('H' . $row, number_format($purchase->total_amount, 0));
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
        $sheet->setCellValue('B' . $summaryRow, number_format($purchases->sum('total_amount'), 0));
        
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
     * Get Excel headers from uploaded file
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
            if ($cellValue !== null && $cellValue !== '') {
                $headers[] = (string) $cellValue;
            }
        }
        
        // Clear memory
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        gc_collect_cycles();
        
        return $headers;
    }

    /**
     * Preview purchase items from Excel file
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param array $columnMapping
     * @param bool $skipHeader
     * @return array
     */
    public function previewPurchaseItems($file, array $columnMapping, bool $skipHeader = true): array
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
        foreach ($columnMapping as $purchaseItemField => $excelColumn) {
            if ($excelColumn && $excelColumn !== 'skip') {
                $columnIndex = array_search($excelColumn, $headers);
                if ($columnIndex !== false) {
                    $columnIndexMapping[$purchaseItemField] = $columnIndex + 1;
                }
            }
        }
        
        // Log the mapping for debugging
        Log::info("Purchase items preview mapping:", [
            'headers' => $headers,
            'columnMapping' => $columnMapping,
            'columnIndexMapping' => $columnIndexMapping
        ]);
        
        // Process first 50 rows for preview
        $previewRows = min(50, $highestRow - $startRow + 1);
        
        for ($row = $startRow; $row < $startRow + $previewRows; $row++) {
            $rowData = [];
            
            // Read row data based on column mapping
            foreach ($columnIndexMapping as $purchaseItemField => $columnIndex) {
                $cellValue = $worksheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex) . $row)->getValue();
                if ($cellValue !== null && $cellValue !== '') {
                    // Convert data types appropriately
                    if (in_array($purchaseItemField, ['quantity', 'unit_cost', 'sale_price'])) {
                        $rowData[$purchaseItemField] = is_numeric($cellValue) ? (float) $cellValue : 0;
                    } else {
                        $rowData[$purchaseItemField] = $cellValue;
                    }
                }
            }
            
            // Apply default values for unmapped or skipped columns
            $rowData = $this->applyDefaultValues($rowData, $columnMapping);
            
            // Only add rows that have at least product name or SKU
            if (!empty($rowData['product_name']) || !empty($rowData['product_sku'])) {
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
     * Import purchase items from Excel file with batch processing for large datasets
     * @param \Illuminate\Http\UploadedFile $file
     * @param array $columnMapping
     * @param bool $skipHeader
     * @param int $purchaseId
     * @return array
     */
    public function importPurchaseItems($file, array $columnMapping, bool $skipHeader = true, int $purchaseId): array
    {
        // Set memory limit for large files
        ini_set('memory_limit', '512M');
        
        $spreadsheet = IOFactory::load($file->getPathname());
        $worksheet = $spreadsheet->getActiveSheet();
        $highestRow = $worksheet->getHighestRow();
        
        $imported = 0;
        $errors = 0;
        $errorDetails = [];
        $startRow = $skipHeader ? 2 : 1;
        
        // Cache headers to avoid repeated calls
        $headers = $this->getExcelHeaders($file);
        
        // Create column index mapping for better performance
        $columnIndexMapping = [];
        foreach ($columnMapping as $purchaseItemField => $excelColumn) {
            if ($excelColumn && $excelColumn !== 'skip') {
                $columnIndex = array_search($excelColumn, $headers);
                if ($columnIndex !== false) {
                    $columnIndexMapping[$purchaseItemField] = $columnIndex + 1;
                }
            }
        }
        
        // Log the mapping for debugging
        Log::info("Purchase items import mapping:", [
            'headers' => $headers,
            'columnMapping' => $columnMapping,
            'columnIndexMapping' => $columnIndexMapping,
            'purchaseId' => $purchaseId,
            'totalRows' => $highestRow - $startRow + 1
        ]);
        
        // Batch size for processing
        $batchSize = 100;
        $totalBatches = ceil(($highestRow - $startRow + 1) / $batchSize);
        
        // Process in batches for better memory management
        for ($batch = 0; $batch < $totalBatches; $batch++) {
            $batchStartRow = $startRow + ($batch * $batchSize);
            $batchEndRow = min($batchStartRow + $batchSize - 1, $highestRow);
            
            // Start database transaction for this batch
            DB::beginTransaction();
            
            try {
                for ($row = $batchStartRow; $row <= $batchEndRow; $row++) {
                    $rowData = [];
                    
                    // Read row data based on column mapping
                    foreach ($columnIndexMapping as $purchaseItemField => $columnIndex) {
                        $cellValue = $worksheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex) . $row)->getValue();
                        if ($cellValue !== null && $cellValue !== '') {
                            // Convert data types appropriately
                            if (in_array($purchaseItemField, ['quantity', 'unit_cost', 'sale_price'])) {
                                $rowData[$purchaseItemField] = is_numeric($cellValue) ? (float) $cellValue : 0;
                            } else {
                                $rowData[$purchaseItemField] = $cellValue;
                            }
                        }
                    }
                    
                    // Apply default values for unmapped or skipped columns
                    $rowData = $this->applyDefaultValues($rowData, $columnMapping);
                    
                    // Skip empty rows (after applying defaults)
                    if (empty($rowData['product_name']) && empty($rowData['product_sku'])) {
                        continue;
                    }
                    
                    // Validate and create purchase item
                    $validationResult = $this->validatePurchaseItemData($rowData);
                    if (!$validationResult['valid']) {
                        $errors++;
                        $errorDetails[] = [
                            'row' => $row,
                            'errors' => $validationResult['errors']
                        ];
                        continue;
                    }
                    
                    // Create purchase item
                    $this->createPurchaseItem($rowData, $purchaseId);
                    $imported++;
                    
                    // Log progress for large imports
                    if ($imported % 50 === 0) {
                        Log::info("Import progress: {$imported} items imported, {$errors} errors");
                    }
                }
                
                DB::commit();
                
                // Log batch completion
                Log::info("Batch " . ($batch + 1) . "/{$totalBatches} completed: rows {$batchStartRow}-{$batchEndRow}");
                
                // Clear memory after each batch
                gc_collect_cycles();
                
            } catch (\Exception $e) {
                DB::rollBack();
                
                Log::error('Purchase items import batch failed:', [
                    'batch' => $batch + 1,
                    'rows' => "{$batchStartRow}-{$batchEndRow}",
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Add batch error to error details
                $errorDetails[] = [
                    'row' => "Batch " . ($batch + 1),
                    'errors' => ['batch_error' => $e->getMessage()]
                ];
                $errors++;
                
                // Continue with next batch instead of failing completely
                continue;
            }
        }
        
        // Clear memory
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        gc_collect_cycles();
        
        return [
            'imported' => $imported,
            'errors' => $errors,
            'message' => "Import completed successfully. {$imported} items imported, {$errors} errors.",
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
            'product_name' => null,
            'product_sku' => null,
            'batch_number' => null,
            'quantity' => 1, // Default to 1 instead of 0 for quantity
            'unit_cost' => 0,
            'sale_price' => null,
            'expiry_date' => null,
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
     * Validate purchase item data
     *
     * @param array $data
     * @return array
     */
    private function validatePurchaseItemData(array $data): array
    {
        // Check required fields
        if (empty($data['product_name']) && empty($data['product_sku'])) {
            return ['valid' => false, 'errors' => ['product' => ['Either product name or SKU is required.']]];
        }
        
        if (!isset($data['quantity']) || !is_numeric($data['quantity']) || $data['quantity'] <= 0) {
            return ['valid' => false, 'errors' => ['quantity' => ['Quantity must be a positive number.']]];
        }
        
        if (!isset($data['unit_cost']) || !is_numeric($data['unit_cost']) || $data['unit_cost'] < 0) {
            return ['valid' => false, 'errors' => ['unit_cost' => ['Unit cost must be a non-negative number.']]];
        }
        
        // Find product by name or SKU
        $product = null;
        if (!empty($data['product_sku'])) {
            $product = Product::where('sku', $data['product_sku'])->first();
        }
        
        if (!$product && !empty($data['product_name'])) {
            $product = Product::where('name', 'like', '%' . $data['product_name'] . '%')->first();
        }
        
        if (!$product) {
            return ['valid' => false, 'errors' => ['product' => ['Product not found. Please check the product name or SKU.']]];
        }
        
        return ['valid' => true, 'errors' => []];
    }

    /**
     * Create purchase item
     *
     * @param array $data
     * @param int $purchaseId
     * @return void
     */
    private function createPurchaseItem(array $data, int $purchaseId): void
    {
        // Find product by name or SKU
        $product = null;
        if (!empty($data['product_sku'])) {
            $product = Product::where('sku', $data['product_sku'])->first();
        }
        
        if (!$product && !empty($data['product_name'])) {
            $product = Product::where('name', 'like', '%' . $data['product_name'] . '%')->first();
        }
        
        if (!$product) {
            throw new \Exception('Product not found');
        }
        
        // Calculate total cost
        $quantity = (int) ($data['quantity'] ?? 0);
        $unitCost = (float) ($data['unit_cost'] ?? 0);
        $totalCost = $quantity * $unitCost;
        
        // Create purchase item
        PurchaseItem::create([
            'purchase_id' => $purchaseId,
            'product_id' => $product->id,
            'batch_number' => $data['batch_number'] ?? null,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'sale_price' => isset($data['sale_price']) && $data['sale_price'] > 0 ? (float) $data['sale_price'] : null,
            'expiry_date' => !empty($data['expiry_date']) ? $data['expiry_date'] : null,
        ]);
        
        // Update product stock quantity
        $product->increment('stock_quantity', $quantity);
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