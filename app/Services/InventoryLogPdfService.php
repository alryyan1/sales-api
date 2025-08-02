<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use TCPDF;

class InventoryLogPdfService
{
    public function generatePdf(array $filters = []): string
    {
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('Sales Management System');
        $pdf->SetAuthor('System Admin');
        $pdf->SetTitle('Inventory Log Report');
        $pdf->SetSubject('Inventory Movement Log');
        
        // Set margins
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetHeaderMargin(5);
        $pdf->SetFooterMargin(10);
        
        // Set auto page breaks
        $pdf->SetAutoPageBreak(TRUE, 25);
        
        // Set image scale factor
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        
        // Set font
        $pdf->SetFont('dejavusans', '', 10);
        
        // Add a page
        $pdf->AddPage();
        
        // Generate header
        $this->generateHeader($pdf);
        
        // Generate filters summary
        $this->generateFiltersSummary($pdf, $filters);
        
        // Generate data table
        $this->generateDataTable($pdf, $filters);
        
        // Generate footer
        $this->generateFooter($pdf);
        
        return $pdf->Output('', 'S');
    }
    
    private function generateHeader($pdf)
    {
        // Header with company info
        $pdf->SetFont('dejavusans', 'B', 18);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 10, 'Inventory Movement Log Report', 0, 1, 'C');
        
        $pdf->SetFont('dejavusans', '', 12);
        $pdf->Cell(0, 8, 'Generated on: ' . Carbon::now()->format('Y-m-d H:i:s'), 0, 1, 'C');
        
