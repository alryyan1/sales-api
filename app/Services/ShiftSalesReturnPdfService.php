<?php

namespace App\Services;

use App\Models\Shift;
use App\Services\Pdf\PdfHeaderRenderer;
use TCPDF;

class ShiftSalesReturnPdfService
{
    private const ORIENTATION = 'P';
    private const UNIT = 'mm';
    private const FORMAT = 'A4';
    private const MARGIN = 15;
    private const FONT_MAIN = 'arial';

    private string $companyName;
    private PdfHeaderRenderer $renderer;

    public function generate(Shift $shift): string
    {
        $this->initializeSettings();
        $this->renderer = new PdfHeaderRenderer('shift_sales_return');
        $pdf = $this->initializePdf();

        $pdf->AddPage();
        $this->renderer->render($pdf);

        $this->renderHeader($pdf, $shift);

        $returns = $shift->saleReturns()->with(['user', 'items', 'sale'])->get();
        if ($returns->isEmpty()) {
            $pdf->SetFont(self::FONT_MAIN, '', 12);
            $pdf->Cell(0, 20, 'لا توجد مردودات مسجلة لهذه الوردية.', 0, 1, 'C');
        } else {
            $this->renderReturnsTable($pdf, $returns);
        }

        $pdfFileName = 'Shift_' . $shift->id . '_Returns_' . now()->format('Ymd_His') . '.pdf';
        return $pdf->Output($pdfFileName, 'S');
    }

    private function initializeSettings(): void
    {
        $settings = (new SettingsService())->getAll();
        $this->companyName = $settings['company_name'] ?? 'Company Name';
    }

    private function initializePdf(): TCPDF
    {
        $pdf = new TCPDF(self::ORIENTATION, self::UNIT, self::FORMAT, true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('System');
        $pdf->SetAuthor($this->companyName);
        $pdf->SetTitle('تقرير مردودات المبيعات للوردية');
        $pdf->SetMargins(self::MARGIN, $this->renderer->getTopMargin(), self::MARGIN);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setRTL(false);
        $pdf->SetFont(self::FONT_MAIN, '', 10);
        return $pdf;
    }

    private function renderHeader(TCPDF $pdf, Shift $shift): void
    {
        $pdf->SetFont(self::FONT_MAIN, 'B', 14);
        $pdf->Cell(0, 8, 'تقرير مردودات المبيعات - وردية رقم #' . $shift->id, 0, 1, 'C');

        $pdf->SetFont(self::FONT_MAIN, '', 10);
        $info = 'تاريخ الفتح: ' . ($shift->opened_at ? $shift->opened_at->format('Y-m-d h:i A') : '—');

        $user = $shift->user;
        if ($user) {
            $info .= ' | المستخدم: ' . $user->name;
        }

        $pdf->Cell(0, 6, $info, 0, 1, 'C');
        $pdf->Cell(0, 6, 'تاريخ الطباعة: ' . now()->format('Y-m-d h:i A'), 0, 1, 'C');

        $pdf->Ln(5);
        $pdf->SetLineWidth(0.4);
        $pdf->Line(self::MARGIN, $pdf->GetY(), 210 - self::MARGIN, $pdf->GetY());
        $pdf->Ln(5);
    }

    private function renderReturnsTable(TCPDF $pdf, $returns): void
    {
        $cols = [
            ['w' => 10, 't' => 'رقم'], // 10
            ['w' => 25, 't' => 'التاريخ والوقت'], // 35
            ['w' => 20, 't' => 'رقم البيع'], // 55
            ['w' => 25, 't' => 'المستخدم'], // 80
            ['w' => 50, 't' => 'عدد/كمية العناصر المرتجعة'], // 130
            ['w' => 25, 't' => 'المبلغ (جنيه)'], // 155
            ['w' => 25, 't' => 'طريقة الدفع'], // 180
        ];

        // Header
        $pdf->SetFont(self::FONT_MAIN, 'B', 10);
        $pdf->SetFillColor(230, 230, 230);
        foreach ($cols as $col) {
            $pdf->Cell($col['w'], 9, $col['t'], 1, 0, 'C', true);
        }
        $pdf->Ln();

        // Body
        $pdf->SetFont(self::FONT_MAIN, '', 9);
        $totalAmount = 0;

        foreach ($returns as $ret) {
            if ($pdf->GetY() > 260) {
                $pdf->AddPage();
                $this->renderer->render($pdf);
                $pdf->SetFont(self::FONT_MAIN, 'B', 10);
                foreach ($cols as $col) {
                    $pdf->Cell($col['w'], 9, $col['t'], 1, 0, 'C', true);
                }
                $pdf->Ln();
                $pdf->SetFont(self::FONT_MAIN, '', 9);
            }

            $dateFormatted = $ret->created_at ? $ret->created_at->format('Y-m-d H:i') : '—';
            $userName = $ret->user ? $ret->user->name : '—';
            $saleId = $ret->sale_id ?? '—';

            $amount = $ret->items->sum(function ($item) {
                return $item->quantity * $item->price;
            });
            $totalAmount += $amount;

            $itemsCount = $ret->items->sum('quantity');

            $methodLabel = $this->getPaymentMethodLabel($ret->returned_payment_method ?? 'cash');

            $pdf->Cell($cols[0]['w'], 8, $ret->id, 1, 0, 'C');
            $pdf->Cell($cols[1]['w'], 8, $dateFormatted, 1, 0, 'C');
            $pdf->Cell($cols[2]['w'], 8, $saleId, 1, 0, 'C');
            $pdf->Cell($cols[3]['w'], 8, $userName, 1, 0, 'C');
            $pdf->Cell($cols[4]['w'], 8, $itemsCount . ' عناصر', 1, 0, 'C');
            $pdf->Cell($cols[5]['w'], 8, number_format($amount, 2), 1, 0, 'C');
            $pdf->Cell($cols[6]['w'], 8, $methodLabel, 1, 1, 'C');
        }

        // Footer Total
        $pdf->SetFont(self::FONT_MAIN, 'B', 10);
        $pdf->SetFillColor(240, 248, 255);

        $totalSpan = $cols[0]['w'] + $cols[1]['w'] + $cols[2]['w'] + $cols[3]['w'] + $cols[4]['w'];
        $pdf->Cell($totalSpan, 9, 'الإجمالي', 1, 0, 'C', true);
        $pdf->Cell($cols[5]['w'], 9, number_format($totalAmount, 2), 1, 0, 'C', true);
        $pdf->Cell($cols[6]['w'], 9, '', 1, 1, 'C', true);
    }

    private function getPaymentMethodLabel(string $method): string
    {
        $labels = [
            'cash' => 'نقدي',
            'bankak' => 'بنكك',
            'bank' => 'بنك',
            'fawry' => 'فوري',
            'ocash' => 'أوكاش',
            'visa' => 'فيزا',
            'bank_transfer' => 'بنك',
            'refund' => 'مرتجع',
        ];
        return $labels[$method] ?? $method;
    }
}
