<?php

namespace App\Services;

use App\Models\ReportTemplate;
use App\Services\Pdf\PdfHeaderRenderer;
use TCPDF;

class ReportTemplatePdfService
{
    private PdfHeaderRenderer $renderer;

    public function __construct()
    {
        $this->renderer = new PdfHeaderRenderer('report_template');
    }

    public function createPdf(ReportTemplate $template, string $date): TCPDF
    {
        $content = $this->replacePlaceholders($template->content, $date);

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setRTL(true);
        $topMargin = $this->renderer->getTopMargin();
        $pdf->SetMargins(15, $topMargin, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        $this->renderer->render($pdf);

        // Title
        $pdf->SetFont('arial', 'B', 14);
        $pdf->Cell(0, 10, $template->name, 0, 1, 'C');
        $pdf->Ln(2);

        // Divider
        $pageW = $pdf->getPageWidth() - 30;
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->SetLineWidth(0.3);
        $pdf->Line(15, $pdf->GetY(), 15 + $pageW, $pdf->GetY());
        $pdf->Ln(5);

        // Body
        $pdf->SetFont('arial', '', 11);
        $pdf->SetTextColor(40, 40, 40);
        foreach (explode("\n", $content) as $line) {
            trim($line) === ''
                ? $pdf->Ln(4)
                : $pdf->MultiCell(0, 7, $line, 0, 'R', false, 1);
        }

        // Footer
        if (!empty($template->footer_text)) {
            $pdf->Ln(6);
            $pdf->SetDrawColor(200, 200, 200);
            $pdf->Line(15, $pdf->GetY(), 15 + $pageW, $pdf->GetY());
            $pdf->Ln(4);
            $pdf->SetFont('arial', '', 10);
            $pdf->SetTextColor(100, 100, 100);
            foreach (explode("\n", $template->footer_text) as $line) {
                trim($line) === ''
                    ? $pdf->Ln(3)
                    : $pdf->MultiCell(0, 6, $line, 0, 'R', false, 1);
            }
        }

        return $pdf;
    }

    private function replacePlaceholders(string $content, string $date): string
    {
        return str_replace(['{{date}}', '{{DATE}}'], [$date, $date], $content);
    }
}