        $pdf->Ln(5);
    }
    
    private function generateFiltersSummary($pdf, $filters)
    {
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(0, 8, 'Filters Applied:', 0, 1, 'L', true);
        
        $pdf->SetFont('dejavusans', '', 10);
        $filterText = [];
        
        if (!empty($filters['start_date'])) {
            $filterText[] = 'From: ' . $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $filterText[] = 'To: ' . $filters['end_date'];
        }
        if (!empty($filters['product_id'])) {
            $productName = DB::table('products')->where('id', $filters['product_id'])->value('name');
            $filterText[] = 'Product: ' . ($productName ?? 'Unknown');
        }
        if (!empty($filters['type'])) {
            $filterText[] = 'Type: ' . ucfirst($filters['type']);
        }
        if (!empty($filters['search'])) {
            $filterText[] = 'Search: ' . $filters['search'];
        }
        
        if (empty($filterText)) {
            $filterText[] = 'All records';
        }
        
        $pdf->Cell(0, 6, implode(' | ', $filterText), 0, 1, 'L');
        $pdf->Ln(5);
    }
    
    private function generateDataTable($pdf, $filters)
    {
        // Get data using the same logic as the controller
        $data = $this->getInventoryLogData($filters);
        
        if (empty($data)) {
            $pdf->SetFont('dejavusans', '', 12);
            $pdf->Cell(0, 10, 'No data found for the specified filters.', 0, 1, 'C');
            return;
        }
        
        // Table header
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetFillColor(200, 200, 200);
        $pdf->SetTextColor(0, 0, 0);
        
        // Column widths
        $colWidths = [25, 25, 50, 25, 25, 35, 25, 40];
        $headers = ['Date', 'Type', 'Product', 'Batch', 'Qty Change', 'Document', 'User', 'Notes'];
        
        // Header row
        foreach ($headers as $i => $header) {
            $pdf->Cell($colWidths[$i], 8, $header, 1, 0, 'C', true);
        }
        $pdf->Ln();
        
        // Data rows
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetFillColor(255, 255, 255);
        
        $rowCount = 0;
        foreach ($data as $row) {
            // Alternate row colors
            $fillColor = ($rowCount % 2 == 0) ? [245, 245, 245] : [255, 255, 255];
            $pdf->SetFillColor($fillColor[0], $fillColor[1], $fillColor[2]);
            
            // Date
            $pdf->Cell($colWidths[0], 7, Carbon::parse($row->transaction_date)->format('Y-m-d'), 1, 0, 'C', true);
            
            // Type with color coding
            $typeColor = $this->getTypeColor($row->type);
            $pdf->SetTextColor($typeColor[0], $typeColor[1], $typeColor[2]);
            $pdf->Cell($colWidths[1], 7, ucfirst($row->type), 1, 0, 'C', true);
            $pdf->SetTextColor(0, 0, 0);
            
            // Product
            $pdf->Cell($colWidths[2], 7, $row->product_name, 1, 0, 'L', true);
            
            // Batch
            $pdf->Cell($colWidths[3], 7, $row->batch_number ?? '-', 1, 0, 'C', true);
            
            // Quantity change with color coding
            $qtyColor = ($row->quantity_change > 0) ? [0, 128, 0] : [255, 0, 0];
            $pdf->SetTextColor($qtyColor[0], $qtyColor[1], $qtyColor[2]);
            $sign = ($row->quantity_change > 0) ? '+' : '';
            $pdf->Cell($colWidths[4], 7, $sign . $row->quantity_change, 1, 0, 'R', true);
            $pdf->SetTextColor(0, 0, 0);
            
            // Document reference
            $pdf->Cell($colWidths[5], 7, $row->document_reference ?? '#' . $row->document_id, 1, 0, 'C', true);
            
            // User
            $pdf->Cell($colWidths[6], 7, $row->user_name ?? '-', 1, 0, 'C', true);
            
            // Notes (truncated if too long)
            $notes = $row->reason_notes ?? '';
            if (strlen($notes) > 30) {
                $notes = substr($notes, 0, 27) . '...';
            }
            $pdf->Cell($colWidths[7], 7, $notes, 1, 0, 'L', true);
            
            $pdf->Ln();
            $rowCount++;
        }
        
        // Summary
        $pdf->Ln(5);
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->Cell(0, 8, 'Total Records: ' . count($data), 0, 1, 'L');
    }
    
    private function generateFooter($pdf)
    {
        $pdf->SetY(-15);
        $pdf->SetFont('dejavusans', 'I', 8);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->Cell(0, 10, 'Page ' . $pdf->getAliasNumPage() . '/' . $pdf->getAliasNbPages(), 0, 0, 'C');
    }
    
    private function getTypeColor($type)
    {
        switch ($type) {
            case 'purchase':
                return [0, 128, 0]; // Green
            case 'sale':
                return [255, 0, 0]; // Red
            case 'adjustment':
                return [0, 0, 255]; // Blue
            case 'requisition_issue':
                return [255, 165, 0]; // Orange
            default:
                return [0, 0, 0]; // Black
        }
    }
    
    private function getInventoryLogData($filters)
    {
        $startDate = isset($filters['start_date']) ? Carbon::parse($filters['start_date'])->startOfDay() : null;
        $endDate = isset($filters['end_date']) ? Carbon::parse($filters['end_date'])->endOfDay() : null;
        $productId = $filters['product_id'] ?? null;
        $type = $filters['type'] ?? null;
        $search = $filters['search'] ?? null;

        // Purchase Items Query
        $purchasesQuery = DB::table('purchase_items as pi')
            ->join('purchases as p', 'pi.purchase_id', '=', 'p.id')
            ->join('products as prod', 'pi.product_id', '=', 'prod.id')
            ->join('users as u', 'p.user_id', '=', 'u.id')
            ->select(
                'p.purchase_date as transaction_date',
                DB::raw("'purchase' as type"),
                'prod.id as product_id',
                'prod.name as product_name',
                'prod.sku as product_sku',
                'pi.batch_number',
                'pi.quantity as quantity_change',
                'p.reference_number as document_reference',
                'p.id as document_id',
                'u.name as user_name',
                'p.notes as reason_notes'
            )
            ->where('p.status', 'received');

        // Sale Items Query
        $salesQuery = DB::table('sale_items as si')
            ->join('sales as s', 'si.sale_id', '=', 's.id')
            ->join('products as prod', 'si.product_id', '=', 'prod.id')
            ->join('users as u', 's.user_id', '=', 'u.id')
            ->leftJoin('purchase_items as pi_batch', 'si.purchase_item_id', '=', 'pi_batch.id')
            ->select(
                's.sale_date as transaction_date',
                DB::raw("'sale' as type"),
                'prod.id as product_id',
                'prod.name as product_name',
                'prod.sku as product_sku',
                'si.batch_number_sold as batch_number',
                DB::raw('si.quantity * -1 as quantity_change'),
                's.invoice_number as document_reference',
                's.id as document_id',
                'u.name as user_name',
                's.notes as reason_notes'
            )
            ->whereIn('s.status', ['completed', 'pending']);

        // Stock Adjustments Query
        $adjustmentsQuery = DB::table('stock_adjustments as sa')
            ->join('products as prod', 'sa.product_id', '=', 'prod.id')
            ->join('users as u', 'sa.user_id', '=', 'u.id')
            ->leftJoin('purchase_items as pi_batch', 'sa.purchase_item_id', '=', 'pi_batch.id')
            ->select(
                'sa.created_at as transaction_date',
                DB::raw("'adjustment' as type"),
                'prod.id as product_id',
                'prod.name as product_name',
                'prod.sku as product_sku',
                'pi_batch.batch_number as batch_number',
                'sa.quantity_change',
                'sa.reason as document_reference',
                'sa.id as document_id',
                'u.name as user_name',
                'sa.notes as reason_notes'
            );

        // Stock Requisition Issues Query
        $requisitionIssuesQuery = DB::table('stock_requisition_items as sri')
            ->join('stock_requisitions as sr', 'sri.stock_requisition_id', '=', 'sr.id')
            ->join('products as prod', 'sri.product_id', '=', 'prod.id')
            ->join('users as u_req', 'sr.requester_user_id', '=', 'u_req.id')
            ->leftJoin('users as u_app', 'sr.approved_by_user_id', '=', 'u_app.id')
            ->leftJoin('purchase_items as pi_batch', 'sri.issued_from_purchase_item_id', '=', 'pi_batch.id')
            ->select(
                'sr.issue_date as transaction_date',
                DB::raw("'requisition_issue' as type"),
                'prod.id as product_id',
                'prod.name as product_name',
                'prod.sku as product_sku',
                'sri.issued_batch_number as batch_number',
                DB::raw('sri.issued_quantity * -1 as quantity_change'),
                DB::raw("CONCAT('REQ-', sr.id) as document_reference"),
                'sr.id as document_id',
                'u_app.name as user_name',
                DB::raw("CONCAT(sr.department_or_reason, ' (Requested by: ', u_req.name, ')') as reason_notes")
            )
            ->where('sri.status', 'issued')
            ->whereNotNull('sr.issue_date');

        // Apply filters
        foreach ([$purchasesQuery, $salesQuery, $adjustmentsQuery, $requisitionIssuesQuery] as $query) {
            if ($startDate) {
                $query->whereDate(DB::raw($query->grammar->wrap('transaction_date')), '>=', $startDate);
            }
            if ($endDate) {
                $query->whereDate(DB::raw($query->grammar->wrap('transaction_date')), '<=', $endDate);
            }
            if ($productId) {
                $query->where('prod.id', $productId);
            }
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('prod.name', 'like', "%{$search}%")
                      ->orWhere('prod.sku', 'like', "%{$search}%")
                      ->orWhere(DB::raw($q->grammar->wrap('batch_number')), 'like', "%{$search}%")
                      ->orWhere(DB::raw($q->grammar->wrap('document_reference')), 'like', "%{$search}%");
                });
            }
        }

        // Apply type filter
        if ($type) {
            switch ($type) {
                case 'purchase':
                    $query = $purchasesQuery;
                    break;
                case 'sale':
                    $query = $salesQuery;
                    break;
                case 'adjustment':
                    $query = $adjustmentsQuery;
                    break;
                case 'requisition_issue':
                    $query = $requisitionIssuesQuery;
                    break;
                default:
                    $query = $purchasesQuery
                        ->unionAll($salesQuery)
                        ->unionAll($adjustmentsQuery)
                        ->unionAll($requisitionIssuesQuery);
                    break;
            }
        } else {
            $query = $purchasesQuery
                ->unionAll($salesQuery)
                ->unionAll($adjustmentsQuery)
                ->unionAll($requisitionIssuesQuery);
        }

        // Execute query and return results
        $unionQuery = DB::query()->fromSub($query, 'inventory_log');
        return $unionQuery->orderBy('transaction_date', 'desc')->orderBy('product_name')->get();
    }
} 