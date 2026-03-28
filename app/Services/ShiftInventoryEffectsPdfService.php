<?php

namespace App\Services;

use App\Models\Shift;
use App\Services\Pdf\PdfHeaderRenderer;
use TCPDF;

class ShiftInventoryEffectsPdfService
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
        $this->renderer = new PdfHeaderRenderer('shift_inventory_effects');
        $pdf = $this->initializePdf();

        $pdf->AddPage();
        $this->renderer->render($pdf);
        $this->renderHeader($pdf, $shift);

        // Aggregate inventory effects (Sales deduct, Returns add back)
        $inventoryEffects = [];

        // 1. Process Sales (Deductions)
        foreach ($shift->sales as $sale) {
            foreach ($sale->items as $item) {
                $productId = $item->product_id;
                $productName = $item->product ? $item->product->name : ('صنف #' . $productId);
                $sku = $item->product ? $item->product->sku : '—';
                $qty = (float) $item->quantity;

                if (isset($inventoryEffects[$productId])) {
                    $inventoryEffects[$productId]['deducted'] += $qty;
                    $inventoryEffects[$productId]['net'] -= $qty;
                } else {
                    $inventoryEffects[$productId] = [
                        'name' => $productName,
                        'sku' => $sku,
                        'deducted' => $qty,
                        'returned' => 0,
                        'net' => -$qty,
                    ];
                }
            }
        }

        // 2. Process Returns (Increments)
        if ($shift->relationLoaded('saleReturns')) {
            foreach ($shift->saleReturns as $return) {
                foreach ($return->items as $item) {
                    $productId = $item->product_id;
                    $productName = $item->product ? $item->product->name : ('صنف #' . $productId);
                    $sku = $item->product ? $item->product->sku : '—';
                    $qty = (float) $item->quantity;

                    if (isset($inventoryEffects[$productId])) {
                        $inventoryEffects[$productId]['returned'] += $qty;
                        $inventoryEffects[$productId]['net'] += $qty;
                    } else {
                        $inventoryEffects[$productId] = [
                            'name' => $productName,
                            'sku' => $sku,
                            'deducted' => 0,
                            'returned' => $qty,
                            'net' => $qty,
                        ];
                    }
                }
            }
        }

        if (empty($inventoryEffects)) {
            $pdf->SetFont(self::FONT_MAIN, '', 12);
            $pdf->Cell(0, 20, 'لا توجد حركات مخزون في هذه الوردية.', 0, 1, 'C');
        } else {
            // Sort by absolute net change descending
            uasort($inventoryEffects, fn($a, $b) => abs($b['net']) <=> abs($a['net']));
            $this->renderTable($pdf, $inventoryEffects);
        }

        $pdfFileName = 'Shift_' . $shift->id . '_InventoryEffects_' . now()->format('Ymd_His') . '.pdf';
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
        $pdf->SetTitle('تقرير أثر المخزون للوردية');
        $pdf->SetMargins(self::MARGIN, $this->renderer->getTopMargin(), self::MARGIN);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setRTL(false);
        $pdf->SetFont(self::FONT_MAIN, '', 10);
        return $pdf;
    }

    private function renderHeader(TCPDF $pdf, Shift $shift): void
    {
        $pdf->SetFont(self::FONT_MAIN, 'B', 14);
        $pdf->Cell(0, 8, 'تقرير أثر المخزون - وردية رقم #' . $shift->id, 0, 1, 'C');

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

    private function renderTable(TCPDF $pdf, array $effects): void
    {
        // Widths sum to 180
        $cols = [
            ['w' => 10,  't' => '#'],
            ['w' => 70, 't' => 'اسم الصنف'],
            ['w' => 30, 't' => 'SKU'],
            ['w' => 25, 't' => 'المباع (-)'],
            ['w' => 25, 't' => 'المرتجع (+)'],
            ['w' => 20, 't' => 'الصافي'],
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
        $rowNum = 1;

        foreach ($effects as $effect) {
            if ($pdf->GetY() > 260) {
                $pdf->AddPage();
                $this->renderer->render($pdf);
                $pdf->SetFont(self::FONT_MAIN, 'B', 10);
                $pdf->SetFillColor(230, 230, 230);
                foreach ($cols as $col) {
                    $pdf->Cell($col['w'], 9, $col['t'], 1, 0, 'C', true);
                }
                $pdf->Ln();
                $pdf->SetFont(self::FONT_MAIN, '', 9);
            }

            $pdf->Cell($cols[0]['w'], 8, $rowNum++, 1, 0, 'C');
            $pdf->Cell($cols[1]['w'], 8, $effect['name'], 1, 0, 'R');
            $pdf->Cell($cols[2]['w'], 8, $effect['sku'], 1, 0, 'C');
            $pdf->Cell($cols[3]['w'], 8, number_format($effect['deducted'], 2), 1, 0, 'C');
            $pdf->Cell($cols[4]['w'], 8, number_format($effect['returned'], 2), 1, 0, 'C');

            // Color code net impact
            $net = $effect['net'];
            if ($net < 0) {
                $pdf->SetTextColor(200, 0, 0); // Red for decrease
            } elseif ($net > 0) {
                $pdf->SetTextColor(0, 150, 0); // Green for increase
            } else {
                $pdf->SetTextColor(0, 0, 0);
            }
            $pdf->Cell($cols[5]['w'], 8, number_format($net, 2), 1, 1, 'C');
            $pdf->SetTextColor(0, 0, 0); // Reset
        }
    }
}
