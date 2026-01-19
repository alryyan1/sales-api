<?php

namespace App\Services;

use TCPDF;
use App\Models\Sale;
use Illuminate\Support\Facades\Storage;

class InvoicePdfService
{
    /**
     * Generate invoice PDF for a sale
     *
     * @param Sale $sale
     * @return string PDF content
     */
    public function generateInvoicePdf(Sale $sale): string
    {
        // Create new PDF document
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // Set document information
        $pdf->SetCreator('Sales System');
        $pdf->SetAuthor('Company');
        $pdf->SetTitle('Invoice #' . $sale->id);
        $pdf->SetSubject('Sales Invoice');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins (30px ≈ 10.5mm)
        $pdf->SetMargins(10.5, 10.5, 10.5);
        $pdf->SetAutoPageBreak(true, 10.5);

        // Add a page
        $pdf->AddPage();

        // Set font
        $pdf->SetFont('arial', '', 10);

        // Get settings
        $settings = app(\App\Services\SettingsService::class)->getAll();

        // Load sale items
        $sale->load(['items.product', 'client', 'user']);

        // Generate content
        $this->generateHeader($pdf, $sale, $settings);
        $this->generateTable($pdf, $sale);
        $this->generateSummary($pdf, $sale);
        $this->generateFooter($pdf, $sale);

        return $pdf->Output('', 'S');
    }

    /**
     * Generate PDF header section
     */
    private function generateHeader(TCPDF $pdf, Sale $sale, array $settings): void
    {
        $y = $pdf->GetY();

        // Logo (if exists)
        if (!empty($settings['company_logo_url'])) {
            $logoPath = public_path(str_replace(url('/'), '', $settings['company_logo_url']));
            if (file_exists($logoPath)) {
                $pdf->Image($logoPath, 15, $y, 0, 20, '', '', '', false, 300, '', false, false, 0);
                $y += 25;
            }
        }

        $pdf->SetY($y);

        // Information section - 3 columns
        $leftX = 15;
        $centerX = 80;
        $rightX = 145;
        $infoY = $pdf->GetY();

        // LEFT COLUMN - Customer Info
        $pdf->SetXY($leftX, $infoY);
        $pdf->SetFont('arial', 'B', 10);
        $customerName = $sale->client ? $sale->client->name : 'Walk-in Customer';
        $pdf->MultiCell(60, 5, 'Customer: ' . $customerName, 0, 'L', false, 1);

        $pdf->SetX($leftX);
        $pdf->MultiCell(60, 5, 'Number: ' . $sale->id, 0, 'L', false, 1);

        // CENTER COLUMN - Sale Type & Date
        $pdf->SetXY($centerX, $infoY);
        $pdf->SetFont('arial', 'B', 10);
        $pdf->MultiCell(50, 5, 'Sales Cash', 0, 'C', false, 1);

        $pdf->SetX($centerX);
        $saleDate = date('Y-m-d', strtotime($sale->sale_date));
        $pdf->MultiCell(50, 5, $saleDate, 0, 'C', false, 1);

        // RIGHT COLUMN - Company Info
        $pdf->SetXY($rightX, $infoY);
        $pdf->SetFont('arial', 'B', 11);
        $companyName = strtoupper($settings['company_name'] ?? 'COMPANY NAME');
        $pdf->MultiCell(50, 5, $companyName, 0, 'R', false, 1);

        $pdf->SetX($rightX);
        $pdf->SetFont('arial', '', 10);
        $pdf->MultiCell(50, 5, 'Enterprises', 0, 'R', false, 1);

        $pdf->SetX($rightX);
        $address = $settings['company_address'] ?? 'Address Line';
        $pdf->MultiCell(50, 5, $address, 0, 'R', false, 1);

        $pdf->SetX($rightX);
        $pdf->SetFont('arial', 'B', 10);
        $phone = $settings['company_phone'] ?? '+0000000000';
        $pdf->MultiCell(50, 5, $phone, 0, 'R', false, 1);

        // Divider line
        $pdf->SetY($pdf->GetY() + 3);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->SetY($pdf->GetY() + 5);
    }

