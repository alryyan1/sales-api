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
        // Get settings
        $settings = app(\App\Services\SettingsService::class)->getAll();

        // Load sale items, payments, and warehouse
        $sale->load(['items.product', 'client', 'user', 'payments', 'warehouse']);

        // Initialize paid amount
        $paidAmount = (float) ($sale->payments?->sum('amount') ?? 0);

        // Determine invoice title
        if ($sale->is_quote) {
            $title = 'تسعيره';
            $isFinal = false;
        } else {
            $isFinal = $paidAmount > 0;
            $title = $isFinal ? 'فاتورة نهائية' : 'فاتورة مبدئية';
        }

        return $this->generateArabicProformaPdf($sale, $settings, $title, $isFinal);
    }

    /** @deprecated — kept for reference only, not called */
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

        // Calculate totals dynamically
        $itemsTotal = $sale->items->sum('total_price');
        $discountAmount = $sale->discount_amount ?? 0;
        $totalAmount = $itemsTotal - $discountAmount;
        $paidAmount = $sale->payments->sum('amount');
        $currentDue = max(0, $totalAmount - $paidAmount);

        // Subtotal (if discount exists)
        if ($discountAmount > 0) {
            $pdf->SetXY($rightX, $y);
            $pdf->Cell($boxWidth - 30, 5, 'Subtotal', 0, 0, 'L');
            $pdf->Cell(30, 5, number_format($itemsTotal, 0, '.', ','), 0, 1, 'R');

            $y = $pdf->GetY() + 1;
            $pdf->Line($rightX, $y, $rightX + $boxWidth, $y);
            $y += 4;

            $pdf->SetXY($rightX, $y);
            $pdf->Cell($boxWidth - 30, 5, 'Discount', 0, 0, 'L');
            $pdf->Cell(30, 5, number_format($discountAmount, 0, '.', ','), 0, 1, 'R');

            $y = $pdf->GetY() + 1;
            $pdf->Line($rightX, $y, $rightX + $boxWidth, $y);
            $y += 4;
        }

        // Total
        $pdf->SetXY($rightX, $y);
        $pdf->Cell($boxWidth - 30, 5, 'Total', 0, 0, 'L');
        $pdf->Cell(30, 5, number_format($totalAmount, 0, '.', ','), 0, 1, 'R');

        // Line
        $y = $pdf->GetY() + 1;
        $pdf->Line($rightX, $y, $rightX + $boxWidth, $y);
        $y += 4;

        // Paid
        $pdf->SetXY($rightX, $y);
        $pdf->Cell($boxWidth - 30, 5, 'Paid', 0, 0, 'L');
        $pdf->Cell(30, 5, number_format($paidAmount, 0, '.', ','), 0, 1, 'R');

        // Line
        $y = $pdf->GetY() + 1;
        $pdf->Line($rightX, $y, $rightX + $boxWidth, $y);
        $y += 4;

        // Current Due
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
        // $pdf->SetY(277); // Near bottom of A4 (297mm - 20mm margin)
        $pdf->SetFont('arial', '', 9);

        $timestamp = date('d/m/Y H:i:s', strtotime($sale->sale_date));
        $pdf->Cell(0, 5, $timestamp, 0, 0, 'L');
    }

    /**
     * Generate Arabic Proforma Invoice PDF
     */
    private function generateArabicProformaPdf(Sale $sale, array $settings, string $title, bool $isFinal = false): string
    {
        $renderer = new \App\Services\Pdf\PdfHeaderRenderer('invoice');

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $companyAddress = $settings['company_address'] ?? null;
        $pdf->setRTL(false);
        $pdf->SetFont('arial', '', 10);
        $pdf->SetMargins(10, $renderer->getTopMargin() + 5, 10);
        $pdf->SetAutoPageBreak(false, 10); // Disable auto page break to prevent unwanted page creation

        $pdf->AddPage();

        $this->generateArabicProformaHeader($pdf, $renderer, $sale, $title, $settings);
        $this->generateArabicProformaTable($pdf, $sale);
        $this->generateArabicProformaSummary($pdf, $sale, $isFinal);
        $this->generateArabicProformaTerms($pdf, $sale);
        $this->generateStampAndSignature($pdf, $settings);

        // ── FOOTER: address ───────────────────────────────────────────────────
        if ($companyAddress) {
            $pageHeight = $pdf->getPageHeight();
            $bottomMargin = 15; // mm from bottom
            $currentY = $pdf->GetY();
            
            // Only add footer if there's space on current page
            if ($currentY < ($pageHeight - $bottomMargin)) {
                $pdf->SetY($pageHeight - $bottomMargin);
                $pdf->SetFont('arial', '', 8);
                $pdf->Cell(0, 6, $companyAddress, 'T', 0, 'C');
            }
        }

        return $pdf->Output('', 'S');
    }

    private function generateArabicProformaHeader(TCPDF $pdf, \App\Services\Pdf\PdfHeaderRenderer $renderer, Sale $sale, string $title, array $settings = []): void
    {
        $pageW   = $pdf->getPageWidth(); // 210mm
        $leftM   = 10;
        $rightM  = 10;
        $usableW = $pageW - $leftM - $rightM; // 190mm

        $taxNumber      = $settings['tax_number']      ?? null;
        $companyAddress = $settings['company_address'] ?? null;

        // ── BRANDING HEADER (logo / header image / text) ──────────────────────
        $renderer->render($pdf);

        // ── TAX NUMBER (top, below branding header) ───────────────────────────
        // if ($taxNumber) {
        //     $pdf->SetFont('arial', '', 9);
        //     $pdf->Cell(0, 6, 'الرقم التعريفي: ' . $taxNumber, 0, 1, 'C');
        // }

        // ── DIVIDER ───────────────────────────────────────────────────────────
        $pdf->SetDrawColor(180, 180, 180);
        $pdf->Line($leftM, $pdf->GetY(), $pageW - $rightM, $pdf->GetY());
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->Ln(2); // Reduced from 3

        // ── TITLE BANNER ─────────────────────────────────────────────────────
        $pdf->SetFont('arial', 'B', 16);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell(0, 5, $title, 0, 1, 'C', false);
        $pdf->Ln(2); // Reduced from 4

        // ── CUSTOMER / INVOICE INFO (2-column bordered box) ───────────────────
        $infoY = $pdf->GetY();
        $rowH  = 6; // Reduced from 8
        $colW  = $usableW / 2; // ~95mm each column

        // Light background box
        $pdf->SetFillColor(248, 248, 248);
        $pdf->Rect($leftM, $infoY, $usableW, $rowH * 3, 'DF');

        // Row 1 — right column: invoice date | left column: client name
        $pdf->SetXY($leftM + $colW, $infoY);
        $pdf->SetFont('arial', 'B', 9); // Reduced from 10
        $pdf->Cell($colW * 0.55, $rowH, date('Y/m/d', strtotime($sale->sale_date)), 0, 0, 'R');
        $pdf->SetFont('arial', '', 9); // Reduced from 10
        $pdf->Cell($colW * 0.45, $rowH, 'تاريخ الفاتورة:', 0, 0, 'C');

        $pdf->SetXY($leftM, $infoY);
        $pdf->SetFont('arial', 'B', 9); // Reduced from 10
        $pdf->Cell($colW * 0.6, $rowH, ($sale->client ? $sale->client->name : 'عميل نقدي'), 0, 0, 'R');
        $pdf->SetFont('arial', '', 9); // Reduced from 10
        $pdf->Cell($colW * 0.4, $rowH, 'اسم العميل:', 0, 0, 'C');

        // Row 2 — right column: invoice number | left column: phone
        $pdf->SetXY($leftM + $colW, $infoY + $rowH);
        $pdf->SetFont('arial', 'B', 9); // Reduced from 10
        $pdf->Cell($colW * 0.55, $rowH, (string) $sale->id, 0, 0, 'R');
        $pdf->SetFont('arial', '', 9); // Reduced from 10
        $pdf->Cell($colW * 0.45, $rowH, 'رقم الفاتورة:', 0, 0, 'C');

        $pdf->SetXY($leftM, $infoY + $rowH);
        $pdf->SetFont('arial', 'B', 9); // Reduced from 10
        $pdf->Cell($colW * 0.6, $rowH, $settings['tax_number'] ?? null, 0, 0, 'R');
        $pdf->SetFont('arial', '', 9); // Reduced from 10
        $pdf->Cell($colW * 0.4, $rowH, 'الرقم التعريفي:', 0, 0, 'C');

        // Row 3 — Branch Name
        if ($sale->warehouse) {
            $pdf->SetXY($leftM + $colW, $infoY + $rowH * 2);
            $pdf->SetFont('arial', 'B', 9);
            $pdf->Cell($colW * 0.55, $rowH, $sale->warehouse->name, 0, 0, 'R');
            $pdf->SetFont('arial', '', 9);
            $pdf->Cell($colW * 0.45, $rowH, 'الفرع:', 0, 0, 'C');
        }

        $pdf->SetY($infoY + $rowH * 3 + 2); // Reduced from 5
    }

    private function generateArabicProformaTable(TCPDF $pdf, Sale $sale): void
    {
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetFont('arial', 'B', 9); // Reduced from 10

        // Column widths
        $w = [10, 80, 15, 15, 25, 45]; // م, البيان, الوحده, العدد, السعر, المبلغ

        $pdf->Cell($w[0], 7, 'م', 1, 0, 'C', true);
        $pdf->Cell($w[1], 7, 'البيان', 1, 0, 'C', true);
        $pdf->Cell($w[2], 7, 'الوحده', 1, 0, 'C', true);
        $pdf->Cell($w[3], 7, 'العدد', 1, 0, 'C', true);
        $pdf->Cell($w[4], 7, 'السعر', 1, 0, 'C', true);
        $pdf->Cell($w[5], 7, 'المبلغ', 1, 1, 'C', true);

        $pdf->SetFont('arial', '', 8); // Reduced from 10
        foreach ($sale->items as $idx => $item) {
            $productName = $item->product->name ?? '';
            $maxW = $w[1] - 2; // Subtract some padding
            if ($pdf->GetStringWidth($productName) > $maxW) {
                while ($pdf->GetStringWidth($productName . '...') > $maxW && mb_strlen($productName) > 0) {
                    $productName = mb_substr($productName, 0, -1);
                }
                $productName .= '...';
            }
            // Wrap with Unicode RLE/PDF markers so TCPDF's bidi algorithm
            // treats mixed Arabic+English product names as RTL (fixes reversed Arabic).
            if (preg_match('/[\x{0600}-\x{06FF}]/u', $productName)) {
                $productName = "\xE2\x80\xAB" . $productName . "\xE2\x80\xAC";
            }

            $pdf->Cell($w[0], 6, ($idx + 1), 1, 0, 'C'); // Reduced height from 8 to 6
            $pdf->Cell($w[1], 6, $productName, 1, 0, 'C');
            $pdf->Cell($w[2], 6, ($item->product->sellableUnit->name ?? 'حبة'), 1, 0, 'C');
            $pdf->Cell($w[3], 6, $item->quantity, 1, 0, 'C');
            $pdf->Cell($w[4], 6, number_format($item->unit_price, 2), 1, 0, 'C');
            $pdf->Cell($w[5], 6, number_format($item->total_price, 2), 1, 1, 'C');
        }
    }

    private function generateArabicProformaSummary(TCPDF $pdf, Sale $sale, bool $isFinal = false): void
    {
        $total = $sale->items->sum('total_price');
        $discount = $sale->discount_amount ?? 0;
        $net = $total - $discount;

        $pdf->Ln(5); // Reduced from 2

        // Total row
        $pdf->SetFont('arial', 'B', 11);
        $pdf->Cell(145, 10, 'الإجمالي الكلي', 1, 0, 'C');
        $pdf->Cell(45, 10, number_format($net, 2), 1, 1, 'C');

        if ($isFinal) {
            $paid = (float) ($sale->payments?->sum('amount') ?? 0);
            $due = max(0, $net - $paid);

            $pdf->Cell(145, 10, 'المدفوع', 1, 0, 'C');
            $pdf->Cell(45, 10, number_format($paid, 2), 1, 1, 'C');

            $pdf->Cell(145, 10, 'المتبقي', 1, 0, 'C');
            $pdf->Cell(45, 10, number_format($due, 2), 1, 1, 'C');
        }

        // Sum in words
        $pdf->SetFont('arial', '', 11);
        $wordAmount = $this->numberToArabicWords($net);
        $pdf->Cell(0, 10, 'فقط وقدره: ' . $wordAmount . ' جنية   لا غير', 0, 1, 'R');
        $pdf->Ln(2); // Reduced from 5
    }

    private function numberToArabicWords($number): string
    {
        $number = round($number, 2);
        if ($number == 0)
            return 'صفر';

        $conjunction = ' و ';

        $dictionary = [
            0 => 'صفر',
            1 => 'واحد',
            2 => 'اثنان',
            3 => 'ثلاثة',
            4 => 'أربعة',
            5 => 'خمسة',
            6 => 'ستة',
            7 => 'سبعة',
            8 => 'ثمانية',
            9 => 'تسعة',
            10 => 'عشرة',
            11 => 'أحد عشر',
            12 => 'اثنا عشر',
            13 => 'ثلاثة عشر',
            14 => 'أربعة عشر',
            15 => 'خمسة عشر',
            16 => 'ستة عشر',
            17 => 'سبعة عشر',
            18 => 'ثمانية عشر',
            19 => 'تسعة عشر',
            20 => 'عشرون',
            30 => 'ثلاثون',
            40 => 'أربعون',
            50 => 'خمسون',
            60 => 'ستون',
            70 => 'سبعون',
            80 => 'ثمانون',
            90 => 'تسعون',
            100 => 'مائة',
            200 => 'مائتان',
            300 => 'ثلاثمائة',
            400 => 'أربعمائة',
            500 => 'خمسمائة',
            600 => 'ستمائة',
            700 => 'سبعمائة',
            800 => 'ثمانمائة',
            900 => 'تسعمائة',
            1000 => 'ألف',
            2000 => 'ألفان',
            3000 => 'ثلاثة آلاف',
            4000 => 'أربعة آلاف',
            5000 => 'خمسة آلاف',
            6000 => 'ستة آلاف',
            7000 => 'سبعة آلاف',
            8000 => 'ثمانية آلاف',
            9000 => 'تسعة آلاف',
            10000 => 'عشرة آلاف'
        ];

        $parts = explode('.', (string) $number);
        $integerPart = (int) $parts[0];
        $decimalPart = isset($parts[1]) ? (int) substr($parts[1], 0, 2) : 0;

        $convertInteger = function ($num) use ($dictionary, $conjunction, &$convertInteger) {
            if ($num <= 20)
                return $dictionary[$num];
            if ($num < 100) {
                $units = $num % 10;
                $tens = (int) ($num / 10) * 10;
                return ($units > 0 ? $dictionary[$units] . $conjunction : '') . $dictionary[$tens];
            }
            if ($num < 1000) {
                $hundreds = (int) ($num / 100) * 100;
                $remainder = $num % 100;
                return $dictionary[$hundreds] . ($remainder > 0 ? $conjunction . $convertInteger($remainder) : '');
            }
            if ($num < 1000000) {
                $thousands = (int) ($num / 1000);
                $remainder = $num % 1000;
                $thStr = ($thousands == 1) ? $dictionary[1000] : (($thousands == 2) ? $dictionary[2000] : ($thousands <= 10 ? $dictionary[$thousands * 1000] : $convertInteger($thousands) . ' ألف'));
                return $thStr . ($remainder > 0 ? $conjunction . $convertInteger($remainder) : '');
            }
            if ($num < 1000000000) {
                $millions = (int) ($num / 1000000);
                $remainder = $num % 1000000;
                $mStr = ($millions == 1) ? 'مليون' : (($millions == 2) ? 'مليونان' : (($millions >= 3 && $millions <= 10) ? $convertInteger($millions) . ' ملايين' : $convertInteger($millions) . ' مليون'));
                return $mStr . ($remainder > 0 ? $conjunction . $convertInteger($remainder) : '');
            }
            if ($num < 1000000000000) {
                $billions = (int) ($num / 1000000000);
                $remainder = $num % 1000000000;
                $bStr = ($billions == 1) ? 'مليار' : (($billions == 2) ? 'ملياران' : (($billions >= 3 && $billions <= 10) ? $convertInteger($billions) . ' مليارات' : $convertInteger($billions) . ' مليار'));
                return $bStr . ($remainder > 0 ? $conjunction . $convertInteger($remainder) : '');
            }
            return (string) $num; // Fallback
        };

        $result = $convertInteger($integerPart);
        if ($decimalPart > 0) {
            $result .= $conjunction . $convertInteger($decimalPart) . ' قرشاً';
        }

        return $result;
    }

    private function generateStampAndSignature(TCPDF $pdf, array $settings): void
    {
        $reportSettings = \App\Models\PdfReportSetting::where('report_key', 'invoice')->first();

        $showStamp     = $reportSettings?->show_stamp     ?? false;
        $showSignature = $reportSettings?->show_signature ?? false;

        if (!$showStamp && !$showSignature) {
            return;
        }

        $stampPath     = $this->resolveImagePath($settings['company_stamp_url']     ?? null);
        $signaturePath = $this->resolveImagePath($settings['company_signature_url'] ?? null);

        $pageW  = $pdf->getPageWidth();
        $leftM  = 10;
        $rightM = 10;
        $imgH   = 25; // mm height for stamp/signature images
        $imgW   = 35; // mm width
        $y      = $pdf->GetY() + 6;

        // Draw labels + images side by side (stamp right, signature left)
        $pdf->SetFont('arial', '', 8);
        $pdf->SetTextColor(120, 120, 120);

        if ($showStamp && $stampPath) {
            // Right side
            $x = $pageW - $rightM - $imgW - 20; // 20mm total padding for label + image
            $pdf->SetXY($x, $y +50);
            $pdf->Cell($imgW, 5, 'الختم', 0, 0, 'C');
            try {
                @$pdf->Image($stampPath, $x, $y + 64, $imgW+20, $imgH+20, '', '', '', false, 300, '', false, false, 0);
            } catch (\Throwable $e) {}
        }

        if ($showSignature && $signaturePath) {
            // Left side
            $x = $leftM;
            $pdf->SetXY($x, $y +60);
            $pdf->Cell($imgW, 5, 'التوقيع', 0, 0, 'C');
            try {
                @$pdf->Image($signaturePath, $x, $y + 65, $imgW, $imgH, '', '', '', false, 300, '', false, false, 0);
            } catch (\Throwable $e) {}
        }

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetY($y + $imgH + 8);
    }

    private function resolveImagePath(?string $url): ?string
    {
        if (!$url) return null;

        $path = parse_url($url, PHP_URL_PATH) ?: '';
        if (!$path) return null;

        $storagePos = strpos($path, '/storage/');
        if ($storagePos !== false) {
            $relative  = substr($path, $storagePos + strlen('/storage/'));
            $candidate = public_path('storage/' . ltrim($relative, '/'));
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function generateArabicProformaTerms(TCPDF $pdf, Sale $sale): void
    {
        // Removed as per request
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