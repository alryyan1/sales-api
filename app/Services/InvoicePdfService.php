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

        // Load sale items and payments
        $sale->load(['items.product', 'client', 'user', 'payments']);

        // Initialize paid amount
        $paidAmount = (float) ($sale->payments?->sum('amount') ?? 0);

        // Always use the custom Arabic layout. title depends on payment status
        $isFinal = $paidAmount > 0;
        $title = $isFinal ? 'فاتورة نهائية' : 'فاتورة مبدئية';

        return $this->generateArabicProformaPdf($sale, $settings, $title, $isFinal);
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
        $pdf->SetY(277); // Near bottom of A4 (297mm - 20mm margin)
        $pdf->SetFont('arial', '', 9);

        $timestamp = date('d/m/Y H:i:s', strtotime($sale->sale_date));
        $pdf->Cell(0, 5, $timestamp, 0, 0, 'L');
    }

    /**
     * Generate Arabic Proforma Invoice PDF
     */
    private function generateArabicProformaPdf(Sale $sale, array $settings, string $title, bool $isFinal = false): string
    {
        $pdf = new \App\Services\Pdf\MyCustomTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // Use Arabic fonts and RTL
        $pdf->setRTL(true);
        $pdf->SetFont('dejavusans', '', 10);

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 10);

        $pdf->AddPage();

        $this->generateArabicProformaHeader($pdf, $sale, $settings, $title);
        $this->generateArabicProformaTable($pdf, $sale);
        $this->generateArabicProformaSummary($pdf, $sale, $isFinal);
        $this->generateArabicProformaTerms($pdf, $sale);

        return $pdf->Output('', 'S');
    }

    private function generateArabicProformaHeader(\App\Services\Pdf\MyCustomTCPDF $pdf, Sale $sale, array $settings, string $title): void
    {
        $y = $pdf->GetY();

        // Logo and Company Info
        if (!empty($settings['company_logo_url'])) {
            $logoPath = $settings['company_logo_url'];
            // Handle relative storage paths
            if (strpos($logoPath, 'http') === false) {
                $logoPath = public_path('storage/' . $logoPath);
            } else {
                $path = parse_url($logoPath, PHP_URL_PATH) ?: '';
                if ($path) {
                    $storagePos = strpos($path, '/storage/');
                    if ($storagePos !== false) {
                        $relative = substr($path, $storagePos + strlen('/storage/'));
                        $candidate = public_path('storage/' . ltrim($relative, '/'));
                        if (file_exists($candidate)) {
                            $logoPath = $candidate;
                        }
                    }
                }
            }

            if (file_exists($logoPath)) {
                $pdf->Image($logoPath, 170, $y, 25, 0, '', '', 'T', false, 300, '', false, false, 0);
            }
        }

        $pdf->SetY($y);
        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->Cell(0, 10, $settings['company_name'] ?? '', 0, 1, 'L');
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->Cell(0, 6, $settings['company_address'] ?? '', 0, 1, 'L');
        $pdf->Cell(0, 6, 'الهاتف: ' . ($settings['company_phone'] ?? ''), 0, 1, 'L');

        $pdf->Ln(5);
        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell(0, 12, $title, 0, 1, 'C', true);
        $pdf->Ln(5);

        // Customer and Invoice Info
        $pdf->SetFont('dejavusans', '', 11);
        $infoY = $pdf->GetY();

        $pdf->Cell(30, 8, 'تاريخ العرض:', 0, 0, 'R');
        $pdf->Cell(60, 8, date('Y/m/d', strtotime($sale->sale_date)), 0, 0, 'R');

        $pdf->SetX(110);
        $pdf->Cell(30, 8, 'إسم العميل:', 0, 0, 'R');
        $pdf->Cell(60, 8, ($sale->client ? $sale->client->name : 'عميل نقدي'), 0, 1, 'R');

        $pdf->Cell(30, 8, 'رقم العرض:', 0, 0, 'R');
        $pdf->Cell(60, 8, 'P-' . $sale->id, 0, 0, 'R');

        $pdf->SetX(110);
        $pdf->Cell(30, 8, 'رقم الهاتف:', 0, 0, 'R');
        $pdf->Cell(60, 8, ($sale->client ? $sale->client->phone : ''), 0, 1, 'R');

        $pdf->Ln(5);
    }

    private function generateArabicProformaTable(\App\Services\Pdf\MyCustomTCPDF $pdf, Sale $sale): void
    {
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetFont('dejavusans', 'B', 10);

        // Column widths
        $w = [10, 80, 15, 15, 25, 45]; // م, البيان, الوحده, العدد, السعر, المبلغ

        $pdf->Cell($w[0], 8, 'م', 1, 0, 'C', true);
        $pdf->Cell($w[1], 8, 'البيان', 1, 0, 'C', true);
        $pdf->Cell($w[2], 8, 'الوحده', 1, 0, 'C', true);
        $pdf->Cell($w[3], 8, 'العدد', 1, 0, 'C', true);
        $pdf->Cell($w[4], 8, 'السعر', 1, 0, 'C', true);
        $pdf->Cell($w[5], 8, 'المبلغ', 1, 1, 'C', true);

        $pdf->SetFont('dejavusans', '', 10);
        foreach ($sale->items as $idx => $item) {
            $pdf->Cell($w[0], 8, ($idx + 1), 1, 0, 'C');
            $pdf->Cell($w[1], 8, ($item->product->name ?? ''), 1, 0, 'R');
            $pdf->Cell($w[2], 8, ($item->product->sellableUnit->name ?? 'حبة'), 1, 0, 'C');
            $pdf->Cell($w[3], 8, $item->quantity, 1, 0, 'C');
            $pdf->Cell($w[4], 8, number_format($item->unit_price, 2), 1, 0, 'C');
            $pdf->Cell($w[5], 8, number_format($item->total_price, 2), 1, 1, 'C');
        }
    }

    private function generateArabicProformaSummary(\App\Services\Pdf\MyCustomTCPDF $pdf, Sale $sale, bool $isFinal = false): void
    {
        $total = $sale->items->sum('total_price');
        $discount = $sale->discount_amount ?? 0;
        $net = $total - $discount;

        $pdf->Ln(2);

        // Total row
        $pdf->SetFont('dejavusans', 'B', 11);
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
        $pdf->SetFont('dejavusans', '', 11);
        $wordAmount = $this->numberToArabicWords($net);
        $pdf->Cell(0, 10, 'فقط وقدره: ' . $wordAmount . ' لا غير', 0, 1, 'R');
        $pdf->Ln(5);
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

    private function generateArabicProformaTerms(\App\Services\Pdf\MyCustomTCPDF $pdf, Sale $sale): void
    {
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->Cell(0, 8, 'شروط وأحكام:', 0, 1, 'R');
        $pdf->SetFont('dejavusans', '', 10);

        $terms = [
            '* يسري هذا العرض لمدة اسبوعين من تاريخ توقيعه',
            '* لا يشمل النقل والترحيل وتركيب المعدات (استلام من مخازن الشركة في شرق النيل - الخرطوم )',
            '* المعدات جاهزة للتسليم عند دفع قيمة الفتورة ',
            '* حساب الشركة : بنكك 8880700 وفوري 30028652  (اوقر للإستثمار والإنتاج الغذائي)'
        ];

        foreach ($terms as $term) {
            $pdf->Cell(0, 6, $term, 0, 1, 'R');
        }

        $pdf->Ln(10);
        $yStart = $pdf->GetY();

        $stampPath = public_path('images/stamp.png');
        $sigPath = public_path('images/signature.png');

        // Center X for the block (shifted left as per previous requests)
        $centerX = 130;

        if (file_exists($stampPath)) {
            // Draw Stamp 
            $pdf->Image($stampPath, $centerX - 22.5, $yStart, 45, 0, '', '', '', false, 300, '', false, false, 0);
        }

        if (file_exists($sigPath)) {
            // Draw Signature under the Stamp (increased distance)
            $sigY = $yStart + 55;
            $pdf->Image($sigPath, $centerX - 20, $sigY, 40, 0, '', '', '', false, 300, '', false, false, 0);

            // Write Name directly under the Signature
            $pdf->SetY($sigY + 18); // Moved slightly closer to signature image bottom
            $pdf->SetX($centerX - 30);
            $pdf->SetFont('dejavusans', 'B', 12);
            $pdf->Cell(60, 10, 'كمال يحى', 0, 1, 'C');
        }
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