    /**
     * Generate items table
     */
    private function generateTable(TCPDF $pdf, Sale $sale): void
    {
        $startY = $pdf->GetY();

        // Column widths (total = 180mm for A4 with margins)
        $colWidths = [
            'index' => 14,   // 8%
            'item' => 94,    // 52%
            'qty' => 18,     // 10%
            'price' => 27,   // 15%
            'total' => 27,   // 15%
        ];

        // Table header
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetFont('arial', 'B', 10);

        $pdf->Cell($colWidths['index'], 7, '#', 1, 0, 'C', true);
        $pdf->Cell($colWidths['item'], 7, 'Item', 1, 0, 'L', true);
        $pdf->Cell($colWidths['qty'], 7, 'Qty', 1, 0, 'C', true);
        $pdf->Cell($colWidths['price'], 7, 'Price', 1, 0, 'C', true);
        $pdf->Cell($colWidths['total'], 7, 'Total', 1, 1, 'C', true);

        // Table rows
        $pdf->SetFont('arial', '', 10);
        $pdf->SetFillColor(255, 255, 255);

        foreach ($sale->items as $index => $item) {
            $itemTotal = $item->unit_price * $item->quantity;

            $pdf->Cell($colWidths['index'], 7, ($index + 1), 1, 0, 'C', false);
            $pdf->Cell($colWidths['item'], 7, $item->product->name ?? 'Product', 1, 0, 'L', false);
            $pdf->Cell($colWidths['qty'], 7, $item->quantity, 1, 0, 'C', false);
            $pdf->Cell($colWidths['price'], 7, number_format($item->unit_price, 0, '.', ','), 1, 0, 'C', false);
            $pdf->Cell($colWidths['total'], 7, number_format($itemTotal, 0, '.', ','), 1, 1, 'C', false);
        }

        $pdf->SetY($pdf->GetY() + 10);
    }

    /**
     * Generate summary section
     */
    private function generateSummary(TCPDF $pdf, Sale $sale): void
    {
        $rightX = 135; // Start of summary box (40% of page width from right)
        $boxWidth = 60;
        $y = $pdf->GetY();

        $pdf->SetFont('arial', 'B', 10);

        // Total
        $pdf->SetXY($rightX, $y);
        $pdf->Cell($boxWidth - 30, 5, 'Total', 0, 0, 'L');
        $pdf->Cell(30, 5, number_format($sale->total_amount, 0, '.', ','), 0, 1, 'R');

        // Line
        $y = $pdf->GetY() + 1;
        $pdf->Line($rightX, $y, $rightX + $boxWidth, $y);
        $y += 4;

        // Paid
        $pdf->SetXY($rightX, $y);
        $pdf->Cell($boxWidth - 30, 5, 'Paid', 0, 0, 'L');
        $pdf->Cell(30, 5, number_format($sale->paid_amount, 0, '.', ','), 0, 1, 'R');

        // Line
        $y = $pdf->GetY() + 1;
        $pdf->Line($rightX, $y, $rightX + $boxWidth, $y);
        $y += 4;

        // Current Due (with green background)
        $currentDue = max(0, $sale->total_amount - $sale->paid_amount);

        $pdf->SetXY($rightX, $y);
        $pdf->Cell($boxWidth - 30, 5, 'Current Due', 0, 0, 'L');

        // Green box for due amount
        $pdf->SetFillColor(167, 243, 208); // Light green #a7f3d0
        $pdf->SetTextColor(0, 0, 255); // Blue text
        $pdf->Cell(30, 5, number_format($currentDue, 0, '.', ','), 1, 1, 'C', true);
        $pdf->SetTextColor(0, 0, 0); // Reset to black

        // Line
        $y = $pdf->GetY() + 1;
        $pdf->Line($rightX, $y, $rightX + $boxWidth, $y);
    }

    /**
     * Generate footer timestamp
     */
    private function generateFooter(TCPDF $pdf, Sale $sale): void
    {
        $pdf->SetY(277); // Near bottom of A4 (297mm - 20mm margin)
        $pdf->SetFont('arial', '', 9);

        $timestamp = date('d/m/Y H:i:s', strtotime($sale->sale_date));
        $pdf->Cell(0, 5, $timestamp, 0, 0, 'L');
    }

    /**
     * Generate and download PDF
     *
     * @param Sale $sale
     * @param string $filename
     * @return \Illuminate\Http\Response
     */
    public function downloadInvoice(Sale $sale, string $filename = null): \Illuminate\Http\Response
    {
        $filename = $filename ?? 'invoice_' . $sale->id . '.pdf';
        $pdfContent = $this->generateInvoicePdf($sale);

        return response($pdfContent)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * Display PDF in browser
     *
     * @param Sale $sale
     * @return \Illuminate\Http\Response
     */
    public function viewInvoice(Sale $sale): \Illuminate\Http\Response
    {
        $pdfContent = $this->generateInvoicePdf($sale);

        return response($pdfContent)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="invoice_' . $sale->id . '.pdf"');
    }
}
