<?php

namespace App\Services;

use App\Models\Shift;
use TCPDF;

class ShiftSoldItemsPdfService
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

        // Aggregate sold items from all sales in this shift
        $soldItems = [];
        foreach ($shift->sales as $sale) {
            foreach ($sale->items as $item) {
                $productId = $item->product_id;
                $productName = $item->product ? $item->product->name : ('صنف #' . $productId);
                $qty = (float) $item->quantity;
                $price = (float) $item->unit_price;
                $total = (float) $item->total_price;

                if (isset($soldItems[$productId])) {
                    $soldItems[$productId]['quantity'] += $qty;
                    $soldItems[$productId]['total'] += $total;
                } else {
                    $soldItems[$productId] = [
                        'name' => $productName,
                        'price' => $price,
                        'quantity' => $qty,
                        'total' => $total,
                    ];
                }
            }
        }

        if (empty($soldItems)) {
            $pdf->SetFont(self::FONT_MAIN, '', 12);
            $pdf->Cell(0, 20, 'لا توجد أصناف مباعة في هذه الوردية.', 0, 1, 'C');
        } else {
            // Sort by total revenue descending
            usort($soldItems, fn($a, $b) => $b['total'] <=> $a['total']);
            $this->renderItemsTable($pdf, $soldItems);
        }

        $pdfFileName = 'Shift_' . $shift->id . '_SoldItems_' . now()->format('Ymd_His') . '.pdf';
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
        $pdf->SetTitle('تقرير الأصناف المباعة للوردية');
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
        $pdf->Cell(0, 8, 'تقرير الأصناف المباعة - وردية رقم #' . $shift->id, 0, 1, 'C');

        $pdf->SetFont(self::FONT_MAIN, '', 10);
        $info = 'تاريخ الفتح: ' . ($shift->opened_at ? $shift->opened_at->format('Y-m-d h:i A') : '—');
        if ($shift->user) {
            $info .= ' | المستخدم: ' . $shift->user->name;
        }
        $pdf->Cell(0, 6, $info, 0, 1, 'C');
        $pdf->Cell(0, 6, 'تاريخ الطباعة: ' . now()->format('Y-m-d h:i A'), 0, 1, 'C');

        $pdf->Ln(5);
        $pdf->SetLineWidth(0.4);
        $pdf->Line(self::MARGIN, $pdf->GetY(), 210 - self::MARGIN, $pdf->GetY());
        $pdf->Ln(5);
    }

    private function renderItemsTable(TCPDF $pdf, array $items): void
    {
        // Widths sum to 180 (A4 210 - 2×15 margins)
        $cols = [
            ['w' => 8,  't' => '#'],
            ['w' => 74, 't' => 'اسم الصنف'],
            ['w' => 28, 't' => 'سعر الوحدة'],
            ['w' => 30, 't' => 'الكمية المباعة'],
            ['w' => 40, 't' => 'الإجمالي (جنيه)'],
        ];

        // Header row
        $pdf->SetFont(self::FONT_MAIN, 'B', 10);
        $pdf->SetFillColor(230, 230, 230);
        foreach ($cols as $col) {
            $pdf->Cell($col['w'], 9, $col['t'], 1, 0, 'C', true);
        }
        $pdf->Ln();

        // Body rows
        $pdf->SetFont(self::FONT_MAIN, '', 9);
        $grandTotal = 0;
        $rowNum = 1;

        foreach ($items as $item) {
            if ($pdf->GetY() > 260) {
                $pdf->AddPage();
                $pdf->SetFont(self::FONT_MAIN, 'B', 10);
                $pdf->SetFillColor(230, 230, 230);
                foreach ($cols as $col) {
                    $pdf->Cell($col['w'], 9, $col['t'], 1, 0, 'C', true);
                }
                $pdf->Ln();
                $pdf->SetFont(self::FONT_MAIN, '', 9);
            }

            $pdf->Cell($cols[0]['w'], 8, $rowNum++, 1, 0, 'C');
            $pdf->Cell($cols[1]['w'], 8, $item['name'], 1, 0, 'R');
            $pdf->Cell($cols[2]['w'], 8, number_format($item['price'], 2), 1, 0, 'C');
            $pdf->Cell($cols[3]['w'], 8, number_format($item['quantity'], 2), 1, 0, 'C');
            $pdf->Cell($cols[4]['w'], 8, number_format($item['total'], 2), 1, 1, 'C');

            $grandTotal += $item['total'];
        }

        // Totals footer
        $pdf->SetFont(self::FONT_MAIN, 'B', 10);
        $pdf->SetFillColor(240, 248, 255);
        $pdf->Cell($cols[0]['w'] + $cols[1]['w'] + $cols[2]['w'] + $cols[3]['w'], 9, 'الإجمالي الكلي', 1, 0, 'C', true);
        $pdf->Cell($cols[4]['w'], 9, number_format($grandTotal, 2), 1, 1, 'C', true);
    }
}
