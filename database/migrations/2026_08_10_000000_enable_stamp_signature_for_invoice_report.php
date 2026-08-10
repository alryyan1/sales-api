<?php

use App\Models\PdfReportSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Seed/enable the "invoice" row so the A4 invoice PDF's stamp and signature show by
     * default, without requiring a settings UI (show_stamp/show_signature default to
     * false and no row existed for report_key="invoice" until now).
     */
    public function up(): void
    {
        PdfReportSetting::updateOrCreate(
            ['report_key' => 'invoice'],
            [
                'report_name' => 'فاتورة البيع',
                'show_stamp' => true,
                'show_signature' => true,
            ]
        );
    }

    public function down(): void
    {
        PdfReportSetting::where('report_key', 'invoice')->update([
            'show_stamp' => false,
            'show_signature' => false,
        ]);
    }
};
