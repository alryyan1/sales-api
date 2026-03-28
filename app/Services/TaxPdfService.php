<?php

namespace App\Services;

use App\Models\Purchase;
use App\Services\Pdf\PdfHeaderRenderer;
use TCPDF;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Professional PDF Report Generator for Tax and Customs
 */
class TaxPdfService
{
    private $pdf;
    private PdfHeaderRenderer $renderer;

    private const COLOR_PRIMARY = [80, 80, 80];
    private const COLOR_HEADER_BG = [60, 60, 60];
    private const COLOR_LIGHT_GRAY = [245, 245, 245];
    private const COLOR_BORDER = [200, 200, 200];
    private const MARGIN = 15;
    private const FONT_SIZE_TITLE = 20;
    private const FONT_SIZE_SUBTITLE = 14;
    private const FONT_SIZE_HEADING = 12;
    private const FONT_SIZE_BODY = 10;
    private const FONT_SIZE_SMALL = 8;

    public function generateTaxPdf(Purchase $purchase, bool $includeDetails = false): string
    {
        try {
            $this->loadRelationships($purchase);
            $this->renderer = new PdfHeaderRenderer('tax');
            $this->initializePdf($purchase, $includeDetails);
            $this->pdf->AddPage();
            $this->renderer->render($this->pdf);
            $this->buildReport($purchase, $includeDetails);
            return $this->pdf->Output('tax_report_' . $purchase->id . '.pdf', 'S');
        } catch (Exception $e) {
            Log::error('Tax PDF Generation Failed', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage(),
            ]);
            throw new Exception('Failed to generate PDF: ' . $e->getMessage());
        }
    }

    private function loadRelationships(Purchase $purchase): void
    {
        $purchase->load(['supplier', 'user', 'items.product']);
    }

    private function initializePdf(Purchase $purchase, bool $includeDetails): void
    {
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->setPrintHeader(false);
        $this->pdf->SetCreator('Sales Management System');
        $this->pdf->SetTitle('تقرير الضرائب والجمارك #' . $purchase->id);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetMargins(self::MARGIN, $this->renderer->getTopMargin(), self::MARGIN);
        $this->pdf->SetAutoPageBreak(true, self::MARGIN + 5);
        $this->pdf->SetFont('arial', '', self::FONT_SIZE_BODY);
        $this->pdf->setRTL(false);
    }

    private function buildReport(Purchase $purchase, bool $includeDetails): void
    {
        $this->addHeader($purchase, $includeDetails);
        $this->addSummarySection($purchase);

        if ($includeDetails) {
            $this->addDetailsSection($purchase);
        }

        $this->addFooter();
    }

    private function addHeader(Purchase $purchase, bool $includeDetails): void
    {
        $this->pdf->SetDrawColor(self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $this->pdf->SetLineWidth(1.5);
        $this->pdf->Line(self::MARGIN, self::MARGIN, $this->pdf->getPageWidth() - self::MARGIN, self::MARGIN);
        $this->pdf->Ln(5);

        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_TITLE);
        $this->pdf->SetTextColor(self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);

        $title = $includeDetails ? 'تقرير الضرائب والجمارك (تفصيلي)' : 'تقرير الضرائب والجمارك (ملخص)';
        $this->pdf->Cell(0, 10, $title, 0, 1, 'C');

        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_SUBTITLE);
        $this->pdf->Cell(0, 8, 'رقم الشراء: #' . $purchase->id, 0, 1, 'C');
        $this->pdf->Cell(0, 8, 'المورد: ' . ($purchase->supplier?->name ?? '---'), 0, 1, 'C');
        $this->pdf->Cell(0, 8, 'التاريخ: ' . ($purchase->purchase_date ? \Carbon\Carbon::parse($purchase->purchase_date)->format('Y-m-d') : '---'), 0, 1, 'C');

        $this->pdf->Ln(5);
        $this->pdf->SetDrawColor(self::COLOR_BORDER[0], self::COLOR_BORDER[1], self::COLOR_BORDER[2]);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Line(self::MARGIN, $this->pdf->GetY(), $this->pdf->getPageWidth() - self::MARGIN, $this->pdf->GetY());
        $this->pdf->Ln(5);
    }

    private function addSummarySection(Purchase $purchase): void
    {
        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_HEADING);
        $this->pdf->Cell(0, 10, 'ملخص الضرائب والجمارك', 0, 1, 'R');

        $pageWidth = $this->pdf->getPageWidth() - (self::MARGIN * 2);

        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_BODY);
        $this->pdf->SetFillColor(self::COLOR_LIGHT_GRAY[0], self::COLOR_LIGHT_GRAY[1], self::COLOR_LIGHT_GRAY[2]);

        // Taxes Row
        $this->pdf->Cell($pageWidth * 0.4, 10, 'إجمالي الضرائب:', 1, 0, 'C', true);
        $this->pdf->SetFont('arial', '', self::FONT_SIZE_BODY);
        $this->pdf->Cell($pageWidth * 0.6, 10, number_format((float)$purchase->tax_amount, 2) . ' ' . $purchase->currency, 1, 1, 'R');

        // Customs Row
        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_BODY);
        $this->pdf->Cell($pageWidth * 0.4, 10, 'إجمالي الجمارك:', 1, 0, 'C', true);
        $this->pdf->SetFont('arial', '', self::FONT_SIZE_BODY);
        $this->pdf->Cell($pageWidth * 0.6, 10, number_format((float)$purchase->customs_amount, 2) . ' ' . $purchase->currency, 1, 1, 'R');

        // Combined Total
        $total = (float)$purchase->tax_amount + (float)$purchase->customs_amount;
        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_BODY);
        $this->pdf->SetFillColor(self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->Cell($pageWidth * 0.4, 10, 'الإجمالي الكلي:', 1, 0, 'C', true);
        $this->pdf->Cell($pageWidth * 0.6, 10, number_format($total, 2) . ' ' . $purchase->currency, 1, 1, 'R', true);

        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->Ln(10);
    }

    private function addDetailsSection(Purchase $purchase): void
    {
        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_HEADING);
        $this->pdf->Cell(0, 10, 'تفاصيل الأصناف', 0, 1, 'R');

        $pageWidth = $this->pdf->getPageWidth() - (self::MARGIN * 2);
        $widths = [
            'no' => $pageWidth * 0.1,
            'name' => $pageWidth * 0.5,
            'qty' => $pageWidth * 0.2,
            'total' => $pageWidth * 0.2,
        ];

        $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_SMALL);
        $this->pdf->SetFillColor(self::COLOR_HEADER_BG[0], self::COLOR_HEADER_BG[1], self::COLOR_HEADER_BG[2]);
        $this->pdf->SetTextColor(255, 255, 255);

        $this->pdf->Cell($widths['no'], 8, '#', 1, 0, 'C', true);
        $this->pdf->Cell($widths['name'], 8, 'الصنف', 1, 0, 'C', true);
        $this->pdf->Cell($widths['qty'], 8, 'الكمية', 1, 0, 'C', true);
        $this->pdf->Cell($widths['total'], 8, 'إجمالي التكلفة', 1, 1, 'C', true);

        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('arial', '', self::FONT_SIZE_SMALL);

        foreach ($purchase->items as $index => $item) {
            $this->pdf->Cell($widths['no'], 8, $index + 1, 1, 0, 'C');
            $this->pdf->Cell($widths['name'], 8, $item->product?->name ?? '---', 1, 0, 'R');
            $this->pdf->Cell($widths['qty'], 8, number_format($item->quantity), 1, 0, 'C');
            $this->pdf->Cell($widths['total'], 8, number_format($item->total_cost, 2), 1, 1, 'C');
        }

        // If there are detailed tax/customs breakdown in JSON, we could add them here
        if (!empty($purchase->tax_details)) {
            $this->pdf->Ln(5);
            $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_HEADING);
            $this->pdf->Cell(0, 10, 'تفصيل الضرائب:', 0, 1, 'R');
            $this->pdf->SetFont('arial', '', self::FONT_SIZE_BODY);
            foreach ($purchase->tax_details as $detail) {
                if (isset($detail['label']) && isset($detail['amount'])) {
                    $this->pdf->Cell($pageWidth * 0.7, 8, $detail['label'], 1, 0, 'R');
                    $this->pdf->Cell($pageWidth * 0.3, 8, number_format($detail['amount'], 2), 1, 1, 'C');
                }
            }
        }

        if (!empty($purchase->customs_details)) {
            $this->pdf->Ln(5);
            $this->pdf->SetFont('arial', 'B', self::FONT_SIZE_HEADING);
            $this->pdf->Cell(0, 10, 'تفصيل الجمارك:', 0, 1, 'R');
            $this->pdf->SetFont('arial', '', self::FONT_SIZE_BODY);
            foreach ($purchase->customs_details as $detail) {
                if (isset($detail['label']) && isset($detail['amount'])) {
                    $this->pdf->Cell($pageWidth * 0.7, 8, $detail['label'], 1, 0, 'R');
                    $this->pdf->Cell($pageWidth * 0.3, 8, number_format($detail['amount'], 2), 1, 1, 'C');
                }
            }
        }
    }

    private function addFooter(): void
    {
        $this->pdf->SetY(-20);
        $this->pdf->SetFont('arial', 'I', self::FONT_SIZE_SMALL);
        $this->pdf->Cell(0, 10, 'تم الإنشاء بواسطة نظام إدارة المبيعات - ' . date('Y-m-d H:i'), 0, 0, 'C');
    }
}
