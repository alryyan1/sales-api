<?php

namespace App\Services;

use App\Models\Shift;
use TCPDF;

class ShiftCostPdfService
{
    private const ORIENTATION = 'P';
    private const UNIT = 'mm';
    private const FORMAT = 'A4';
    private const MARGIN = 15;
    private const FONT_MAIN = 'arial';

    private string $companyName;

    public function generate(Shift $shift): string
    {
        $this->initializeSettings();
        $pdf = $this->initializePdf();

        $pdf->AddPage();

        $this->renderHeader($pdf, $shift);

        $expenses = $shift->expenses()->with(['user', 'category'])->get();
        if ($expenses->isEmpty()) {
            $pdf->SetFont(self::FONT_MAIN, '', 12);
            $pdf->Cell(0, 20, 'لا توجد مصروفات مسجلة لهذه الوردية.', 0, 1, 'C');
        } else {
            $this->renderExpensesTable($pdf, $expenses);
        }

        $pdfFileName = 'Shift_' . $shift->id . '_Costs_' . now()->format('Ymd_His') . '.pdf';
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
        $pdf->SetCreator('System');
        $pdf->SetAuthor($this->companyName);
        $pdf->SetTitle('تقرير مصروفات الوردية');
        $pdf->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setRTL(true);
        $pdf->SetFont(self::FONT_MAIN, '', 10);
        return $pdf;
    }

    private function renderHeader(TCPDF $pdf, Shift $shift): void
    {
        $pdf->SetFont(self::FONT_MAIN, 'B', 16);
        $pdf->Cell(0, 8, $this->companyName, 0, 1, 'R');
        $pdf->Ln(5);

        $pdf->SetFont(self::FONT_MAIN, 'B', 14);
        $pdf->Cell(0, 8, 'تقرير المصروفات - وردية رقم #' . $shift->id, 0, 1, 'C');

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

    private function renderExpensesTable(TCPDF $pdf, $expenses): void
    {
        $cols = [
            ['w' => 10, 't' => '#'], // 10
            ['w' => 30, 't' => 'التاريخ والوقت'], // 40
            ['w' => 30, 't' => 'المستخدم'], // 70
            ['w' => 60, 't' => 'البيان / التصنيف'], // 130
            ['w' => 25, 't' => 'المبلغ (جنيه)'], // 155
            ['w' => 25, 't' => 'طريقة الدفع'], // 180 (A4 is 210, margins are 15+15=30 so width is 180)
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

        foreach ($expenses as $expense) {
            if ($pdf->GetY() > 250) {
                $pdf->AddPage();
                $pdf->SetFont(self::FONT_MAIN, 'B', 10);
                foreach ($cols as $col) {
                    $pdf->Cell($col['w'], 9, $col['t'], 1, 0, 'C', true);
                }
                $pdf->Ln();
                $pdf->SetFont(self::FONT_MAIN, '', 9);
            }

            $dateFormatted = $expense->expense_date ? \Carbon\Carbon::parse($expense->expense_date)->format('Y-m-d H:i') : '—';
            $userName = $expense->user ? $expense->user->name : '—';
            $categoryName = $expense->category ? $expense->category->name : ($expense->description ?? '—');

            $amount = (float)$expense->amount;
            $totalAmount += $amount;

            $methodLabel = $this->getPaymentMethodLabel($expense->payment_method ?? 'cash');

            // Save current Y position
            $yBefore = $pdf->GetY();

            // Need to support multi-line "Description" safely
            $startX = $pdf->GetX();

            // Store current X,Y so we know where to draw cells
            $pdf->Cell($cols[0]['w'], 8, $expense->id, 1, 0, 'C');
            $pdf->Cell($cols[1]['w'], 8, $dateFormatted, 1, 0, 'C');
            $pdf->Cell($cols[2]['w'], 8, $userName, 1, 0, 'C');
            $pdf->Cell($cols[3]['w'], 8, $categoryName, 1, 0, 'C');
            $pdf->Cell($cols[4]['w'], 8, number_format($amount, 2), 1, 0, 'C');
            $pdf->Cell($cols[5]['w'], 8, $methodLabel, 1, 1, 'C');
        }

        // Footer Total
        $pdf->SetFont(self::FONT_MAIN, 'B', 10);
        $pdf->SetFillColor(240, 248, 255);

        $pdf->Cell($cols[0]['w'] + $cols[1]['w'] + $cols[2]['w'] + $cols[3]['w'], 9, 'الإجمالي', 1, 0, 'C', true);
        $pdf->Cell($cols[4]['w'], 9, number_format($totalAmount, 2), 1, 0, 'C', true);
        $pdf->Cell($cols[5]['w'], 9, '', 1, 1, 'C', true);
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
        ];
        return $labels[$method] ?? $method;
    }
}
