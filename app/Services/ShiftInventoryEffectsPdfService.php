<?php

namespace App\Services;

use App\Models\Shift;
use App\Models\User;
use App\Services\Pdf\PdfHeaderRenderer;
use TCPDF;

class ShiftInventoryEffectsPdfService
{
    private const ORIENTATION = 'L';

    private const UNIT = 'mm';

    private const FORMAT = 'A4';

    private const MARGIN = 15;

    private const PAGE_W = 297;

    private const FONT_MAIN = 'arial';

    private string $companyName;

    private PdfHeaderRenderer $renderer;

    public function generate(Shift $shift, ?int $userId = null): string
    {
        $this->initializeSettings();
        $this->renderer = new PdfHeaderRenderer('shift_inventory_effects');
        $pdf = $this->initializePdf();

        $pdf->AddPage();
        $this->renderer->render($pdf);
        $filterUser = $userId ? User::find($userId) : null;
        $this->renderHeader($pdf, $shift, $filterUser);

        // Aggregate inventory effects (Sales deduct, Returns add back) per
        // product AND per cashier, so each row shows who moved that stock —
        // optionally scoped to just one cashier's sales/returns.
        $inventoryEffects = [];

        $sales = $userId ? $shift->sales->where('user_id', $userId) : $shift->sales;

        // 1. Process Sales (Deductions)
        foreach ($sales as $sale) {
            $saleUserName = $sale->user ? $sale->user->name : '—';
            foreach ($sale->items as $item) {
                $productId = $item->product_id;
                $key = $productId.'_'.$sale->user_id;
                $productName = $item->product ? $item->product->name : ('صنف #'.$productId);
                $sku = $item->product ? $item->product->sku : '—';
                $qty = (float) $item->quantity;

                if (isset($inventoryEffects[$key])) {
                    $inventoryEffects[$key]['deducted'] += $qty;
                    $inventoryEffects[$key]['net'] -= $qty;
                } else {
                    $inventoryEffects[$key] = [
                        'name' => $productName,
                        'sku' => $sku,
                        'user' => $saleUserName,
                        'deducted' => $qty,
                        'returned' => 0,
                        'net' => -$qty,
                    ];
                }
            }
        }

        // 2. Process Returns (Increments)
        if ($shift->relationLoaded('saleReturns')) {
            $returns = $userId ? $shift->saleReturns->where('user_id', $userId) : $shift->saleReturns;
            foreach ($returns as $return) {
                $returnUserName = $return->user ? $return->user->name : '—';
                foreach ($return->items as $item) {
                    $productId = $item->product_id;
                    $key = $productId.'_'.$return->user_id;
                    $productName = $item->product ? $item->product->name : ('صنف #'.$productId);
                    $sku = $item->product ? $item->product->sku : '—';
                    $qty = (float) $item->quantity;

                    if (isset($inventoryEffects[$key])) {
                        $inventoryEffects[$key]['returned'] += $qty;
                        $inventoryEffects[$key]['net'] += $qty;
                    } else {
                        $inventoryEffects[$key] = [
                            'name' => $productName,
                            'sku' => $sku,
                            'user' => $returnUserName,
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
            uasort($inventoryEffects, fn ($a, $b) => abs($b['net']) <=> abs($a['net']));
            $this->renderTable($pdf, $inventoryEffects);
        }

        $pdfFileName = 'Shift_'.$shift->id.'_InventoryEffects_'.now()->format('Ymd_His').'.pdf';

        return $pdf->Output($pdfFileName, 'S');
    }

    private function initializeSettings(): void
    {
        $settings = (new SettingsService)->getAll();
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

    private function renderHeader(TCPDF $pdf, Shift $shift, ?User $filterUser = null): void
    {
        $pdf->SetFont(self::FONT_MAIN, 'B', 14);
        $pdf->Cell(0, 8, 'تقرير أثر المخزون - وردية رقم #'.$shift->id, 0, 1, 'C');

        $pdf->SetFont(self::FONT_MAIN, '', 10);
        $info = 'تاريخ الفتح: '.($shift->opened_at ? $shift->opened_at->format('Y-m-d h:i A') : '—');
        if ($shift->user) {
            $info .= ' | المستخدم: '.$shift->user->name;
        }
        $pdf->Cell(0, 6, $info, 0, 1, 'C');

        if ($filterUser) {
            $pdf->SetFont(self::FONT_MAIN, 'B', 10);
            $pdf->Cell(0, 6, 'حركة مخزون المستخدم: '.$filterUser->name, 0, 1, 'C');
            $pdf->SetFont(self::FONT_MAIN, '', 10);
        }
        $pdf->Cell(0, 6, 'تاريخ الطباعة: '.now()->format('Y-m-d h:i A'), 0, 1, 'C');

        $pdf->Ln(5);
        $pdf->SetLineWidth(0.4);
        $pdf->Line(self::MARGIN, $pdf->GetY(), self::PAGE_W - self::MARGIN, $pdf->GetY());
        $pdf->Ln(5);
    }

    private function renderTable(TCPDF $pdf, array $effects): void
    {
        // Widths sum to 267 (landscape body width: 297 - 2*15 margin)
        $cols = [
            ['w' => 10, 't' => '#'],
            ['w' => 75, 't' => 'اسم الصنف'],
            ['w' => 35, 't' => 'SKU'],
            ['w' => 45, 't' => 'المستخدم'],
            ['w' => 34, 't' => 'المباع (-)'],
            ['w' => 34, 't' => 'المرتجع (+)'],
            ['w' => 34, 't' => 'الصافي'],
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
            if ($pdf->GetY() > 175) {
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
            $pdf->Cell($cols[3]['w'], 8, $effect['user'], 1, 0, 'R');
            $pdf->Cell($cols[4]['w'], 8, number_format($effect['deducted'], 2), 1, 0, 'C');
            $pdf->Cell($cols[5]['w'], 8, number_format($effect['returned'], 2), 1, 0, 'C');

            // Color code net impact
            $net = $effect['net'];
            if ($net < 0) {
                $pdf->SetTextColor(200, 0, 0); // Red for decrease
            } elseif ($net > 0) {
                $pdf->SetTextColor(0, 150, 0); // Green for increase
            } else {
                $pdf->SetTextColor(0, 0, 0);
            }
            $pdf->Cell($cols[6]['w'], 8, number_format($net, 2), 1, 1, 'C');
            $pdf->SetTextColor(0, 0, 0); // Reset
        }
    }
}
